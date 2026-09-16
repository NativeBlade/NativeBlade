---
title: "App Settings"
description: "Open the OS settings page for your app."
---

# App Settings

Backed by the NativeBlade `nativeblade-system` native plugin:
`Settings.ACTION_APPLICATION_DETAILS_SETTINGS` on Android and
`UIApplication.openSettingsURLString` on iOS. This plugin is always on, so there
is no `Plugin::` to declare and nothing to configure.

Opens the OS settings page for your app. This is the natural next step after a
permission was permanently denied, where the app can no longer show its own prompt
and the user has to flip the switch in system settings.

**Blade:**
```blade
<button wire:nb-bridge="open_app_settings">Open settings</button>
```

**PHP:**
```php
public function openSettings()
{
    return NativeBlade::openAppSettings()->toResponse();
}
```

A common pattern is to call it when a permission check comes back `denied`:

```php
#[On('nb:permission')]
public function onPermission($name, $status)
{
    if ($status === 'denied') {
        // The app cannot re-prompt; send the user to settings instead.
        $this->openSettings();
    }
}
```

On **desktop this is a no-op**: there is no per-app settings screen, so the call
does nothing. It fires only on Android and iOS.

See [Permissions](/mobile/permissions/) for checking and requesting the
permissions that lead here.

---
