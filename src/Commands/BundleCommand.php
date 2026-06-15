<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use NativeBlade\ShellConfig;

class BundleCommand extends Command
{
    protected $signature = 'nativeblade:bundle
        {--tag= : Tag the output with a version (writes laravel-bundle-{tag}.json.gz alongside the canonical name)}
        {--channel= : Publish under a release channel (e.g. beta). Omit for the default stable channel}
        {--shell= : Minimum shell version required to run this bundle (defaults to 1.0.0 so it applies to every installed shell)}
        {--no-dev : Run composer install --no-dev before bundling (default: true)}';

    protected $description = 'Build only the Laravel bundle (laravel-bundle.json.gz) — for OTA bundle push without rebuilding the native shell';

    public function handle(): int
    {
        $tag = $this->option('tag');
        $channel = $this->option('channel');
        $shell = $this->option('shell');
        $noDev = $this->option('no-dev') !== false;

        $this->line('');
        $this->info('  Building Laravel bundle...');
        $this->line('');

        if ($noDev) {
            $this->line('  Running composer install --no-dev...');
            $this->runShell('composer install --no-dev --optimize-autoloader 2>&1');
        }

        $bundleScript = NativeBladeServiceProvider::packagePath('js/scripts/bundle-laravel.js');
        $this->runShell("node {$bundleScript} " . base_path());

        $bundleGz = base_path('public/laravel-bundle.json.gz');
        if (!file_exists($bundleGz)) {
            $this->error('  Bundle output not found at public/laravel-bundle.json.gz');
            return self::FAILURE;
        }

        $size = filesize($bundleGz);
        $sizeMb = round($size / 1024 / 1024, 2);

        $this->line('');
        $this->line("  <fg=green>✓</> public/laravel-bundle.json.gz ({$sizeMb} MB)");

        if ($tag) {
            $tagged = base_path("public/laravel-bundle-{$tag}.json.gz");
            copy($bundleGz, $tagged);
            $this->line("  <fg=green>✓</> public/laravel-bundle-{$tag}.json.gz");
        }

        $this->printPostBundleInstructions($tag, $channel, $shell);

        return self::SUCCESS;
    }

    /**
     * Print the post-build hint. When `bundlePush()` was configured in the
     * AppServiceProvider, derive the real bundle URL from the manifest URL
     * by swapping the last path segment with the tagged bundle filename, so
     * the dev gets a copy-pasteable manifest. Otherwise fall back to a
     * placeholder URL.
     *
     * `minShellVersion` comes from the `--shell` option and defaults to 1.0.0,
     * which every installed shell satisfies, so a pure-PHP bundle flows to all
     * of them. Pass `--shell=<version already in the stores>` when the bundle
     * requires a native plugin or facade method added in a newer shell, so
     * devices on an older shell skip the update rather than crash.
     */
    private function printPostBundleInstructions(?string $tag, ?string $channel = null, ?string $shell = null): void
    {
        $version = $tag ?: '1.0.0';
        $filename = "laravel-bundle-{$version}.json.gz";
        $minShellVersion = $shell ?: '1.0.0';

        $configured = ShellConfig::getAppConfigs()['bundlePush'] ?? null;
        $manifestUrl = $configured['url'] ?? null;

        $entry = [
            'version' => $version,
            'url' => $manifestUrl
                ? $this->deriveBundleUrl($manifestUrl, $filename)
                : "https://releases.myapp.com/{$filename}",
            'minShellVersion' => $minShellVersion,
        ];

        $isStable = !$channel || $channel === 'stable';
        $bundleUrl = $entry['url'];
        $manifest = $isStable
            ? ['bundle' => $entry]
            : ['channels' => [$channel => $entry]];

        if ($manifestUrl) {
            $this->line('');
            $this->info('  Bundle built. Upload public/' . $filename . ' to:');
            $this->line('');
            $this->line('    ' . $bundleUrl);
            $this->line('');
            $this->info('  Then update your manifest at:');
            $this->line('');
            $this->line('    ' . $manifestUrl);
            $this->line('');
            $this->info($isStable
                ? '  with this content (copy-paste):'
                : "  by merging this '{$channel}' channel entry (copy-paste):");
        } else {
            $this->line('');
            $this->info('  Bundle built. Upload it to your CDN and update version.json:');
        }

        $this->line('');
        foreach (explode("\n", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
            $this->line('    ' . $line);
        }
        $this->line('');

        $this->line("  <fg=yellow>→</> minShellVersion is set to <fg=cyan>{$minShellVersion}</>"
            . ($shell ? ' (from --shell).' : ' (default — every installed shell applies it).'));
        $this->line('     Pass --shell=<version already in the stores> when this bundle needs a native plugin or');
        $this->line('     facade method added in a newer shell, so older installs skip it instead of crashing.');

        if (!$manifestUrl) {
            $this->line('');
            $this->line('  <fg=yellow>→</> Tip: declare NativeBladeConfig::bundlePush(\'<your-manifest-url>\') in your AppServiceProvider');
            $this->line('     to make this command print the real URL instead of a placeholder.');
        }
        $this->line('');
    }

    /**
     * Given a manifest URL like `https://example.com/portal/version.json`
     * and a bundle filename like `laravel-bundle-1.0.22.json.gz`, return
     * `https://example.com/portal/laravel-bundle-1.0.22.json.gz`.
     */
    private function deriveBundleUrl(string $manifestUrl, string $filename): string
    {
        $pos = strrpos($manifestUrl, '/');
        if ($pos === false) {
            return $filename;
        }
        return substr($manifestUrl, 0, $pos + 1) . $filename;
    }

    private function runShell(string $cmd): void
    {
        $cwd = base_path();
        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );

        if (!is_resource($process)) {
            $this->error("  Failed to run: {$cmd}");
            return;
        }

        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line !== false) $this->getOutput()->write($line);
        }
        while (!feof($pipes[2])) {
            $line = fgets($pipes[2]);
            if ($line !== false) $this->getOutput()->write($line);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}
