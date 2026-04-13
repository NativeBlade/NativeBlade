# Native Plugins

NativeBlade ships with every major Tauri 2 plugin pre-registered, pre-permissioned, and bridged into PHP. You don't need to touch Rust, edit capabilities, or write JavaScript — just call the bridge from your Livewire component or Blade template.

This document lists every built-in bridge, what it does, and how to call it from both the **Blade directive side** (`wire:nb-bridge`) and the **PHP side** (`NativeBlade` facade).

## Table of Contents

- [How Bridges Work](#how-bridges-work)
- [The `NativeBlade` Facade](#the-nativeblade-facade)
- [Dialogs](#dialogs)
- [Notifications](#notifications)
- [Clipboard](#clipboard)
- [Geolocation](#geolocation)
- [Haptics](#haptics)
- [Biometric](#biometric)
- [Barcode Scanner](#barcode-scanner)
- [NFC](#nfc)
- [Opener](#opener)
- [OS Info](#os-info)
- [Camera & Gallery](#camera--gallery)
- [Navigation](#navigation)
- [Modal](#modal)
- [Process](#process)
- [Receiving Results in PHP](#receiving-results-in-php)
- [Adding Your Own Bridge](#adding-your-own-bridge)

---

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
    });
}
```

The facade returns a `NativeResponse` that is Responsable — return it directly from a Livewire action or controller and it dispatches the native actions. Every method is **chainable**, so you can queue multiple actions into a single response:

```php
return NativeBlade::alert(fn (Dialog $d) => $d->title('Hey')->message('Welcome back!'))
    ->notification(fn (Notification $n) => $n->title('Heads up')->body('3 new lessons available'))
    ->navigate('/dashboard')
    ->transition('fade');
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
    ->vibrate(200);
```

| Category | Methods |
|---|---|
| Dialogs | `alert(Closure)`, `confirm(Closure)` |
| Notifications | `notification(Closure)` |
| Clipboard | `clipboardWrite($text)`, `clipboardRead(?Closure)` |
| Geolocation | `geolocation(?Closure)` |
| Haptics | `vibrate($ms = 100)`, `impact($style = 'medium')`, `selection()` |
| Biometric | `biometric(Closure)` |
| Barcode | `scan(?Closure)` |
| NFC | `nfcRead(?Closure)` |
| Opener | `openUrl($url)`, `openFile($path)` |
| OS | `osInfo()` |
| Camera | `camera(?Closure)`, `gallery(?Closure)` |
| Navigation | `navigate($path, $replace = false)` |
| Modal | `showModal()`, `hideModal()` |
| Process | `exit()` |

All closure-based builders live in `NativeBlade\Plugins\*` (`Dialog`, `Notification`, `Camera`, `Biometric`, `Scan`, `Geolocation`, `Clipboard`, `Nfc`). Builders marked with `?Closure` (nullable) let you omit the closure when you don't need to configure anything — useful for the simple `NativeBlade::geolocation()` / `NativeBlade::scan()` case.

### Modifiers (attach to the last action)

After any action, chain any of these to customize it:

| Modifier | Applies to |
|---|---|
| `transition($type)` | navigate — `'slide' \| 'fade' \| 'zoom' \| 'flip' \| 'bounce' \| 'blur'` |
| `replace($bool = true)` | navigate |

> All other per-action options live inside their dedicated `Plugins\*` builder closures — `Dialog`, `Notification`, `Camera`, `Biometric`, `Scan`, `Geolocation`, `Clipboard`, `Nfc`. The `NativeResponse` itself only keeps modifiers that affect the action queue (`transition`, `replace`).

### Persistent state

Native-backed key/value store that survives app restarts:

```php
NativeBlade::setState('user_prefs', ['theme' => 'dark'], scope: 'persistent');
$prefs = NativeBlade::getState('user_prefs', default: []);
NativeBlade::forget('user_prefs');
NativeBlade::flush(scope: 'persistent');

// Batched writes for performance:
NativeBlade::pool(function () {
    NativeBlade::setState('key1', 'value1');
    NativeBlade::setState('key2', 'value2');
});
```

See [LIFECYCLE.md](./LIFECYCLE.md) for how state interacts with the boot sequence.

### Platform detection

```php
NativeBlade::platform();  // 'windows' | 'macos' | 'linux' | 'android' | 'ios' | 'web'

NativeBlade::isDesktop(); // windows || macos || linux
NativeBlade::isMobile();  // android || ios
NativeBlade::isAndroid();
NativeBlade::isIos();
NativeBlade::isWindows();
NativeBlade::isMacos();
NativeBlade::isLinux();
NativeBlade::isWeb();     // running outside the Tauri shell
```

Typical usage:

```php
public function mount()
{
    if (NativeBlade::isMobile()) {
        $this->layout = 'mobile';
    }

    if (NativeBlade::isWeb()) {
        abort(404); // feature only available in the native app
    }
}
```

---

## Dialogs

Backed by [`tauri-plugin-dialog`](https://v2.tauri.app/plugin/dialog/).

Both `alert` and `confirm` are configured through the same `Dialog` builder passed as a closure. This keeps all dialog-specific options (title, message, kind, button labels) together and out of the generic modifier chain.

The `Dialog` builder supports:

| Method | Description |
|---|---|
| `->title($text)` | Title shown above the message |
| `->message($text)` | Main body text of the dialog |
| `->kind($level)` | `'info'`, `'warning'` or `'error'` — affects icon/color |
| `->confirmLabel($text)` | Override the OK / confirm button label |
| `->cancelLabel($text)` | Override the Cancel button label (confirm only) |
| `->id($identifier)` | Tag the dialog so its result can be routed (see below) |

### alert

Native alert dialog with a single OK button.

**Blade:**
```blade
<button wire:nb-bridge="alert" wire:nb-payload='{"title":"Heads up","message":"Your session will expire soon","kind":"warning"}'>
    Show alert
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Dialog;

return NativeBlade::alert(function (Dialog $d) {
    $d->title('Heads up')
      ->message('Your session will expire soon')
      ->kind('warning');
});
```

### confirm

Native confirmation dialog with OK/Cancel buttons. The user's choice is delivered via the `nb:confirm-result` Livewire event.

**Blade:**
```blade
<button wire:nb-bridge="confirm" wire:nb-payload='{"title":"Delete?","message":"This cannot be undone"}'>
    Delete
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Dialog;

return NativeBlade::confirm(function (Dialog $d) {
    $d->title('Delete?')
      ->message('This cannot be undone')
      ->kind('warning')
      ->confirmLabel('Delete')
      ->cancelLabel('Keep');
});
```

### Handling multiple confirms in the same component

When a component has more than one confirm dialog (e.g. a delete button **and** a sign out button), tag each one with `->id()` and route the result in a single listener. The id is echoed back in the `nb:confirm-result` event:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Dialog;

public function deleteExport()
{
    return NativeBlade::confirm(function (Dialog $d) {
        $d->id('delete')
          ->title('Delete export?')
          ->message('This will permanently remove stats.json.')
          ->kind('warning')
          ->confirmLabel('Delete');
    })->toResponse();
}

public function signOut()
{
    return NativeBlade::confirm(function (Dialog $d) {
        $d->id('signout')
          ->title('Sign out?')
          ->message('Your progress is saved.')
          ->confirmLabel('Sign out');
    })->toResponse();
}

#[On('nb:confirm-result')]
public function onConfirm($confirmed, $id = null)
{
    if (!$confirmed) return;

    return match ($id) {
        'delete'  => $this->performDelete(),
        'signout' => $this->performSignOut(),
        default   => null,
    };
}
```

Without `->id()`, the event still fires but `$id` arrives as `null` — fine when a component only has a single confirm dialog.

See [Receiving Results](#receiving-results-in-php) for more on handling dialog responses.

---

## Notifications

Backed by [`tauri-plugin-notification`](https://v2.tauri.app/plugin/notification/). Automatically requests permission on first use.

Notifications are configured through a dedicated `Notification` builder passed as a closure. This keeps all notification-specific options (title, body, sound, icon, channel) together and out of the generic modifier chain.

**Blade:**
```blade
<button wire:nb-bridge="notification" wire:nb-payload='{"title":"Lesson complete!","body":"You earned 50 XP"}'>
    Notify
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Notification;

public function completeLesson()
{
    $this->user->addXp(50);

    return NativeBlade::notification(function (Notification $n) {
        $n->title('Lesson complete!')
          ->body('You earned 50 XP')
          ->sound('default')
          ->icon('lesson_icon')
          ->channel('lessons');
    })->vibrate(150)->navigate('/profile');
}
```

The `Notification` builder supports:

| Method | Description |
|---|---|
| `->title($text)` | Title shown above the body (all platforms) |
| `->body($text)` | Main notification text |
| `->sound($name)` | Sound played on delivery — `'default'` or a platform-specific identifier |
| `->icon($name)` | Small icon — Android drawable resource or iOS attachment |
| `->channel($id)` | Android notification channel (ignored on iOS) |

Combine with the [Scheduler](./SCHEDULER.md) for local reminders:

```php
Schedule::call(function () {
    foreach (User::inactiveFor(20)->get() as $user) {
        event(new SendStreakReminder($user));
    }
})->dailyAt('19:00');
```

---

## Clipboard

Backed by [`tauri-plugin-clipboard-manager`](https://v2.tauri.app/plugin/clipboard/).

### Write

**Blade:**
```blade
<button wire:nb-bridge="clipboard_write" wire:nb-payload='{"text":"Copied text"}'>
    Copy
</button>
```

**PHP:**
```php
return NativeBlade::clipboardWrite($this->shareUrl)
    ->notification(fn (Notification $n) => $n->title('Copied')->body('Link copied to clipboard!'));
```

### Read

**Blade (simple):**
```blade
<button wire:nb-bridge="clipboard_read">Paste from clipboard</button>
```

**Blade (with id):**
```blade
<button wire:nb-bridge="clipboard_read" wire:nb-payload='{"id":"password_field"}'>
    Paste password
</button>
<button wire:nb-bridge="clipboard_read" wire:nb-payload='{"id":"notes_field"}'>
    Paste notes
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Clipboard;

public function paste()
{
    // Simple case — no id needed:
    return NativeBlade::clipboardRead();
}

public function pastePassword()
{
    return NativeBlade::clipboardRead(fn (Clipboard $c) => $c->id('password_field'));
}

#[On('nb:clipboard')]
public function onPaste($text, $id = null)
{
    match ($id) {
        'password_field' => $this->password = $text,
        'notes_field'    => $this->notes = $text,
        default          => $this->content = $text,
    };
}
```

---

## Geolocation

Backed by [`tauri-plugin-geolocation`](https://v2.tauri.app/plugin/geolocation/). Automatically requests permission on first use.

**Blade (simple):**
```blade
<button wire:nb-bridge="geolocation">Find nearby</button>
```

**Blade (with id):**
```blade
<button wire:nb-bridge="geolocation" wire:nb-payload='{"id":"nearby_users"}'>
    Nearby users
</button>
<button wire:nb-bridge="geolocation" wire:nb-payload='{"id":"delivery_address"}'>
    Use current address
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Geolocation;

public function findNearby()
{
    return NativeBlade::geolocation(fn (Geolocation $g) => $g->id('nearby_users'));
}

public function useCurrentAddress()
{
    return NativeBlade::geolocation(fn (Geolocation $g) => $g->id('delivery_address'));
}

#[On('nb:geolocation')]
public function onLocation($position, $id = null)
{
    $lat = $position['coords']['latitude'];
    $lng = $position['coords']['longitude'];

    match ($id) {
        'nearby_users'     => $this->loadNearbyUsers($lat, $lng),
        'delivery_address' => $this->setDeliveryAddress($lat, $lng),
        default            => null,
    };
}
```

---

## Haptics

Backed by [`tauri-plugin-haptics`](https://v2.tauri.app/plugin/haptics/). Mobile only (desktop is a no-op).

### Attribute shortcut (preferred for buttons)

```blade
<button nb-feedback wire:click="save">Save</button>
```

Any element with `nb-feedback` triggers a light selection haptic on touchstart. Zero configuration.

### Explicit calls

**Blade:**
```blade
<button wire:nb-bridge="vibrate" wire:nb-payload='{"duration":200}'>Vibrate</button>
<button wire:nb-bridge="impact" wire:nb-payload='{"style":"heavy"}'>Heavy impact</button>
<button wire:nb-bridge="selection">Selection</button>
```

**PHP:**
```php
NativeBlade::vibrate(200);
NativeBlade::impact('heavy'); // 'light' | 'medium' | 'heavy'
NativeBlade::selection();

// Or chained with other actions:
return NativeBlade::notification(fn (Notification $n) => $n->title('Saved')->body('Profile updated'))
    ->vibrate(150);
```

---

## Biometric

Backed by [`tauri-plugin-biometric`](https://v2.tauri.app/plugin/biometric/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="biometric"
        wire:nb-payload='{"reason":"Confirm your purchase","id":"checkout"}'>
    Confirm purchase
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Biometric;

public function checkout()
{
    return NativeBlade::biometric(function (Biometric $b) {
        $b->id('checkout')
          ->reason('Confirm your purchase')
          ->allowDeviceCredential();
    });
}

public function editEmail()
{
    return NativeBlade::biometric(function (Biometric $b) {
        $b->id('edit_email')
          ->reason('Authenticate to change your email');
    });
}

#[On('nb:biometric')]
public function onBiometric($success, $error = null, $id = null)
{
    if (!$success) {
        $this->addError('biometric', $error);
        return;
    }

    match ($id) {
        'checkout'   => $this->completePayment(),
        'edit_email' => $this->unlockEmailEdit(),
        default      => null,
    };
}
```

---

## Barcode Scanner

Backed by [`tauri-plugin-barcode-scanner`](https://v2.tauri.app/plugin/barcode-scanner/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="scan"
        wire:nb-payload='{"formats":["QR_CODE","EAN_13"],"id":"product_lookup"}'>
    Scan product
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Scan;

public function scanProduct()
{
    return NativeBlade::scan(function (Scan $s) {
        $s->id('product_lookup')
          ->formats(['QR_CODE', 'EAN_13', 'CODE_128']);
    });
}

public function scanTicket()
{
    return NativeBlade::scan(function (Scan $s) {
        $s->id('event_ticket')
          ->formats(['QR_CODE']);
    });
}

#[On('nb:scan')]
public function onScan($result, $id = null)
{
    $code = $result['content'];

    match ($id) {
        'product_lookup' => $this->lookupProduct($code),
        'event_ticket'   => $this->validateTicket($code),
        default          => null,
    };
}
```

---

## NFC

Backed by [`tauri-plugin-nfc`](https://v2.tauri.app/plugin/nfc/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="nfc_read" wire:nb-payload='{"id":"identify_product"}'>
    Tap product tag
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Nfc;

public function readProductTag()
{
    return NativeBlade::nfcRead(fn (Nfc $n) => $n->id('identify_product'));
}

public function readTicketTag()
{
    return NativeBlade::nfcRead(fn (Nfc $n) => $n->id('scan_ticket'));
}

#[On('nb:nfc')]
public function onNfcTag($tag, $id = null)
{
    match ($id) {
        'identify_product' => $this->loadProduct($tag['id']),
        'scan_ticket'      => $this->validateTicket($tag['id']),
        default            => null,
    };
}
```

---

## Opener

Backed by [`tauri-plugin-opener`](https://v2.tauri.app/plugin/opener/). Opens URLs or files with the system default handler.

**Blade:**
```blade
<button wire:nb-bridge="open_url" wire:nb-payload='{"url":"https://laravel.com"}'>
    Laravel site
</button>

<button wire:nb-bridge="open_file" wire:nb-payload='{"path":"/path/to/file.pdf"}'>
    Open PDF
</button>
```

**PHP:**
```php
NativeBlade::openUrl('https://laravel.com');
NativeBlade::openFile(native_path('export.pdf'));
```

---

## OS Info

Backed by [`tauri-plugin-os`](https://v2.tauri.app/plugin/os-info/). Returns platform, version, architecture, and locale.

**Blade:**
```blade
<button wire:nb-bridge="os_info">Check device</button>
```

**PHP:**
```php
public function detectPlatform()
{
    return NativeBlade::osInfo();
}

#[On('nb:os-info')]
public function onOsInfo($info)
{
    // $info = ['platform' => 'android', 'version' => '14', 'arch' => 'arm64', 'locale' => 'en-US']
    $this->isMobile = in_array($info['platform'], ['android', 'ios']);
}
```

---

## Camera & Gallery

Opens the device camera or photo library and returns a base64-encoded image. Both `camera()` and `gallery()` share the same `Camera` builder and deliver their result via the same `nb:camera-result` event — use `->id()` to distinguish between multiple capture targets in the same component.

**Blade (profile + document capture in the same page):**
```blade
<button wire:nb-bridge="camera"
        wire:nb-payload='{"maxWidth":400,"maxHeight":400,"quality":0.5,"id":"avatar"}'>
    Take profile photo
</button>

<button wire:nb-bridge="gallery"
        wire:nb-payload='{"maxWidth":400,"maxHeight":400,"quality":0.5,"id":"avatar"}'>
    Choose from gallery
</button>

<button wire:nb-bridge="camera"
        wire:nb-payload='{"maxWidth":1600,"maxHeight":1600,"quality":0.9,"id":"document"}'>
    Scan document
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Camera;

public function takeAvatar()
{
    return NativeBlade::camera(function (Camera $c) {
        $c->id('avatar')
          ->maxWidth(400)
          ->maxHeight(400)
          ->quality(0.5);
    });
}

public function scanDocument()
{
    return NativeBlade::camera(function (Camera $c) {
        $c->id('document')
          ->maxWidth(1600)
          ->maxHeight(1600)
          ->quality(0.9);
    });
}

#[On('nb:camera-result')]
public function onPhoto($data = null, $name = null, $mime = null, $size = null, $id = null)
{
    if (!$data) return;

    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $data);
    $bytes  = base64_decode($base64);

    match ($id) {
        'avatar'   => $this->saveAvatar($data, $bytes),
        'document' => $this->saveDocument($bytes),
        default    => null,
    };
}
```

> Buttons wired to the same logical target (e.g. both "Take photo" and "Choose from gallery" feeding the avatar) should use the **same id**. The listener only cares about the target, not how the user got the image.

---

## Navigation

Internal SPA-style navigation without reloading the PHP runtime.

**Blade:**
```blade
<button wire:nb-bridge="navigate" wire:nb-payload='{"path":"/profile","transition":"slide"}'>
    Profile
</button>
```

Or with the shorthand attribute on any element:
```blade
<div data-nav="/profile">Profile</div>
<div data-nav="/login" data-replace>Sign out</div>
```

**PHP:**
```php
public function goToDashboard()
{
    return NativeBlade::navigate('/dashboard')
        ->replace()
        ->transition('fade');
}
```

---

## Modal

Controls a shell-level modal component (`<x-nativeblade-modal>`) pre-rendered on the page.

**Blade:**
```blade
<x-nativeblade-modal>
    <div style="padding:24px">
        <h3>Sign out?</h3>
        <button data-dismiss>Cancel</button>
        <button data-nav="/login" data-replace>Confirm</button>
    </div>
</x-nativeblade-modal>

<button wire:nb-bridge="showModal">Open</button>
<button wire:nb-bridge="hideModal">Close</button>
```

**PHP:**
```php
public function confirmDelete()
{
    return NativeBlade::showModal();
}
```

---

## Process

Backed by [`tauri-plugin-process`](https://v2.tauri.app/plugin/process/). Quits the application.

**Blade:**
```blade
<button wire:nb-bridge="exit">Quit</button>
```

**PHP:**
```php
return NativeBlade::exit();
```

---

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
}
```

Event names are always `nb:<name>`. They are automatically dispatched from the JS bridge via `Livewire.dispatch()` inside the iframe.

**Every result-bearing bridge supports an optional `$id` argument as the last listener parameter.** When a component has multiple calls to the same bridge (e.g. two cameras, three confirms, several geolocations), set `->id('unique_tag')` inside the builder closure — or add `"id":"unique_tag"` to the Blade `wire:nb-payload` JSON — and the same tag comes back on the listener. Use `match ($id) { ... }` to route the response. When you only have one call per component, skip the id and the argument arrives as `null`.

---

## Adding Your Own Bridge

If none of the built-in bridges fit your use case, add a custom one:

1. **Install the Rust crate** in `src-tauri/Cargo.toml`
2. **Register it** in `src-tauri/src/lib.rs` with `.plugin(...)`
3. **Grant capabilities** in `src-tauri/capabilities/default.json`
4. **Add a case** in `js/wasm-app/bridge.js` that invokes the plugin
5. **Use it from Blade/PHP** via `wire:nb-bridge="myAction"` or by extending `NativeResponse`

A minimal custom bridge — appending a new case to the existing `handleNativeAction` switch:

```javascript
case 'myAction':
    myPluginApi.doSomething(payload).then(result => {
        appFrame?.contentWindow?.postMessage({
            type: 'nativeblade-my-result',
            result,
        }, '*');
    });
    break;
```

For a strongly-typed PHP API, add a method to `NativeResponse`:

```php
public function myAction(array $payload): static
{
    return $this->push('myAction', $payload);
}
```

No Rust code is needed if the plugin exposes a JavaScript API directly (most official Tauri plugins do). Only write Rust commands for custom logic not covered by an existing plugin.

For the full Tauri plugin tutorial, see the [Tauri 2 Plugin docs](https://v2.tauri.app/plugin/).

---

## See Also

- [LIFECYCLE.md](./LIFECYCLE.md) — bridge internals and the exit/re-execute pattern
- [SCHEDULER.md](./SCHEDULER.md) — running code on a schedule
- [FILESYSTEM.md](./FILESYSTEM.md) — `Storage::disk('native')` and `native_path()`
- [DATABASE.md](./DATABASE.md) — external MySQL/PostgreSQL via the `nativeblade-db` driver
- [DIRECTIVES.md](./DIRECTIVES.md) — full list of `wire:nb-*` directives and attributes
- [CONFIGURATION.md](./CONFIGURATION.md) — platform-specific configuration
