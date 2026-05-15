<?php

namespace NativeBlade\Config;

/**
 * Individual menu entry returned by `Menu::item()`. Modifiers attach
 * extra metadata (icon, disabled state, accelerator) to a single item
 * without breaking the parent `Menu`'s chain — the parent stores a
 * reference to the MenuItem and reads back its current state when
 * `Menu::toArray()` is called.
 */
class MenuItem
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(string $label, string $action)
    {
        $this->data = ['label' => $label, 'action' => $action];
    }

    /**
     * Icon shown next to the label. Accepts an icon name resolved by
     * the host platform (e.g. a Phosphor icon name, an SF Symbol on
     * macOS, or a drawable name on Linux/Windows).
     */
    public function icon(string $name): static
    {
        $this->data['icon'] = $name;
        return $this;
    }

    /**
     * Grey out the item so it cannot be clicked. Accepts a boolean
     * so the dev can write `$m->item(...)->disabled(! $user->canEdit())`
     * inline against any condition.
     */
    public function disabled(bool $value = true): static
    {
        $this->data['disabled'] = $value;
        return $this;
    }

    /**
     * Keyboard shortcut, e.g. `'Ctrl+S'`, `'CmdOrCtrl+Shift+P'`. Follows
     * the Tauri / Electron accelerator syntax.
     */
    public function accelerator(string $shortcut): static
    {
        $this->data['accelerator'] = $shortcut;
        return $this;
    }

    /**
     * Renders the item with a checkmark prefix. Useful for toggle-style
     * menu entries.
     */
    public function checked(bool $value = true): static
    {
        $this->data['checked'] = $value;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
