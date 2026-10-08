<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\ShellConfig;
use NativeBlade\Support\LogFile;
use Symfony\Component\Process\Process;

class LogsCommand extends Command
{
    protected $signature = 'nativeblade:logs
        {--platform=desktop : Where the app runs: desktop, android or ios}
        {--lines=100 : How many of the latest lines to show}
        {--path : Only print where the log file lives}';

    protected $description = 'Show the app\'s nativeblade.log (NativeBlade::log() entries and PHP errors written by the shell)';

    public function handle(): int
    {
        $platform = (string) $this->option('platform');

        return match ($platform) {
            'desktop' => $this->desktop(),
            'android' => $this->android(),
            'ios' => $this->ios(),
            default => $this->unknownPlatform("Unknown platform '{$platform}'. Use desktop, android or ios."),
        };
    }

    private function desktop(): int
    {
        $identifier = $this->identifier('desktop');
        $path = LogFile::desktopPath($identifier, PHP_OS_FAMILY, getenv() ?: []);

        $this->line("  Log file: <info>{$path}</info>");
        if ($this->option('path')) {
            return self::SUCCESS;
        }

        if (!is_file($path)) {
            $this->line('  <fg=yellow>→</> No log yet. The shell writes it on the first NativeBlade::log() call or PHP error.');
            return self::SUCCESS;
        }

        $this->printTail((string) file_get_contents($path));

        return self::SUCCESS;
    }

    private function android(): int
    {
        $identifier = $this->identifier('android');

        if ($this->option('path')) {
            $this->line("  On the device: the app's log directory (Tauri's app log dir) under /data/data/{$identifier}");
            return self::SUCCESS;
        }

        // run-as only works for debuggable builds (dev client, debug APK).
        $find = $this->adb(['shell', 'run-as', $identifier, 'find', '.', '-name', LogFile::NAME]);
        if ($find === null) {
            return self::FAILURE;
        }

        $files = array_values(array_filter(array_map('trim', explode("\n", $find))));
        if (empty($files)) {
            $this->line("  <fg=yellow>→</> No " . LogFile::NAME . " on the device for {$identifier}.");
            $this->line('     It appears after the first NativeBlade::log() call or PHP error, and run-as needs a debuggable build (dev client or debug APK).');
            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $this->line("  Log file: <info>{$file}</info> (inside the app's data dir)");
            $content = $this->adb(['shell', 'run-as', $identifier, 'cat', $file]);
            if ($content !== null) {
                $this->printTail($content);
            }
        }

        return self::SUCCESS;
    }

    private function ios(): int
    {
        $identifier = $this->identifier('ios');

        $this->line("  iOS keeps {$identifier}'s files inside its sandbox; there is no adb equivalent.");
        $this->line('  To read ' . LogFile::NAME . ': Xcode > Window > Devices and Simulators > select the device > the app');
        $this->line('  > "Download Container...", then open Library/Logs/' . LogFile::NAME . ' in the .xcappdata bundle.');
        $this->line('  During development, `nativeblade:dev` already prints every entry in this terminal.');

        return self::SUCCESS;
    }

    /**
     * Run adb and return stdout, or null after printing why it failed.
     *
     * @param  string[]  $args
     */
    private function adb(array $args): ?string
    {
        $process = new Process(array_merge(['adb'], $args));
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->error('  adb not found. Install Android platform-tools and connect a device (adb devices).');
            return null;
        }

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            if (str_contains($err, 'not debuggable')) {
                $this->error('  run-as refused: the installed app is not debuggable. Install the dev client or a debug build to read its files.');
            } elseif (str_contains($err, 'no devices') || str_contains($err, 'device offline')) {
                $this->error('  No device connected (adb devices).');
            } else {
                $this->error('  adb failed: ' . $err);
            }
            return null;
        }

        return $process->getOutput();
    }

    private function identifier(string $platform): string
    {
        $configs = ShellConfig::getAppConfigs();
        $fromConfig = $configs[$platform]['identifier'] ?? null;
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }

        $conf = base_path('src-tauri/tauri.conf.json');
        if (is_file($conf)) {
            $json = json_decode((string) file_get_contents($conf), true);
            if (is_string($json['identifier'] ?? null) && $json['identifier'] !== '') {
                return $json['identifier'];
            }
        }

        return 'com.example.application';
    }

    private function printTail(string $content): void
    {
        $lines = preg_split('/\R/', rtrim($content));
        $limit = max(1, (int) $this->option('lines'));
        $shown = array_slice($lines, -$limit);

        if (count($lines) > $limit) {
            $this->line('  <fg=gray>... ' . (count($lines) - $limit) . ' earlier lines, raise --lines to see them</>');
        }
        foreach ($shown as $line) {
            $this->line('  ' . $line);
        }
    }

    private function unknownPlatform(string $message): int
    {
        $this->error("  {$message}");

        return self::FAILURE;
    }
}
