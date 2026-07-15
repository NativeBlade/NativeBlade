<?php

namespace NativeBlade\Commands\Concerns;

/**
 * Copies components shipped by composer packages into the app's
 * `nativeblade-components/{name}/`, where the `@components` build alias and
 * the Blade discovery pick them up exactly like app-authored components
 * (including native shell modules — see NATIVE-SHELL.md).
 *
 * A package declares them in its composer.json:
 *
 * ```json
 * "extra": { "nativeblade": { "components": { "video-player": "dist" } } }
 * ```
 *
 * Runs at the start of both `nativeblade:dev` and `nativeblade:build`, so a
 * fresh machine going straight to a production build still gets vendor
 * components. Copies deliberately persist between runs; each sync replaces
 * the destination folder wholesale, so the installed package version always
 * wins over a stale copy.
 */
trait SyncsPackageComponents
{
    /** @return array<int, string> names of the components synced from packages */
    protected function syncPackageComponents(): array
    {
        $installedPath = base_path('vendor/composer/installed.json');
        if (!file_exists($installedPath)) return [];

        $data = json_decode(file_get_contents($installedPath), true);
        $packages = $data['packages'] ?? $data;
        $names = [];

        foreach ($packages as $package) {
            $nb = $package['extra']['nativeblade'] ?? null;
            if (!$nb || empty($nb['components'])) continue;

            $pkgPath = base_path('vendor/' . ($package['name'] ?? ''));

            foreach ($nb['components'] as $name => $folder) {
                $srcDir = $pkgPath . '/' . ltrim($folder, '/');
                if (!is_dir($srcDir)) continue;

                $destDir = base_path("nativeblade-components/{$name}");
                $this->deleteComponentDir($destDir);
                $this->copyComponentDir($srcDir, $destDir);

                $names[] = $name;
            }
        }

        if (!empty($names)) {
            $this->newLine();
            $this->line('  <fg=cyan;options=bold>External Components</>');
            foreach ($names as $name) {
                $this->line("  <fg=green>✓</> {$name}");
            }
            $this->newLine();
        }

        return $names;
    }

    /** Recursive copy — package components may ship subfolders (assets, lib/). */
    private function copyComponentDir(string $src, string $dest): void
    {
        @mkdir($dest, 0755, true);
        foreach (scandir($src) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $from = "{$src}/{$entry}";
            $to = "{$dest}/{$entry}";
            is_dir($from) ? $this->copyComponentDir($from, $to) : copy($from, $to);
        }
    }

    private function deleteComponentDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->deleteComponentDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
