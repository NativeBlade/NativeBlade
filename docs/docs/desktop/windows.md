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
| `position(int $x, int $y)` | Top-left position in exact pixels. |
| `position(string $anchor, margin: int)` | Anchored position the framework resolves against the monitor's work area at open time, so PHP never measures the screen. Anchors: `center`, `top-left`, `top-center`, `top-right`, `bottom-left`, `bottom-center`, `bottom-right`. Needs `size()`; `margin` insets from the anchored edges. |
| `alwaysOnTop(bool = true)` | Keep the window above others. |
| `resizable(bool = true)` | Allow resizing. Defaults to true. |
| `frameless(bool = true)` | Remove the OS title bar and borders. Default is a normal decorated window. |
| `transparent(bool = true)` | Make the window background transparent so only what the component paints shows. The satellite's document is made transparent to match, so give your component its own background. On macOS this needs `app.macOSPrivateApi: true` in `tauri.conf.json`. |
| `shadow(bool = true)` | OS window shadow. On by default; pass `false` for a frameless/transparent overlay so the square window shadow does not show around the component's rounded corners. Windows and macOS; ignored on Linux. |

A satellite never shows the app's boot splash: it has no runtime to wait for, so
the window opens straight into its component. Pair `frameless()` + `transparent()`
for an overlay or bar that appears with no chrome and no flash.

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

### Local assets are inlined for you

A window runs in an origin-null iframe with no file server, so a `<link>` or
`<script>` pointing at a real URL would fail. NativeBlade inlines your local
`public/` assets into the page before the window renders it, exactly as it does
for the main window. Both forms resolve to the bundled file:

```blade
<link rel="stylesheet" href="/css/panel.css">
<link rel="stylesheet" href="{{ asset('css/panel.css') }}">
<script src="/js/panel.js"></script>
<script src="{{ asset('js/panel.js') }}"></script>
```

Stylesheets (`rel="stylesheet"`), scripts, and `<img>` under `public/` are inlined
automatically, including files in subdirectories and `url(...)` references inside
your CSS. You do not need to inline anything by hand. This applies to files in the
bundle; a truly external URL (a CDN) is left as-is and still needs a network.

### Assets inside a component: mark them with `wire:nb-asset`

Inlining happens on the initial render. A Livewire **update** returns JSON that
skips it, so if an asset tag lives inside your component (not in the `<head>`), the
morph re-inserts the original network `<link>` / `<script>` and it fails with
`ERR_CONNECTION_REFUSED` after the first interaction. In a window the component is
the whole page, so its stylesheets and scripts sit in the body and hit exactly
this.

Mark those tags with `wire:nb-asset`. The inlined asset then persists across
updates instead of reverting to a network request, and the original tag still
travels as a few bytes, so nothing bloats your per-update payload:

```blade
<link wire:nb-asset rel="stylesheet" href="{{ asset('css/ds.css') }}">
<script wire:nb-asset src="{{ asset('js/panel.js') }}"></script>
```

You only need this for assets rendered inside a component. In the main window,
stylesheets in the layout `<head>` are outside every component and are never
re-morphed, so they do not need the marker.

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
