<?php

namespace NativeBlade\Commands\Config;

use Illuminate\Console\Command;

class AndroidConfigGenerator
{
    public function __construct(private Command $cmd) {}

    private const START_XML = '<!-- nativeblade:config:start -->';
    private const END_XML = '<!-- nativeblade:config:end -->';

    public function generate(array $config): void
    {
        $this->generateAppName();
        $this->generateTheme($config);
        $this->generateOrientation($config);
        $this->generateEdgeToEdge();
        $this->generateVersion($config);
        $this->generateSdk($config);
        $this->generateProguard();
        $this->stripDebugSymbolsBlock();
        $this->generateNfcAutoLaunch($config);
        $this->generateMetaData($config);
        $this->generateAppLinks();
        $this->generateAnalyticsDefault();
        $this->generateAdId();
        $this->generateFirebase($config);
        $this->ensureKotlinVersion();
    }

    private const ADID_START = '<!-- nativeblade:adid:start -->';
    private const ADID_END = '<!-- nativeblade:adid:end -->';

    /**
     * Firebase Analytics pulls in `com.google.android.gms.permission.AD_ID`,
     * which forces a Play Console "advertising ID" data-safety declaration on
     * Android 13+. Apps that only use Analytics (no ads) can drop the
     * permission with `tools:node="remove"` and declare "no advertising id".
     *
     * Removed by default when analytics is enabled; opt back in with
     * `analyticsConfig(advertisingId: true)` (then declare "yes" in Play).
     */
    private function generateAdId(): void
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $manifest = file_get_contents($manifestPath);
        $original = $manifest;

        $startQ = preg_quote(self::ADID_START, '/');
        $endQ = preg_quote(self::ADID_END, '/');
        $manifest = preg_replace("/\s*{$startQ}.*?{$endQ}/s", '', $manifest);

        $analytics = \NativeBlade\ShellConfig::getAppConfigs()['analytics'] ?? null;
        $removeAdId = $analytics !== null && !($analytics['advertisingId'] ?? false);

        if ($removeAdId && preg_match('/<manifest\b[^>]*>/', $manifest)) {
            if (!str_contains($manifest, 'xmlns:tools=')) {
                $manifest = preg_replace(
                    '/<manifest\b/',
                    '<manifest xmlns:tools="http://schemas.android.com/tools"',
                    $manifest,
                    1
                );
            }

            $block = implode("\n", [
                '    ' . self::ADID_START,
                '    <uses-permission android:name="com.google.android.gms.permission.AD_ID" tools:node="remove" />',
                '    ' . self::ADID_END,
            ]);
            $manifest = preg_replace('/(<manifest\b[^>]*>)/', "$1\n" . $block, $manifest, 1);
        }

        if ($manifest !== $original) {
            file_put_contents($manifestPath, $manifest);
            $this->cmd->line($removeAdId
                ? "  <fg=green>✓</> AD_ID permission removed (analytics without advertising id)"
                : "  <fg=green>✓</> Restored AD_ID permission");
        }
    }

    private const KOTLIN_TARGET = '2.2.0';

    /**
     * Tauri's Android scaffold pins the Kotlin Gradle plugin at 1.9.x, but
     * modern Google and AndroidX artifacts (Firebase 34.x being the first to
     * bite here) ship modules compiled with Kotlin 2.x metadata that a 1.9
     * toolchain cannot read ("incompatible version of Kotlin ... metadata is
     * 2.2.0, expected 1.9.0").
     *
     * Standardize every project on a 2.x toolchain so these errors never
     * surface, regardless of which plugins are enabled. Only raises a version
     * older than the target, never downgrades a newer toolchain the dev set.
     */
    private function ensureKotlinVersion(): void
    {
        $rootGradle = base_path('src-tauri/gen/android/build.gradle.kts');
        if (!file_exists($rootGradle)) return;

        $root = file_get_contents($rootGradle);
        $original = $root;

        $root = preg_replace_callback(
            '/(classpath\(\s*"org\.jetbrains\.kotlin:kotlin-gradle-plugin:)(\d+\.\d+\.\d+)("\s*\))/',
            fn($m) => $m[1] . $this->maxKotlin($m[2]) . $m[3],
            $root
        );
        $root = preg_replace_callback(
            '/(id\(\s*"org\.jetbrains\.kotlin\.android"\s*\)\s*version\s*")(\d+\.\d+\.\d+)(")/',
            fn($m) => $m[1] . $this->maxKotlin($m[2]) . $m[3],
            $root
        );

        if ($root !== $original) {
            file_put_contents($rootGradle, $root);
            $this->cmd->line("  <fg=green>✓</> Kotlin toolchain raised to " . self::KOTLIN_TARGET);
        }
    }

    private function maxKotlin(string $current): string
    {
        return version_compare($current, self::KOTLIN_TARGET, '<') ? self::KOTLIN_TARGET : $current;
    }

    private const ANALYTICS_START = '<!-- nativeblade:analytics:start -->';
    private const ANALYTICS_END = '<!-- nativeblade:analytics:end -->';

    /**
     * Build-time Firebase Analytics collection default via
     * `NativeBladeConfig::analytics(collectionEnabledByDefault: ...)`. Writes
     * the `firebase_analytics_collection_enabled` meta-data into the
     * application element, fenced with markers. Removed when analytics is not
     * configured.
     */
    private function generateAnalyticsDefault(): void
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $manifest = file_get_contents($manifestPath);
        $original = $manifest;

        $startQ = preg_quote(self::ANALYTICS_START, '/');
        $endQ = preg_quote(self::ANALYTICS_END, '/');
        $manifest = preg_replace("/\s*{$startQ}.*?{$endQ}/s", '', $manifest);

        $analytics = \NativeBlade\ShellConfig::getAppConfigs()['analytics'] ?? null;

        if ($analytics !== null && str_contains($manifest, '</application>')) {
            $value = ($analytics['collectionEnabledByDefault'] ?? true) ? 'true' : 'false';
            $block = implode("\n", [
                '        ' . self::ANALYTICS_START,
                '        <meta-data android:name="firebase_analytics_collection_enabled" android:value="' . $value . '" />',
                '        ' . self::ANALYTICS_END,
            ]);
            $manifest = preg_replace('/(\s*<\/application>)/', "\n" . $block . "$1", $manifest, 1);
        }

        if ($manifest !== $original) {
            file_put_contents($manifestPath, $manifest);
            $this->cmd->line("  <fg=green>✓</> Android analytics collection default");
        }
    }

    private const APPLINKS_START = '<!-- nativeblade:applinks:start -->';
    private const APPLINKS_END = '<!-- nativeblade:applinks:end -->';

    /**
     * Verified Android App Links via `NativeBladeConfig::deepLinks([...])`.
     *
     * Emits one autoVerify intent-filter (with a `<data>` per domain) inside the
     * main activity, fenced by `<!-- nativeblade:applinks:start -->` markers so
     * re-runs replace it cleanly and dropping the config removes it. Pair with a
     * hosted `.well-known/assetlinks.json` for the links to actually verify.
     */
    private function generateAppLinks(): void
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $manifest = file_get_contents($manifestPath);
        $original = $manifest;

        $startQ = preg_quote(self::APPLINKS_START, '/');
        $endQ = preg_quote(self::APPLINKS_END, '/');
        $manifest = preg_replace("/\s*{$startQ}.*?{$endQ}/s", '', $manifest);

        $domains = \NativeBlade\ShellConfig::getAppConfigs()['deepLinks']['domains'] ?? [];

        if (!empty($domains) && str_contains($manifest, '</activity>')) {
            $lines = ['            ' . self::APPLINKS_START];
            $lines[] = '            <intent-filter android:autoVerify="true">';
            $lines[] = '                <action android:name="android.intent.action.VIEW" />';
            $lines[] = '                <category android:name="android.intent.category.DEFAULT" />';
            $lines[] = '                <category android:name="android.intent.category.BROWSABLE" />';
            foreach ($domains as $domain) {
                $d = htmlspecialchars((string) $domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $lines[] = '                <data android:scheme="https" android:host="' . $d . '" />';
            }
            $lines[] = '            </intent-filter>';
            $lines[] = '            ' . self::APPLINKS_END;
            $block = implode("\n", $lines);

            $manifest = preg_replace('/(\s*<\/activity>)/', "\n" . $block . "$1", $manifest, 1);
        }

        if ($manifest !== $original) {
            file_put_contents($manifestPath, $manifest);
            $this->cmd->line(empty($domains)
                ? "  <fg=green>✓</> Stripped App Links intent-filter"
                : "  <fg=green>✓</> Android App Links: " . count($domains) . " domain(s)");
        }
    }

    private const META_START = '<!-- nativeblade:meta:start -->';
    private const META_END = '<!-- nativeblade:meta:end -->';

    /**
     * App-specific `<meta-data>` escape hatch via `AndroidConfig::manifestMetaData()`.
     *
     * Entries are fenced inside `<!-- nativeblade:meta:start -->` markers right
     * before `</application>`, so re-runs replace the block cleanly and dropping
     * the config removes it. Built-in plugins write their own meta-data; this is
     * only for keys the app needs directly (e.g. an AdMob application id).
     */
    private function generateMetaData(array $config): void
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $manifest = file_get_contents($manifestPath);
        $original = $manifest;

        // Strip the previous block first so re-runs and removals are clean.
        $startQ = preg_quote(self::META_START, '/');
        $endQ = preg_quote(self::META_END, '/');
        $manifest = preg_replace("/\s*{$startQ}.*?{$endQ}/s", '', $manifest);

        $entries = $config['manifestMetaData'] ?? [];

        if (!empty($entries) && str_contains($manifest, '</application>')) {
            $lines = ['        ' . self::META_START];
            foreach ($entries as $name => $value) {
                $n = htmlspecialchars((string) $name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $v = htmlspecialchars($this->metaValueToString($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $lines[] = '        <meta-data android:name="' . $n . '" android:value="' . $v . '" />';
            }
            $lines[] = '        ' . self::META_END;
            $block = implode("\n", $lines);

            $manifest = preg_replace('/(\s*<\/application>)/', "\n" . $block . "$1", $manifest, 1);
        }

        if ($manifest !== $original) {
            file_put_contents($manifestPath, $manifest);
            $this->cmd->line(empty($entries)
                ? "  <fg=green>✓</> Stripped manifest meta-data block"
                : "  <fg=green>✓</> Android manifest meta-data: " . count($entries) . " entries");
        }
    }

    private function metaValueToString(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        return (string) $value;
    }

    private const NFC_START = '<!-- nativeblade:nfc:start -->';
    private const NFC_END = '<!-- nativeblade:nfc:end -->';

    /**
     * NFC auto-launch is opt-in via `AndroidConfig::nfcAutoLaunch()`.
     *
     * Without that call, the generator strips any stale NFC filters that
     * could let contactless cards (credit, transit, badges) wake the app.
     * With it, the generator emits exactly the filters the dev declared,
     * fenced by `<!-- nativeblade:nfc:start -->` markers so re-runs replace
     * the previous block cleanly.
     */
    private function generateNfcAutoLaunch(array $config): void
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $manifest = file_get_contents($manifestPath);
        $original = $manifest;
        $manifest = $this->stripNfcBlock($manifest);

        $declared = $config['nfcAutoLaunch'] ?? null;
        $anyTag = (bool) ($declared['anyTag'] ?? false);
        $techs = $declared['techs'] ?? [];
        $shouldEmit = $anyTag || !empty($techs);

        $techFilterPath = base_path('src-tauri/gen/android/app/src/main/res/xml/nfc_tech_filter.xml');

        if (!$shouldEmit) {
            if ($manifest !== $original) {
                file_put_contents($manifestPath, $manifest);
                $this->cmd->line("  <fg=green>✓</> Stripped NFC auto-launch filters from AndroidManifest");
            }
            if (file_exists($techFilterPath)) {
                unlink($techFilterPath);
                $this->cmd->line("  <fg=green>✓</> Removed unused res/xml/nfc_tech_filter.xml");
            }
            return;
        }

        $block = $this->buildNfcBlock($anyTag, $techs);
        $manifest = $this->injectNfcBlock($manifest, $block);

        if ($manifest !== $original) {
            file_put_contents($manifestPath, $manifest);
        }

        if (!empty($techs)) {
            $this->writeNfcTechFilter($techFilterPath, $techs);
        } elseif (file_exists($techFilterPath)) {
            unlink($techFilterPath);
        }

        $parts = [];
        if ($anyTag) $parts[] = 'TAG_DISCOVERED';
        if (!empty($techs)) $parts[] = 'TECH_DISCOVERED(' . count($techs) . ' techs)';
        $this->cmd->line("  <fg=green>✓</> NFC auto-launch: " . implode(', ', $parts));
        $this->cmd->line("  <fg=yellow>→</> Reminder: contactless cards (credit, transit, badges) may now wake the app");
    }

    private function stripNfcBlock(string $manifest): string
    {
        $startQ = preg_quote(self::NFC_START, '/');
        $endQ = preg_quote(self::NFC_END, '/');
        $manifest = preg_replace("/\s*{$startQ}.*?{$endQ}/s", '', $manifest);

        // Legacy non-marker blocks left over from earlier nativeblade releases
        // or hand-pasted Tauri NFC plugin snippets.
        $manifest = preg_replace(
            '/\s*<!--\s*NFC PLUGIN\..*?NFC PLUGIN\..*?-->/s',
            '',
            $manifest
        );
        $manifest = preg_replace(
            '/\s*<intent-filter>\s*<action\s+android:name="android\.nfc\.action\.(?:NDEF|TECH|TAG)_DISCOVERED"\s*\/>\s*<category\s+android:name="android\.intent\.category\.DEFAULT"\s*\/>\s*<\/intent-filter>/s',
            '',
            $manifest
        );
        $manifest = preg_replace(
            '/\s*<meta-data\s+android:name="android\.nfc\.action\.TECH_DISCOVERED"\s+android:resource="@xml\/nfc_tech_filter"\s*\/>/s',
            '',
            $manifest
        );

        return $manifest;
    }

    private function buildNfcBlock(bool $anyTag, array $techs): string
    {
        $lines = ['            ' . self::NFC_START];

        if ($anyTag) {
            $lines[] = '            <intent-filter>';
            $lines[] = '                <action android:name="android.nfc.action.TAG_DISCOVERED" />';
            $lines[] = '                <category android:name="android.intent.category.DEFAULT" />';
            $lines[] = '            </intent-filter>';
        }

        if (!empty($techs)) {
            $lines[] = '            <intent-filter>';
            $lines[] = '                <action android:name="android.nfc.action.TECH_DISCOVERED" />';
            $lines[] = '                <category android:name="android.intent.category.DEFAULT" />';
            $lines[] = '            </intent-filter>';
            $lines[] = '            <meta-data';
            $lines[] = '                android:name="android.nfc.action.TECH_DISCOVERED"';
            $lines[] = '                android:resource="@xml/nfc_tech_filter" />';
        }

        $lines[] = '            ' . self::NFC_END;
        return implode("\n", $lines);
    }

    /**
     * Inject the NFC marker block just before `</activity>` so it sits inside
     * the main activity alongside the launcher intent-filter. If the manifest
     * has no `</activity>` (unlikely but defensive), leave it alone.
     */
    private function injectNfcBlock(string $manifest, string $block): string
    {
        if (!str_contains($manifest, '</activity>')) {
            return $manifest;
        }

        return preg_replace(
            '/(\s*<\/activity>)/',
            "\n" . $block . "$1",
            $manifest,
            1
        );
    }

    private function writeNfcTechFilter(string $path, array $techs): void
    {
        $xmlDir = dirname($path);
        if (!is_dir($xmlDir)) mkdir($xmlDir, 0755, true);

        $tags = array_map(
            fn($tech) => '        <tech>android.nfc.tech.' . htmlspecialchars($tech, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</tech>',
            $techs
        );

        $body = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            . "<resources xmlns:xliff=\"urn:oasis:names:tc:xliff:document:1.2\">\n"
            . "    <tech-list>\n"
            . implode("\n", $tags) . "\n"
            . "    </tech-list>\n"
            . "</resources>\n";

        file_put_contents($path, $body);
    }

    private function generateAppName(): void
    {
        $name = \NativeBlade\ShellConfig::getName();
        if ($name === null) return;

        $path = base_path('src-tauri/gen/android/app/src/main/res/values/strings.xml');
        if (!file_exists($path)) return;

        $xml = file_get_contents($path);
        $escaped = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = preg_replace(
            '/<string\s+name="app_name">[^<]*<\/string>/',
            '<string name="app_name">' . $escaped . '</string>',
            $xml
        );
        $xml = preg_replace(
            '/<string\s+name="main_activity_title">[^<]*<\/string>/',
            '<string name="main_activity_title">' . $escaped . '</string>',
            $xml
        );

        file_put_contents($path, $xml);
        $this->cmd->line("  <fg=green>✓</> Android app name: {$name}");
    }

    /**
     * Tauri's release builds enable minification + ProGuard, which strips
     * methods called via JNI from Rust (`Rust.onActivityCreate`, etc.) and
     * crashes the app at activity create with a JavaException.
     *
     * Patch the user's proguard-rules.pro with the keep rules NativeBlade
     * needs, between markers so the dev's own rules outside the block are
     * preserved.
     */
    private function generateProguard(): void
    {
        $path = base_path('src-tauri/gen/android/app/proguard-rules.pro');
        if (!file_exists($path)) return;

        $packageName = $this->detectPackageName();
        $startMarker = '# nativeblade:proguard:start';
        $endMarker = '# nativeblade:proguard:end';

        $rules = <<<RULES
{$startMarker}
# Tauri runtime + Wry WebView bindings
-keep class app.tauri.** { *; }

# Activity wrapper + Rust JNI bridge generated by Wry
-keep class {$packageName}.** { *; }
-keepclassmembers class {$packageName}.** { *; }

# NativeBlade plugins (push, media)
-keep class app.nativeblade.** { *; }
-keepclassmembers class app.nativeblade.** { *; }

# Firebase (used by push plugin)
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }

# WebView JavaScript interfaces
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}

# Native JNI methods
-keepclasseswithmembers class * {
    native <methods>;
}
{$endMarker}
RULES;

        $content = file_get_contents($path);

        $startQ = preg_quote($startMarker, '/');
        $endQ = preg_quote($endMarker, '/');
        $pattern = "/[ \t]*{$startQ}.*?{$endQ}/s";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $rules, $content);
        } else {
            $content = rtrim($content) . "\n\n" . $rules . "\n";
        }

        file_put_contents($path, $content);
        $this->cmd->line("  <fg=green>✓</> proguard-rules.pro patched");
    }

    private function detectPackageName(): string
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (file_exists($manifestPath)) {
            $manifest = file_get_contents($manifestPath);
            if (preg_match('/package="([^"]+)"/', $manifest, $m)) return $m[1];
        }

        $gradlePath = base_path('src-tauri/gen/android/app/build.gradle.kts');
        if (file_exists($gradlePath)) {
            $gradle = file_get_contents($gradlePath);
            if (preg_match('/namespace\s*=\s*"([^"]+)"/', $gradle, $m)) return $m[1];
        }

        return 'com.example.app';
    }

    /**
     * Single source of truth for everything written to themes.xml: status
     * bar color/style, navigation bar color, fullscreen, splash background.
     * Items are wrapped in `<!-- nativeblade:config -->` markers — anything
     * outside the markers (custom items the dev added manually) stays put.
     */
    private function generateTheme(array $config): void
    {
        $themePath = base_path('src-tauri/gen/android/app/src/main/res/values/themes.xml');
        if (!file_exists($themePath)) return;

        $themeName = $this->detectThemeName();
        $items = $this->buildThemeItems($config);

        foreach ([$themePath, str_replace('/values/', '/values-night/', $themePath)] as $path) {
            if (!file_exists($path)) {
                $this->writeFreshTheme($path, $themeName, $items);
                continue;
            }

            $xml = file_get_contents($path);
            $xml = $this->upsertThemeItems($xml, $themeName, $items);
            file_put_contents($path, $xml);
        }

        $this->cmd->line("  <fg=green>✓</> Android theme: " . count($items) . " items");
    }

    /**
     * `android:statusBarColor` and `android:navigationBarColor` map to
     * `Window.setStatusBarColor` / `setNavigationBarColor`, both deprecated
     * in Android 15 and ignored under edge-to-edge. We only emit the light
     * icon flags here; the bars are made transparent by `enableEdgeToEdge()`
     * in MainActivity (see generateEdgeToEdge). Devs who still want a
     * visible color paint it inside the WebView via CSS using safe-area
     * insets, since the system bars sit over the content.
     */
    private function buildThemeItems(array $config): array
    {
        $items = [];

        if (isset($config['statusBar'])) {
            $light = ($config['statusBar']['style'] ?? 'dark') === 'light' ? 'true' : 'false';
            $items[] = '<item name="android:windowLightStatusBar" tools:targetApi="23">' . $light . '</item>';
            $items[] = '<item name="android:windowLightNavigationBar" tools:targetApi="27">' . $light . '</item>';
        }

        if (isset($config['fullscreen'])) {
            $items[] = '<item name="android:windowFullscreen">' . ($config['fullscreen'] ? 'true' : 'false') . '</item>';
        }

        if (isset($config['splashBackground'])) {
            $color = $this->normalizeArgb($config['splashBackground']);
            $items[] = '<item name="android:windowSplashScreenBackground">' . $color . '</item>';
        }

        return $items;
    }

    private function normalizeArgb(string $color): string
    {
        return str_starts_with($color, '#FF') ? $color : '#FF' . ltrim($color, '#');
    }

    private function writeFreshTheme(string $path, string $themeName, array $items): void
    {
        $body = empty($items) ? '' : "\n        " . implode("\n        ", $items);
        $xml = <<<XML
<resources xmlns:tools="http://schemas.android.com/tools">
    <style name="{$themeName}" parent="Theme.MaterialComponents.DayNight.NoActionBar">
        {$this->markerOpen()}{$body}
        {$this->markerClose()}
    </style>
</resources>
XML;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $xml);
    }

    private function upsertThemeItems(string $xml, string $themeName, array $items): string
    {
        $body = empty($items) ? '' : "\n        " . implode("\n        ", $items);
        $newBlock = $this->markerOpen() . $body . "\n        " . $this->markerClose();

        $startQ = preg_quote(self::START_XML, '/');
        $endQ = preg_quote(self::END_XML, '/');
        $pattern = "/[ \t]*{$startQ}.*?{$endQ}/s";

        if (preg_match($pattern, $xml)) {
            return preg_replace($pattern, $newBlock, $xml);
        }

        $itemPattern = '/<item name="android:(statusBarColor|navigationBarColor|windowLightStatusBar|windowLightNavigationBar|windowFullscreen|windowSplashScreenBackground)"[^>]*>[^<]*<\/item>\s*/';
        $xml = preg_replace($itemPattern, '', $xml);

        if (preg_match('/<style name="' . preg_quote($themeName, '/') . '"[^>]*>/', $xml)) {
            return preg_replace(
                '/(<style name="' . preg_quote($themeName, '/') . '"[^>]*>)/',
                "$1\n        {$newBlock}",
                $xml,
                1
            );
        }

        return $xml;
    }

    private function markerOpen(): string
    {
        return self::START_XML;
    }

    private function markerClose(): string
    {
        return self::END_XML;
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

    /**
     * Activity attribute, not a child element — can't wrap with comments.
     * Set when defined; remove the attribute entirely when not.
     */
    private function generateOrientation(array $config): void
    {
        $manifestPath = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifestPath)) return;

        $manifest = file_get_contents($manifestPath);
        $orientation = $config['orientation'] ?? null;

        if ($orientation === null) {
            $manifest = preg_replace('/\s*android:screenOrientation="[^"]*"/', '', $manifest);
            file_put_contents($manifestPath, $manifest);
            return;
        }

        $androidOrientation = match ($orientation) {
            'portrait' => 'portrait',
            'landscape' => 'landscape',
            'auto', 'unspecified' => 'unspecified',
            default => 'portrait',
        };

        if (str_contains($manifest, 'android:screenOrientation')) {
            $manifest = preg_replace(
                '/android:screenOrientation="[^"]*"/',
                'android:screenOrientation="' . $androidOrientation . '"',
                $manifest
            );
        } else {
            $manifest = preg_replace(
                '/<activity\b/',
                '<activity android:screenOrientation="' . $androidOrientation . '"',
                $manifest,
                1
            );
        }

        file_put_contents($manifestPath, $manifest);
        $this->cmd->line("  <fg=green>✓</> Android orientation: {$androidOrientation}");

        if ($androidOrientation !== 'unspecified') {
            $this->cmd->line("  <fg=yellow>→</> Android 16+ ignores screenOrientation on foldables and large-screen devices, test layout responsively");
        }
    }

    /**
     * Safety net for the Play Console "edge-to-edge may not be available"
     * warning. Recent Tauri scaffolds already inject `enableEdgeToEdge()`
     * in MainActivity.kt; this patch covers older scaffolds (e.g. apps
     * shipped before that template change) and projects where the dev
     * removed the call.
     *
     * Skip silently when an existing `enableEdgeToEdge()` or
     * `setDecorFitsSystemWindows` call is detected so we don't fight a
     * dev who customized the activity.
     */
    private function generateEdgeToEdge(): void
    {
        $mainActivity = $this->findMainActivity();
        if (!$mainActivity) return;

        $content = file_get_contents($mainActivity);

        if (preg_match('/\benableEdgeToEdge\s*\(/', $content)
            || preg_match('/\bsetDecorFitsSystemWindows\s*\(/', $content)) {
            return;
        }

        $startMarker = '// nativeblade:edgetoedge:start';
        $endMarker = '// nativeblade:edgetoedge:end';

        $block = "    {$startMarker}\n"
            . "    override fun onCreate(savedInstanceState: android.os.Bundle?) {\n"
            . "        super.onCreate(savedInstanceState)\n"
            . "        androidx.core.view.WindowCompat.setDecorFitsSystemWindows(window, false)\n"
            . "    }\n"
            . "    {$endMarker}";

        $startQ = preg_quote($startMarker, '/');
        $endQ = preg_quote($endMarker, '/');

        if (preg_match("/[ \t]*{$startQ}.*?{$endQ}/s", $content)) {
            $content = preg_replace("/[ \t]*{$startQ}.*?{$endQ}/s", ltrim($block), $content);
        } elseif (preg_match('/class\s+MainActivity\s*:\s*TauriActivity\s*\(\s*\)\s*$/m', $content)) {
            $content = preg_replace(
                '/(class\s+MainActivity\s*:\s*TauriActivity\s*\(\s*\))(\s*)$/m',
                "$1 {\n{$block}\n}\n",
                $content,
                1
            );
        } elseif (preg_match('/class\s+MainActivity\s*:\s*TauriActivity\s*\(\s*\)\s*\{/', $content)) {
            $content = preg_replace(
                '/(class\s+MainActivity\s*:\s*TauriActivity\s*\(\s*\)\s*\{)/',
                "$1\n{$block}\n",
                $content,
                1
            );
        } else {
            $this->cmd->line("  <fg=yellow>→</> MainActivity.kt has unexpected shape, skip edge-to-edge patch");
            return;
        }

        file_put_contents($mainActivity, $content);
        $this->cmd->line("  <fg=green>✓</> MainActivity.kt: edge-to-edge enabled");
    }

    private function findMainActivity(): ?string
    {
        $base = base_path('src-tauri/gen/android/app/src/main/java');
        if (!is_dir($base)) return null;

        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getFilename() === 'MainActivity.kt') {
                return $file->getPathname();
            }
        }
        return null;
    }

    private function generateVersion(array $config): void
    {
        if (!isset($config['version']) || !isset($config['buildNumber'])) return;

        // Tauri derives the Android versionCode from the semver version unless
        // bundle.android.versionCode is set, so the build number has to be
        // written there or it is silently ignored (1.4.8 becomes 1004008).
        $this->setTauriAndroidVersionCode((int) $config['buildNumber']);

        $gradlePath = base_path('src-tauri/gen/android/app/build.gradle.kts');
        if (file_exists($gradlePath)) {
            $gradle = file_get_contents($gradlePath);
            if (str_contains($gradle, 'versionCode')) {
                $gradle = preg_replace('/versionCode\s*=\s*\d+/', 'versionCode = ' . $config['buildNumber'], $gradle);
                $gradle = preg_replace('/versionName\s*=\s*"[^"]*"/', 'versionName = "' . $config['version'] . '"', $gradle);
                file_put_contents($gradlePath, $gradle);
            }
        }

        $this->cmd->line("  <fg=green>✓</> Android version: {$config['version']} ({$config['buildNumber']})");
    }

    /**
     * The build.gradle.kts scaffold reads versionCode from tauri.properties,
     * which Tauri regenerates from the conf version on every build. The only
     * override Tauri honors is bundle.android.versionCode in tauri.conf.json,
     * so the authoritative write lives here, independent of the gradle file
     * (which does not exist yet during the first nativeblade:config run).
     */
    private function setTauriAndroidVersionCode(int $versionCode): void
    {
        $confPath = base_path('src-tauri/tauri.conf.json');
        if (!file_exists($confPath)) return;

        $conf = json_decode(file_get_contents($confPath), true);
        if (!is_array($conf)) return;

        $conf['bundle']['android']['versionCode'] = $versionCode;

        file_put_contents($confPath, json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function stripDebugSymbolsBlock(): void
    {
        $gradlePath = base_path('src-tauri/gen/android/app/build.gradle.kts');
        if (!file_exists($gradlePath)) return;

        $gradle = file_get_contents($gradlePath);
        if (!str_contains($gradle, 'debugSymbolLevel')) return;

        $stripped = preg_replace(
            '/\n\s*ndk\s*\{\s*debugSymbolLevel\s*=\s*"[^"]*"\s*\}\n?/',
            "\n",
            $gradle
        );

        file_put_contents($gradlePath, $stripped);
        $this->cmd->line("  <fg=green>✓</> Removed legacy debugSymbolLevel block (AGP ignores Tauri's jniLibs)");
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

    private function generateFirebase(array $config): void
    {
        $source = \NativeBlade\ShellConfig::getAppConfigs()['firebase']['googleServices']
            ?? ($config['notification']['fcmConfig'] ?? null);
        if (!$source) return;

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

}
