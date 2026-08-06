---
title: "Process & Window"
description: "Exit, restart, and window controls."
---

# Process

Backed by [`tauri-plugin-process`](https://v2.tauri.app/plugin/process/). Quits the application.

**Blade:**
```blade
<button wire:nb-bridge="exit">Quit</button>
```

**PHP:**
```php
return NativeBlade::exit()->toResponse();
```

---

## Window Controls

Control the main window (desktop only, mobile platforms ignore these). Backed by `@tauri-apps/api/window`.

**Blade:**
```blade
<button wire:nb-bridge="minimize">_</button>
<button wire:nb-bridge="toggle_maximize">⬜</button>
<button wire:nb-bridge="hide">Hide</button>
```

**PHP:**
```php
return NativeBlade::minimize()->toResponse();
return NativeBlade::maximize()->toResponse();
return NativeBlade::unmaximize()->toResponse();
return NativeBlade::toggleMaximize()->toResponse();
return NativeBlade::hide()->toResponse();
return NativeBlade::show()->toResponse();
return NativeBlade::focus()->toResponse();
```

| Method | Description |
|---|---|
| `minimize()` | Minimize the window to the taskbar / dock |
| `maximize()` | Maximize the window to fill the screen |
| `unmaximize()` | Restore from maximized state |
| `toggleMaximize()` | Toggle between maximized and restored |
| `hide()` | Hide the window without quitting (process keeps running). Useful with tray + `Tray::hideOnClose()` |
| `show()` | Re-show a hidden window. Typical pair to `hide()` from a tray menu item |
| `focus()` | Bring the window to the front: restores it if minimized, un-hides it from the tray, and gives it keyboard focus. The "summon the app" combo |

Chain with other actions when you want a side-effect after work completes:

```php
return NativeBlade::notification(fn (Notification $n) => $n->title('Done'))
    ->toggleMaximize()
    ->toResponse();
```

The hide / show pair is what enables the "minimize to tray" pattern. Configure the tray with `Tray::hideOnClose()` (see [Menus & Tray](/desktop/menus-tray/)) so the close button calls `hide()` automatically, and add a `Show` entry in the tray context menu that maps to the `show` action:

```php
use NativeBlade\Config\Menu;
use NativeBlade\Config\Tray;

NativeBladeConfig::desktop(function ($config) {
    $config->tray(function (Tray $t) {
        $t->icon('public/tray.png')
          ->menu(function (Menu $m) {
              $m->item('Show', 'show');
              $m->separator();
              $m->item('Quit', 'exit');
          })
          ->hideOnClose();
    });
});
```

Where `show()` only un-hides the window, `focus()` also restores it from a minimized state and pulls it to the foreground with keyboard focus. It is the one call that brings the app back from anywhere: minimized, hidden in the tray, or just behind another window. That makes it the natural response to a [global shortcut](/desktop/global-shortcuts/), so a hotkey can summon the app even while it sits in the tray:

```php
use Livewire\Attributes\On;

#[On('nb:shortcut')]
public function onShortcut(string $name)
{
    if ($name === 'summon') {
        return NativeBlade::focus()->toResponse();
    }
}
```

On mobile (Android / iOS) all window-control actions are no-ops with a console warning. Hide your window-chrome buttons on mobile via `NativeBlade::isDesktop()`.

---

