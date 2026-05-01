<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;

class SignCommand extends Command
{
    protected $signature = 'nativeblade:sign
        {platform : android or ios}
        {--alias=upload : Key alias (Android only)}
        {--validity=10000 : Validity in days (Android only)}';

    protected $description = 'Configure release signing for Android (keystore) or iOS (ExportOptions.plist)';

    public function handle(): int
    {
        $platform = $this->argument('platform');

        return match ($platform) {
            'android' => $this->signAndroid(),
            'ios' => $this->signIos(),
            default => $this->failWith("Unknown platform: {$platform}. Use android or ios."),
        };
    }

    private function signAndroid(): int
    {
        if (!$this->commandExists('keytool')) {
            $this->error('  keytool not found in PATH. Install JDK and try again.');
            return self::FAILURE;
        }

        $androidDir = base_path('src-tauri/gen/android');
        if (!is_dir($androidDir)) {
            $this->error("  Android project not found. Run `nativeblade:add android` first.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('  Android Signing Setup');
        $this->line('');

        $alias = $this->option('alias');
        $validity = (int) $this->option('validity');

        $defaultKeystore = $androidDir . '/upload-keystore.jks';
        $keystorePath = $this->ask('  Keystore path', $defaultKeystore);

        if (file_exists($keystorePath)) {
            if (!$this->confirm("  Keystore already exists at {$keystorePath}. Overwrite?", false)) {
                $this->line('  Skipping keystore creation, will only configure Gradle.');
            } else {
                unlink($keystorePath);
            }
        }

        if (!file_exists($keystorePath)) {
            $password = $this->secret('  Password (min 6 chars, letters + numbers only — avoid special chars)');

            if (strlen($password) < 6) {
                $this->error('  Password must be at least 6 characters.');
                return self::FAILURE;
            }

            if (!preg_match('/^[A-Za-z0-9]+$/', $password)) {
                $this->error('  Password must contain only letters and numbers (the .properties format breaks with special characters).');
                return self::FAILURE;
            }

            $name = $this->ask('  Your name (CN)', 'NativeBlade Developer');
            $org = $this->ask('  Organization (O)', 'NativeBlade');
            $country = $this->ask('  Country code (C)', 'US');

            $dname = "CN={$name}, O={$org}, C={$country}";

            $cmd = sprintf(
                'keytool -genkeypair -v -keystore %s -alias %s -keyalg RSA -keysize 2048 -validity %d -storepass %s -keypass %s -dname %s 2>&1',
                escapeshellarg($keystorePath),
                escapeshellarg($alias),
                $validity,
                escapeshellarg($password),
                escapeshellarg($password),
                escapeshellarg($dname)
            );

            $output = [];
            exec($cmd, $output, $code);

            if ($code !== 0) {
                $this->error('  keytool failed:');
                foreach ($output as $line) $this->line('  ' . $line);
                return self::FAILURE;
            }

            $this->line("  <fg=green>✓</> Keystore created at {$keystorePath}");
        } else {
            $password = $this->secret('  Existing keystore password');
        }

        $this->writeKeystoreProperties($androidDir, $keystorePath, $password, $password, $alias);
        $this->patchAppGradle($androidDir);
        $this->ensureGitignore($androidDir);

        $this->line('');
        $this->info('  Done! Run `php artisan nativeblade:build android` to produce a signed AAB.');
        $this->line('');
        $this->line('  <fg=yellow>Important:</> back up your keystore safely. Losing it means you cannot publish updates to the Play Store.');
        $this->line('');

        return self::SUCCESS;
    }

    private function writeKeystoreProperties(string $androidDir, string $keystore, string $storePass, string $keyPass, string $alias): void
    {
        $relativeKeystore = str_replace('\\', '/', $keystore);

        $contents = <<<PROPS
storeFile={$relativeKeystore}
storePassword={$storePass}
keyAlias={$alias}
keyPassword={$keyPass}

PROPS;

        file_put_contents($androidDir . '/keystore.properties', $contents);
        $this->line("  <fg=green>✓</> keystore.properties written");
    }

    private function patchAppGradle(string $androidDir): void
    {
        $path = $androidDir . '/app/build.gradle.kts';
        if (!file_exists($path)) return;

        $content = file_get_contents($path);

        if (str_contains($content, 'nativeblade:signing:start')) {
            $this->line('  <fg=yellow>→</> Gradle signing config already present, skipping');
            return;
        }

        foreach (['java.io.FileInputStream', 'java.util.Properties'] as $import) {
            if (!preg_match('/^\s*import\s+' . preg_quote($import, '/') . '\s*$/m', $content)) {
                $content = "import {$import}\n" . $content;
            }
        }

        $signingBlock = <<<KOTLIN

// nativeblade:signing:start
val keystorePropertiesFile = rootProject.file("keystore.properties")
val keystoreProperties = Properties()
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}
// nativeblade:signing:end

KOTLIN;

        $content = preg_replace(
            '/(\nval tauriProperties = Properties\(\))/',
            $signingBlock . "$1",
            $content,
            1
        );

        $signingConfigKotlin = <<<KOTLIN
        // nativeblade:signing:start
        if (keystoreProperties.isNotEmpty()) {
            create("release") {
                keyAlias = keystoreProperties["keyAlias"] as String?
                keyPassword = keystoreProperties["keyPassword"] as String?
                storeFile = keystoreProperties["storeFile"]?.let { file(it as String) }
                storePassword = keystoreProperties["storePassword"] as String?
            }
        }
        // nativeblade:signing:end
KOTLIN;

        if (preg_match('/signingConfigs\s*\{/', $content)) {
            $content = preg_replace(
                '/(signingConfigs\s*\{)/',
                "$1\n{$signingConfigKotlin}",
                $content,
                1
            );
        } else {
            $content = preg_replace(
                '/(android\s*\{[^\n]*\n)/',
                "$1    signingConfigs {\n{$signingConfigKotlin}\n    }\n",
                $content,
                1
            );
        }

        if (!preg_match('/getByName\("release"\)\s*\{[^}]*signingConfig/s', $content)) {
            $content = preg_replace(
                '/(getByName\("release"\)\s*\{)/',
                "$1\n            // nativeblade:signing:start\n            if (keystoreProperties.isNotEmpty()) {\n                signingConfig = signingConfigs.getByName(\"release\")\n            }\n            // nativeblade:signing:end",
                $content,
                1
            );
        }

        file_put_contents($path, $content);
        $this->line("  <fg=green>✓</> app/build.gradle.kts patched");
    }

    private function ensureGitignore(string $androidDir): void
    {
        $gitignore = $androidDir . '/.gitignore';
        $entries = ['keystore.properties', 'upload-keystore.jks', '*.jks', '*.keystore'];

        $existing = file_exists($gitignore) ? file_get_contents($gitignore) : '';
        $additions = [];

        foreach ($entries as $entry) {
            if (!preg_match('/^' . preg_quote($entry, '/') . '$/m', $existing)) {
                $additions[] = $entry;
            }
        }

        if (empty($additions)) return;

        $existing = rtrim($existing) . "\n\n# NativeBlade signing\n" . implode("\n", $additions) . "\n";
        file_put_contents($gitignore, $existing);
        $this->line("  <fg=green>✓</> .gitignore updated");
    }

    private function signIos(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('  iOS signing setup must be run on macOS.');
            return self::FAILURE;
        }

        $iosDir = base_path('src-tauri/gen/apple');
        if (!is_dir($iosDir)) {
            $this->error('  iOS project not found. Run `nativeblade:add ios` first.');
            return self::FAILURE;
        }

        $this->line('');
        $this->info('  iOS Signing Setup');
        $this->line('');
        $this->line('  iOS code signing requires an Apple Developer account.');
        $this->line('  Apple does not allow CLI tools to create certificates — only Xcode or the Apple Developer portal can.');
        $this->line('');

        $teamId = $this->detectTeamId();
        $teamId = $this->ask('  Apple Team ID (10-char string from developer.apple.com)', $teamId);

        if (!$teamId || !preg_match('/^[A-Z0-9]{10}$/', $teamId)) {
            $this->error('  Invalid Team ID. Find yours at developer.apple.com → Membership.');
            return self::FAILURE;
        }

        $method = $this->choice(
            '  Distribution method',
            ['app-store', 'ad-hoc', 'enterprise', 'development'],
            0
        );

        $this->writeExportOptions($iosDir, $teamId, $method);
        $this->writeXcodeConfigFiles($iosDir, $teamId);

        $this->line('');
        $this->info('  Done!');
        $this->line('');
        $this->line('  Next manual steps (one-time setup):');
        $this->line('  ');
        $this->line('  <fg=cyan>1.</> Open the Xcode project:');
        $this->line('       open src-tauri/gen/apple/*.xcodeproj');
        $this->line('  ');
        $this->line('  <fg=cyan>2.</> Select the app target → Signing & Capabilities');
        $this->line('       Check "Automatically manage signing" and pick your team');
        $this->line('       Xcode will create the certificate and provisioning profile');
        $this->line('  ');
        $this->line('  <fg=cyan>3.</> Build:');
        $this->line('       php artisan nativeblade:build ios');
        $this->line('');

        return self::SUCCESS;
    }

    private function detectTeamId(): ?string
    {
        $output = [];
        @exec('security find-identity -v -p codesigning 2>/dev/null', $output);
        foreach ($output as $line) {
            if (preg_match('/\(([A-Z0-9]{10})\)/', $line, $m)) return $m[1];
        }
        return null;
    }

    private function writeExportOptions(string $iosDir, string $teamId, string $method): void
    {
        $plist = <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>method</key>
    <string>{$method}</string>
    <key>teamID</key>
    <string>{$teamId}</string>
    <key>signingStyle</key>
    <string>automatic</string>
    <key>stripSwiftSymbols</key>
    <true/>
    <key>uploadSymbols</key>
    <true/>
    <key>uploadBitcode</key>
    <false/>
</dict>
</plist>
PLIST;

        file_put_contents($iosDir . '/ExportOptions.plist', $plist);
        $this->line("  <fg=green>✓</> ExportOptions.plist written ({$method})");
    }

    private function writeXcodeConfigFiles(string $iosDir, string $teamId): void
    {
        $config = "DEVELOPMENT_TEAM = {$teamId}\nCODE_SIGN_STYLE = Automatic\n";

        $configPath = $iosDir . '/project.xcconfig';
        file_put_contents($configPath, $config);
        $this->line("  <fg=green>✓</> project.xcconfig written");
    }

    private function commandExists(string $cmd): bool
    {
        $check = PHP_OS_FAMILY === 'Windows' ? "where {$cmd}" : "command -v {$cmd}";
        @exec($check . ' 2>&1', $out, $code);
        return $code === 0;
    }

    private function failWith(string $message): int
    {
        $this->error('  ' . $message);
        return self::FAILURE;
    }
}
