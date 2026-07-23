---
title: "Firebase & Analytics"
description: "Firebase and analytics configuration."
---

# Firebase & Analytics

`google-services.json` (Android) and `GoogleService-Info.plist` (iOS) configure the whole Firebase project, shared by push, analytics, and any other Firebase service. Point at them once, at the top level:

```php
NativeBladeConfig::firebase(
    base_path('secrets/google-services.json'),
    base_path('secrets/GoogleService-Info.plist'),
);
```

`php artisan nativeblade:config` copies `google-services.json` into the Android project and enables the `google-services` Gradle plugin.

> The push `->fcmConfig(...)` is deprecated in favour of this. It still works for backward compatibility, but the file belongs at the top level since every Firebase service reads it.

Enable Firebase Analytics (needs `Plugin::ANALYTICS` and the firebase config above):

```php
NativeBladeConfig::analyticsConfig(
    autoScreenTracking: true,          // log screen_view on every router navigation
    collectionEnabledByDefault: true,  // false ships with collection off (consent-first)
);
```

See [Analytics](/mobile/analytics/) for the logging API, screen tracking, and the consent flow.

