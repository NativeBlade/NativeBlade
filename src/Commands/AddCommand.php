<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use Symfony\Component\Process\Process;

class AddCommand extends Command
{
    protected $signature = 'nativeblade:add
        {platform : Platform to add (android or ios)}';

    protected $description = 'Add a mobile platform scaffold to an existing NativeBlade project';

    public function handle(): int
    {
        $platform = strtolower((string) $this->argument('platform'));

        if (!in_array($platform, ['android', 'ios'], true)) {
            $this->error("Unsupported platform: {$platform}. Use android or ios.");
            return self::FAILURE;
        }

        if (!is_dir(base_path('src-tauri'))) {
            $this->error('src-tauri/ not found. Run php artisan nativeblade:install first.');
            return self::FAILURE;
        }

        return match ($platform) {
            'android' => $this->addAndroid(),
            'ios' => $this->addIos(),
        };
    }

    private function addAndroid(): int
    {
        $this->info('  Adding Android platform...');

        $cargoDir = base_path('src-tauri/.cargo');
        if (!is_dir($cargoDir)) {
            mkdir($cargoDir, 0755, true);
        }

        $this->publishStub('.cargo/config.toml.stub', $cargoDir . '/config.toml', [
            '{{NDK_HOME}}' => str_replace('\\', '/', env('NDK_HOME', env('ANDROID_NDK_HOME', ''))),
        ]);

        if (!is_dir(base_path('src-tauri/gen/android'))) {
            $this->exec($this->tauriCliCommand('tauri android init --ci'));
            $this->line('  <fg=green>✓</> Android scaffold generated');
        } else {
            $this->line('  <fg=yellow>→</> Android scaffold already exists, skipped');
        }

        $this->patchAndroidPermissions();
        $this->call('nativeblade:icon');
        $this->call('nativeblade:config');

        $this->info('');
        $this->info('  Android support is ready.');
        return self::SUCCESS;
    }

    private function addIos(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('iOS scaffold must be generated on macOS.');
            return self::FAILURE;
        }

        $this->info('  Adding iOS platform...');

        $this->exec($this->tauriCliCommand('tauri ios init --ci'));
        $this->line('  <fg=green>✓</> iOS scaffold generated');

        $this->patchIosPermissions();
        $this->call('nativeblade:icon');
        $this->call('nativeblade:config');

        $this->info('');
        $this->info('  iOS support is ready.');
        return self::SUCCESS;
    }

    private function exec(string $command, array $env = []): void
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(null);
        $process->setEnv(array_merge($_ENV, $env));
        $process->setTty(Process::isTtySupported());
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });
    }

    private function tauriCliCommand(string $args): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? "npx.cmd {$args}"
            : "npx {$args}";
    }

    private function patchIosPermissions(): void
    {
        $plist = base_path('src-tauri/gen/apple/App/Info.plist');
        if (!file_exists($plist)) return;

        $content = file_get_contents($plist);

        if (str_contains($content, 'NSCameraUsageDescription')) {
            $this->line('  <fg=yellow>→</> iOS permissions already patched');
            return;
        }

        $permissions = <<<'PERMS'
	<key>NSCameraUsageDescription</key>
	<string>This app needs camera access to scan barcodes and QR codes</string>
	<key>NSLocationWhenInUseUsageDescription</key>
	<string>This app needs your location</string>
	<key>NSLocationAlwaysUsageDescription</key>
	<string>This app needs your location</string>
	<key>NSFaceIDUsageDescription</key>
	<string>This app uses Face ID for authentication</string>
	<key>NFCReaderUsageDescription</key>
	<string>This app needs NFC access to read tags</string>
	<key>NSPhotoLibraryUsageDescription</key>
	<string>This app needs access to your photo library</string>
PERMS;

        $content = str_replace(
            '</dict>',
            $permissions . "\n</dict>",
            $content
        );

        file_put_contents($plist, $content);
        $this->line('  <fg=green>✓</> iOS permissions patched');
    }

    private function patchAndroidPermissions(): void
    {
        $manifest = base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml');
        if (!file_exists($manifest)) return;

        $content = file_get_contents($manifest);

        if (str_contains($content, 'ACCESS_FINE_LOCATION')) {
            $this->line('  <fg=yellow>→</> Android permissions already patched');
            return;
        }

        $permissions = <<<'PERMS'
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
    <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
    <uses-permission android:name="android.permission.CAMERA" />
    <uses-permission android:name="android.permission.NFC" />
    <uses-permission android:name="android.permission.USE_BIOMETRIC" />
    <uses-permission android:name="android.permission.VIBRATE" />
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
    <uses-permission android:name="android.permission.READ_EXTERNAL_STORAGE" />
    <uses-permission android:name="android.permission.WRITE_EXTERNAL_STORAGE" />
PERMS;

        $content = str_replace(
            '<uses-permission android:name="android.permission.INTERNET" />',
            '<uses-permission android:name="android.permission.INTERNET" />' . "\n" . $permissions,
            $content
        );

        file_put_contents($manifest, $content);
        $this->line('  <fg=green>✓</> Android permissions patched');
    }

    private function publishStub(string $stub, string $dest, array $extra = []): void
    {
        $content = file_get_contents($this->stubPath($stub));
        $content = str_replace(array_keys($extra), array_values($extra), $content);
        file_put_contents($dest, $content);
    }

    private function stubPath(string $name): string
    {
        return NativeBladeServiceProvider::packagePath("stubs/{$name}");
    }
}
