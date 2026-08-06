<?php

namespace NativeBlade\Commands\Concerns;

use NativeBlade\NativeBladeServiceProvider;

trait RefreshesNativeBuild
{
    /**
     * Force Cargo to recompile the vendored `nativeblade-tauri` crate when its
     * sources actually changed. Cargo fingerprints by mtime, but the crate is a
     * path dependency into the Composer package, and composer (or a sync) can land
     * updated files with an older mtime than the last-built .rlib — so a real
     * change is silently skipped and a framework fix never compiles.
     *
     * We content-hash the native sources instead: when the hash moves we touch
     * them (Cargo then sees them as new and recompiles), and when it is unchanged
     * we do nothing (no wasteful rebuild). Deterministic, and immune to whatever
     * the delivery did to mtimes. Runs from dev/build/update, so no manual
     * `cargo clean` is ever needed.
     */
    protected function refreshNativeBuildIfChanged(): void
    {
        $rustPath = NativeBladeServiceProvider::packagePath('rust');
        if (!is_dir($rustPath) || !is_dir(base_path('src-tauri'))) {
            return; // No native crate, or no native project — nothing to rebuild.
        }

        $files = $this->nativeSourceFiles($rustPath);
        if (empty($files)) {
            return;
        }

        $hash = $this->hashNativeSources($files, $rustPath);
        $stampFile = base_path('storage/app/.nativeblade-native.hash');
        $previous = is_file($stampFile) ? trim((string) @file_get_contents($stampFile)) : '';

        if ($hash === $previous) {
            return; // Native sources unchanged since the last build; let Cargo be.
        }

        $now = time();
        foreach ($files as $file) {
            @touch($file, $now);
        }
        @file_put_contents($stampFile, $hash);

        if (isset($this->output)) {
            $this->line('  <fg=green>✓</> Native sources changed — Cargo will recompile nativeblade-tauri');
        }
    }

    /**
     * @return string[] Absolute paths of every `.rs` / `Cargo.toml` under the
     *                  vendored Rust crate, sorted for a stable hash.
     */
    private function nativeSourceFiles(string $rustPath): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rustPath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains(str_replace('\\', '/', $path), '/target/')) {
                continue; // Never a build output in the vendored sources.
            }
            $name = $file->getFilename();
            if (str_ends_with($name, '.rs') || $name === 'Cargo.toml') {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @param  string[]  $files
     */
    private function hashNativeSources(array $files, string $rustPath): string
    {
        $ctx = hash_init('sha256');
        foreach ($files as $file) {
            // Hash the relative path too, so a rename/move counts as a change.
            hash_update($ctx, str_replace('\\', '/', substr($file, strlen($rustPath))));
            hash_update_file($ctx, $file);
        }
        return hash_final($ctx);
    }
}
