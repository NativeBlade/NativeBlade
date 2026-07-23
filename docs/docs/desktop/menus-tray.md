---
title: "Menus & Tray"
description: "Configure the desktop menu bar and system tray."
---

# Menus & Tray

Desktop apps can add a native menu bar and a system tray icon, both configured in your `AppServiceProvider` through the config closure.

## System Tray

The tray is configured through a fluent closure. Calling `->tray(...)` enables the tray icon; omit it to disable.

```php
use NativeBlade\Config\Menu;
use NativeBlade\Config\Tray;

NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->tray(function (Tray $t) {
            $t->icon('public/tray.png')
              ->tooltip('My App is running')
              ->menu(function (Menu $m) {
                  $m->item('Show', 'show');
                  $m->item('Hide', 'hide');
                  $m->separator();
                  $m->item('Quit', 'exit');
              })
              ->hideOnClose();
        });
});
```

| Method | Description |
|--------|-------------|
| `icon(string)` | Path to a PNG tray icon (relative to project root) |
| `tooltip(string)` | Tooltip shown on mouse hover |
| `menu(Closure)` | Context menu, receives a [`Menu`](#menu-builder) instance |
| `hideOnClose(bool)` | When `true`, clicking the window's close button hides the window into the tray instead of quitting (default `false`) |

`hideOnClose` lives on `Tray` (not `DesktopConfig`) because it only makes sense when a tray icon exists, without one, the user would have no way to restore the window.

## Menu Builder

The same `Menu` builder powers both the application menu bar (`DesktopConfig::menu()`) and the tray context menu (`Tray::menu()`).

```php
use NativeBlade\Config\Menu;

NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->menu(function (Menu $m) {
        $m->submenu('File', function (Menu $file) {
            $file->item('New', '/new')->icon('plus')->accelerator('CmdOrCtrl+N');
            $file->item('Open', '/open')->accelerator('CmdOrCtrl+O');
            $file->separator();
            $file->item('Quit', 'exit')->accelerator('CmdOrCtrl+Q');
        });
        $m->submenu('Help', function (Menu $help) {
            $help->item('About', '/about');
            $help->item('License', '/license')->disabled(! $user->isPro());
        });
    });
});
```

| Method | Returns | Description |
|--------|---------|-------------|
| `item(string $label, string $action)` | `MenuItem` | Clickable item. `$action` is either a route path (`'/dashboard'`) or a command name (`'exit'`, `'show'`, `'hide'`, custom action). |
| `separator()` | `Menu` | Horizontal divider between groups of items. |
| `submenu(string $label, Closure $callback)` | `Menu` | Nested submenu, the closure receives its own `Menu` instance. Submenus can nest arbitrarily deep. |

`item()` returns a `MenuItem` so you can chain modifiers on the same line:

| Modifier | Description |
|----------|-------------|
| `->icon(string $name)` | Icon shown next to the label (icon name resolved by the host platform) |
| `->disabled(bool $value = true)` | Greys out the item. Accepts any boolean expression: `->disabled(! $user->isAdmin())` |
| `->accelerator(string $shortcut)` | Keyboard shortcut, e.g. `'Ctrl+S'`, `'CmdOrCtrl+Shift+P'`. Use `'CmdOrCtrl+'` for cross-platform shortcuts (⌘ on macOS, Ctrl elsewhere). |
| `->checked(bool $value = true)` | Renders the item with a checkmark prefix, for toggle-style entries. |

Action conventions (same for tray menus and menu bars):

| Action | Behavior |
|--------|----------|
| `/path` | Navigates the app to that route |
| `exit` | Quits the application |
| `show` / `hide` | Shows / hides the main window (useful from a tray menu) |
| Custom string | Forwarded to your `wire:nb-bridge` handler or `#[On('nb:menu')]` listener |

