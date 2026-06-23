# AdMob

Native mobile ads through the Google Mobile Ads SDK. v1 covers the two full-screen formats that fit NativeBlade's overlay model and carry most of the revenue: **rewarded** (the opt-in "watch for a reward" flow) and **interstitial** (with frequency capping baked in). The required consent layer (Google UMP on both platforms, App Tracking Transparency on iOS) is part of v1. Requires `Plugin::ADMOB`.

Banner and native ads are out of scope for v1 — they are embedded views composited with the WebView, a harder problem for a later version.

## Platforms

Mobile-only (Android + iOS). On desktop and web every call is a no-op that reports a `failed` result, so the same handler code runs on all platforms without branching.

## Testing (important)

Clicking your own **live** ads gets the AdMob account banned, so you never test with real impressions. There are two safe ways to test, both **without** publishing to a store:

**1. Test ads, zero setup (default).** In a debug build the plugin automatically serves Google's reserved **test ad units** instead of whatever unit you pass. Rewards, dismissals and capping all fire, so the full flow is testable with no AdMob account at all. Your real unit only goes live in a release build.

**2. Your real ad units, on a registered test device.** To verify your own unit ids, register your device as a test device — then your real units serve **test ads** on that device (safe, no revenue, no ban). Pass the device's hashed id to `requestAdConsent`:

```php
NativeBlade::requestAdConsent(['THE_DEVICE_HASH'])->toResponse();
```

When test device ids are present, the plugin stops substituting Google's test unit and uses your real unit (which the SDK serves as a test ad on registered devices). Find the hash by running the app once and reading the log: the Mobile Ads SDK prints a line like `setTestDeviceIds(Arrays.asList("33BE2250..."))` (Android logcat) or the equivalent in the Xcode console.

> Never click a real ad on a device that is **not** registered as a test device, in debug or release. That is the banning trigger.

## Setup

```php
use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;

// 1. Point at your AdMob app (ids differ per platform)
NativeBladeConfig::admob(
    androidAppId: 'ca-app-pub-XXXXXXXX~AAAAAAAA',
    iosAppId:     'ca-app-pub-XXXXXXXX~BBBBBBBB',
);

// 2. Ship the plugin
NativeBladeConfig::plugins([Plugin::ADMOB, /* ... */]);
```

Run `php artisan nativeblade:config`. It writes the app id as `com.google.android.gms.ads.APPLICATION_ID` meta-data on Android and `GADApplicationIdentifier` (plus `NSUserTrackingUsageDescription` and an `SKAdNetworkItems` entry) in Info.plist on iOS.

> **Advertising ID / Play data safety:** AdMob needs the `AD_ID` permission, so with `admob(...)` configured NativeBlade keeps it (it overrides the analytics-only removal). You must declare **"yes, this app uses an advertising id"** in the Play Console data-safety form, or the submission is rejected.

## Consent

Ads require consent that purchases do not. Request it once at boot, before showing any ad:

```php
use NativeBlade\Facades\NativeBlade;

public function mount()
{
    return NativeBlade::requestAdConsent()->toResponse();
}
```

On iOS this shows the App Tracking Transparency prompt, then the Google UMP (GDPR) form if required; on Android just UMP. Pass hashed test device ids to force the EEA form in debug: `requestAdConsent(['HASHED_ID'])`.

## Rewarded ads

The user opts in, so just load and show. Grant the reward only after the SDK confirms it was earned:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\RewardedAd;

public function watchForCoins()
{
    return NativeBlade::rewardedAd(function (RewardedAd $a) {
        $a->id('coins')->unit('ca-app-pub-XXXX/rewarded');
    })->toResponse();
}

#[On('nb:ad-reward')]
public function onReward($earned, $amount = null, $type = null, $id = null)
{
    if (!$earned) return;

    match ($id) {
        'coins' => auth()->user()->addCoins(50),
        default => null,
    };
}
```

## Interstitial ads

Fire at a natural transition, capped so it never spams:

```php
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\InterstitialAd;

public function nextLevel()
{
    return NativeBlade::interstitialAd(function (InterstitialAd $a) {
        $a->unit('ca-app-pub-XXXX/interstitial')->minInterval(120); // at most once every 2 min
    })->navigate('/level/' . $this->next)->toResponse();
}
```

When called within `minInterval` seconds of the last interstitial for that unit, the ad is skipped and the result reports `status: 'capped'`.

## Result events

Both formats report on `nb:ad-result`; rewarded additionally reports on `nb:ad-reward`.

```php
#[On('nb:ad-result')]
public function onAdResult($status, $error = null, $id = null)
{
    // $status = 'dismissed' | 'failed' | 'capped'
}
```

| Builder method | Applies to | Description |
|---|---|---|
| `->unit($adUnitId)` | both | AdMob ad unit id (a test unit is substituted in debug) |
| `->id($tag)` | both | Tag echoed back on the result events for routing |
| `->minInterval($seconds)` | interstitial | Frequency cap; returns `status: 'capped'` if shown too soon |

The `->toResponse()` rule applies: inside a Livewire component action call `->toResponse()`; inside a push or deep-link handler return the bare `NativeResponse`.

## See Also

- [CONFIGURATION.md](CONFIGURATION.md) — `admob()` config
- [PLUGINS.md](PLUGINS.md) — the `NativeBlade` facade
- [ANALYTICS.md](ANALYTICS.md) — the other Google SDK plugin (and the AD_ID interaction)
