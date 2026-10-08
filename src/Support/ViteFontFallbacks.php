<?php

namespace NativeBlade\Support;

/**
 * Turns off laravel-vite-plugin's optimized font fallbacks in the app's Vite
 * config.
 *
 * The Laravel scaffold declares fonts with `bunny('Instrument Sans', {...})`.
 * On every build the fonts feature then tries to compute fallback metrics with
 * the optional "fontaine" package and warns when it is missing. The metrics
 * only soften the layout shift of a web font arriving over the network; a
 * NativeBlade app ships its fonts in the bundle and inlines them, so there is
 * nothing to soften. Disabling the feature removes the warning and a useless
 * optional dependency.
 */
class ViteFontFallbacks
{
    public const CANDIDATES = ['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.cjs'];

    /** First Vite config present in the project, or null. */
    public static function findConfig(string $basePath): ?string
    {
        foreach (self::CANDIDATES as $file) {
            $path = $basePath . DIRECTORY_SEPARATOR . $file;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Patched source, or null when there is nothing to change: no font
     * helpers in use, or `optimizedFallbacks` already set (either way, the
     * developer's choice stands).
     */
    public static function disable(string $source): ?string
    {
        if (!str_contains($source, 'laravel-vite-plugin/fonts') || str_contains($source, 'optimizedFallbacks')) {
            return null;
        }

        $helpers = '(?:bunny|google)';
        $quoted = '(["\'])[^"\']*\2';

        // bunny('Name', { ... }): add the option right after the brace, on its
        // own line when the object is multi-line, inline otherwise.
        $patched = preg_replace_callback(
            "/\\b({$helpers})\\(\\s*{$quoted}\\s*,\\s*\\{(\\s*)/",
            function (array $m) {
                $callStart = substr($m[0], 0, -strlen($m[3]));

                return $callStart . $m[3] . 'optimizedFallbacks: false,' . $m[3];
            },
            $source
        );

        // bunny('Name'): no options object yet.
        $patched = preg_replace(
            "/\\b({$helpers})\\(\\s*(([\"'])[^\"']*\\3)\\s*\\)/",
            '$1($2, { optimizedFallbacks: false })',
            $patched
        );

        return $patched === $source ? null : $patched;
    }
}
