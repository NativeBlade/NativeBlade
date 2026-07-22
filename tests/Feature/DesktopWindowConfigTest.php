<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use NativeBlade\Config\DesktopConfig;
use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * ShellConfig::get() is what feeds the JS shell (the injected __nb-shell-config).
 * A named corner anchor must surface there as `window.anchor` so the shell can
 * resolve it against the monitor at launch; 'center' and exact x/y are static
 * (tauri.conf) and must NOT appear.
 */
final class DesktopWindowConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAppConfigs();
        // Force desktop platform detection (match() default already maps web →
        // desktop, but be explicit so an android/ios env can't leak in).
        unset($_SERVER['NATIVEBLADE_PLATFORM']);
    }

    protected function tearDown(): void
    {
        $this->resetAppConfigs();
        parent::tearDown();
    }

    private function resetAppConfigs(): void
    {
        $p = (new ReflectionClass(ShellConfig::class))->getProperty('appConfigs');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    #[Test]
    public function a_corner_anchor_surfaces_as_window_anchor(): void
    {
        $config = new ShellConfig();
        $config->desktop(fn (DesktopConfig $c) => $c->position('bottom-right'));

        self::assertSame(['anchor' => 'bottom-right'], $config->get()['window'] ?? null);
    }

    #[Test]
    public function the_center_anchor_is_static_and_never_surfaces(): void
    {
        $config = new ShellConfig();
        $config->desktop(fn (DesktopConfig $c) => $c->position('center'));

        self::assertArrayNotHasKey('window', $config->get());
    }

    #[Test]
    public function exact_coordinates_never_surface(): void
    {
        $config = new ShellConfig();
        $config->desktop(fn (DesktopConfig $c) => $c->position(120, 60));

        self::assertArrayNotHasKey('window', $config->get());
    }

    #[Test]
    public function no_placement_config_produces_no_window_key(): void
    {
        $config = new ShellConfig();
        $config->desktop(fn (DesktopConfig $c) => $c->size(800, 600));

        self::assertArrayNotHasKey('window', $config->get());
    }
}
