<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\Doctor\AsyncifyScanner;

/**
 * Static scan for PHP that php-wasm cannot run or cannot pause. The Asyncify
 * build (iOS and macOS) ends the instance when PHP is suspended on a path
 * that is not instrumented, and the crash message can only point at the
 * request; this finds the calls before the first run.
 */
class DoctorCommand extends Command
{
    protected $signature = 'nativeblade:doctor
        {--path=* : Files or directories to scan, relative to the project (default: the app directories and the third-party packages in vendor)}
        {--vendor : Scan all of vendor, including the Laravel, Livewire and Symfony families the default skips}
        {--json : Print the findings as JSON}';

    protected $description = 'Scan the app for PHP that php-wasm cannot run or pause: sleeps, sockets, processes, and native calls inside callbacks the runtime cannot suspend';

    public const DEFAULT_PATHS = ['app', 'routes', 'config', 'database', 'resources/views', 'bootstrap'];

    /**
     * Vendor namespaces the default scan skips: the framework families, whose
     * findings are paths the app never runs (cache locks, console commands,
     * queue workers), and test tooling, which is not in the app.
     */
    public const KNOWN_VENDORS = [
        'laravel', 'livewire', 'symfony', 'illuminate', 'nativeblade', 'psr', 'composer',
        'phpunit', 'sebastian', 'mockery', 'fakerphp', 'nunomaduro', 'pestphp', 'filp',
        'phar-io', 'myclabs', 'theseer', 'hamcrest', 'brianium', 'jean85', 'staabm',
    ];

    public function handle(): int
    {
        $scanner = new AsyncifyScanner(function (string $source): string {
            return (string) $this->laravel->make('blade.compiler')->compileString($source);
        });

        $paths = $this->paths();
        $packages = $this->packages();
        $paths = array_merge($paths, array_values($packages));
        $files = $scanner->files($paths);
        $findings = $scanner->scanPaths($paths);

        $base = rtrim(str_replace('\\', '/', $this->laravel->basePath()), '/') . '/';
        $relative = fn (string $file) => str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;

        $errors = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'error'));
        $warnings = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'warning'));

        if ($this->option('json')) {
            $this->line(json_encode([
                'files' => count($files),
                'errors' => count($errors),
                'warnings' => count($warnings),
                'findings' => array_map(fn ($f) => ['file' => $relative($f['file'])] + $f, $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('  Scanning ' . implode(', ', array_map($relative, array_map(fn ($p) => str_replace('\\', '/', $p), $this->paths()))));
        if ($packages !== []) {
            $this->line('  and ' . count($packages) . ' vendor ' . (count($packages) === 1 ? 'package' : 'packages')
                . ': ' . implode(', ', array_keys($packages))
                . ' (the framework families, what they require and dev packages are skipped; --vendor scans them too)');
        } elseif ((array) $this->option('path') === [] && !$this->option('vendor')) {
            $this->line('  and no vendor package beyond what the framework families require (--vendor scans all of vendor)');
        }
        $this->newLine();

        if ($findings === []) {
            $this->line("  <fg=green>✓</> No incompatible code found in " . count($files) . ' PHP files.');

            return self::SUCCESS;
        }

        $touched = [];
        foreach ($findings as $finding) {
            $touched[$finding['file']] = true;
            $mark = $finding['severity'] === 'error' ? '<fg=red>✗</>' : '<fg=yellow>!</>';
            $this->line("  {$mark} <info>{$relative($finding['file'])}:{$finding['line']}</info>  {$finding['message']}");
        }

        $this->newLine();
        $this->line(sprintf(
            '  %s, %s in %d %s.',
            $this->count(count($errors), 'problem'),
            $this->count(count($warnings), 'warning'),
            count($touched),
            count($touched) === 1 ? 'file' : 'files'
        ));
        $this->line('  Problems end the PHP instance on iOS and macOS (the Asyncify build); warnings throw NativeCallNotAllowed on every platform.');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The third-party packages under vendor, scanned when neither --path nor
     * --vendor is given: a library the developer just installed is the usual
     * source of a sleep or a socket. Skipped so the result is about that
     * library: the framework families (KNOWN_VENDORS), everything they pull
     * in (guzzle, monolog, ramsey/uuid, psysh through tinker: every app has
     * them and their findings are paths the app never runs) and the dev
     * packages, which are not in a store build. Read from Composer's
     * installed.json; without it, every vendor directory outside the known
     * families.
     *
     * @return array<string, string> "vendor/name" => absolute path
     */
    private function packages(): array
    {
        if ((array) $this->option('path') !== [] || $this->option('vendor')) {
            return [];
        }

        $vendor = $this->laravel->basePath('vendor');
        if (!is_dir($vendor)) {
            return [];
        }

        $installed = $this->installedPackages($vendor);
        if ($installed === null) {
            return $this->packagesByDirectory($vendor);
        }

        $skip = [];
        foreach ($installed['packages'] as $name => $package) {
            if ($this->isKnownVendor($name)) {
                $skip[$name] = true;
            }
        }
        foreach ($installed['dev'] as $name) {
            $skip[$name] = true;
        }
        // Everything the known families require, transitively.
        $queue = array_keys($skip);
        while ($queue !== []) {
            $name = array_pop($queue);
            foreach ($installed['packages'][$name]['require'] ?? [] as $dependency) {
                if (!isset($skip[$dependency]) && isset($installed['packages'][$dependency])) {
                    $skip[$dependency] = true;
                    $queue[] = $dependency;
                }
            }
        }

        $packages = [];
        foreach ($installed['packages'] as $name => $package) {
            if (isset($skip[$name]) || $package['path'] === null || !is_dir($package['path'])) {
                continue;
            }
            $packages[$name] = $package['path'];
        }
        ksort($packages);

        return $packages;
    }

    /**
     * @return array{packages: array<string, array{require: string[], path: string|null}>, dev: string[]}|null
     */
    private function installedPackages(string $vendor): ?array
    {
        $file = $vendor . '/composer/installed.json';
        if (!is_file($file)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json)) {
            return null;
        }
        $list = $json['packages'] ?? $json;
        if (!is_array($list)) {
            return null;
        }

        $packages = [];
        foreach ($list as $package) {
            if (!is_array($package) || !isset($package['name'])) {
                continue;
            }
            $path = isset($package['install-path'])
                ? realpath($vendor . '/composer/' . $package['install-path'])
                : realpath($vendor . '/' . $package['name']);
            $packages[$package['name']] = [
                'require' => array_keys($package['require'] ?? []),
                'path' => $path === false ? null : str_replace('\\', '/', $path),
            ];
        }

        return ['packages' => $packages, 'dev' => array_values((array) ($json['dev-package-names'] ?? []))];
    }

    /** @return array<string, string> */
    private function packagesByDirectory(string $vendor): array
    {
        $packages = [];
        foreach (glob($vendor . '/*', GLOB_ONLYDIR) ?: [] as $namespace) {
            $owner = basename($namespace);
            if (in_array(strtolower($owner), self::KNOWN_VENDORS, true)) {
                continue;
            }
            foreach (glob($namespace . '/*', GLOB_ONLYDIR) ?: [] as $package) {
                $packages[$owner . '/' . basename($package)] = $package;
            }
        }
        ksort($packages);

        return $packages;
    }

    private function isKnownVendor(string $name): bool
    {
        $owner = strtolower((string) strstr($name, '/', true));

        return in_array($owner, self::KNOWN_VENDORS, true);
    }

    /** @return string[] absolute paths that exist */
    private function paths(): array
    {
        $requested = (array) $this->option('path');
        $names = $requested !== [] ? $requested : self::DEFAULT_PATHS;
        if ($this->option('vendor')) {
            $names[] = 'vendor';
        }

        $paths = [];
        foreach ($names as $name) {
            $path = $this->isAbsolute($name) ? $name : $this->laravel->basePath($name);
            if (file_exists($path)) {
                $paths[] = $path;
            } elseif ($requested !== []) {
                $this->warn("  Path not found: {$name}");
            }
        }

        return $paths;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function count(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }
}
