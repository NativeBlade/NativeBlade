<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;
use NativeBlade\ShellConfig;

class BundleCommand extends Command
{
    protected $signature = 'nativeblade:bundle
        {--tag= : Tag the output with a version (writes laravel-bundle-{tag}.json.gz alongside the canonical name)}
        {--no-dev : Run composer install --no-dev before bundling (default: true)}';

    protected $description = 'Build only the Laravel bundle (laravel-bundle.json.gz) — for OTA bundle push without rebuilding the native shell';

    public function handle(): int
    {
        $tag = $this->option('tag');
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

        $this->printPostBundleInstructions($tag);

        return self::SUCCESS;
    }

    /**
     * Print the post-build hint. When `bundlePush()` was configured in the
     * AppServiceProvider, derive the real bundle URL from the manifest URL
     * by swapping the last path segment with the tagged bundle filename, so
     * the dev gets a copy-pasteable manifest. Otherwise fall back to a
     * placeholder URL.
     *
     * Always include `minShellVersion`: when the bundle requires a feature
     * shipped in a newer shell, devices on older shells must skip the update
     * rather than apply it and crash. The default is the currently declared
     * platform version (i.e. "this bundle was built against the shell that
     * is in the stores right now"). Devs bump it manually when they ship a
     * bundle that depends on a new native plugin or facade method.
     */
    private function printPostBundleInstructions(?string $tag): void
    {
        $version = $tag ?: '1.0.0';
        $filename = "laravel-bundle-{$version}.json.gz";
        $minShellVersion = $this->detectMinShellVersion();

        $configured = ShellConfig::getAppConfigs()['bundlePush'] ?? null;
        $manifestUrl = $configured['url'] ?? null;

        $manifest = [
            'bundle' => [
                'version' => $version,
                'url' => $manifestUrl
                    ? $this->deriveBundleUrl($manifestUrl, $filename)
                    : "https://releases.myapp.com/{$filename}",
                'minShellVersion' => $minShellVersion,
            ],
        ];

        if ($manifestUrl) {
            $this->line('');
            $this->info('  Bundle built. Upload public/' . $filename . ' to:');
            $this->line('');
            $this->line('    ' . $manifest['bundle']['url']);
            $this->line('');
            $this->info('  Then update your manifest at:');
            $this->line('');
            $this->line('    ' . $manifestUrl);
            $this->line('');
            $this->info('  with this content (copy-paste):');
        } else {
            $this->line('');
            $this->info('  Bundle built. Upload it to your CDN and update version.json:');
        }

        $this->line('');
        foreach (explode("\n", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
            $this->line('    ' . $line);
        }
        $this->line('');

        $this->line("  <fg=yellow>→</> minShellVersion is set to <fg=cyan>{$minShellVersion}</> (the version declared in your AppServiceProvider).");
        $this->line('     Bump it manually if this bundle requires a native plugin or facade method added in a newer shell.');

        if (!$manifestUrl) {
            $this->line('');
            $this->line('  <fg=yellow>→</> Tip: declare NativeBladeConfig::bundlePush(\'<your-manifest-url>\') in your AppServiceProvider');
            $this->line('     to make this command print the real URL instead of a placeholder.');
        }
        $this->line('');
    }

    /**
     * Pick the version that should anchor `minShellVersion`. The dev usually
     * declares the same version across desktop/android/ios. We try those in
     * order and use the first one we find. Fall back to "1.0.0" so the
     * manifest is always syntactically valid even on a fresh project that
     * never set a version.
     */
    private function detectMinShellVersion(): string
    {
        foreach (['desktop', 'android', 'ios'] as $platform) {
            try {
                $info = ShellConfig::getVersion($platform);
                if (!empty($info['version'])) {
                    return (string) $info['version'];
                }
            } catch (\Throwable) {
                // platform not configured; try the next one
            }
        }
        return '1.0.0';
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
