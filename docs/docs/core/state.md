---
title: "State Management"
description: "Persist key-value state across app launches."
---

# State Management

Native-backed key/value store that survives app restarts:

```php
NativeBlade::setState('user_prefs', ['theme' => 'dark'], scope: 'persistent');
$prefs = NativeBlade::getState('user_prefs', default: []);
NativeBlade::forget('user_prefs');
NativeBlade::flush(scope: 'persistent');

// Batched writes for performance:
NativeBlade::pool(function () {
    NativeBlade::setState('key1', 'value1');
    NativeBlade::setState('key2', 'value2');
});
```

See [Lifecycle](/core/lifecycle/) for how state interacts with the boot sequence.

