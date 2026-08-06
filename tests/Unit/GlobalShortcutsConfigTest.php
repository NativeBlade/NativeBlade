<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use NativeBlade\ShellConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * NativeBladeConfig::globalShortcuts() stores an accelerator => name map that
 * ConfigCommand publishes into nativeblade-config.json. The map is validated:
 * both sides must be non-empty strings. Relies on ShellConfig static state, so
 * tearDown resets it.
 */
final class GlobalShortcutsConfigTest extends TestCase
{
    private ShellConfig $config;

    protected function setUp(): void
    {
        $this->config = new ShellConfig();
    }

    protected function tearDown(): void
    {
        $p = (new ReflectionClass(ShellConfig::class))->getProperty('appConfigs');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    #[Test]
    public function stores_the_accelerator_to_name_map(): void
    {
        $this->config->globalShortcuts([
            'CmdOrCtrl+Shift+K' => 'search',
            'CmdOrCtrl+,' => 'preferences',
        ]);

        self::assertSame(
            ['CmdOrCtrl+Shift+K' => 'search', 'CmdOrCtrl+,' => 'preferences'],
            ShellConfig::getAppConfigs()['globalShortcuts']
        );
    }

    #[Test]
    public function empty_map_stores_an_empty_array(): void
    {
        $this->config->globalShortcuts([]);
        self::assertSame([], ShellConfig::getAppConfigs()['globalShortcuts']);
    }

    #[Test]
    public function rejects_an_empty_accelerator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->globalShortcuts(['' => 'search']);
    }

    #[Test]
    public function rejects_an_empty_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->globalShortcuts(['CmdOrCtrl+K' => '']);
    }

    #[Test]
    public function rejects_a_non_string_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->globalShortcuts(['CmdOrCtrl+K' => 123]);
    }
}
