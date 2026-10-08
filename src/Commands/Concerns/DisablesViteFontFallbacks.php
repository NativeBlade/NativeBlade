<?php

namespace NativeBlade\Commands\Concerns;

use NativeBlade\Support\ViteFontFallbacks;

trait DisablesViteFontFallbacks
{
    /**
     * Set `optimizedFallbacks: false` on the fonts declared in the app's Vite
     * config, so the CSS build stops asking for the optional "fontaine"
     * package. See ViteFontFallbacks. Idempotent; leaves a config alone when
     * the developer already set the option.
     */
    protected function disableViteFontFallbacks(): void
    {
        $path = ViteFontFallbacks::findConfig(base_path());
        if ($path === null) {
            return;
        }

        $patched = ViteFontFallbacks::disable(file_get_contents($path));
        if ($patched === null) {
            return;
        }

        file_put_contents($path, $patched);
        $this->line('  <fg=green>✓</> ' . basename($path) . ': optimized font fallbacks disabled (fonts ship in the bundle)');
    }
}
