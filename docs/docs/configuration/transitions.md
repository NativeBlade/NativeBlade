---
title: "Page Transitions"
description: "The default page transition."
---

# Page Transitions

```php
NativeBladeConfig::transition('slide');  // slide + fade (default in demo)
NativeBladeConfig::transition('fade');   // fade only
NativeBladeConfig::transition('zoom');   // zoom in
NativeBladeConfig::transition('none');   // no transition
```

Per-navigation override:

```php
NativeBlade::navigate('/lesson/1')->transition('slide')->toResponse();
```

Available: `none`, `slide`, `fade`. Page transitions are intentionally limited to these three because each one requires its own dual-iframe choreography in the router. For richer element-level animations, see [Animations](/core/animations/) (`nb-animation` attribute and `<x-nativeblade-animate>` component).
