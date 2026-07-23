---
title: "Sharing"
description: "The native share sheet."
---

# Sharing

Backed by the NativeBlade `nativeblade-sharing` native plugin: `UIActivityViewController` on iOS, `Intent.ACTION_SEND` on Android. Mobile only. Requires `Plugin::SHARING`.

Opens the OS share sheet so the user can send text and/or a link to other apps (messages, mail, social, clipboard). v1 shares text and URLs; file sharing comes later.

**Blade:**
```blade
<button wire:nb-bridge="share"
        wire:nb-payload='{"text":"Check this out","url":"https://myapp.com/p/42"}'>
    Share
</button>
```

**PHP:**
```php
public function invite()
{
    return NativeBlade::share(
        text: 'Join me on MyApp',
        url: 'https://myapp.com/invite/abc',
    )->toResponse();
}
```

Pass at least one of `text` / `url`. It is fire-and-forget: the OS sheet appears and there is no result back. No-op on desktop.

---

