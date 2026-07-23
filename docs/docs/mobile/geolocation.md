---
title: "Geolocation"
description: "GPS and network location."
---

# Geolocation

Backed by [`tauri-plugin-geolocation`](https://v2.tauri.app/plugin/geolocation/). Automatically requests permission on first use.

**Blade (simple):**
```blade
<button wire:nb-bridge="geolocation">Find nearby</button>
```

**Blade (with id):**
```blade
<button wire:nb-bridge="geolocation" wire:nb-payload='{"id":"nearby_users"}'>
    Nearby users
</button>
<button wire:nb-bridge="geolocation" wire:nb-payload='{"id":"delivery_address"}'>
    Use current address
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Geolocation;

public function findNearby()
{
    return NativeBlade::geolocation(fn (Geolocation $g) => $g->id('nearby_users'))->toResponse();
}

public function useCurrentAddress()
{
    return NativeBlade::geolocation(fn (Geolocation $g) => $g->id('delivery_address'))->toResponse();
}

#[On('nb:geolocation')]
public function onLocation($position, $id = null)
{
    $lat = $position['coords']['latitude'];
    $lng = $position['coords']['longitude'];

    match ($id) {
        'nearby_users'     => $this->loadNearbyUsers($lat, $lng),
        'delivery_address' => $this->setDeliveryAddress($lat, $lng),
        default            => null,
    };
}
```

---

