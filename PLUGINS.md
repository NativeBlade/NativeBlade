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

public function save()
{
    // ... business logic
    return NativeBlade::notification('Your changes are safe.')
        ->title('Saved!');
}
```

The facade returns a `NativeResponse` that is Responsable — return it directly from a Livewire action or controller and it dispatches the native actions. Every method is **chainable**, so you can queue multiple actions into a single response:

```php
return NativeBlade::alert('Welcome back!')
    ->title('Hey')
    ->notification('3 new lessons available')
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
return NativeBlade::notification('Saved!')->vibrate(200);
```

| Category | Methods |
|---|---|
| Dialogs | `alert($msg)`, `confirm($msg)` |
| Notifications | `notification($body)` |
| Clipboard | `clipboardWrite($text)`, `clipboardRead()` |
| Geolocation | `geolocation()` |
| Haptics | `vibrate($ms = 100)`, `impact($style = 'medium')`, `selection()` |
| Biometric | `biometric($reason = 'Authenticate')` |
| Barcode | `scan($formats = [])` |
| NFC | `nfcRead()` |
| Opener | `openUrl($url)`, `openFile($path)` |
| OS | `osInfo()` |
| Camera | `camera($options = [])`, `gallery($options = [])` |
| Navigation | `navigate($path, $replace = false)` |
| Modal | `showModal()`, `hideModal()` |
| Process | `exit()` |

### Modifiers (attach to the last action)

After any action, chain any of these to customize it:

| Modifier | Applies to |
|---|---|
| `title($text)` | alert, confirm, notification |
| `kind($level)` | alert, confirm — `'info' \| 'warning' \| 'error'` |
| `confirmLabel($text)` / `cancelLabel($text)` | alert, confirm |
| `transition($type)` | navigate — `'slide' \| 'fade' \| 'zoom' \| 'flip' \| 'bounce' \| 'blur'` |
| `replace($bool = true)` | navigate |
| `sound($name)` / `icon($name)` / `channel($id)` | notification |
| `reason($text)` / `allowDeviceCredential($bool)` | biometric |
| `formats($array)` | scan |
| `maxWidth($n)` / `maxHeight($n)` / `quality($f)` | camera, gallery |

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
return NativeBlade::alert('Your session will expire soon')
    ->title('Heads up')
    ->kind('warning');
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
return NativeBlade::confirm('This cannot be undone')
    ->title('Delete?')
    ->confirmLabel('Delete')
    ->cancelLabel('Keep');
```

See [Receiving Results](#receiving-results-in-php) for handling the response.

---

## Notifications

Backed by [`tauri-plugin-notification`](https://v2.tauri.app/plugin/notification/). Automatically requests permission on first use.

**Blade:**
```blade
<button wire:nb-bridge="notification" wire:nb-payload='{"title":"Lesson complete!","body":"You earned 50 XP"}'>
    Notify
</button>
```

**PHP:**
```php
public function completeLesson()
{
    $this->user->addXp(50);

    return NativeBlade::notification('You earned 50 XP')
        ->title('Lesson complete!')
        ->sound('default')
        ->channel('lessons');
}
```

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
    ->notification('Link copied!');
```

### Read

**Blade:**
```blade
<button wire:nb-bridge="clipboard_read">Paste from clipboard</button>
```

**PHP:**
```php
public function paste()
{
    return NativeBlade::clipboardRead();
}

#[On('nb:clipboard')]
public function onPaste($text)
{
    $this->content = $text;
}
```

---

## Geolocation

Backed by [`tauri-plugin-geolocation`](https://v2.tauri.app/plugin/geolocation/). Automatically requests permission on first use.

**Blade:**
```blade
<button wire:nb-bridge="geolocation">Find nearby</button>
```

**PHP:**
```php
public function findNearby()
{
    return NativeBlade::geolocation();
}

#[On('nb:geolocation')]
public function onLocation($position)
{
    $this->lat = $position['coords']['latitude'];
    $this->lng = $position['coords']['longitude'];
    $this->places = Place::near($this->lat, $this->lng)->get();
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
return NativeBlade::notification('Saved!')
    ->vibrate(150);
```

---

## Biometric

Backed by [`tauri-plugin-biometric`](https://v2.tauri.app/plugin/biometric/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="biometric" wire:nb-payload='{"reason":"Confirm your identity"}'>
    Unlock
</button>
```

**PHP:**
```php
public function requestUnlock()
{
    return NativeBlade::biometric('Confirm your identity')
        ->allowDeviceCredential();
}

#[On('nb:biometric')]
public function onBiometric($success, $error = null)
{
    if ($success) {
        Auth::login($this->pendingUser);
        $this->redirect('/');
    } else {
        $this->addError('biometric', $error);
    }
}
```

---

## Barcode Scanner

Backed by [`tauri-plugin-barcode-scanner`](https://v2.tauri.app/plugin/barcode-scanner/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="scan" wire:nb-payload='{"formats":["QR_CODE","EAN_13"]}'>
    Scan code
</button>
```

**PHP:**
```php
public function startScan()
{
    return NativeBlade::scan(['QR_CODE', 'EAN_13', 'CODE_128']);
}

#[On('nb:scan')]
public function onScan($result)
{
    $this->code = $result['content'];
    $this->lookupProduct();
}
```

---

## NFC

Backed by [`tauri-plugin-nfc`](https://v2.tauri.app/plugin/nfc/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="nfc_read">Tap NFC tag</button>
```

**PHP:**
```php
public function readTag()
{
    return NativeBlade::nfcRead();
}

#[On('nb:nfc')]
public function onNfcTag($tag)
{
    $this->tagId = $tag['id'];
    $this->payload = $tag['records'][0]['payload'] ?? null;
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

Custom bridge that opens the device camera or photo library and returns a base64-encoded image.

**Blade:**
```blade
<button wire:nb-bridge="camera" wire:nb-payload='{"maxWidth":800,"maxHeight":800,"quality":0.8}'>
    Take photo
</button>

<button wire:nb-bridge="gallery" wire:nb-payload='{"maxWidth":800,"maxHeight":800,"quality":0.8}'>
    Choose from gallery
</button>
```

**PHP:**
```php
public function takePhoto()
{
    return NativeBlade::camera([
        'maxWidth' => 400,
        'maxHeight' => 400,
        'quality' => 0.5,
    ]);
}

#[On('nb:camera-result')]
public function onPhoto($data)
{
    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $data);
    Storage::disk('native')->put(native_path('avatar.jpg'), base64_decode($base64));
    $this->avatarSrc = $data;
}
```

Both `camera()` and `gallery()` deliver their result via the same `nb:camera-result` event.

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
    public function onConfirm($confirmed) { /* ... */ }

    #[On('nb:clipboard')]
    public function onPaste($text) { /* ... */ }

    #[On('nb:geolocation')]
    public function onLocation($position) { /* ... */ }

    #[On('nb:biometric')]
    public function onBiometric($success, $error = null) { /* ... */ }

    #[On('nb:scan')]
    public function onScan($result) { /* ... */ }

    #[On('nb:nfc')]
    public function onNfcTag($tag) { /* ... */ }

    #[On('nb:os-info')]
    public function onOsInfo($info) { /* ... */ }

    #[On('nb:camera-result')]
    public function onPhoto($data) { /* ... */ }
}
```

Event names are always `nb:<name>`. They are automatically dispatched from the JS bridge via `Livewire.dispatch()` inside the iframe.

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
