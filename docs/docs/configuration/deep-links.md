---
title: "Deep Links"
description: "Universal and app links."
---

# Deep Links (Universal / App Links)

Verified https links that open the app directly: Universal Links on iOS, App Links on Android. Custom `myapp://` schemes are intentionally not used (they are unverified and show an app chooser on Android). Requires `Plugin::DEEP_LINK`.

Declare your domains and a handler in the AppServiceProvider:

```php
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::deepLinks(['myapp.com', 'www.myapp.com'], function (string $url) {
    return NativeBlade::navigate(parse_url($url, PHP_URL_PATH) ?: '/');
});
```

The handler runs for both a cold start (app launched from a link) and a link tapped while the app is already running, no matter which screen is showing. Return a `NativeResponse` (typically `navigate`) to route the URL.

Run `php artisan nativeblade:config` to wire the native side.

- **Android (App Links):** an `autoVerify` intent-filter per domain is written into the manifest automatically.

### Association files

Verified links need two files hosted on your domain. Generate them with:

```bash
php artisan nativeblade:deeplinks --team=YOUR_TEAM_ID --fingerprint=AA:BB:CC:...
```

This writes `public/.well-known/assetlinks.json` (Android) and `public/.well-known/apple-app-site-association` (iOS), filled from your `identifier()` config. Host both at `https://yourdomain.com/.well-known/` for every domain. The Apple file must be served with **no file extension** and `Content-Type: application/json`.

- `--fingerprint` is the SHA-256 of your Android signing certificate (`keytool -list -v -keystore your.keystore`).
- `--team` is your Apple Developer Team ID.

Omit the flags to scaffold the files with placeholders you fill in later.

### iOS: Associated Domains capability (manual, in Xcode)

iOS needs the entitlement set in Xcode, the same one-time way push notifications are enabled: open the project, select the app target, go to **Signing & Capabilities**, click **+ Capability**, add **Associated Domains**, and enter `applinks:yourdomain.com` for each domain.

