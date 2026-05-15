<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Config;

use NativeBlade\Config\Menu;
use NativeBlade\Config\MenuItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase
{
    #[Test]
    public function empty_menu_yields_empty_list(): void
    {
        self::assertSame([], (new Menu())->toArray());
    }

    #[Test]
    public function item_pairs_label_with_action(): void
    {
        $menu = new Menu();
        $menu->item('New Window', 'new_window');
        $menu->item('Quit', 'quit');

        self::assertSame([
            ['label' => 'New Window', 'action' => 'new_window'],
            ['label' => 'Quit', 'action' => 'quit'],
        ], $menu->toArray());
    }

    #[Test]
    public function item_returns_a_menu_item_for_modifier_chaining(): void
    {
        $menu = new Menu();

        self::assertInstanceOf(MenuItem::class, $menu->item('Settings', '/settings'));
    }

    #[Test]
    public function item_modifiers_are_visible_in_the_final_output(): void
    {
        $menu = new Menu();
        $menu->item('Save', 'save')->icon('floppy')->accelerator('Ctrl+S');
        $menu->item('Delete', 'delete')->disabled();

        self::assertSame([
            ['label' => 'Save', 'action' => 'save', 'icon' => 'floppy', 'accelerator' => 'Ctrl+S'],
            ['label' => 'Delete', 'action' => 'delete', 'disabled' => true],
        ], $menu->toArray());
    }

    #[Test]
    public function separator_emits_a_distinct_separator_marker(): void
    {
        $menu = new Menu();
        $menu->item('First', 'first');
        $menu->separator();
        $menu->item('Second', 'second');

        self::assertCount(3, $menu->toArray());
        self::assertSame(['separator' => true], $menu->toArray()[1]);
    }

    #[Test]
    public function submenu_nests_items_via_closure(): void
    {
        $menu = (new Menu())
            ->submenu('File', function (Menu $file) {
                $file->item('Open', 'open_file');
                $file->item('Close', 'close_file');
            });

        $entry = $menu->toArray()[0];
        self::assertSame('File', $entry['label']);
        self::assertSame([
            ['label' => 'Open', 'action' => 'open_file'],
            ['label' => 'Close', 'action' => 'close_file'],
        ], $entry['items']);
    }

    #[Test]
    public function separators_inside_submenus_are_preserved(): void
    {
        $menu = (new Menu())->submenu('Edit', function (Menu $edit) {
            $edit->item('Undo', 'undo');
            $edit->separator();
            $edit->item('Redo', 'redo');
        });

        $items = $menu->toArray()[0]['items'];
        self::assertSame(['separator' => true], $items[1]);
    }

    #[Test]
    public function deeply_nested_submenus_recurse_correctly(): void
    {
        $menu = (new Menu())
            ->submenu('A', function (Menu $a) {
                $a->submenu('B', function (Menu $b) {
                    $b->item('C', 'run_c');
                });
            });

        $a = $menu->toArray()[0];
        self::assertSame('A', $a['label']);
        $b = $a['items'][0];
        self::assertSame('B', $b['label']);
        self::assertSame([['label' => 'C', 'action' => 'run_c']], $b['items']);
    }

    #[Test]
    public function structural_setters_are_chainable_on_menu(): void
    {
        // item() returns MenuItem (for modifier chains), but separator()
        // and submenu() still return Menu so the parent chain keeps working.
        $menu = new Menu();

        self::assertSame($menu, $menu->separator());
        self::assertSame($menu, $menu->submenu('S', fn (Menu $s) => $s->item('X', 'x')));
    }
}
