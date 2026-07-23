---
title: "Platform Detection"
description: "Detect the platform and environment at runtime."
---

# Platform Detection

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

