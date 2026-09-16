---
title: "Permissions"
description: "Check and request runtime permissions with a normalized status."
---

# Permissions

Most permissions are handled on demand: the first time you use a feature, the OS
prompts. When you need to know a permission's state up front, or ask for it with
your own context (an onboarding screen, a "why we need this" explainer), use the
permission API. Results come back on the `nb:permission` Livewire event with a
normalized status, so iOS and Android read the same way.

Use a `NativeBlade\Config\Permission` constant for the permission name.

## Check and request

```php
use NativeBlade\Config\Permission;
use NativeBlade\Facades\NativeBlade;

// Silent: never shows a prompt.
return NativeBlade::checkPermission(Permission::LOCATION)->toResponse();

// Shows the OS prompt when the permission has not been decided yet.
return NativeBlade::requestPermission(Permission::CAMERA)->toResponse();
```

The outcome arrives on the `nb:permission` event:

```php
use Livewire\Attributes\On;

#[On('nb:permission')]
public function onPermission(string $name, string $status)
{
    // $name:   the permission you asked about
    // $status: 'granted' | 'denied' | 'prompt' | 'unsupported'
    if ($name === 'camera' && $status === 'denied') {
        $this->showEnableCameraHint = true;
    }
}
```

## Status values

| Status | Meaning |
|---|---|
| `granted` | The app has the permission. |
| `denied` | Refused. On iOS you cannot prompt again, send the user to Settings. |
| `prompt` | Not decided yet, `requestPermission` will show the OS dialog. |
| `unsupported` | No backing plugin for this permission (or running on desktop/web). |

## Coverage

Each permission resolves through a plugin that already exposes it, so the backing
plugin must be enabled. Anything else returns `unsupported`.

| Permission | Backing plugin | Check | Request |
|---|---|---|---|
| `Permission::LOCATION` | `Plugin::GEOLOCATION` | yes | yes |
| `Permission::CAMERA` | `Plugin::MEDIA` | yes | yes |
| `Permission::NOTIFICATIONS` | `Plugin::PUSH` (mobile), built in on desktop | yes | yes |

Other constants (`MICROPHONE`, `CONTACTS`, `CALENDAR`, `PHOTOS`, ...) return
`unsupported` until a plugin backs them.

## Open the app settings

After a `denied` the app usually cannot prompt again, so the natural next step is
to send the user to the OS settings page for the app:

```php
return NativeBlade::openAppSettings()->toResponse();
```

Works on iOS. On Android and desktop it is a no-op for now.

## Notes

- Declaring a permission (so it appears in the manifest / Info.plist) is separate
  from asking for it at runtime. See [Plugins](/core/plugins/) for declaring the
  permissions your app ships with.
- `requestPermission` shows the system dialog only when the state is `prompt`. If
  it is already `granted` or `denied`, no dialog appears and you get the current
  status back.
