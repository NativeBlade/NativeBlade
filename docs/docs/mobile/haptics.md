---
title: "Haptics"
description: "Vibration and haptic feedback."
---

# Haptics

Backed by [`tauri-plugin-haptics`](https://v2.tauri.app/plugin/haptics/). Mobile only (desktop is a no-op).

### Attribute shortcut (preferred for buttons)

```blade
<button nb-feedback wire:click="save">Save</button>
```

Any element with `nb-feedback` triggers a light selection haptic on touchstart. Zero configuration.

### Explicit calls

**Blade:**
```blade
<button wire:nb-bridge="vibrate" wire:nb-payload='{"duration":200}'>Vibrate</button>
<button wire:nb-bridge="impact" wire:nb-payload='{"style":"heavy"}'>Heavy impact</button>
<button wire:nb-bridge="selection">Selection</button>
```

**PHP:**
```php
NativeBlade::vibrate(200);
NativeBlade::impact('heavy'); // 'light' | 'medium' | 'heavy'
NativeBlade::selection();

// Or chained with other actions:
return NativeBlade::notification(fn (Notification $n) => $n->title('Saved')->body('Profile updated'))
    ->vibrate(150)
    ->toResponse();
```

---

