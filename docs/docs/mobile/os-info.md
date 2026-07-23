---
title: "OS Info"
description: "Platform, version, and device metadata."
---

# OS Info

Backed by [`tauri-plugin-os`](https://v2.tauri.app/plugin/os-info/). Returns platform, version, architecture, and locale.

**Blade:**
```blade
<button wire:nb-bridge="os_info">Check device</button>
```

**PHP:**
```php
public function detectPlatform()
{
    return NativeBlade::osInfo()->toResponse();
}

#[On('nb:os-info')]
public function onOsInfo($info)
{
    // $info = ['platform' => 'android', 'version' => '14', 'arch' => 'arm64', 'locale' => 'en-US']
    $this->isMobile = in_array($info['platform'], ['android', 'ios']);
}
```

---

