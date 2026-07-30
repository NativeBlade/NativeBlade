---
title: "Push Notifications"
description: "Send and handle push notifications on mobile."
---

# Push Notifications

Server-pushed notifications on Android (FCM) and iOS (APNS) that can wake the app even when it's closed. Receiving is handled by the NativeBlade plugin; sending is done from your own backend using whatever library you prefer.

The same plugin powers `NativeBlade::notification(...)`, local, scheduled, and remote notifications share one native code path (WorkManager on Android, `UNUserNotificationCenter` on iOS). The local and scheduled side is covered in the [next section](#local-and-scheduled-notifications). The plugin is enabled by declaring `Plugin::PUSH` in your `NativeBladeConfig::plugins([...])`, it covers both push *and* local.

---

## Local and scheduled notifications

`NativeBlade::notification()` posts a notification from PHP right now, and `NativeBlade::scheduleNotification()` posts one later, on the same native path as push. Every builder method is chainable, and you dispatch with `->toResponse()` from a Livewire action.

Fire one immediately:

```php
use NativeBlade\Plugins\Notification;

return NativeBlade::notification(fn (Notification $n) => $n
    ->title('Saved')
    ->body('Your changes are safe.')
    ->channel('app'))
    ->toResponse();
```

Schedule it for a specific moment with `at()`:

```php
return NativeBlade::scheduleNotification(fn (Notification $n) => $n
    ->id('trial-ending')                 // give it an id so you can cancel later
    ->title('Your trial ends tomorrow')
    ->body('Upgrade to keep your builds running.')
    ->at(now()->addDay()))
    ->toResponse();
```

Or make it repeat, `dailyAt('HH:MM')` for a time of day, `every()` for an interval:

```php
// Every day at 09:00
NativeBlade::scheduleNotification(fn (Notification $n) => $n
    ->id('daily-standup')
    ->title('Standup in 5 minutes')
    ->dailyAt('09:00'));

// Every 2 hours
NativeBlade::scheduleNotification(fn (Notification $n) => $n
    ->id('hydrate')
    ->title('Time for water')
    ->every('hour', 2));
```

`every()` kinds are `minute`, `hour`, `day`, `week`, `month` (with an optional count, default `1`).

Cancel a scheduled notification by its id, or clear them all:

```php
NativeBlade::cancelNotification('trial-ending')->toResponse();
NativeBlade::cancelAllNotifications()->toResponse();
```

::: callout note "Exact timing on Android"
`scheduleNotification()` asks for exact delivery so the notification fires on time even in Doze. Android needs `Permission::EXACT_ALARM` declared for that, without it the delivery degrades to inexact and may fire late. iOS is always exact.
:::

---

## Android

1. Create a project in [Firebase Console](https://console.firebase.google.com), add an Android app with the package name matching `AndroidConfig::identifier()`, and download `google-services.json`.

2. Save the file somewhere in your Laravel project (add it to `.gitignore`) and point the service provider at it:

   ```php
   use NativeBlade\Config\Push\AndroidPushNotificationConfig;
   use NativeBlade\Plugins\PushPayload;

   NativeBladeConfig::android(function (AndroidConfig $config) {
       $config
           ->permissions([Permission::NOTIFICATIONS => 'Receive updates'])
           ->notification(function (AndroidPushNotificationConfig $push) {
               $push
                   ->fcmConfig(base_path('secrets/google-services.json'))
                   ->channel('app', 'App', importance: 'high')
                   ->onTokenRefresh(fn ($token) => NativeBlade::setState('push.token', $token))
                   ->onReceive(fn (PushPayload $payload) => /* ... */);
           });
   });
   ```

3. Run `php artisan nativeblade:config`. NativeBlade copies `google-services.json` into the Android project and enables the Google Services Gradle plugin automatically.

4. Build: `php artisan nativeblade:build android`.

---

## iOS

1. Run `php artisan nativeblade:add ios` (if you haven't already), open the Xcode project, select the app target → **Signing & Capabilities** → **+ Capability** → **Push Notifications**. This is a one-time setup.

2. Configure the service provider:

   ```php
   use NativeBlade\Config\Push\IosPushNotificationConfig;

   NativeBladeConfig::ios(function (IosConfig $config) {
       $config
           ->permissions([Permission::NOTIFICATIONS => 'Receive updates'])
           ->notification(function (IosPushNotificationConfig $push) {
               $push
                   ->environment('production')  // 'sandbox' for TestFlight / dev builds
                   ->onTokenRefresh(fn ($token) => NativeBlade::setState('push.token', $token))
                   ->onReceive(fn (PushPayload $payload) => /* ... */);
           });
   });
   ```

3. Build and run on a **real device**: `php artisan nativeblade:build ios`. The iOS Simulator does not receive pushes, test on hardware.

---

## Handling pushes

Your `onReceive` callback is invoked on every incoming push and on cold start when the user taps a notification. It receives a `PushPayload` DTO:

```php
readonly string $id;               // unique message id
readonly array  $data;             // key/value payload from your server
readonly array  $notification;     // { title, body } if present
readonly string $state;            // 'foreground' | 'background' | 'cold'
```

Route on `$payload->data` and return a `NativeResponse` to trigger native actions:

```php
->onReceive(function (PushPayload $payload) {
    return match ($payload->data['type'] ?? null) {
        'new_lesson' => NativeBlade::navigate('/lesson/' . $payload->data['lesson_id']),
        'chat'       => NativeBlade::navigate('/chat/' . $payload->data['room_id']),
        default      => null,
    };
});
```

Typical pattern: on `foreground` just update state silently; on `background` / `cold` navigate the user to the relevant screen.

---

## Sending pushes

NativeBlade's job ends at **receiving**. Sending is done from your own backend with server-side credentials that never ship with the app:

- **Android**: Firebase Admin SDK with a service account JSON (different from the client `google-services.json`)
- **iOS**: APNS `.p8` auth key downloaded from the Apple Developer portal

Use any library you prefer, `kreait/firebase-php`, `edamov/pushok`, etc. The NativeBlade app only needs the device token, which your `onTokenRefresh` callback delivers.

---

## Troubleshooting

- **Android: `Firebase not initialized` in logcat**, `nativeblade:config` didn't run, or `google-services.json` is missing. Re-run the command and check the path in `fcmConfig()`.

- **iOS: `aps-environment entitlement missing` in NSLog**, the Push Notifications capability isn't enabled in the Xcode project. Open Xcode → Signing & Capabilities → + Capability.

- **Pushes don't arrive on iOS**, make sure `environment()` matches how the app is signed. Debug/TestFlight builds need `'sandbox'`, App Store builds need `'production'`. Mixing the two is the #1 cause of silent delivery failures.

- **Token never arrives on iOS simulator**, expected. Test on a physical device.
