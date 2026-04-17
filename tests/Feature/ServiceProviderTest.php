<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use NativeBlade\NativeBladeServiceProvider;
use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Container wiring for the NativeBladeServiceProvider:
 *  - 'nativeblade' binds to a ShellConfig singleton
 *  - Blade components (nativeblade-*) are registered
 *  - Commands are registered when running in console
 *  - packagePath() resolves asset paths correctly
 */
final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function native_blade_singleton_is_bound(): void
    {
        self::assertTrue($this->app->bound('nativeblade'));
        self::assertInstanceOf(ShellConfig::class, $this->app['nativeblade']);
    }

    #[Test]
    public function native_blade_binding_is_a_singleton(): void
    {
        $a = $this->app->make('nativeblade');
        $b = $this->app->make('nativeblade');
        self::assertSame($a, $b);
    }

    #[Test]
    public function core_blade_components_are_registered(): void
    {
        $aliases = $this->app['blade.compiler']->getClassComponentAliases();

        foreach ([
            'nativeblade-header',
            'nativeblade-action',
            'nativeblade-bottom-nav',
            'nativeblade-tab',
            'nativeblade-drawer',
            'nativeblade-drawer-item',
            'nativeblade-icon',
            'nativeblade-image',
            'nativeblade-modal',
            'nativeblade-safe',
            'nativeblade-skeleton',
            'nativeblade-animate',
            'nativeblade-font',
        ] as $name) {
            self::assertArrayHasKey($name, $aliases, "Missing Blade component alias: {$name}");
        }
    }

    #[Test]
    public function nativeblade_view_namespace_is_registered(): void
    {
        $hints = $this->app['view']->getFinder()->getHints();
        self::assertArrayHasKey('nativeblade', $hints);
    }

    #[Test]
    public function package_path_resolves_relative_paths(): void
    {
        $base = NativeBladeServiceProvider::packagePath();
        $resources = NativeBladeServiceProvider::packagePath('resources/views');

        self::assertIsString($base);
        self::assertSame($base . '/resources/views', $resources);
    }

    #[Test]
    public function package_path_handles_leading_slashes(): void
    {
        $base = NativeBladeServiceProvider::packagePath();
        self::assertSame($base . '/foo', NativeBladeServiceProvider::packagePath('/foo'));
        self::assertSame($base . '/foo', NativeBladeServiceProvider::packagePath('foo'));
    }

    #[Test]
    public function asset_to_data_uri_falls_back_to_asset_for_missing_files(): void
    {
        // public_path('nope.png') won't exist — method must fall back
        $result = NativeBladeServiceProvider::assetToDataUri('does-not-exist-' . uniqid() . '.png');
        self::assertIsString($result);
    }
}
