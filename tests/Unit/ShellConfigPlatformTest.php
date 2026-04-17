<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use NativeBlade\ShellConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure unit tests for ShellConfig's platform detection, transition, and boot
 * callback. These rely on static state inside ShellConfig, so tearDown uses
 * reflection to reset them between tests.
 */
final class ShellConfigPlatformTest extends TestCase
{
    private ShellConfig $config;
    private ?string $originalPlatform;

    protected function setUp(): void
    {
        $this->originalPlatform = $_SERVER['NATIVEBLADE_PLATFORM'] ?? null;
        unset($_SERVER['NATIVEBLADE_PLATFORM']);
        $this->config = new ShellConfig();
    }

    protected function tearDown(): void
    {
        // restore env
        if ($this->originalPlatform === null) {
            unset($_SERVER['NATIVEBLADE_PLATFORM']);
        } else {
            $_SERVER['NATIVEBLADE_PLATFORM'] = $this->originalPlatform;
        }

        // reset static state on ShellConfig
        $ref = new ReflectionClass(ShellConfig::class);
        foreach (['appConfigs' => [], 'transition' => 'none', 'onBootCallback' => null] as $prop => $default) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $default);
        }
    }

    #[Test]
    public function platform_defaults_to_web_without_env(): void
    {
        self::assertSame('web', $this->config->platform());
        self::assertTrue($this->config->isWeb());
    }

    #[Test]
    public function platform_reads_nativeblade_platform_env(): void
    {
        $_SERVER['NATIVEBLADE_PLATFORM'] = 'android';
        self::assertSame('android', $this->config->platform());
    }

    #[Test]
    public function is_desktop_is_true_for_windows_macos_linux(): void
    {
        foreach (['windows', 'macos', 'linux'] as $p) {
            $_SERVER['NATIVEBLADE_PLATFORM'] = $p;
            self::assertTrue($this->config->isDesktop(), "expected isDesktop for {$p}");
            self::assertFalse($this->config->isMobile(), "did not expect isMobile for {$p}");
        }
    }

    #[Test]
    public function is_mobile_is_true_for_android_and_ios(): void
    {
        foreach (['android', 'ios'] as $p) {
            $_SERVER['NATIVEBLADE_PLATFORM'] = $p;
            self::assertTrue($this->config->isMobile(), "expected isMobile for {$p}");
            self::assertFalse($this->config->isDesktop(), "did not expect isDesktop for {$p}");
        }
    }

    #[Test]
    public function platform_specific_helpers_are_mutually_exclusive(): void
    {
        $cases = [
            'windows' => 'isWindows',
            'macos' => 'isMacos',
            'linux' => 'isLinux',
            'android' => 'isAndroid',
            'ios' => 'isIos',
            'web' => 'isWeb',
        ];

        foreach ($cases as $platform => $method) {
            $_SERVER['NATIVEBLADE_PLATFORM'] = $platform;
            foreach ($cases as $otherPlatform => $otherMethod) {
                if ($otherPlatform === $platform) {
                    self::assertTrue($this->config->{$otherMethod}(), "{$otherMethod}() should be true when platform is {$platform}");
                } else {
                    self::assertFalse($this->config->{$otherMethod}(), "{$otherMethod}() should be false when platform is {$platform}");
                }
            }
        }
    }

    #[Test]
    public function transition_getter_defaults_to_none(): void
    {
        self::assertSame('none', ShellConfig::getTransition());
    }

    #[Test]
    public function transition_setter_updates_static_state(): void
    {
        $this->config->transition('slide');
        self::assertSame('slide', ShellConfig::getTransition());
    }

    #[Test]
    public function transition_setter_defaults_to_fade(): void
    {
        $this->config->transition();
        self::assertSame('fade', ShellConfig::getTransition());
    }

    #[Test]
    public function transition_setter_returns_self_for_chaining(): void
    {
        self::assertSame($this->config, $this->config->transition('fade'));
    }

    #[Test]
    public function on_boot_stores_the_callback_statically(): void
    {
        self::assertNull(ShellConfig::getBootCallback());

        $cb = fn () => 'booted';
        $result = $this->config->onBoot($cb);

        self::assertSame($this->config, $result);
        self::assertSame($cb, ShellConfig::getBootCallback());
    }

    #[Test]
    public function bottom_nav_and_top_bar_return_self_for_chaining(): void
    {
        self::assertSame($this->config, $this->config->bottomNav([['label' => 'Home', 'path' => '/']]));
        self::assertSame($this->config, $this->config->topBar(['title' => 'App']));
    }
}
