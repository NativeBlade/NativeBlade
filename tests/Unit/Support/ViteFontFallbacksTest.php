<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Support;

use NativeBlade\Support\ViteFontFallbacks;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ViteFontFallbacks patches the app's vite.config.js so laravel-vite-plugin's
 * fonts feature stops asking for the optional "fontaine" package. The patch
 * is text-based, so these pin the shapes it must handle and the ones it must
 * leave alone.
 */
final class ViteFontFallbacksTest extends TestCase
{
    // Laravel 12/13 scaffold, verbatim.
    private const LARAVEL_DEFAULT = <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
});
JS;

    #[Test]
    public function adds_the_option_to_the_laravel_default_config_keeping_indentation(): void
    {
        $out = ViteFontFallbacks::disable(self::LARAVEL_DEFAULT);

        self::assertNotNull($out);
        self::assertStringContainsString(
            "bunny('Instrument Sans', {\n                    optimizedFallbacks: false,\n                    weights: [400, 500, 600],\n                }),",
            $out
        );
    }

    #[Test]
    public function is_idempotent(): void
    {
        $once = ViteFontFallbacks::disable(self::LARAVEL_DEFAULT);

        self::assertNull(ViteFontFallbacks::disable($once));
    }

    #[Test]
    public function respects_an_explicit_choice_by_the_developer(): void
    {
        $source = str_replace('weights: [400, 500, 600],', "weights: [400, 500, 600],\n                    optimizedFallbacks: true,", self::LARAVEL_DEFAULT);

        self::assertNull(ViteFontFallbacks::disable($source));
    }

    #[Test]
    public function handles_inline_options_and_calls_without_options(): void
    {
        $source = "import { bunny, google } from 'laravel-vite-plugin/fonts';\n"
            . "fonts: [bunny('Inter', { weights: [400] }), google(\"Roboto\")],";

        $out = ViteFontFallbacks::disable($source);

        self::assertStringContainsString("bunny('Inter', { optimizedFallbacks: false, weights: [400] })", $out);
        self::assertStringContainsString('google("Roboto", { optimizedFallbacks: false })', $out);
    }

    #[Test]
    public function leaves_configs_without_the_fonts_helpers_alone(): void
    {
        $source = "import laravel from 'laravel-vite-plugin';\nexport default { plugins: [laravel({ input: ['resources/js/app.js'] })] };";

        self::assertNull(ViteFontFallbacks::disable($source));
    }

    #[Test]
    public function finds_the_first_vite_config_variant(): void
    {
        $dir = sys_get_temp_dir() . '/nb-vite-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            self::assertNull(ViteFontFallbacks::findConfig($dir));

            file_put_contents($dir . '/vite.config.ts', '');
            self::assertSame($dir . DIRECTORY_SEPARATOR . 'vite.config.ts', ViteFontFallbacks::findConfig($dir));

            file_put_contents($dir . '/vite.config.js', '');
            self::assertSame($dir . DIRECTORY_SEPARATOR . 'vite.config.js', ViteFontFallbacks::findConfig($dir));
        } finally {
            @unlink($dir . '/vite.config.ts');
            @unlink($dir . '/vite.config.js');
            rmdir($dir);
        }
    }
}
