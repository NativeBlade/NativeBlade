---
title: "In-App Review"
description: "Prompt for a native app store review."
---

# In-App Review

Backed by the NativeBlade `nativeblade-review` native plugin: `SKStoreReviewController` on iOS and Google Play In-App Review on Android. Mobile only. Requires `Plugin::IN_APP_REVIEW`.

Asks the OS to show its own in-place review card so the user can rate the app without leaving for the store.

**Blade:**
```blade
<button wire:nb-bridge="request_review">Rate this app</button>
```

**PHP:**
```php
public function rateApp()
{
    return NativeBlade::requestReview()->toResponse();
}
```

On mobile the OS already knows which app to show (it is identified by your bundle id / package name from the store listing), so there is nothing to pass. On **desktop this is a no-op**, there is no native in-place review, so for a "rate us" link there just call `NativeBlade::openUrl(...)` with your store page yourself.

> **The OS decides whether it shows.** Both StoreKit and Play heavily rate-limit the prompt (roughly a few times per year) and may display nothing at all. You get **no result back** about whether the user reviewed, and you must **not** reward or gate anything on it. Apple and Google forbid incentivizing reviews. Call it at a natural, positive moment, never in a loop or on every launch.

---

