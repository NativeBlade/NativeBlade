# Desktop Windows

Open extra OS windows — floating panels, inspectors, chat tabs — each rendering
a **real Livewire component**. There is still **one runtime**: the main window
owns php-wasm and the database; the extra windows render through it. No second
runtime, no second SQLite. Desktop only.

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

The component is an ordinary Livewire component:

```php
class ChatPanel extends Component
{
    public string $windowId = '';
    public array $messages = [];
    public string $text = '';

    public function mount(string $windowId = ''): void
    {
        $this->windowId = $windowId;      // which window this is
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

`wire:click`, `wire:model`, `wire:submit`, `#[On]` — all work exactly as on a
normal screen. The window is passed its `id` as the `windowId` mount param, so a
component knows which window/conversation it is.

## The `Window` builder

| Method | Effect |
|---|---|
| `id(string)` | Unique handle — targets `closeWindow`/`focusWindow` and scopes events. Required. |
| `component(class)` | The Livewire component the window renders. Required. |
| `size(int $w, int $h)` | Initial size in pixels. |
| `minSize(int $w, int $h)` | Minimum size. |
| `position(int $x, int $y)` | Top-left position in pixels. |
| `alwaysOnTop(bool = true)` | Keep the window above others. |
| `resizable(bool = true)` | Allow resizing (default true). |
| `frameless(bool = true)` | Remove the OS title bar/borders. Default is a normal decorated window. |

## Command a window

Close and focus by `id`. They are chainable actions like any other:

```php
NativeBlade::closeWindow('chat')->toResponse();
NativeBlade::focusWindow('chat')->toResponse();

// several actions, one response:
return NativeBlade::closeWindow('chat')
    ->focusWindow('inspector')
    ->haptics('light')
    ->toResponse();
```

Opening a window whose `id` is already open **focuses it** instead of stacking a
duplicate.

## Multiple windows

Each `id` is an independent window with its own component instance and state —
MSN-style conversation tabs, one window per contact:

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

Messages typed in `ana` stay in `ana`; each window has its own state.

## What a window component can use

A window component is a **focused Livewire component**. It has the full stack
except the shell layer:

**Works:**
- Livewire — `wire:click`, `wire:model`, `wire:submit`, `#[On]`, computed props.
- **Database, filesystem, HTTP** — Eloquent, `Storage::disk('native')`,
  `Http::get()`. The native work runs on the main window's runtime, so a window
  component uses them exactly like a normal screen.
- Native actions — `NativeBlade::dialog()`, `haptics()`, `share()`, etc. run in
  that window's own context (a dialog attaches to the window the user is on).
- `NativeBlade::jsEvent()` for your own `public/js` page scripts (below).

**Does not work — by design:**
- **Shell chrome** (`x-nativeblade-header`, bottom-nav, drawer, modal) — these
  belong to the main window and don't fit a panel.
- **Shell modules** (`HasNativeShell` / `#[NativeProp]`) — those bind JS to the
  window that owns the runtime; a satellite doesn't. Use plain Livewire props.

## Page JavaScript in a window

A window renders its component the same way a normal screen does, so its own
front-end JavaScript follows the same convention: a **`public/js` script** —
classic scripts split by responsibility, wired through one namespace global, one
`<script src>` tag per file. See [ARCHITECTURE.md](ARCHITECTURE.md) for the rules
(no `import`/`export` in `public/js`; those belong to `nativeblade-components/`).

Talk to it with `NativeBlade::jsEvent()` (PHP → page, arrives as a
`nb:js:{event}` DOM event) and `wire:click` (page → PHP):

```php
// PHP → the window's JavaScript
return NativeBlade::jsEvent('map-center', ['lat' => -23.5, 'lng' => -46.6])->toResponse();
```

```blade
{{-- the component's view --}}
<div wire:ignore id="map" style="height:100%"></div>
<script src="/js/map/main.js"></script>
```

```js
// public/js/map/main.js
window.addEventListener('nb:js:map-center', (e) => centerMap(e.detail.lat, e.detail.lng));
```

`wire:ignore` keeps Livewire from wiping the JS-managed element on morph. Each
window loads its own copy of the script (independent instances).

## How it works (one paragraph)

The extra window loads the same frontend but boots in **relay mode**: it renders
your component but has no php-wasm. Its Livewire requests are relayed over IPC to
the main window, serviced by the single runtime, and the result is sent back —
Livewire morphs locally, unaware the response crossed a window boundary. That is
why there is one runtime and one database no matter how many windows you open.

## Notes

- **Desktop only.** Mobile has no OS multi-window; the call is a no-op there.
- **Closing the main window quits the app**, so its extra windows close with it —
  they are children of the runtime that feeds them.
- Human-paced use is smooth. Because there is one runtime, requests are
  serialized; avoid designs where many windows hammer it at the same instant.
