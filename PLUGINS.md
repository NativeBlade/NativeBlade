# Native Plugins

NativeBlade ships with every major Tauri 2 plugin pre-registered, pre-permissioned, and bridged into PHP. You don't need to touch Rust, edit capabilities, or write JavaScript — just call the bridge from your Livewire component or Blade template.

This document lists every built-in bridge, what it does, and how to call it from both the **Blade directive side** (`wire:nb-bridge`) and the **PHP side** (`NativeBlade` facade).

## Table of Contents

- [Declaring Plugins](#declaring-plugins)
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
- [In-App Review](#in-app-review)
- [Secure Storage](#secure-storage)
- [Sharing](#sharing)
- [Analytics](#analytics)
- [Camera & Gallery](#camera--gallery)
- [Navigation](#navigation)
- [Modal](#modal)
- [Shell](#shell)
- [Process](#process)
- [Window Controls](#window-controls)
- [Receiving Results in PHP](#receiving-results-in-php)
- [Using Third-Party Tauri Plugins](#using-third-party-tauri-plugins)
- [Composer plugin discovery](#composer-plugin-discovery)

---

## Declaring Plugins

By default, every plugin ships in your build. For production apps you should **declare only what you actually use** — App Store and Play Store reviewers flag binaries that reference unused permissions, and unused plugins also bloat the binary.

Declare your plugin set in `app/Providers/AppServiceProvider.php`:

```php
use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::plugins([
    Plugin::MEDIA,        // camera, gallery, video picker
    Plugin::PUSH,         // FCM / APNS push notifications
    Plugin::HAPTICS,      // vibration, taptic feedback
    Plugin::GEOLOCATION,  // GPS
]);
```

Run `php artisan nativeblade:config` to apply. NativeBlade regenerates `Cargo.toml`, capabilities, and `package.json` so only the listed plugins compile in. Cargo skips the unused crates entirely — their code never reaches the binary.

### How it works

The `plugins()` declaration drives a Cargo feature toggle on every optional crate:

```toml
# Generated in your src-tauri/Cargo.toml
[features]
default = ["custom-protocol"]
haptics = ["dep:tauri-plugin-haptics"]
media = ["dep:tauri-plugin-nativeblade-media"]
push = ["dep:tauri-plugin-nativeblade-push"]
```

When you run `nativeblade:dev` or `nativeblade:build`, the CLI passes `--features haptics,media,push` to Cargo. Optional crates whose feature isn't enabled are not downloaded, compiled, or linked.

### Always-on plugins

`dialog`, `os`, `process`, `store`, `fs`, and `opener` are always included regardless of declaration — NativeBlade core depends on them.

### Available plugins

| Enum | What it provides |
|------|------------------|
| `Plugin::MEDIA` | `NativeBlade::camera()`, `gallery()`, `video()` (mobile only) |
| `Plugin::PUSH` | FCM (Android) and APNS (iOS) push **and** all local / scheduled notifications via `NativeBlade::notification()` (mobile only) |
| `Plugin::IN_APP_REVIEW` | Native review prompt via `NativeBlade::requestReview()` (mobile only) |
| `Plugin::SECURE_STORAGE` | Encrypted key-value via `NativeBlade::setSecure()` / `getSecure()` (mobile only) |
| `Plugin::SHARING` | Native share sheet via `NativeBlade::share()` (mobile only) |
| `Plugin::ANALYTICS` | Firebase Analytics via `NativeBlade::analytics()` (mobile only) |
| `Plugin::GEOLOCATION` | `nb:geolocation` event with current position |
| `Plugin::BIOMETRIC` | `NativeBlade::biometric()` (mobile only) |
| `Plugin::BARCODE_SCANNER` | `NativeBlade::scan()` (mobile only) |
| `Plugin::NFC` | `NativeBlade::nfc()` (mobile only) |
| `Plugin::HAPTICS` | `NativeBlade::impact()`, `vibrate()`, `selection()` |
| `Plugin::CLIPBOARD` | `NativeBlade::clipboardWrite()`, `clipboardRead()` |
| `Plugin::UPLOAD` | `NativeBlade::upload($path, $url)` streaming uploads |
| `Plugin::HTTP` | Native HTTP requests (bypasses CORS) |
| `Plugin::DEEP_LINK` | Verified universal / app links via `NativeBladeConfig::deepLinks()` (see [CONFIGURATION.md](CONFIGURATION.md#deep-links-universal--app-links)) |
| `Plugin::SHELL` | Run external commands (desktop only — disabled by default) |

> **Behavior when missing:** if a Livewire action calls `NativeBlade::camera()` without declaring `Plugin::MEDIA`, the build fails with a Cargo error pointing at the missing permission. This is intentional — fail at build time, not at runtime.

### Skipping declaration

If you don't call `NativeBladeConfig::plugins([...])`, all plugins are included by default. Useful while prototyping; switch to explicit declaration before shipping.

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
| Camera | `camera(?Closure)`, `gallery(?Closure)` |
| Navigation | `navigate($path, $replace = false)` |
| Modal | `showModal()`, `hideModal()` |
| Shell | `shell(Closure)` (desktop only) |
| Process | `exit()` |

All closure-based builders live in `NativeBlade\Plugins\*` (`Dialog`, `Notification`, `Camera`, `Biometric`, `Scan`, `Geolocation`, `Clipboard`, `Nfc`). Builders marked with `?Closure` (nullable) let you omit the closure when you don't need to configure anything — useful for the simple `NativeBlade::geolocation()` / `NativeBlade::scan()` case.

### Modifiers (attach to the last action)

After any action, chain any of these to customize it:

| Modifier | Applies to |
|---|---|
| `transition($type)` | navigate — `'none' \| 'slide' \| 'fade'` |
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
})->toResponse();
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
})->toResponse();
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

Backed by the same native plugin that handles push (`nativeblade-push` — Kotlin on Android, Swift on iOS). Local notifications, scheduled notifications, and remote pushes all share one code path. Permission is requested at app boot together with `POST_NOTIFICATIONS`; iOS prompts on first use.

Configured through a dedicated `Notification` builder passed as a closure. Calls without `->at()` / `->every()` / `->dailyAt()` fire immediately; with one of those, the OS handles the timing natively (WorkManager on Android, UNUserNotificationCenter on iOS).

**Blade:**
```blade
<button wire:nb-bridge="notification" wire:nb-payload='{"title":"Lesson complete!","body":"You earned 50 XP"}'>
    Notify
</button>
```

**PHP — immediate:**
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
    })->vibrate(150)->navigate('/profile')->toResponse();
}
```

**PHP — scheduled:**
```php
// Fire once at a specific moment
NativeBlade::notification(function (Notification $n) {
    $n->title('Lesson reminder')
      ->body('Continue where you stopped')
      ->id('lesson-reminder')         // tag so we can cancel it later
      ->at(now()->addHours(2));
});

// Repeat every day at 9am local time
NativeBlade::notification(function (Notification $n) {
    $n->title('Daily streak')
      ->body('Keep your streak alive')
      ->id('daily-streak')
      ->dailyAt('09:00');
});

// Repeat every N units of time
NativeBlade::notification(function (Notification $n) {
    $n->title('Hydrate')
      ->body('Drink some water')
      ->id('hydrate')
      ->every('hour', 2);              // every 2 hours
});

// Cancel — anywhere later in the app
NativeBlade::cancelNotification('lesson-reminder');
NativeBlade::cancelAllNotifications();
```

The `Notification` builder supports:

| Method | Description |
|---|---|
| `->title($text)` | Title shown above the body (all platforms) |
| `->body($text)` | Main notification text |
| `->sound($name)` | Sound played on delivery — `'default'` or a platform-specific identifier |
| `->icon($name)` | Small icon — Android drawable resource, iOS attachment, or (desktop) absolute path / bundled resource. See note below. |
| `->channel($id)` | Android notification channel — auto-created on first use (ignored on iOS/desktop) |
| `->id($id)` | String tag so the notification can be cancelled or updated later |
| `->at($dateTime)` | Fire once at the given `DateTimeInterface`, serialised in UTC ISO 8601 |
| `->every($kind, $count = 1)` | Repeat every N units; kind is `'minute'`, `'hour'`, `'day'`, `'week'`, `'month'`. Android `every('minute')` is clamped to a 15-minute minimum by WorkManager. iOS requires a minimum 60-second interval. |
| `->dailyAt($time)` | Repeat daily at the given `'HH:MM'` (24-hour, device local time) |
| `->exact()` | Ask for *exact* delivery (fires on the second even in Doze). Normally set for you by `scheduleNotification()` — see below. Needs `Permission::EXACT_ALARM` on Android or it degrades to inexact; no effect on iOS (already exact). |

### Reliable scheduled reminders — `scheduleNotification()`

By default a scheduled notification on Android uses an **inexact** alarm: it
pierces Doze but can land a few minutes late (the OS batches it). That needs no
special permission and is fine for most nudges.

When timing must be precise (a habit reminder at exactly 17:00), use
`scheduleNotification()` instead of `notification()`. It is identical except it
flags the notification as exact, so Android uses `setExactAndAllowWhileIdle`:

```php
NativeBlade::scheduleNotification(function (Notification $n) {
    $n->title('Time to practice')
      ->body("Don't lose your streak")
      ->id('practice-reminder')
      ->at(now()->setTime(17, 0));
});
```

Exact alarms are **opt-in** because Google Play scrutinizes them — they're only
allowed for apps whose core purpose is alarms / reminders / calendars. So the
`PUSH` plugin does **not** request the permission by default. To use
`scheduleNotification()` on Android, declare it yourself:

```php
NativeBladeConfig::android(function (AndroidConfig $config) {
    $config->permissions([
        Permission::EXACT_ALARM => 'Deliver your reminders on time.',
    ]);
});
```

This adds `SCHEDULE_EXACT_ALARM` + `USE_EXACT_ALARM` to the manifest. Apps that
only use remote push or best-effort `notification()->at()` stay clean — they
never inherit the scrutinized permission. If you call `scheduleNotification()`
*without* declaring the permission, it still works: the OS silently falls back to
the inexact alarm rather than failing. On iOS there's nothing to opt into —
`UNUserNotificationCenter` triggers are already exact.

> NativeBlade automatically creates the Android notification channel the first time you use one, so `->channel('lessons')` Just Works without registering the channel explicitly. The auto-created channel uses the default importance, lights and vibration settings.

> **About desktop icons.** Each platform handles per-notification icons differently:
> - **macOS** always uses the app bundle icon (`bundle.icon` in `tauri.conf.json`). Custom `->icon()` values are ignored by the OS.
> - **Windows** uses the AppUserModelID-registered icon (the bundled app icon). Custom paths require an MSIX-packaged build to take effect; on plain `.exe` installs they're ignored.
> - **Linux** accepts either an XDG icon name (`'dialog-information'`) or an absolute file path. NativeBlade auto-resolves relative paths against the app's resource directory, so if you add your image to `tauri.conf.json` -> `bundle.resources`, `->icon('images/logo.png')` will work.
>
> The safe default on desktop is to skip `->icon()` and let the OS use your app's installed icon (which you configure once via `NativeBladeConfig::desktop()->icon(...)`).

> **About scheduling internals.** On Android, one-shot (`->at()`) and daily (`->dailyAt()`) notifications are armed with `AlarmManager` so they wake the app even in Doze — inexact by default (`setAndAllowWhileIdle`), or exact when you opt in via `scheduleNotification()` + `Permission::EXACT_ALARM` (`setExactAndAllowWhileIdle`). Recurring `->every()` schedules use `WorkManager` (deferrable batching is acceptable there). On iOS, `UNUserNotificationCenter` triggers fire even when the app is suspended and are always exact. AlarmManager alarms do **not** survive a reboot, so the app should re-arm its schedules on launch; pass `->id($tag)` to cancel or replace a pending schedule later.

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
    ->notification(fn (Notification $n) => $n->title('Copied')->body('Link copied to clipboard!'))
    ->toResponse();
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
    return NativeBlade::clipboardRead()->toResponse();
}

public function pastePassword()
{
    return NativeBlade::clipboardRead(fn (Clipboard $c) => $c->id('password_field'))->toResponse();
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
    return NativeBlade::geolocation(fn (Geolocation $g) => $g->id('nearby_users'))->toResponse();
}

public function useCurrentAddress()
{
    return NativeBlade::geolocation(fn (Geolocation $g) => $g->id('delivery_address'))->toResponse();
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
    ->vibrate(150)
    ->toResponse();
```

---

## Biometric

Backed by [`tauri-plugin-biometric`](https://v2.tauri.app/plugin/biometric/). Mobile only — on desktop the action posts back `success: false, error: 'Biometric not available'` so your `nb:biometric` handler can show a fallback (typically a password form).

**Setup (`app/Providers/AppServiceProvider.php`):**

```php
use NativeBlade\Config\Permission;

NativeBladeConfig::android(function (AndroidConfig $config) {
    $config->permissions([
        Permission::BIOMETRIC => 'Sign in with fingerprint or face',
        // ... other permissions
    ]);
});

NativeBladeConfig::ios(function (IosConfig $config) {
    $config->permissions([
        Permission::BIOMETRIC => 'Sign in with Face ID',
        // ... other permissions
    ]);
});
```

`Permission::BIOMETRIC` maps to `USE_BIOMETRIC` on Android and `NSFaceIDUsageDescription` on iOS. Run `php artisan nativeblade:config` after editing.

**Trigger the prompt — Blade:**
```blade
<button wire:nb-bridge="biometric"
        wire:nb-payload='{"reason":"Confirm your purchase","id":"checkout"}'>
    Confirm purchase
</button>
```

**Trigger from PHP:**
```php
use NativeBlade\Plugins\Biometric;

public function checkout()
{
    return NativeBlade::biometric(function (Biometric $b) {
        $b->id('checkout')
          ->reason('Confirm your purchase')
          ->allowDeviceCredential();
    })->toResponse();
}
```

**Builder methods:**

| Method | Description |
|---|---|
| `->reason($text)` | Explanation shown inside the system prompt (e.g. `'Sign in to NativeBlade'`). Default `'Authenticate'`. |
| `->id($tag)` | String tag echoed back on the `nb:biometric` event so a single listener can route multiple prompts (login vs checkout vs edit email). |
| `->allowDeviceCredential($allow = true)` | Allow the device PIN / pattern / passcode as a fallback when biometric hardware fails or isn't enrolled. Default `true`. Pass `false` if you require biometric specifically. |

**Result event:**

Listen with `#[On('nb:biometric')]`. The handler receives three arguments:

| Argument | Type | Meaning |
|---|---|---|
| `$success` | `bool` | `true` if the user authenticated, `false` on cancel, failure, or unavailable. |
| `$error` | `?string` | OS-provided error message when `$success` is false (e.g. `'User cancelled'`, `'Biometric not available'`). `null` on success. |
| `$id` | `?string` | The tag passed via `->id(...)`, or `null` if none. |

```php
use Livewire\Attributes\On;

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

### Recipe: biometric login

The bundled demo (`php artisan nativeblade:install --demo`) ships a working biometric login flow. The idea: after the first successful password login, save the user object to NativeBlade state so the biometric prompt can restore the session without re-checking credentials.

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Biometric;

class Login extends Component
{
    public bool $biometricAvailable = false;
    public string $biometricEmail = '';

    public function mount()
    {
        $saved = NativeBlade::getState('biometric.user');
        if (is_array($saved) && !empty($saved['email'])) {
            $this->biometricAvailable = true;
            $this->biometricEmail = $saved['email'];
        }
    }

    public function login()
    {
        // ... validate password ...
        $user = ['name' => 'Admin', 'email' => $this->email];

        NativeBlade::setState('auth.user', $user);
        NativeBlade::setState('biometric.user', $user);

        return NativeBlade::navigate('/', replace: true)->toResponse();
    }

    public function biometricLogin()
    {
        return NativeBlade::biometric(function (Biometric $b) {
            $b->id('login')
              ->reason('Sign in to ' . $this->biometricEmail)
              ->allowDeviceCredential();
        })->toResponse();
    }

    #[On('nb:biometric')]
    public function onBiometric($success, $error = null, $id = null)
    {
        if ($id !== 'login') return;

        if (!$success) {
            $this->addError('biometric', $error ?: 'Authentication failed');
            return;
        }

        $saved = NativeBlade::getState('biometric.user');
        NativeBlade::setState('auth.user', $saved);

        return NativeBlade::navigate('/', replace: true)->toResponse();
    }
}
```

Show the button only when `$biometricAvailable` is true — that way the first login is always password, and subsequent visits get the biometric shortcut.

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
    })->toResponse();
}

public function scanTicket()
{
    return NativeBlade::scan(function (Scan $s) {
        $s->id('event_ticket')
          ->formats(['QR_CODE']);
    })->toResponse();
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

> **Scanning overlay (automatic).** The underlying Tauri plugin is headless: it shows the camera behind a transparent webview and expects the app to draw the scanning UI, so on its own a scan opens a bare fullscreen camera with no way out. NativeBlade renders that UI for you: when a scan starts it shows a viewfinder and a **Cancel** button, and removes them when a code is read or the user cancels. You do not call anything extra. To cancel from your own button instead, fire the `scan_cancel` bridge:
>
> ```blade
> <button wire:nb-bridge="scan_cancel">Stop scanning</button>
> ```

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
    return NativeBlade::nfcRead(fn (Nfc $n) => $n->id('identify_product'))->toResponse();
}

public function readTicketTag()
{
    return NativeBlade::nfcRead(fn (Nfc $n) => $n->id('scan_ticket'))->toResponse();
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

### Reading tags only while the app is open (default)

By default, NFC works through **foreground dispatch**: the plugin captures tags only while the user is inside the app and `NativeBlade::nfcRead()` was invoked. This is the behaviour 99% of apps want. No manifest filter is required — `Plugin::NFC` and the `android.permission.NFC` entry are enough.

### Auto-launching the app from a tag (opt-in)

If your app is *built around* NFC (a transit reader, a payment terminal, an inventory scanner where the user taps the tag instead of opening the app first), you can declare an auto-launch filter via `AndroidConfig::nfcAutoLaunch()`. Android will then wake the device and bring your app to the front whenever a matching tag is presented.

```php
use NativeBlade\Config\NfcTech;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::android(function ($c) {
    // (a) Any NFC tag wakes the app — broadest filter
    $c->nfcAutoLaunch(anyTag: true);

    // (b) Only tags exposing specific technologies wake the app
    $c->nfcAutoLaunch(techs: [NfcTech::ISO_DEP, NfcTech::MIFARE_CLASSIC]);

    // (c) Both
    $c->nfcAutoLaunch(anyTag: true, techs: [NfcTech::ISO_DEP]);
});
```

After declaring, run `php artisan nativeblade:config` to write the manifest filters and the `res/xml/nfc_tech_filter.xml` resource.

**Warning:** turning auto-launch on (especially `anyTag: true` or `NfcTech::ISO_DEP`) means **contactless credit cards, transit cards, and corporate badges** will wake the device and launch your app whenever they pass near the phone. That is the exact symptom users report when this is misconfigured. Only enable it if your app actually needs that behaviour.

**Available `NfcTech` cases** (mapping to `android.nfc.tech.*`):

| Case | Class | Typical tags |
|---|---|---|
| `ISO_DEP` | `IsoDep` | Credit cards, transit cards, NFC passports |
| `NFC_A` | `NfcA` | MIFARE Classic, most Android phone-emulated tags |
| `NFC_B` | `NfcB` | Some ID cards |
| `NFC_F` | `NfcF` | Japanese transit / e-money (FeliCa) |
| `NFC_V` | `NfcV` | Vicinity tags, library books |
| `NDEF` | `Ndef` | Vast majority of consumer NFC tags |
| `NDEF_FORMATABLE` | `NdefFormatable` | Blank tags ready to format |
| `MIFARE_CLASSIC` | `MifareClassic` | Legacy access control, transit |
| `MIFARE_ULTRALIGHT` | `MifareUltralight` | Event tickets, paper-thin tags |
| `NFC_BARCODE` | `NfcBarcode` | Kovio barcode payload tags |

The generator writes/removes the filters idempotently — drop the `nfcAutoLaunch()` call and rerun `nativeblade:config` to revert to the safe default.

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
    return NativeBlade::osInfo()->toResponse();
}

#[On('nb:os-info')]
public function onOsInfo($info)
{
    // $info = ['platform' => 'android', 'version' => '14', 'arch' => 'arm64', 'locale' => 'en-US']
    $this->isMobile = in_array($info['platform'], ['android', 'ios']);
}
```

---

## In-App Review

Backed by the NativeBlade `nativeblade-review` native plugin: `SKStoreReviewController` on iOS and Google Play In-App Review on Android. Mobile only. Requires `Plugin::IN_APP_REVIEW`.

Asks the OS to show its own in-place review card so the user can rate the app without leaving for the store.

**Blade:**
```blade
<button wire:nb-bridge="request_review">Rate this app</button>
```

**PHP:**
```php
public function rateApp()
{
    return NativeBlade::requestReview()->toResponse();
}
```

On mobile the OS already knows which app to show (it is identified by your bundle id / package name from the store listing), so there is nothing to pass. On **desktop this is a no-op** — there is no native in-place review, so for a "rate us" link there just call `NativeBlade::openUrl(...)` with your store page yourself.

> **The OS decides whether it shows.** Both StoreKit and Play heavily rate-limit the prompt (roughly a few times per year) and may display nothing at all. You get **no result back** about whether the user reviewed, and you must **not** reward or gate anything on it. Apple and Google forbid incentivizing reviews. Call it at a natural, positive moment, never in a loop or on every launch.

---

## Secure Storage

Backed by the NativeBlade `nativeblade-secure-storage` native plugin: the iOS **Keychain** and, on Android, **Google Tink** AEAD with the keyset sealed by the **Android Keystore** (the modern replacement for the now-deprecated EncryptedSharedPreferences). Mobile only. Requires `Plugin::SECURE_STORAGE`.

Use this for secrets that must survive at rest in encrypted, OS-protected storage: auth tokens, refresh tokens, subscription entitlements. It is **not** `setState()` — the regular state store is SQLite persisted to IndexedDB in plaintext, fine for preferences but wrong for credentials. Keep values small (tokens, keys), not blobs.

### Write and remove

```php
public function signIn()
{
    // ... validate ...
    return NativeBlade::setSecure('auth.token', $token)->toResponse();
}

public function signOut()
{
    return NativeBlade::forgetSecure('auth.token')->toResponse();
}
```

Values are strings. For structured data, `json_encode()` before storing and `json_decode()` what you read back.

### Read

Reading crosses into native code, so the value comes back asynchronously on the `nb:secure` Livewire event (the same pattern as `clipboardRead()` and `scan()`), not as a return value:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;

public function loadSession()
{
    return NativeBlade::getSecure('auth.token', id: 'auth')->toResponse();
}

#[On('nb:secure')]
public function onSecure($value = null, $id = null)
{
    if ($id === 'auth' && $value) {
        $this->restoreSession($value);
    }
}
```

`$value` is `null` when the key is absent. Pass `id` to route the result when a component reads more than one key in the same component.

> **Desktop is a no-op in v1.** There is no native keystore binding on desktop yet, so `setSecure()` / `forgetSecure()` do nothing and `getSecure()` returns `null`. Branch with `NativeBlade::isMobile()` if your desktop build needs a different path.

---

## Sharing

Backed by the NativeBlade `nativeblade-sharing` native plugin: `UIActivityViewController` on iOS, `Intent.ACTION_SEND` on Android. Mobile only. Requires `Plugin::SHARING`.

Opens the OS share sheet so the user can send text and/or a link to other apps (messages, mail, social, clipboard). v1 shares text and URLs; file sharing comes later.

**Blade:**
```blade
<button wire:nb-bridge="share"
        wire:nb-payload='{"text":"Check this out","url":"https://myapp.com/p/42"}'>
    Share
</button>
```

**PHP:**
```php
public function invite()
{
    return NativeBlade::share(
        text: 'Join me on MyApp',
        url: 'https://myapp.com/invite/abc',
    )->toResponse();
}
```

Pass at least one of `text` / `url`. It is fire-and-forget: the OS sheet appears and there is no result back. No-op on desktop.

---

## Analytics

Firebase Analytics through the native SDK. Mobile only. Requires `Plugin::ANALYTICS` and `NativeBladeConfig::firebase(...)`.

```php
use NativeBlade\Plugins\Analytics;

return NativeBlade::analytics(function (Analytics $a) {
    $a->event('add_to_cart', ['item_id' => 'sku_123', 'value' => 9.99])
      ->setUserProperty('plan', 'pro');
})->toResponse();
```

Builder methods: `event()`, `screen()`, `setUserId()`, `setUserProperty()`, `enable()`, `disable()`. Enable automatic screen tracking and the consent default in config (`NativeBladeConfig::analyticsConfig(...)`). Full guide, including the consent flow, in [ANALYTICS.md](ANALYTICS.md).

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
    })->toResponse();
}

public function scanDocument()
{
    return NativeBlade::camera(function (Camera $c) {
        $c->id('document')
          ->maxWidth(1600)
          ->maxHeight(1600)
          ->quality(0.9);
    })->toResponse();
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
        ->transition('fade')
        ->toResponse();
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
    return NativeBlade::showModal()->toResponse();
}
```

---

## Shell

Backed by [`tauri-plugin-shell`](https://v2.tauri.app/plugin/shell/). **Desktop only** — on mobile the call is a no-op and a failure event is emitted with `exitCode = -1` and stderr `"not supported on this platform"`, so your listener code can handle both paths uniformly.

Two modes:

- **Captured execution** — runs the command in the platform shell (`cmd /C` on Windows, `/bin/sh -c` on Unix) and delivers stdout / stderr / exit code via the `nb:shell-result` Livewire event.
- **`openTerminal()`** — spawns the command inside a visible OS terminal window (Windows Terminal / cmd / PowerShell on Windows, Terminal.app on macOS, gnome-terminal / konsole / xterm on Linux). Fire-and-forget: no result event is emitted, and the user can interact with the process directly.

The `Shell` builder supports:

| Method | Description |
|---|---|
| `->id($identifier)` | Tag the execution — echoed back as `$id` on the `nb:shell-result` listener |
| `->run($command)` | Command line to execute (passed to the platform shell) |
| `->cwd($path)` | Working directory for the command |
| `->env(['K' => 'V'])` | Extra environment variables, merged on top of the process environment |
| `->timeout($seconds)` | Kill the command and emit a timeout error after N seconds |
| `->openTerminal($type = null)` | Spawn inside a visible terminal instead of capturing output. `$type` is Windows-only and accepts `'wt'`, `'cmd'` or `'powershell'` — on macOS/Linux the default terminal is auto-detected |

### Example: run a captured command

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Shell;

public function checkDocker()
{
    return NativeBlade::shell(function (Shell $s) {
        $s->id('docker_check')->run('docker ps');
    })->toResponse();
}

public function gitPull()
{
    return NativeBlade::shell(function (Shell $s) {
        $s->id('pull')
          ->cwd('/home/user/repo')
          ->env(['GIT_PAGER' => 'cat'])
          ->timeout(30)
          ->run('git pull');
    })->toResponse();
}

#[On('nb:shell-result')]
public function onShellResult($stdout = null, $stderr = null, $exitCode = null, $id = null)
{
    match ($id) {
        'docker_check' => $this->parseDocker($stdout),
        'pull'         => $this->updateBranchStatus($exitCode),
        default        => null,
    };
}
```

### Example: open the command in the OS terminal

```php
public function connectSsh()
{
    return NativeBlade::shell(function (Shell $s) {
        $s->openTerminal()->run('ssh prod-server');
    })->toResponse();
}
```

On Windows you can pick a specific terminal:

```php
NativeBlade::shell(fn (Shell $s) => $s->openTerminal('powershell')->run('Get-Service'));
NativeBlade::shell(fn (Shell $s) => $s->openTerminal('wt')->cwd('C:\\repo')->run('npm run dev'));
```

### Permissions & scope

`nativeblade:install` wires up `shell:allow-execute` + a scope for common shells (`cmd`, `powershell`, `wt`, `sh`, `bash`, `osascript`, `gnome-terminal`, `konsole`, `xfce4-terminal`, `xterm`) in `src-tauri/capabilities/default.json`. To call a different binary directly (without going through the shell), add it to the `shell:allow-execute` scope.

> **Security note.** Shell execution is only enforced by the Tauri capabilities scope — NativeBlade does not sandbox the command itself. Never forward untrusted input into `->run()`. For apps that accept user input, whitelist the set of commands you expose and build the command line yourself.

---

## Process

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

Control the main window (desktop only — mobile platforms ignore these). Backed by `@tauri-apps/api/window`.

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
```

| Method | Description |
|---|---|
| `minimize()` | Minimize the window to the taskbar / dock |
| `maximize()` | Maximize the window to fill the screen |
| `unmaximize()` | Restore from maximized state |
| `toggleMaximize()` | Toggle between maximized and restored |
| `hide()` | Hide the window without quitting (process keeps running). Useful with tray + `Tray::hideOnClose()` |
| `show()` | Re-show a hidden window. Typical pair to `hide()` from a tray menu item |

Chain with other actions when you want a side-effect after work completes:

```php
return NativeBlade::notification(fn (Notification $n) => $n->title('Done'))
    ->toggleMaximize()
    ->toResponse();
```

The hide / show pair is what enables the "minimize to tray" pattern. Configure the tray with `Tray::hideOnClose()` (see [CONFIGURATION.md → System Tray](./CONFIGURATION.md#system-tray)) so the close button calls `hide()` automatically, and add a `Show` entry in the tray context menu that maps to the `show` action:

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

On mobile (Android / iOS) all window-control actions are no-ops with a console warning. Hide your window-chrome buttons on mobile via `NativeBlade::isDesktop()`.

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

    #[On('nb:secure')]
    public function onSecure($value = null, $id = null) { /* ... */ }

    #[On('nb:shell-result')]
    public function onShellResult($stdout = null, $stderr = null, $exitCode = null, $id = null) { /* ... */ }
}
```

Event names are always `nb:<name>`. They are automatically dispatched from the JS bridge via `Livewire.dispatch()` inside the iframe.

**Every result-bearing bridge supports an optional `$id` argument as the last listener parameter.** When a component has multiple calls to the same bridge (e.g. two cameras, three confirms, several geolocations), set `->id('unique_tag')` inside the builder closure — or add `"id":"unique_tag"` to the Blade `wire:nb-payload` JSON — and the same tag comes back on the listener. Use `match ($id) { ... }` to route the response. When you only have one call per component, skip the id and the argument arrives as `null`.

---

## Using Third-Party Tauri Plugins

You don't have to fork NativeBlade to use Tauri plugins that aren't built in. There are two paths depending on how much glue you want to write.

### Quick path: `tauriInvoke` from PHP (recommended)

NativeBlade ships a generic action that calls **any** Tauri plugin command directly from PHP — no JS handler required.

**Example: using `tauri-plugin-fingerprint`** (a hypothetical third-party plugin)

1. **Install the Rust crate.** Add it to `src-tauri/Cargo.toml` *outside* the `# nativeblade:plugins` markers (anything outside the markers is preserved across `nativeblade:config` runs):

   ```toml
   [dependencies]
   tauri-plugin-fingerprint = "0.1"
   ```

2. **Initialize it** in `src-tauri/src/lib.rs` outside the `// nativeblade:plugins` markers:

   ```rust
   builder.plugin(tauri_plugin_fingerprint::init())
   ```

3. **Grant capabilities** in `src-tauri/capabilities/default.json` outside the entries managed by NativeBlade:

   ```json
   { "permissions": ["fingerprint:default"] }
   ```

4. **Call it from PHP**:

   ```php
   public function login()
   {
       return NativeBlade::tauriInvoke(
           command: 'plugin:fingerprint|authenticate',
           args: ['reason' => 'Confirm to log in'],
           emit: 'fingerprint-result',
       )->toResponse();
   }

   #[On('nb:fingerprint-result')]
   public function onAuth($result = null, $error = null)
   {
       if ($error) { $this->message = $error; return; }
       if ($result?->authenticated) { $this->redirect('/dashboard'); }
   }
   ```

That's it. No JS file, no `bridge.js` patch, no extension to NativeResponse. Anything `invoke()`-able from `@tauri-apps/api/core` works through `tauriInvoke`.

### Declarative wiring: `customPlugins()`

The quick path above asks you to hand-edit three Tauri files (Cargo.toml, lib.rs, capabilities). You don't have to. Declare the plugin from your `AppServiceProvider` and `php artisan nativeblade:config` wires all of it for you, exactly like a built-in plugin.

You still author a normal Tauri 2 plugin crate (its `android/` and `ios/` native sources compile through Tauri's own toolchain). `customPlugins()` only tells NativeBlade how to plug that crate into your app.

```php
use NativeBlade\Config\CustomPlugin;

NativeBladeConfig::customPlugins([
    CustomPlugin::init(
        feature: 'fingerprint',                       // Cargo feature name
        feature_crate: 'tauri-plugin-fingerprint',    // crate name
        rust_init: 'tauri_plugin_fingerprint::init()',// expression added to .plugin(...)
        version: '0.1',                               // crates.io — OR use path: for a local/vendor crate
        capabilities: ['fingerprint:default'],
        android_permissions: ['USE_BIOMETRIC'],
        ios_plist: ['NSFaceIDUsageDescription'],
        mobile_only: false,                           // true gates the crate to Android/iOS only
    ),
]);
```

On `nativeblade:config`, NativeBlade writes, between its managed markers:

- **Cargo.toml** — the dependency line (`{ version = "0.1", optional = true }` or `{ path = "...", optional = true }`, mobile-only crates land in the Android/iOS target section) plus the `[features]` entry.
- **src/lib.rs** — the `.plugin(...)` init, gated by the Cargo feature (and by `target_os` when `mobile_only`).
- **capabilities/default.json** (or **mobile.json**) — the permissions you listed.
- **AndroidManifest.xml** / **Info.plist** — the OS permissions and usage-description keys.
- **package.json** — any `npm` guest bindings you declared.

The build (`nativeblade:build`) enables the feature automatically via `--features`.

| Field | Required | Notes |
|---|---|---|
| `feature` | yes | Cargo feature name. Must not collide with a built-in feature (see below). |
| `feature_crate` | yes | Crate name, used for the dependency and `dep:<crate>`. |
| `rust_init` | yes | The init expression added to the `.plugin(...)` chain. |
| `version` / `path` | one of | `version` for a crates.io crate; `path` for a local (`../plugins/...`) or vendor (`vendor/org/pkg`) crate. |
| `mobile_only` | no | When true, the crate and its init are gated to Android/iOS. |
| `capabilities` / `mobile_capabilities` | no | Tauri permissions added to `default.json` / `mobile.json`. |
| `android_permissions` / `ios_plist` | no | Manifest `uses-permission` entries / Info.plist usage-description keys. |
| `npm` | no | Guest-JS package(s), `name => version`. |

**iOS usage-description text.** `ios_plist` only declares which keys the plugin needs; each ships with a sensible default string. To set the exact wording the App Store reviewer sees, the app sets it with `IosConfig::infoPlist(['NSFaceIDUsageDescription' => 'Use Face ID to unlock'])` — the [iOS escape hatch](CONFIGURATION.md#custom-native-config-escape-hatch). Declare a given key in one place, not both, or it lands twice in `Info.plist`.

**Name collisions throw.** A custom `feature` may not reuse a built-in feature name (`media`, `biometric`, `haptics`, etc.), declared or not. This prevents a package from silently shadowing a first-party plugin. To replace a built-in, drop it from `plugins([...])` and give your plugin a different feature name.

**It changes the native binary.** Adding a custom plugin is a shell change, so it ships through a store release (or notarized desktop update), never through [bundle push](UPDATES.md). Existing installs need the new shell before a bundle that calls the plugin will work.

Once wired, call it from PHP via [`tauriInvoke`](#quick-path-tauriinvoke-from-php-recommended) (or wrap it in a typed API as below).

### Idiomatic path: extend the bridge

If you want a strongly-typed PHP API like the built-ins (`NativeBlade::camera()`, `NativeBlade::scan()`, etc.), wrap your invocation in:

1. A method on `NativeResponse` (or a Composer package — see [Composer plugin discovery](#composer-plugin-discovery))
2. Optionally, a builder class in `src/Plugins/` for fluent configuration
3. Optionally, a Blade component (`x-mypackage-fingerprint`) for declarative use

Underneath, it can still call `tauriInvoke` — that's the supported escape hatch. You only need to add a case to the JS bridge if your plugin needs custom JS-side glue (e.g. compressing image data before passing it to PHP).

### Custom Rust commands

If your need isn't covered by an existing Tauri plugin, write a custom Rust command in `src-tauri/src/lib.rs` and register it in `tauri::generate_handler![...]`. Then from PHP:

```php
NativeBlade::tauriInvoke(
    command: 'my_custom_command',
    args: [...],
    emit: 'custom-result',
)->toResponse();
```

For the full Tauri plugin tutorial, see the [Tauri 2 Plugin docs](https://v2.tauri.app/plugin/).

## Composer plugin discovery

You can publish a NativeBlade plugin as a regular Composer package. NativeBlade auto-discovers them via `composer.json`'s `extra.nativeblade` field — same pattern Laravel uses for its own packages.

```json
{
    "name": "myorg/my-nativeblade-plugin",
    "description": "Toast notifications for NativeBlade",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "nativeblade/nativeblade": "*"
    },
    "extra": {
        "nativeblade": {
            "components": {
                "toast": "MyOrg\\Plugin\\ToastComponent"
            },
            "views": "resources/views",
            "js": "resources/js"
        }
    }
}
```

After `composer require myorg/my-nativeblade-plugin`, the consumer can use:

```blade
<x-nativeblade-toast message="Saved!" />
```

No service provider registration needed. Components, views, and JS modules are picked up automatically from `vendor/composer/installed.json`.

---

## See Also

- [LIFECYCLE.md](./LIFECYCLE.md) — bridge internals and the exit/re-execute pattern
- [SCHEDULER.md](./SCHEDULER.md) — running code on a schedule
- [FILESYSTEM.md](./FILESYSTEM.md) — `Storage::disk('native')` and `native_path()`
- [DATABASE.md](./DATABASE.md) — external MySQL/PostgreSQL via the `nativeblade-db` driver
- [DIRECTIVES.md](./DIRECTIVES.md) — full list of `wire:nb-*` directives and attributes
- [CONFIGURATION.md](./CONFIGURATION.md) — platform-specific configuration
