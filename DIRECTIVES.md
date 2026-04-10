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
