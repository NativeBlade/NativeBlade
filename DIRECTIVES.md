# Livewire Directives

NativeBlade extends Livewire with custom directives. No `onclick` or `__nbBridge` needed — everything is declarative.

## `wire:nb-bridge`

Triggers a native bridge action on click:

```blade
<button wire:nb-bridge="alert" wire:nb-payload='{"message":"Hello!","title":"Alert"}'>Alert</button>
<button wire:nb-bridge="toast" wire:nb-payload='{"message":"Saved!","type":"success"}'>Toast</button>
<button wire:nb-bridge="notification" wire:nb-payload='{"body":"New message"}'>Push</button>
<button wire:nb-bridge="vibrate" wire:nb-payload='{"duration":100}'>Vibrate</button>
<button wire:nb-bridge="scan">Scan QR</button>
<button wire:nb-bridge="clipboard_write" wire:nb-payload='{"text":"Copied!"}'>Copy</button>
<button wire:nb-bridge="open_url" wire:nb-payload='{"url":"https://github.com"}'>Open</button>
<button wire:nb-bridge="showModal">Open Modal</button>
```

## `wire:nb-navigate`

Navigates using NativeBlade's internal history stack:

```blade
<button wire:nb-navigate="/users">Users</button>
<button wire:nb-navigate.replace="/">Home (no back)</button>
```

From PHP:

```php
NativeBlade::navigate('/dashboard')->toResponse();
NativeBlade::navigate('/', replace: true)->toResponse();
```

> Use `wire:nb-navigate` instead of `wire:navigate` — Livewire's built-in navigation doesn't work in WASM.

## Native Actions

| Action | Directive | PHP |
|--------|-----------|-----|
| Alert | `wire:nb-bridge="alert"` | `NativeBlade::alert($msg)` |
| Notification | `wire:nb-bridge="notification"` | `NativeBlade::notification($body)` |
| Confirm | `wire:nb-bridge="confirm"` | — |
| Navigate | `wire:nb-navigate="/path"` | `NativeBlade::navigate($path)` |
| Navigate (replace) | `wire:nb-navigate.replace="/path"` | `NativeBlade::navigate($path, replace: true)` |
| Clipboard copy | `wire:nb-bridge="clipboard_write"` | — |
| Clipboard paste | `wire:nb-bridge="clipboard_read"` | — |
| Geolocation | `wire:nb-bridge="geolocation"` | — |
| Vibrate | `wire:nb-bridge="vibrate"` | — |
| Impact feedback | `wire:nb-bridge="impact"` | — |
| Biometric auth | `wire:nb-bridge="biometric"` | — |
| QR/Barcode scan | `wire:nb-bridge="scan"` | — |
| NFC read | `wire:nb-bridge="nfc_read"` | — |
| Open URL | `wire:nb-bridge="open_url"` | — |
| OS info | `wire:nb-bridge="os_info"` | — |
| Camera | `wire:nb-bridge="camera"` | — |
| Exit app | `wire:nb-bridge="exit"` | — |

## Receiving Results

Bridge actions that return data dispatch Livewire events:

```php
use Livewire\Attributes\On;

#[On('nb:scan')]
public function onScan($result) {
    $this->qrCode = $result['content'] ?? '';
}

#[On('nb:geolocation')]
public function onLocation($position) {
    $this->lat = $position['coords']['latitude'] ?? null;
}

#[On('nb:clipboard')]
public function onClipboard($text) {
    $this->pastedText = $text ?? '';
}

#[On('nb:biometric')]
public function onBiometric($success) {
    $this->authenticated = $success ?? false;
}

#[On('nb:os-info')]
public function onOsInfo($info) {
    $this->osInfo = $info ?? [];
}

#[On('nb:confirm-result')]
public function onConfirm($confirmed) {
    $this->confirmed = $confirmed ?? false;
}
```

## Haptic Feedback

Add `nb-feedback` to any element:

```blade
<button wire:click="save" nb-feedback>Save</button>
```

See [ANIMATIONS.md](ANIMATIONS.md#haptic-feedback) for details.

---

## PHP Attributes

NativeBlade adds a small set of PHP attributes that plug into the Livewire 3 lifecycle. They live in `NativeBlade\Attributes\*` and are used the same way as Livewire's own attributes (`#[Computed]`, `#[Locked]`, `#[Url]`, etc).

### `#[Flash]`

Marks a Livewire property as a **flash value** — one that lives for exactly one request cycle and is automatically reset to its declared default at the start of every subsequent request.

Use this for one-shot messages (e.g. "Exported to Documents!", "File deleted!") that should appear after an action and disappear on the next interaction, without the dev having to manually clear the property in every other method of the component.

**Problem it solves:**

```php
// Without #[Flash] — cleanup boilerplate in every action
public string $exportMessage = '';

public function exportStats()
{
    $this->exportMessage = 'Exported!';
    // ...
}

public function deleteExport()
{
    $this->exportMessage = '';  // ← cleanup
    // ...
}

public function signOut()
{
    $this->exportMessage = '';  // ← cleanup
    // ...
}

public function onPhoto()
{
    $this->exportMessage = '';  // ← cleanup
    // ...
}
```

**With `#[Flash]`:**

```php
use NativeBlade\Attributes\Flash;

#[Flash]
public string $exportMessage = '';

public function exportStats()
{
    $this->exportMessage = 'Exported!';
    // ...
}

public function deleteExport()
{
    // zero cleanup — #[Flash] handles it
}

public function signOut()
{
    // zero cleanup
}

public function onPhoto()
{
    // zero cleanup
}
```

The reset value is inferred from the property's declared default:

```php
#[Flash]
public string $message = '';        // resets to ''

#[Flash]
public array $pendingErrors = [];    // resets to []

#[Flash]
public ?int $count = null;           // resets to null

#[Flash]
public bool $justSaved = false;      // resets to false
```

Properties without a declared default are reset to `null`.

**How it works:**

`#[Flash]` hooks into Livewire 3's `hydrate()` lifecycle, which runs when an incoming request re-hydrates the component from its previous snapshot — **before** the incoming action executes. The property is reset to its default, then the action runs. If the action sets a new flash value, it appears in the immediate re-render. On the next interaction, the cycle repeats and the value is cleared again.

Flash does **not** run on the first mount, so the initial default declared on the property is preserved unchanged.

**Typical pairing with `<x-nativeblade-animate>`:**

```blade
@if($exportMessage)
    <x-nativeblade-animate in="fadeInUp" out="fadeOutUp" dismiss="2.5s"
         class="mt-3 p-3 bg-green-500/10 rounded-xl">
        <p class="text-green-500 text-sm font-bold text-center">
            {{ $exportMessage }}
        </p>
    </x-nativeblade-animate>
@endif
```

The banner animates in on the action that sets the flash, dismisses itself visually after 2.5s, and automatically stops appearing once the user triggers any other action.
