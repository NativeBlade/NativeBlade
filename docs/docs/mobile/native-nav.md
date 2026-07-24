---
title: "Native Navigation"
description: "Upgrade page transitions to the platform's native compositor on Android."
---

# Native Navigation

Page transitions animate on every platform out of the box (see
[Page Transitions](/configuration/transitions/)). `Plugin::NATIVE_NAV` upgrades
them on Android and iOS: instead of a CSS transition, the outgoing page is
snapshotted and animated by the platform's own compositor (Material shared-axis
on Android, a push/pop slide on iOS).

It is self-detecting. You do not wire anything up: when the plugin is present and
the platform supports the native compositor, the router uses it; otherwise it
falls back to the CSS transition, so navigation stays smooth everywhere.

## Per platform

| Platform | Transition |
|---|---|
| Android | Native Material compositor (this plugin) |
| iOS | Native push/pop slide (this plugin) |
| Desktop | CSS transition |

## Enable

```php
NativeBladeConfig::plugins([
    Plugin::NATIVE_NAV,
]);
```

The transition style itself is chosen with `NativeBladeConfig::transition(...)`.
See [Page Transitions](/configuration/transitions/) and
[Navigation](/core/navigation/).
