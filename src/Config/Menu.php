<?php

namespace NativeBlade\Config;

use Closure;

/**
 * Fluent builder for native menus — shared by the desktop app menu bar
 * (`DesktopConfig::menu()`) and the system tray context menu
 * (`Tray::menu()`).
 */
class Menu
{
    /** @var array<int, MenuItem|array<string, mixed>> */
    private array $entries = [];

    /**
     * Add a clickable menu item. Returns the underlying `MenuItem` so
     * the caller can chain modifiers (`->icon()`, `->disabled()`,
     * `->accelerator()`, `->checked()`). Discarding the return value
     * is the common case for simple items.
     *
     * @param  string  $label   Text shown in the menu.
     * @param  string  $action  Action name (e.g. `'exit'`, `'show'`) or route path (`'/dashboard'`).
     */
    public function item(string $label, string $action): MenuItem
    {
        $item = new MenuItem($label, $action);
        $this->entries[] = $item;
        return $item;
    }

    /** Horizontal divider between groups of items. */
    public function separator(): static
    {
        $this->entries[] = ['separator' => true];
        return $this;
    }

    /**
     * Nested submenu.
     *
     * @param  Closure(Menu): void  $callback
     */
    public function submenu(string $label, Closure $callback): static
    {
        $sub = new Menu();
        $callback($sub);
        $this->entries[] = ['label' => $label, 'items' => $sub->toArray()];
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            fn ($entry) => $entry instanceof MenuItem ? $entry->toArray() : $entry,
            $this->entries,
        );
    }
}
