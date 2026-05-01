<?php

namespace NativeBlade\Commands\Config;

use Illuminate\Console\Command;

class AndroidConfigGenerator
{
    private const PERMISSIONS = [
        'camera' => 'android.permission.CAMERA',
        'location' => 'android.permission.ACCESS_FINE_LOCATION',
        'location_coarse' => 'android.permission.ACCESS_COARSE_LOCATION',
        'microphone' => 'android.permission.RECORD_AUDIO',
        'storage' => 'android.permission.READ_EXTERNAL_STORAGE',
        'storage_write' => 'android.permission.WRITE_EXTERNAL_STORAGE',
        'notifications' => 'android.permission.POST_NOTIFICATIONS',
        'vibrate' => 'android.permission.VIBRATE',
        'biometric' => 'android.permission.USE_BIOMETRIC',
        'nfc' => 'android.permission.NFC',
        'internet' => 'android.permission.INTERNET',
        'network_state' => 'android.permission.ACCESS_NETWORK_STATE',
    ];

    public function __construct(private Command $cmd) {}

    public function generate(array $config): void
    {
        $this->generateTheme($config);
        $this->generateOrientation($config);
        $this->generateVersion($config);
        $this->generateFullscreen($config);
        $this->generateSdk($config);
        $this->generateSplash($config);
        $this->generatePushNotification($config);
    }

    private function generateTheme(array $config): void
    {
        $themePath = base_path('src-tauri/gen/android/app/src/main/res/values/themes.xml');
        if (!file_exists($themePath)) return;

        $themeName = $this->detectThemeName();
        $statusColor = $config['statusBar']['color'] ?? '#FF0A0A0A';
        $navColor = $config['navigationBar']['color'] ?? '#FF0A0A0A';
        $lightStatus = ($config['statusBar']['style'] ?? 'dark') === 'light' ? 'true' : 'false';

        if (!str_starts_with($statusColor, '#FF')) $statusColor = '#FF' . ltrim($statusColor, '#');
        if (!str_starts_with($navColor, '#FF')) $navColor = '#FF' . ltrim($navColor, '#');

        $xml = <<<XML
<resources xmlns:tools="http://schemas.android.com/tools">
    <style name="{$themeName}" parent="Theme.MaterialComponents.DayNight.NoActionBar">
        <item name="android:statusBarColor">{$statusColor}</item>
        <item name="android:navigationBarColor">{$navColor}</item>
        <item name="android:windowLightStatusBar" tools:targetApi="23">{$lightStatus}</item>
    </style>
</resources>
XML;

        file_put_contents($themePath, $xml);

        $nightPath = str_replace('/values/', '/values-night/', $themePath);
        if (file_exists($nightPath)) file_put_contents($nightPath, $xml);

        $this->cmd->line("  <fg=green>✓</> Android theme updated");
    }

    private function detectThemeName(): string
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return 'Theme.nativeblade';

        $manifest = file_get_contents($manifestPath);
        if (preg_match('/android:theme="@style\/([^"]+)"/', $manifest, $matches)) {
            return $matches[1];
        }

        return 'Theme.nativeblade';
    }

    private function generateOrientation(array $config): void
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $orientation = $config['orientation'] ?? null;
        if (!$orientation) return;

        $androidOrientation = match ($orientation) {
            'portrait' => 'portrait',
            'landscape' => 'landscape',
            'auto', 'unspecified' => 'unspecified',
            default => 'portrait',
        };

        $manifest = file_get_contents($manifestPath);

        if (str_contains($manifest, 'android:screenOrientation')) {
            $manifest = preg_replace(
                '/android:screenOrientation="[^"]*"/',
                'android:screenOrientation="' . $androidOrientation . '"',
                $manifest
            );
        } else {
            $manifest = str_replace(
                '<activity',
                '<activity android:screenOrientation="' . $androidOrientation . '"',
                $manifest
            );
        }

        file_put_contents($manifestPath, $manifest);
        $this->cmd->line("  <fg=green>✓</> Android orientation: {$androidOrientation}");
    }

    private function generateVersion(array $config): void
    {
        if (!isset($config['version']) || !isset($config['buildNumber'])) return;

        $gradlePath = base_path('src-tauri/gen/android/app/build.gradle.kts');
        if (!file_exists($gradlePath)) return;

        $gradle = file_get_contents($gradlePath);

        if (str_contains($gradle, 'versionCode')) {
            $gradle = preg_replace('/versionCode\s*=\s*\d+/', 'versionCode = ' . $config['buildNumber'], $gradle);
            $gradle = preg_replace('/versionName\s*=\s*"[^"]*"/', 'versionName = "' . $config['version'] . '"', $gradle);
        }

        file_put_contents($gradlePath, $gradle);
        $this->cmd->line("  <fg=green>✓</> Android version: {$config['version']} ({$config['buildNumber']})");
    }

    private function generateFullscreen(array $config): void
    {
        if (!isset($config['fullscreen'])) return;

        $themePath = base_path('src-tauri/gen/android/app/src/main/res/values/themes.xml');
        if (!file_exists($themePath)) return;

        $value = $config['fullscreen'] ? 'true' : 'false';

        foreach ([$themePath, str_replace('/values/', '/values-night/', $themePath)] as $path) {
            if (!file_exists($path)) continue;
            $xml = file_get_contents($path);

            if (str_contains($xml, 'windowFullscreen')) {
                $xml = preg_replace(
                    '/<item name="android:windowFullscreen">[^<]*<\/item>/',
                    '<item name="android:windowFullscreen">' . $value . '</item>',
                    $xml
                );
            } else {
                $xml = str_replace(
                    '</style>',
                    '    <item name="android:windowFullscreen">' . $value . '</item>' . "\n    </style>",
                    $xml
                );
            }

            file_put_contents($path, $xml);
        }

        $this->cmd->line("  <fg=green>✓</> Android fullscreen: {$value}");
    }

    private function generatePermissions(array $config): void
    {
        $permissions = $config['permissions'] ?? [];
        if (empty($permissions)) return;

        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $manifest = file_get_contents($manifestPath);

        foreach ($permissions as $key => $description) {
            $androidPerm = self::PERMISSIONS[$key] ?? null;
            if (!$androidPerm) continue;

            if (!str_contains($manifest, $androidPerm)) {
                $tag = '<uses-permission android:name="' . $androidPerm . '" />';
                $manifest = str_replace('<application', $tag . "\n\n    <application", $manifest);
            }
        }

        $allowedPerms = array_merge(
            array_values(array_filter(array_map(fn($k) => self::PERMISSIONS[$k] ?? null, array_keys($permissions)))),
            ['android.permission.INTERNET', 'android.permission.ACCESS_NETWORK_STATE']
        );

        preg_match_all('/android.permission\.[A-Z_]+/', $manifest, $matches);
        foreach (array_unique($matches[0]) as $existing) {
            if (!in_array($existing, $allowedPerms)) {
                $manifest = preg_replace('/\s*<uses-permission[^>]*' . preg_quote($existing) . '[^>]*\/?>/', '', $manifest);
            }
        }

        file_put_contents($manifestPath, $manifest);
        $this->cmd->line("  <fg=green>✓</> Android permissions: " . implode(', ', array_keys($permissions)));
    }

    private function generateSdk(array $config): void
    {
        $gradlePath = base_path('src-tauri/gen/android/app/build.gradle.kts');
        if (!file_exists($gradlePath)) return;

        $gradle = file_get_contents($gradlePath);
        $changed = false;

        if (isset($config['minSdk'])) {
            $gradle = preg_replace('/minSdk\s*=\s*\d+/', 'minSdk = ' . $config['minSdk'], $gradle);
            $changed = true;
        }

        if (isset($config['targetSdk'])) {
            $gradle = preg_replace('/targetSdk\s*=\s*\d+/', 'targetSdk = ' . $config['targetSdk'], $gradle);
            $changed = true;
        }

        if ($changed) {
            file_put_contents($gradlePath, $gradle);
            $this->cmd->line("  <fg=green>✓</> Android SDK: min=" . ($config['minSdk'] ?? '?') . " target=" . ($config['targetSdk'] ?? '?'));
        }
    }

    private function generatePushNotification(array $config): void
    {
        $push = $config['notification'] ?? null;
        if (!$push || !isset($push['fcmConfig'])) return;

        $source = $push['fcmConfig'];
        if (!file_exists($source)) {
            $this->cmd->line("  <fg=yellow>→</> google-services.json not found at {$source}");
            return;
        }

        $destDir = base_path('src-tauri/gen/android/app');
        if (!is_dir($destDir)) {
            $this->cmd->line("  <fg=yellow>→</> src-tauri/gen/android/app missing — run 'nativeblade:add android' first");
            return;
        }

        copy($source, $destDir . '/google-services.json');
        $this->cmd->line("  <fg=green>✓</> google-services.json copied to Android project");

        $this->ensureGoogleServicesPlugin();
    }

    private function ensureGoogleServicesPlugin(): void
    {
        $appGradle = base_path('src-tauri/gen/android/app/build.gradle.kts');
        if (!file_exists($appGradle)) return;

        $content = file_get_contents($appGradle);
        $changed = false;

        if (!str_contains($content, 'com.google.gms.google-services')) {
            $content = preg_replace(
                '/(plugins\s*\{[^}]*?)(\n\})/s',
                "$1\n    id(\"com.google.gms.google-services\")$2",
                $content,
                1
            );
            $changed = true;
        }

        if ($changed) {
            file_put_contents($appGradle, $content);
            $this->cmd->line("  <fg=green>✓</> google-services Gradle plugin enabled");
        }

        $rootGradle = base_path('src-tauri/gen/android/build.gradle.kts');
        if (!file_exists($rootGradle)) return;

        $root = file_get_contents($rootGradle);

        if (str_contains($root, 'com.google.gms:google-services') || str_contains($root, 'com.google.gms.google-services')) {
            return;
        }

        if (preg_match('/plugins\s*\{/', $root)) {
            $root = preg_replace(
                '/(plugins\s*\{)/',
                "$1\n    id(\"com.google.gms.google-services\") version \"4.4.4\" apply false",
                $root,
                1
            );
            file_put_contents($rootGradle, $root);
            $this->cmd->line("  <fg=green>✓</> google-services plugin declared in root build.gradle.kts");
            return;
        }

        if (preg_match('/buildscript\s*\{.*?dependencies\s*\{/s', $root)) {
            $root = preg_replace(
                '/(buildscript\s*\{.*?dependencies\s*\{)(\s*\n)/s',
                "$1$2        classpath(\"com.google.gms:google-services:4.4.4\")\n",
                $root,
                1
            );
            file_put_contents($rootGradle, $root);
            $this->cmd->line("  <fg=green>✓</> google-services classpath added to root build.gradle.kts");
            return;
        }

        $this->cmd->line("  <fg=yellow>→</> could not auto-patch root build.gradle.kts — add `classpath(\"com.google.gms:google-services:4.4.4\")` to the buildscript.dependencies block manually");
    }

    private function generateSplash(array $config): void
    {
        $color = $config['splashBackground'] ?? null;
        if (!$color) return;

        $argb = '#FF' . ltrim($color, '#');

        foreach (['values', 'values-night'] as $dir) {
            $themePath = base_path("src-tauri/gen/android/app/src/main/res/{$dir}/themes.xml");
            if (!file_exists($themePath)) continue;

            $xml = file_get_contents($themePath);

            if (str_contains($xml, 'windowSplashScreenBackground')) {
                $xml = preg_replace(
                    '/<item name="android:windowSplashScreenBackground">[^<]*<\/item>/',
                    '<item name="android:windowSplashScreenBackground">' . $argb . '</item>',
                    $xml
                );
            } else {
                $xml = str_replace(
                    '</style>',
                    '    <item name="android:windowSplashScreenBackground">' . $argb . '</item>' . "\n    </style>",
                    $xml
                );
            }

            file_put_contents($themePath, $xml);
        }

        $this->cmd->line("  <fg=green>✓</> Android splash: {$color}");
    }
}
