# Navigation

NativeBlade has a SPA-style router that swaps Livewire pages inside the WebView without reloading the PHP runtime. There are two supported ways to trigger a navigation: the `wire:nb-navigate` directive in Blade, and `NativeBlade::navigate()` from PHP. Both call the same router under the hood.

## Quick reference

| Form | When to use |
|---|---|
| `wire:nb-navigate="/path"` | Buttons / components that navigate on click |
| `wire:nb-navigate.replace="/path"` | Same, but replaces history entry (no back arrow) |
| `wire:nb-navigate.none="/path"` | Instant, no animation |
| `wire:nb-navigate.slide="/path"` | Slide transition |
| `wire:nb-navigate.fade="/path"` | Fade transition |
| `wire:nb-navigate.slide.replace="/path"` | Combine modifiers |
| `NativeBlade::navigate('/path')` | From PHP (after a form submit, login check, etc.) |
| `NativeBlade::navigate('/path', replace: true)` | History replace from PHP |

## `wire:nb-navigate` — the directive

Attach to any clickable element. The expression is the destination path:

```blade
<button wire:nb-navigate="/dashboard">Dashboard</button>
<div wire:nb-navigate="/settings" role="button">Settings</div>
```

### Modifiers

You can stack any combination after `wire:nb-navigate.`:

```blade
<button wire:nb-navigate.replace="/">Home</button>
<button wire:nb-navigate.none="/login">Login (no animation)</button>
<button wire:nb-navigate.slide="/profile">Slide to profile</button>
<button wire:nb-navigate.fade="/dashboard">Fade in dashboard</button>
<button wire:nb-navigate.slide.replace="/login">Slide and replace history</button>
<button wire:nb-navigate.none.replace="/">Instant, no animation, no back arrow</button>
```

Order of the modifiers doesn't matter. `.slide.replace` and `.replace.slide` are equivalent.

### Available transition modifiers

| Modifier | Effect |
|---|---|
| `.none` | Instant swap, no animation |
| `.slide` | Slide horizontally (default direction depends on history direction) |
| `.fade` | Cross-fade between pages |

If no transition modifier is set, the router uses the **global default** (configured in `AppServiceProvider`, see below).

> Page transitions are limited to these three on purpose. Each one is a coordinated dual-iframe choreography in the router (the new page enters while the old one exits with matching transforms). For richer effects, animate **individual elements** inside the page with `nb-animation` or `<x-nativeblade-animate>` (90+ Animate.css names — see [ANIMATIONS.md](ANIMATIONS.md)).

> Unknown modifiers on the directive (e.g. `wire:nb-navigate.flip`) are silently ignored and fall back to the global default. The PHP equivalent is stricter and **throws** `InvalidArgumentException` — see below.

## From PHP

Use `NativeBlade::navigate()` after a form submit, a Livewire `wire:click`, an authentication check, etc.

```php
use NativeBlade\Facades\NativeBlade;

public function save()
{
    $this->validate();
    Item::create($this->state);
    return NativeBlade::navigate('/items')->toResponse();
}

public function login()
{
    // ... auth check ...
    return NativeBlade::navigate('/', replace: true)->toResponse();
}
```

### Chained modifiers from PHP

`navigate()` returns a `NativeResponse` you can chain on:

```php
return NativeBlade::navigate('/profile')
    ->transition('slide')
    ->toResponse();

return NativeBlade::navigate('/')
    ->replace()
    ->transition('none')
    ->toResponse();

return NativeBlade::notification(fn ($n) => $n->title('Saved'))
    ->navigate('/items', replace: true)
    ->toResponse();
```

The `replace` modifier behaves the same as `replace: true` in the constructor — pick whichever reads cleaner.

`->transition()` validates its argument. Passing anything other than `'none'`, `'slide'`, or `'fade'` throws `InvalidArgumentException` at the point of the call, so typos and stale code paths surface immediately instead of silently falling back to the global default. Same goes for `NativeBladeConfig::transition()`.

## Global default transition

Set once in `AppServiceProvider::boot()`. Applied to every navigation that doesn't specify a transition modifier:

```php
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::transition('slide');
```

Without this call, the default is `none` (instant).

## Order of precedence

When multiple sources specify a transition, the router picks them in this order, first wins:

1. **Per-call** — `wire:nb-navigate.slide` or `->transition('slide')`
2. **Global default** — `NativeBladeConfig::transition('slide')`
3. **Hard default** — `none`

So you can set `slide` as the global default and override with `.none` on individual links that should be instant. Or set `none` globally and use `.slide` on the few that should animate.

## Recipes

### Login flow that doesn't let the user "back" to the form

```php
public function login()
{
    if ($this->valid()) {
        NativeBlade::setState('auth.user', $user);
        return NativeBlade::navigate('/', replace: true)->toResponse();
    }
}
```

```blade
<button wire:nb-navigate.replace="/login">Sign in</button>
```

The `replace` rewrites the current history entry so pressing back doesn't return to the login screen.

### "Settings" tab that should feel instant inside a tabbed layout

```blade
<button wire:nb-navigate.none.replace="/tab/settings">Settings</button>
```

`.none` removes animation (so the tab doesn't slide in like a page), `.replace` so the back arrow doesn't pile up tab swaps.

### Modal-like screen that should slide from the right

Set globally:

```php
NativeBladeConfig::transition('slide');
```

Then plain `wire:nb-navigate="/payment-sheet"` slides in. Use `.none` for any non-modal-y nav (tabs, settings list rows, etc.).

### Conditional redirect after biometric login

```php
#[On('nb:biometric')]
public function onBiometric($success, $error = null, $id = null)
{
    if (!$success) {
        $this->error = $error;
        return;
    }

    return NativeBlade::navigate('/', replace: true)
        ->transition('fade')
        ->toResponse();
}
```

## What NOT to do

| Pattern | Why to avoid |
|---|---|
| `<a href="/path">` | Even though the router intercepts clicks on internal anchors, this is implicit behavior with edge cases (external links, fragment jumps, `javascript:`). Use `wire:nb-navigate` for clarity and predictability. |
| `window.location.href = '/path'` | Causes a hard reload of the WebView, restarting the PHP runtime. Always go through the directive or the facade. |
| `window.history.pushState(...)` | Bypasses the NativeBlade router entirely. Page swap won't happen. |
