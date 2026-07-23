---
title: "Barcode Scanner"
description: "Scan QR codes and barcodes with the camera."
---

# Barcode Scanner

Backed by [`tauri-plugin-barcode-scanner`](https://v2.tauri.app/plugin/barcode-scanner/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="scan"
        wire:nb-payload='{"formats":["QR_CODE","EAN_13"],"id":"product_lookup"}'>
    Scan product
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Scan;

public function scanProduct()
{
    return NativeBlade::scan(function (Scan $s) {
        $s->id('product_lookup')
          ->formats(['QR_CODE', 'EAN_13', 'CODE_128']);
    })->toResponse();
}

public function scanTicket()
{
    return NativeBlade::scan(function (Scan $s) {
        $s->id('event_ticket')
          ->formats(['QR_CODE']);
    })->toResponse();
}

#[On('nb:scan')]
public function onScan($result, $id = null)
{
    $code = $result['content'];

    match ($id) {
        'product_lookup' => $this->lookupProduct($code),
        'event_ticket'   => $this->validateTicket($code),
        default          => null,
    };
}
```

> **Scanning overlay (automatic).** The underlying Tauri plugin is headless: it shows the camera behind a transparent webview and expects the app to draw the scanning UI, so on its own a scan opens a bare fullscreen camera with no way out. NativeBlade renders that UI for you: when a scan starts it shows a viewfinder and a **Cancel** button, and removes them when a code is read or the user cancels. You do not call anything extra. To cancel from your own button instead, fire the `scan_cancel` bridge:
>
> ```blade
> <button wire:nb-bridge="scan_cancel">Stop scanning</button>
> ```

---

