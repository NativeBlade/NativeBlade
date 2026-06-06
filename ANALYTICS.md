# Analytics

Firebase Analytics through the **native SDK** (not the web SDK), so events feed Firebase's app analytics: install attribution, audiences, and the AdMob / Google Ads integration. A web SDK inside the WebView would be logged as web traffic and lose all of that. Requires `Plugin::ANALYTICS`.

## Platforms

This plugin is **mobile-only** (Android + iOS). Desktop is a WebView and web mode is the browser, neither has a native Firebase SDK, so `NativeBlade::analytics(...)` is a **no-op** there.

To track analytics on web and desktop, load the **Firebase JS SDK** in your frontend with the web config object and call it directly:

```html
<script type="module">
  import { initializeApp } from 'https://www.gstatic.com/firebasejs/11.0.0/firebase-app.js';
  import { getAnalytics, logEvent } from 'https://www.gstatic.com/firebasejs/11.0.0/firebase-analytics.js';

  const app = initializeApp({
    apiKey: '...',
    projectId: '...',
    appId: '...',
    measurementId: 'G-XXXXXXX', // Analytics id
  });
  const analytics = getAnalytics(app);
  // logEvent(analytics, 'add_to_cart', { item_id: 'sku_123' });
</script>
```

That config object is a separate **Web app** registration in the same Firebase project (Android uses `google-services.json`, iOS `GoogleService-Info.plist`, web this snippet), so it is one project with three platform registrations. Native `google-services` files do not apply to desktop or web.

## Setup

Analytics shares the Firebase project config with push and any other Firebase service, so it lives at the top level:

```php
use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;

// 1. Point at your Firebase project (Android + iOS files)
NativeBladeConfig::firebase(
    base_path('secrets/google-services.json'),
    base_path('secrets/GoogleService-Info.plist'),
);

// 2. Ship the plugin
NativeBladeConfig::plugins([Plugin::ANALYTICS, /* ... */]);

// 3. Enable analytics, with optional auto screen tracking
NativeBladeConfig::analyticsConfig(autoScreenTracking: true);
```

Run `php artisan nativeblade:config`. On Android this copies `google-services.json` and enables the `google-services` Gradle plugin (shared with push). On iOS it copies `GoogleService-Info.plist` into the Xcode project and registers it in the app's bundle resources, so Firebase finds it at launch.

> **Migrating from push:** the deprecated `->fcmConfig(...)` still works, but move the `google-services.json` path to `NativeBladeConfig::firebase(...)`. The same file backs every Firebase service, so it does not belong under push.

## Logging

`NativeBlade::analytics(...)` takes a closure builder, like the other actions, and returns a chainable `NativeResponse`:

```php
use NativeBlade\Plugins\Analytics;

return NativeBlade::analytics(function (Analytics $a) {
    $a->event('add_to_cart', ['item_id' => 'sku_123', 'value' => 9.99])
      ->setUserProperty('plan', 'pro');
})->toResponse();
```

Builder methods:

| Method | Firebase call |
|---|---|
| `->event($name, $params = [])` | `logEvent` |
| `->screen($name)` | `screen_view` event |
| `->setUserId($id)` | `setUserId` |
| `->setUserProperty($key, $value)` | `setUserProperty` |
| `->enable()` / `->disable()` | `setAnalyticsCollectionEnabled` |

The `->toResponse()` rule applies: inside a Livewire component action call `->toResponse()`; inside a push or deep-link handler return the bare `NativeResponse`.

## Screen tracking

The native SDK auto-collects screen views from native screen changes, but a NativeBlade app is a single WebView, so it only ever sees one screen. Two ways to fix that:

- **Automatic:** `NativeBladeConfig::analyticsConfig(autoScreenTracking: true)` logs a `screen_view` on every router navigation, using the raw path as the screen name.
- **Manual:** `NativeBlade::analytics(fn ($a) => $a->screen('Checkout'))` for a custom name.

## Consent (LGPD / GDPR)

`disable()` calls the native `setAnalyticsCollectionEnabled(false)`, which stops collection **at the SDK level** and **persists across launches** (you call it once when consent changes, not every boot). `enable()` turns it back on.

For consent-first apps, ship with collection off and turn it on only after the user opts in:

```php
NativeBladeConfig::analyticsConfig(collectionEnabledByDefault: false); // build-time default: off
```
```php
// after the user accepts
return NativeBlade::analytics(fn ($a) => $a->enable())->toResponse();
```

`collectionEnabledByDefault: false` writes the build-time default (`firebase_analytics_collection_enabled` meta-data on Android, `FIREBASE_ANALYTICS_COLLECTION_ENABLED` in Info.plist on iOS), so nothing is collected on the first launch before the user can choose.

> Ad and attribution features (IDFA on iOS) additionally need App Tracking Transparency, the same consent layer used by AdMob.

## See Also

- [CONFIGURATION.md](CONFIGURATION.md) — `firebase()` and `analyticsConfig()` config
- [PLUGINS.md](PLUGINS.md) — the `NativeBlade` facade
- [PUSH.md](PUSH.md) — the other Firebase service
