---
title: "Action Response"
description: "The NativeBlade facade: build native actions and return them from a Livewire component."
---

# Action Response

The `NativeBlade` facade builds native actions and returns them from a Livewire component. Actions are chainable and flushed with `->toResponse()`.

## How Bridges Work

There are two equivalent ways to invoke any built-in plugin:

### 1. From Blade (user-triggered)

```blade
<button wire:nb-bridge="action" wire:nb-payload='{"key":"value"}'>
    Click me
</button>
```

Fires on tap without a Livewire round-trip. Best for pure UI actions.

### 2. From PHP (programmatic)

```php
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Notification;

public function save()
{
    // ... business logic
    return NativeBlade::notification(function (Notification $n) {
        $n->title('Saved!')->body('Your changes are safe.');
    })->toResponse();
}
```

The facade returns a `NativeResponse`. From a Livewire component action you must call `->toResponse()` on it to dispatch the native actions; returning it bare does **not** dispatch. (Inside a push or deep-link handler you return the bare `NativeResponse` instead, because the internal route calls `->toResponse()` for you.) Every method is **chainable**, so you can queue multiple actions into one response and call `->toResponse()` once at the end:

```php
return NativeBlade::alert(fn (Dialog $d) => $d->title('Hey')->message('Welcome back!'))
    ->notification(fn (Notification $n) => $n->title('Heads up')->body('3 new lessons available'))
    ->navigate('/dashboard')
    ->transition('fade')
    ->toResponse();
```

Both paths end up in the same JavaScript dispatcher (`js/wasm-app/bridge.js`) and call the same native API. The difference is purely where the trigger comes from.

---

## The `NativeBlade` Facade

The `NativeBlade\Facades\NativeBlade` facade is the primary PHP entry point for native actions, state, and platform detection.

### Native action builders

Every action below is available both as a direct call on the facade *and* as a chained method on an existing response:

```php
// Direct (starts a new response):
NativeBlade::vibrate(200);

// Chained (appends to an existing response):
return NativeBlade::notification(fn (Notification $n) => $n->title('Saved')->body('Changes persisted'))
    ->vibrate(200)
    ->toResponse();
```

| Category | Methods |
|---|---|
| Dialogs | `alert(Closure)`, `confirm(Closure)` |
| Notifications | `notification(Closure)`, `scheduleNotification(Closure)` |
| Clipboard | `clipboardWrite($text)`, `clipboardRead(?Closure)` |
| Geolocation | `geolocation(?Closure)` |
| Haptics | `vibrate($ms = 100)`, `impact($style = 'medium')`, `selection()` |
| Biometric | `biometric(Closure)` |
| Barcode | `scan(?Closure)` |
| NFC | `nfcRead(?Closure)` |
| Opener | `openUrl($url)`, `openFile($path)` |
| OS | `osInfo()` |
| In-App Review | `requestReview()` |
| Secure Storage | `setSecure($key, $value)`, `getSecure($key, $id = null)`, `forgetSecure($key)` |
| Sharing | `share($text = null, $url = null)` |
| Analytics | `analytics(Closure)` |
| AdMob | `requestAdConsent(array $testDeviceIds = [])`, `rewardedAd(Closure)`, `interstitialAd(Closure)` |
| Payments | `products(array $productIds)`, `purchase(Closure)`, `restorePurchases()`, `subscriptionStatus(array $productIds = [])` |
| Camera | `camera(?Closure)`, `gallery(?Closure)` |
| Navigation | `navigate($path, $replace = false)` |
| Modal | `showModal()`, `hideModal()` |
| Shell | `shell(Closure)` (desktop only) |
| Process | `exit()` |

All closure-based builders live in `NativeBlade\Plugins\*` (`Dialog`, `Notification`, `Camera`, `Biometric`, `Scan`, `Geolocation`, `Clipboard`, `Nfc`). Builders marked with `?Closure` (nullable) let you omit the closure when you don't need to configure anything, useful for the simple `NativeBlade::geolocation()` / `NativeBlade::scan()` case.

### Modifiers (attach to the last action)

After any action, chain any of these to customize it:

| Modifier | Applies to |
|---|---|
| `transition($type)` | navigate, `'none' \| 'slide' \| 'fade'` |
| `replace($bool = true)` | navigate |

> All other per-action options live inside their dedicated `Plugins\*` builder closures, `Dialog`, `Notification`, `Camera`, `Biometric`, `Scan`, `Geolocation`, `Clipboard`, `Nfc`. The `NativeResponse` itself only keeps modifiers that affect the action queue (`transition`, `replace`).

## Receiving Results in PHP

Bridges that return data post the result as a Livewire event. Listen with the `#[On]` attribute:

```php
use Livewire\Attributes\On;

class MyComponent extends Component
{
    #[On('nb:confirm-result')]
    public function onConfirm($confirmed, $id = null) { /* ... */ }

    #[On('nb:clipboard')]
    public function onPaste($text, $id = null) { /* ... */ }

    #[On('nb:geolocation')]
    public function onLocation($position, $id = null) { /* ... */ }

    #[On('nb:biometric')]
    public function onBiometric($success, $error = null, $id = null) { /* ... */ }

    #[On('nb:scan')]
    public function onScan($result, $id = null) { /* ... */ }

    #[On('nb:nfc')]
    public function onNfcTag($tag, $id = null) { /* ... */ }

    #[On('nb:os-info')]
    public function onOsInfo($info) { /* ... */ }

    #[On('nb:camera-result')]
    public function onPhoto($data = null, $name = null, $mime = null, $size = null, $id = null) { /* ... */ }

    #[On('nb:secure')]
    public function onSecure($value = null, $id = null) { /* ... */ }

    #[On('nb:shell-result')]
    public function onShellResult($stdout = null, $stderr = null, $exitCode = null, $id = null) { /* ... */ }
}
```

Event names are always `nb:<name>`. They are automatically dispatched from the JS bridge via `Livewire.dispatch()` inside the iframe.

**Every result-bearing bridge supports an optional `$id` argument as the last listener parameter.** When a component has multiple calls to the same bridge (e.g. two cameras, three confirms, several geolocations), set `->id('unique_tag')` inside the builder closure, or add `"id":"unique_tag"` to the Blade `wire:nb-payload` JSON, and the same tag comes back on the listener. Use `match ($id) { ... }` to route the response. When you only have one call per component, skip the id and the argument arrives as `null`.

---

