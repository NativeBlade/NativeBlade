---
title: "NFC"
description: "Read NFC tags."
---

# NFC

Backed by [`tauri-plugin-nfc`](https://v2.tauri.app/plugin/nfc/). Mobile only.

**Blade:**
```blade
<button wire:nb-bridge="nfc_read" wire:nb-payload='{"id":"identify_product"}'>
    Tap product tag
</button>
```

**PHP:**
```php
use NativeBlade\Plugins\Nfc;

public function readProductTag()
{
    return NativeBlade::nfcRead(fn (Nfc $n) => $n->id('identify_product'))->toResponse();
}

public function readTicketTag()
{
    return NativeBlade::nfcRead(fn (Nfc $n) => $n->id('scan_ticket'))->toResponse();
}

#[On('nb:nfc')]
public function onNfcTag($tag, $id = null)
{
    match ($id) {
        'identify_product' => $this->loadProduct($tag['id']),
        'scan_ticket'      => $this->validateTicket($tag['id']),
        default            => null,
    };
}
```

### Reading tags only while the app is open (default)

By default, NFC works through **foreground dispatch**: the plugin captures tags only while the user is inside the app and `NativeBlade::nfcRead()` was invoked. This is the behaviour 99% of apps want. No manifest filter is required, `Plugin::NFC` and the `android.permission.NFC` entry are enough.

### Auto-launching the app from a tag (opt-in)

If your app is *built around* NFC (a transit reader, a payment terminal, an inventory scanner where the user taps the tag instead of opening the app first), you can declare an auto-launch filter via `AndroidConfig::nfcAutoLaunch()`. Android will then wake the device and bring your app to the front whenever a matching tag is presented.

```php
use NativeBlade\Config\NfcTech;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::android(function ($c) {
    // (a) Any NFC tag wakes the app, broadest filter
    $c->nfcAutoLaunch(anyTag: true);

    // (b) Only tags exposing specific technologies wake the app
    $c->nfcAutoLaunch(techs: [NfcTech::ISO_DEP, NfcTech::MIFARE_CLASSIC]);

    // (c) Both
    $c->nfcAutoLaunch(anyTag: true, techs: [NfcTech::ISO_DEP]);
});
```

After declaring, run `php artisan nativeblade:config` to write the manifest filters and the `res/xml/nfc_tech_filter.xml` resource.

**Warning:** turning auto-launch on (especially `anyTag: true` or `NfcTech::ISO_DEP`) means **contactless credit cards, transit cards, and corporate badges** will wake the device and launch your app whenever they pass near the phone. That is the exact symptom users report when this is misconfigured. Only enable it if your app actually needs that behaviour.

**Available `NfcTech` cases** (mapping to `android.nfc.tech.*`):

| Case | Class | Typical tags |
|---|---|---|
| `ISO_DEP` | `IsoDep` | Credit cards, transit cards, NFC passports |
| `NFC_A` | `NfcA` | MIFARE Classic, most Android phone-emulated tags |
| `NFC_B` | `NfcB` | Some ID cards |
| `NFC_F` | `NfcF` | Japanese transit / e-money (FeliCa) |
| `NFC_V` | `NfcV` | Vicinity tags, library books |
| `NDEF` | `Ndef` | Vast majority of consumer NFC tags |
| `NDEF_FORMATABLE` | `NdefFormatable` | Blank tags ready to format |
| `MIFARE_CLASSIC` | `MifareClassic` | Legacy access control, transit |
| `MIFARE_ULTRALIGHT` | `MifareUltralight` | Event tickets, paper-thin tags |
| `NFC_BARCODE` | `NfcBarcode` | Kovio barcode payload tags |

The generator writes/removes the filters idempotently, drop the `nfcAutoLaunch()` call and rerun `nativeblade:config` to revert to the safe default.

---

