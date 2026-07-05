<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\NativeBladeServiceProvider;

class UpdateCommand extends Command
{
    protected $signature = 'nativeblade:update';
    protected $description = 'Sync the project with the installed NativeBlade version (package.json deps + regenerate config)';

    public function handle(): int
    {
        $this->info('');
        $this->info('  ⚡ NativeBlade Update');
        $this->info('');

        $this->syncPackageJson();
        $this->syncViteConfig();
        $this->syncCargoConfig();

        $this->line('');
        $this->line('  Regenerating config...');
        $this->call('nativeblade:config');

        $this->line('');
        $this->line('  Installing npm dependencies...');
        exec('cd ' . escapeshellarg(base_path()) . ' && npm install 2>&1', $output, $code);
        $this->line($code === 0
            ? "  <fg=green>✓</> npm dependencies updated"
            : "  <fg=yellow>→</> npm install failed, run it manually");

        $this->info('');
        $this->info('  ✓ Project updated. Rebuild with `npm run build` (and `nativeblade:build` per platform).');
        $this->info('');

        return self::SUCCESS;
    }

    /**
     * Re-publish vite.wasm.config.js from the current stub so config fixes (the
     * php-wasm asset handling, the dev-server host allowlist, ...) reach
     * existing projects. The previous file is kept as a .bak so any local tweak
     * can be diffed back. No-op when already identical.
     */
    private function syncViteConfig(): void
    {
        $stubPath = NativeBladeServiceProvider::packagePath('stubs/vite.wasm.config.js.stub');
        $targetPath = base_path('vite.wasm.config.js');

        if (!file_exists($stubPath)) {
            return;
        }

        $new = file_get_contents($stubPath);

        if (file_exists($targetPath) && file_get_contents($targetPath) === $new) {
            $this->line("  <fg=green>✓</> vite.wasm.config.js already current");
            return;
        }

        if (file_exists($targetPath)) {
            copy($targetPath, $targetPath . '.bak');
        }

        file_put_contents($targetPath, $new);
        $this->line(file_exists($targetPath . '.bak')
            ? "  <fg=green>✓</> vite.wasm.config.js synced (previous saved to vite.wasm.config.js.bak)"
            : "  <fg=green>✓</> vite.wasm.config.js created");
    }

    /**
     * Ensure the Android Rust targets link with 16 KB ELF page alignment,
     * which Google Play requires for apps targeting Android 15+. Projects
     * generated before this flag existed have only the linker lines, and the
     * alignment then silently depends on the installed NDK (r28+ aligns by
     * default, older ones don't). The rustflags line is injected under each
     * Android target's linker so the machine-specific NDK paths are preserved.
     */
    private function syncCargoConfig(): void
    {
        $targetPath = base_path('src-tauri/.cargo/config.toml');

        if (!file_exists($targetPath)) {
            return; // No Android platform added yet.
        }

        $config = file_get_contents($targetPath);

        if (str_contains($config, 'max-page-size=16384')) {
            $this->line("  <fg=green>✓</> src-tauri/.cargo/config.toml already current");
            return;
        }

        $patched = preg_replace(
            '/^(\[target\.(?:aarch64-linux-android|armv7-linux-androideabi|i686-linux-android|x86_64-linux-android)\]\R+linker = "[^"]+")/m',
            "$1\nrustflags = [\"-C\", \"link-arg=-Wl,-z,max-page-size=16384\"]",
            $config
        );

        if ($patched === null || $patched === $config) {
            $this->line("  <fg=yellow>→</> src-tauri/.cargo/config.toml has an unexpected layout, add the 16 KB page-size rustflags manually (see stubs/.cargo/config.toml.stub)");
            return;
        }

        file_put_contents($targetPath, $patched);
        $this->line("  <fg=green>✓</> src-tauri/.cargo/config.toml synced (16 KB page-size alignment for Android)");
    }

    /**
     * Bring package.json into line with the current NativeBlade stub: bump
     * every NativeBlade-managed dependency (the @php-wasm and @tauri-apps
     * families, Vite, Tailwind, ...) to the version this release ships, add
     * any new ones, and pick up new scripts. The dev's own dependencies and
     * the chosen @php-wasm/web-<php> variant are preserved — the bare framework
     * runtime itself comes from the Composer package, so it needs no copying.
     */
    private function syncPackageJson(): void
    {
        $targetPath = base_path('package.json');
        $stubPath = NativeBladeServiceProvider::packagePath('stubs/package.json.stub');

        if (!file_exists($targetPath) || !file_exists($stubPath)) {
            $this->line("  <fg=yellow>→</> package.json not found, skipped");
            return;
        }

        $pkg = json_decode(file_get_contents($targetPath), true) ?? [];
        // Neutralize the {{PHP_VERSION}} placeholder so the web-<php> entry is
        // recognizable as a family member rather than a literal package.
        $stub = json_decode(str_replace('{{PHP_VERSION}}', '0-0', file_get_contents($stubPath)), true) ?? [];

        $phpWasmWeb = null;
        $targets = [];
        foreach (array_merge($stub['dependencies'] ?? [], $stub['devDependencies'] ?? []) as $name => $ver) {
            if (str_starts_with($name, '@php-wasm/web-')) {
                $phpWasmWeb = $ver;
                continue;
            }
            $targets[$name] = $ver;
        }

        $changed = [];

        // Bump managed deps already present, keeping their section and the
        // project's chosen @php-wasm/web-<php> variant.
        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach ($pkg[$section] ?? [] as $name => $cur) {
                $want = $targets[$name]
                    ?? (str_starts_with($name, '@php-wasm/web-') ? $phpWasmWeb : null);
                if ($want !== null && $want !== $cur) {
                    $pkg[$section][$name] = $want;
                    $changed[] = "{$name}: {$cur} -> {$want}";
                }
            }
        }

        // Add managed deps the project is missing (skip the web-<php> family,
        // which the project already has from install).
        $present = array_merge(
            array_keys($pkg['dependencies'] ?? []),
            array_keys($pkg['devDependencies'] ?? [])
        );
        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach ($stub[$section] ?? [] as $name => $ver) {
                if (str_starts_with($name, '@php-wasm/web-') || in_array($name, $present, true)) {
                    continue;
                }
                $pkg[$section][$name] = $ver;
                $changed[] = "+ {$name}: {$ver}";
            }
        }

        // Pick up new scripts without overriding the dev's.
        $pkg['scripts'] = array_merge($stub['scripts'] ?? [], $pkg['scripts'] ?? []);

        if (empty($changed)) {
            $this->line("  <fg=green>✓</> package.json already current");
            return;
        }

        file_put_contents($targetPath, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->line("  <fg=green>✓</> package.json synced:");
        foreach ($changed as $c) {
            $this->line("      <fg=gray>{$c}</>");
        }
    }
}
