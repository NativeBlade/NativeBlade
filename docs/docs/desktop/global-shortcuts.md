---
title: "Global Shortcuts"
description: "System-wide keyboard shortcuts on the desktop."
---

# Global Shortcuts

Register system-wide keyboard shortcuts that fire even when your app is not the
focused window. Each press dispatches the `nb:shortcut` Livewire event, so you
react to it in PHP like any other event.

This is a desktop-only feature (Windows, macOS, Linux). It is a no-op on mobile
and in the browser preview.

::: callout warning "Requires Plugin::GLOBAL_SHORTCUT"
Declare it in your `AppServiceProvider`, or the shortcuts are never registered:

```php
use NativeBlade\Facades\NativeBladeConfig;
use NativeBlade\Config\Plugin;

NativeBladeConfig::plugins([
    Plugin::GLOBAL_SHORTCUT,
    // ...your other plugins
]);
```
:::

## Declare the shortcuts

Map each accelerator to a name. The name is what you receive when the shortcut
fires, so keep it stable and meaningful:

```php
NativeBladeConfig::globalShortcuts([
    'CmdOrCtrl+Shift+K' => 'search',
    'CmdOrCtrl+Shift+P' => 'palette',
    'CmdOrCtrl+,'       => 'preferences',
]);
```

The value (`'search'`, `'palette'`, ...) is a label **you** choose, not a command
or a method name. It is delivered to your handler so you know which shortcut fired
and can decide what to do. Naming it separately from the keys lets you rebind the
key later without touching your handler.

Run `php artisan nativeblade:config` so the shortcuts are published to the runtime
config, then rebuild or restart `nativeblade:dev`.

### Accelerator format

An accelerator is zero or more modifiers joined with `+`, ending in exactly one
key, e.g. `CmdOrCtrl+Shift+K`, `Alt+Space`, `F11`. It is case-insensitive.

**Modifiers** (write one accelerator, it adapts per OS):

| Token(s) | Windows / Linux | macOS |
|---|---|---|
| `CmdOrCtrl` (also `CommandOrControl`) | Ctrl | Cmd |
| `Ctrl` (also `Control`) | Ctrl | Ctrl |
| `Alt` (also `Option`) | Alt | Option |
| `Shift` | Shift | Shift |
| `Super` (also `Cmd`, `Command`) | Windows key | Cmd |

`CmdOrCtrl` is the portable one: Cmd on macOS, Ctrl on Windows/Linux, so you write
the shortcut once.

**Keys** (pick exactly one):

| Group | Keys |
|---|---|
| Letters | `A` - `Z` |
| Digits | `0` - `9` |
| Function | `F1` - `F24` |
| Editing / whitespace | `Space`, `Tab`, `Enter`, `Escape` (`Esc`), `Backspace`, `Delete`, `Insert` |
| Navigation | `Up`, `Down`, `Left`, `Right`, `Home`, `End`, `PageUp`, `PageDown` |
| Locks / system | `CapsLock`, `NumLock`, `ScrollLock`, `PrintScreen`, `Pause` |
| Punctuation | `` ` ``  `-`  `=`  `[`  `]`  `\`  `;`  `'`  `,`  `.`  `/` |
| Numpad | `Num0` - `Num9`, `NumAdd`, `NumSubtract`, `NumMultiply`, `NumDivide`, `NumDecimal`, `NumEnter`, `NumEqual` |
| Media | `VolumeUp`, `VolumeDown`, `VolumeMute`, `MediaPlayPause`, `MediaPlay`, `MediaPause`, `MediaStop`, `MediaTrackNext`, `MediaTrackPrevious` |

If you prefer the longer W3C names they also work (`KeyK` for `K`, `ArrowUp` for
`Up`, `Comma` for `,`, `Numpad0` for `Num0`), but the short forms above are all
you need.

## React in PHP

Every press fires the `nb:shortcut` Livewire event with the shortcut's `name`
(and its `accelerator`). Handle it on any component with `#[On]`:

```php
use Livewire\Attributes\On;

#[On('nb:shortcut')]
public function onShortcut(string $name)
{
    match ($name) {
        'search'      => $this->openSearch(),
        'palette'     => NativeBlade::navigate('/command-palette')->toResponse(),
        'preferences' => $this->showPreferences = true,
        default       => null,
    };
}
```

What the shortcut does is entirely up to you: open a satellite window, navigate,
toggle a panel, run a command. The framework only delivers the event.

## Change them at runtime

The declared set is registered at boot, but you can add or remove shortcuts on
demand from a component, returned as a native action:

```php
use NativeBlade\Facades\NativeBlade;

// Add one (re-registering the same accelerator replaces its binding)
return NativeBlade::registerShortcut('CmdOrCtrl+J', 'jump')->toResponse();

// Remove one
return NativeBlade::unregisterShortcut('CmdOrCtrl+J')->toResponse();

// Remove all of them (declared and runtime)
return NativeBlade::unregisterAllShortcuts()->toResponse();
```

Runtime shortcuts fire the same `nb:shortcut` event with their `name`, so your
`#[On('nb:shortcut')]` handler treats them identically. They are registered with
the OS (not the page), so they persist across navigation until you unregister
them. Desktop only, same `Plugin::GLOBAL_SHORTCUT` requirement, a no-op on mobile.

## How it works

On boot, the desktop shell reads your shortcut map and registers each accelerator
with the OS through Tauri's global-shortcut plugin. When the user presses one,
anywhere in the system, the press is forwarded into your app and re-dispatched as
the `nb:shortcut` Livewire event. On mobile the plugin does not exist, so the
whole step is skipped and your `#[On('nb:shortcut')]` handler simply never fires.
