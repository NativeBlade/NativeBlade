---
title: "Windows"
description: "Open extra OS windows on desktop, each rendering a real Livewire component driven by the app's single runtime."
---

# Windows

Open extra OS windows such as floating panels, inspectors, or chat tabs, each
rendering a **real Livewire component**. There is still **one runtime**: the main
window owns php-wasm and the database, and the extra windows render through it.
No second runtime, no second SQLite.

::: callout warning "Desktop only"
Mobile has no OS multi-window, so the call is a no-op there. Everything on this
page targets Windows, macOS, and Linux.
:::

## Open a window

`NativeBlade::window()` takes a closure that configures the window, and renders
the component you point it at:

```php
use App\Livewire\ChatPanel;
use NativeBlade\Config\Window;
use NativeBlade\Facades\NativeBlade;

return NativeBlade::window(function (Window $w) {
    $w->id('chat')
      ->component(ChatPanel::class)
      ->size(380, 560)
      ->position(200, 160)
      ->alwaysOnTop();
})->toResponse();
```

The component is an ordinary Livewire component. It receives its window `id` as
the `windowId` mount parameter:

```php
class ChatPanel extends Component
{
    public string $windowId = '';
    public array $messages = [];
    public string $text = '';

    public function mount(string $windowId = ''): void
    {
        $this->windowId = $windowId; // which window this is
    }

    public function send(): void
    {
        $this->messages[] = $this->text;
        $this->text = '';
    }

    public function render()
    {
        return view('livewire.chat-panel');
    }
}
```

`wire:click`, `wire:model`, `wire:submit`, and `#[On]` all work exactly as on a
normal screen.

## The Window builder

| Method | Effect |
|---|---|
| `id(string)` | Unique handle that targets `closeWindow` / `focusWindow` and scopes events. Required. |
| `component(class)` | The Livewire component the window renders. Required. |
| `size(int $w, int $h)` | Initial size in pixels. |
| `minSize(int $w, int $h)` | Minimum size. |
| `position(int $x, int $y)` | Top-left position in pixels. |
| `alwaysOnTop(bool = true)` | Keep the window above others. |
| `resizable(bool = true)` | Allow resizing. Defaults to true. |
| `frameless(bool = true)` | Remove the OS title bar and borders. Default is a normal decorated window. |

## Command a window

Close and focus by `id`. They are chainable actions like any other:

```php
return NativeBlade::closeWindow('chat')
    ->focusWindow('inspector')
    ->haptics('light')
    ->toResponse();
```

Opening a window whose `id` is already open **focuses it** instead of stacking a
duplicate.

## Multiple windows

Each `id` is an independent window with its own component instance and state.
Think MSN-style conversation tabs, one window per contact:

```php
public array $contacts = ['ana', 'bruno', 'carla'];

public function openChat(string $name)
{
    return NativeBlade::window(fn (Window $w) => $w
        ->id($name)
        ->component(ChatPanel::class)
        ->size(340, 460)
    )->toResponse();
}

public function focusChat(string $name) { return NativeBlade::focusWindow($name)->toResponse(); }
public function closeChat(string $name) { return NativeBlade::closeWindow($name)->toResponse(); }
```

Messages typed in `ana` stay in `ana`. Each window has its own state.

## What a window component can use

A window component is a **focused Livewire component**. It has the full stack
except the shell layer.

::: card "Works"
- Livewire: `wire:click`, `wire:model`, `wire:submit`, `#[On]`, computed props.
- Database, filesystem, and HTTP: Eloquent, `Storage`, `Http::get()`. The native
  work runs on the main window's runtime, exactly like a normal screen.
- Native actions: `dialog()`, `haptics()`, `share()` run in that window's context.
- `NativeBlade::jsEvent()` for your own `public/js` page scripts.
:::

::: card "Does not, by design"
- Shell chrome (header, bottom-nav, drawer, modal) belongs to the main window.
- Shell modules (`HasNativeShell`, `#[NativeProp]`) bind JS to the window that
  owns the runtime, which a satellite does not. Use plain Livewire props.
:::

## Page JavaScript in a window

A window renders its component like a normal screen, so its own front-end
JavaScript follows the same convention: a **`public/js` script** (classic
scripts, no `import` / `export`, which belong to `nativeblade-components/`). Talk
to it with `NativeBlade::jsEvent()` (PHP to page) and `wire:click` (page to PHP):

```php
return NativeBlade::jsEvent('map-center', ['lat' => -23.5, 'lng' => -46.6])->toResponse();
```

```blade
<div wire:ignore id="map" style="height:100%"></div>
<script src="/js/map/main.js"></script>
```

```js
// public/js/map/main.js
window.addEventListener('nb:js:map-center', (e) => centerMap(e.detail.lat, e.detail.lng));
```

`wire:ignore` keeps Livewire from wiping the JS-managed element on morph. Each
window loads its own copy of the script.

## How it works

The extra window loads the same frontend but boots in **relay mode**. It renders
your component but has no php-wasm. Its Livewire requests are relayed over IPC to
the main window, serviced by the single runtime, and the result is sent back.
Livewire morphs locally, unaware the response crossed a window boundary. That is
why there is one runtime and one database no matter how many windows you open.

::: callout note "Lifecycle"
Closing the main window closes every extra window with it. They render through
the main window's runtime, so the framework shuts them down rather than leave
them frozen against a runtime that is gone.
:::
