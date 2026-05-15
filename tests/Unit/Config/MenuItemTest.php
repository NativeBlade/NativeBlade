<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Config;

use NativeBlade\Config\MenuItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MenuItemTest extends TestCase
{
    #[Test]
    public function bare_item_serialises_label_and_action_only(): void
    {
        $payload = (new MenuItem('Open', 'open_file'))->toArray();

        self::assertSame(['label' => 'Open', 'action' => 'open_file'], $payload);
    }

    #[Test]
    public function icon_attaches_named_icon(): void
    {
        $payload = (new MenuItem('Settings', '/settings'))->icon('gear')->toArray();

        self::assertSame('gear', $payload['icon']);
    }

    #[Test]
    public function disabled_defaults_to_true_when_called_without_argument(): void
    {
        $payload = (new MenuItem('Pay', 'pay'))->disabled()->toArray();

        self::assertTrue($payload['disabled']);
    }

    #[Test]
    public function disabled_accepts_a_boolean_expression(): void
    {
        $isAdmin = false;

        $payload = (new MenuItem('Admin', '/admin'))->disabled(! $isAdmin)->toArray();

        self::assertTrue($payload['disabled']);
    }

    #[Test]
    public function accelerator_attaches_keyboard_shortcut(): void
    {
        $payload = (new MenuItem('Save', 'save'))->accelerator('Ctrl+S')->toArray();

        self::assertSame('Ctrl+S', $payload['accelerator']);
    }

    #[Test]
    public function checked_marks_the_item_as_a_toggle(): void
    {
        $payload = (new MenuItem('Dark Mode', 'toggle_dark'))->checked()->toArray();

        self::assertTrue($payload['checked']);
    }

    #[Test]
    public function modifiers_compose_in_a_single_chain(): void
    {
        $payload = (new MenuItem('Save', 'save'))
            ->icon('floppy')
            ->accelerator('Ctrl+S')
            ->disabled(false)
            ->toArray();

        self::assertSame([
            'label' => 'Save',
            'action' => 'save',
            'icon' => 'floppy',
            'accelerator' => 'Ctrl+S',
            'disabled' => false,
        ], $payload);
    }
}
