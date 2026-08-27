<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\IconRegistry;

/**
 * Regenerates the shell's JS icon registry from the PHP IconRegistry.
 *
 * The shell (bottom-nav, drawer, top-bar) is drawn in plain JS outside the
 * Livewire tree, so it needs the icon SVGs available synchronously without a
 * PHP round-trip. That means the icons live in two places: IconRegistry.php
 * (for the x-nb-icon Blade component) and js/wasm-app/components/icons.js (for
 * the shell). Kept in sync by hand they drift, which is how the shell ended up
 * missing first the fill weight and later the bold weight. This command makes
 * IconRegistry.php the single source of truth and generates the JS from it.
 */
class IconsSyncCommand extends Command
{
    protected $signature = 'nativeblade:icons:sync
        {--check : Verify the JS registry matches without writing; exit 1 if it drifted}';

    protected $description = 'Regenerate the shell JS icon registry from IconRegistry.php';

    public function handle(): int
    {
        $target = self::jsPath();
        $expected = self::buildJs();

        if ($this->option('check')) {
            $current = is_file($target) ? file_get_contents($target) : '';
            if ($current === $expected) {
                $this->line('  <fg=green>✓</> icons.js is in sync ('.count(IconRegistry::entries()).' icons)');
                return self::SUCCESS;
            }
            $this->error('  icons.js is out of sync with IconRegistry. Run: php artisan nativeblade:icons:sync');
            return self::FAILURE;
        }

        file_put_contents($target, $expected);
        $this->line('  <fg=green>✓</> Wrote '.count(IconRegistry::entries()).' icons to js/wasm-app/components/icons.js');

        return self::SUCCESS;
    }

    /** Absolute path to the shell's JS icon registry inside the package. */
    public static function jsPath(): string
    {
        return dirname(__DIR__, 2).'/js/wasm-app/components/icons.js';
    }

    /**
     * Build the full contents of icons.js from IconRegistry. Pure: depends only
     * on the registry, so it is safe to call outside a booted app.
     */
    public static function buildJs(): string
    {
        $entries = [];
        foreach (IconRegistry::entries() as $name => $paths) {
            $entries[] = json_encode($name, JSON_UNESCAPED_SLASHES)
                .':'.json_encode($paths, JSON_UNESCAPED_SLASHES);
        }

        // Single-quoted: the backticks and ${...} below are literal JS, not PHP.
        $svg = 'export function svg(name){const p=I[name];if(!p){if(typeof console!=="undefined"&&console.warn)console.warn("[NativeBlade] unknown shell icon:",name);return"";}const d=p.map(x=>`<path d="${x}"/>`).join("");return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor">${d}</svg>`;}';

        $header = "// AUTO-GENERATED from src/IconRegistry.php by `php artisan nativeblade:icons:sync`.\n"
            ."// Do not edit by hand; run the command to regenerate.\n";

        return $header.'const I={'.implode(',', $entries)."};\n".$svg."\n";
    }
}
