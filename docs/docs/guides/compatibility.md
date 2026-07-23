---
title: "Compatibility"
description: "Which platform targets each NativeBlade SDK line ships against."
---

# Compatibility

Each NativeBlade SDK is a release line. A line pins a set of platform targets:
the Android build tools, the desktop shell version, and the PHP runtime. When a
new SDK ships, these move together, which is why an SDK bump is treated as a
breaking boundary.

::: callout note "SDK is the NativeBlade version, not the Android API level"
"SDK 37" means the NativeBlade release line. It is not the Android `targetSdk`.
The Android API level and NDK for a line are listed in the table below.
:::

## SDK 37

| Target | Value |
|---|---|
| Laravel | 13 |
| Livewire | 4 |
| PHP | 8.5 |
| Desktop shell | Tauri v2 |
| Android NDK | r26 |
| Android page size | 16 KB aligned |

Values for older SDK lines stay documented in their own version of these docs.
Switch versions from the menu at the top of the sidebar.
