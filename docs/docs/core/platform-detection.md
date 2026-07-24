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

## In JavaScript

### From a shell module or shell script

Shell JavaScript runs in the shell document, where the framework sets a
synchronous flag at boot, so you can branch without any await:

```js
if (window.__NB_IS_MOBILE__) {
    // Android or iOS
} else {
    // desktop
}
```

### From a page script (`public/js`)

Your page scripts run in an isolated frame, so hand them the platform from PHP
with `jsEvent`:

```php
return NativeBlade::jsEvent('platform', ['os' => NativeBlade::platform()])->toResponse();
```

```js
// public/js/app/main.js
window.addEventListener('nb:js:platform', (e) => {
    if (e.detail.os === 'android' || e.detail.os === 'ios') {
        // mobile
    }
});
```

For a quick check without a round-trip, plain `navigator.userAgent` also works in
any script:

```js
const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
```

