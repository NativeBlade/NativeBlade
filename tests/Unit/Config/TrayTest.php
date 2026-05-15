<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Config;

use NativeBlade\Config\Menu;
use NativeBlade\Config\Tray;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrayTest extends TestCase
{
    #[Test]
    public function defaults_emit_a_disabled_tray_with_no_icon_or_menu(): void
    {
        $payload = (new Tray())->toArray();

        self::assertSame([
            'icon' => '',
            'tooltip' => '',
            'menu' => [],
            'hideOnClose' => false,
        ], $payload);
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $tray = new Tray();

        self::assertSame($tray, $tray->icon('public/tray.png'));
        self::assertSame($tray, $tray->tooltip('My App'));
        self::assertSame($tray, $tray->menu(fn (Menu $m) => $m->item('Show', 'show')));
        self::assertSame($tray, $tray->hideOnClose());
    }

    #[Test]
    public function it_serialises_every_field_when_fully_configured(): void
    {
        $payload = (new Tray())
            ->icon('public/tray.png')
            ->tooltip('My App')
            ->menu(function (Menu $m) {
                $m->item('Show', 'show');
                $m->separator();
                $m->item('Quit', 'exit');
            })
            ->hideOnClose()
            ->toArray();

        self::assertSame('public/tray.png', $payload['icon']);
        self::assertSame('My App', $payload['tooltip']);
        self::assertSame([
            ['label' => 'Show', 'action' => 'show'],
            ['separator' => true],
            ['label' => 'Quit', 'action' => 'exit'],
        ], $payload['menu']);
        self::assertTrue($payload['hideOnClose']);
    }

    #[Test]
    public function hide_on_close_can_be_disabled_explicitly(): void
    {
        $payload = (new Tray())->hideOnClose(false)->toArray();

        self::assertFalse($payload['hideOnClose']);
    }
}
