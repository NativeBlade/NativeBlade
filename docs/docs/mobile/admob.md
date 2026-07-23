---
title: "AdMob"
description: "Show Google AdMob ads in a mobile app."
---

# AdMob

Native mobile ads through the Google Mobile Ads SDK. Three formats are covered: **rewarded** (the opt-in "watch for a reward" flow), **interstitial** (with frequency capping baked in) and **banner** (an adaptive banner anchored below the WebView). The required consent layer (Google UMP on both platforms, App Tracking Transparency on iOS) is included. Requires `Plugin::ADMOB`.

Native (in-content) ads are out of scope, they are embedded views composited with the WebView, a harder problem for a later version. The banner works because it never overlaps the page: it is pinned to the bottom edge and the WebView shrinks to make room.

## Platforms

Mobile-only (Android + iOS). On desktop and web, ad-show calls (`rewardedAd`, `interstitialAd`, `bannerAd`) are no-ops that report a `failed` result on `nb:ad-result`, so the same handler code runs on all platforms without branching. `requestAdConsent` and `hideBannerAd` are silent no-ops there (they emit no event on any platform).

## Testing (important)

Clicking your own **live** ads gets the AdMob account banned, so you never test with real impressions. There are two safe ways to test, both **without** publishing to a store:

**1. Test ads, zero setup (default).** In a debug build the plugin automatically serves Google's reserved **test ad units** instead of whatever unit you pass. Rewards, dismissals and capping all fire, so the full flow is testable with no AdMob account at all. Your real unit only goes live in a release build.

> **Caveat, GDPR regions:** the zero-setup path relies on Google's *sample app id* (`ca-app-pub-3940256099942544~...`). Google's ad server rejects requests from that app id in apps other than its own samples, observed as `failed` with `HTTP response code: 403`, notably when the device is in a GDPR region, even with consent granted and even for test units. If you hit that, create a (free) AdMob app, put its real app id in `NativeBladeConfig::admob(...)` and rerun `nativeblade:config`, in debug the plugin still substitutes Google's test units, so there is no ban risk.

**2. Your real ad units, on a registered test device.** To verify your own unit ids, register your device as a test device, then your real units serve **test ads** on that device (safe, no revenue, no ban). Pass the device's hashed id to `requestAdConsent`:

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

Ads require consent that purchases do not. Request it once when the screen opens, before showing any ad. Trigger it with `wire:init` rather than `mount()`, so the request (which crosses the native bridge) never blocks the first paint:

```blade
<div wire:init="requestConsent">
    {{-- your screen --}}
</div>
```

```php
use NativeBlade\Facades\NativeBlade;

public function requestConsent()
{
    return NativeBlade::requestAdConsent()->toResponse();
}
```

On iOS this shows the App Tracking Transparency prompt, then the Google UMP (GDPR) form if required; on Android just UMP. Pass hashed test device ids to force the EEA form in debug: `requestAdConsent(['HASHED_ID'])`.

Chaining an ad into the same response is safe, ad loads wait for the in-flight consent flow to finish before requesting:

```php
return NativeBlade::requestAdConsent()
    ->bannerAd(fn (BannerAd $a) => $a->id('home')->unit('ca-app-pub-XXXX/banner'))
    ->toResponse();
```

> **`failed` with `HTTP response code: 403`?** The ad server refused the request. Two known causes, both of which also block Google's **test** units: **(a)** the stored consent does not allow ads, GDPR applies and the user declined, or an interrupted consent form left a denied state; clear the app's data (`adb shell pm clear <package>`) so the form shows again, and accept it. Note that uninstall+reinstall is **not** enough: Android's auto backup restores the app's data (consent state included) unless the app sets `allowBackup(false)` in the Android config. **(b)** the app is still on Google's *sample app id* and the device is in a GDPR region, see the caveat under [Testing](#testing-important); configure a real AdMob app id.

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
public function onReward($earned, $amount = null, $rewardType = null, $id = null)
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

## Banner ads

An anchored adaptive banner pinned **below the WebView**, the page shrinks to make room (above the navigation bar / home indicator), so the banner never covers content. Show it when the screen opens, hide it when the screen should be ad-free:

```php
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\BannerAd;

public function showBanner()
{
    return NativeBlade::bannerAd(function (BannerAd $a) {
        $a->id('home')->unit('ca-app-pub-XXXX/banner');
    })->toResponse();
}

public function hideBanner()
{
    return NativeBlade::hideBannerAd()->toResponse();
}
```

The result arrives on `nb:ad-result` with `status: 'shown'` once the first ad fills (the SDK refreshes it on its own afterwards) or `status: 'failed'` when nothing fills, on failure the reserved space is given back automatically. Calling `bannerAd` while a banner is showing replaces it; the banner also stays up across Livewire navigation, so hide it explicitly when leaving ad-supported screens. On device rotation the banner is rebuilt for the new width automatically (adaptive banners are sized for the width they were loaded with).

## Result events

All formats report on `nb:ad-result`; rewarded additionally reports on `nb:ad-reward`.

```php
#[On('nb:ad-result')]
public function onAdResult($status, $error = null, $id = null)
{
    // $status = 'dismissed' | 'failed' | 'capped' (full-screen) | 'shown' (banner)
}
```

| Builder method | Applies to | Description |
|---|---|---|
| `->unit($adUnitId)` | all | AdMob ad unit id (a test unit is substituted in debug) |
| `->id($tag)` | all | Tag echoed back on the result events for routing |
| `->minInterval($seconds)` | interstitial | Frequency cap; returns `status: 'capped'` if shown too soon |

The `->toResponse()` rule applies: inside a Livewire component action call `->toResponse()`; inside a push or deep-link handler return the bare `NativeResponse`.

## See Also

- [Configuration](/configuration/overview/), `admob()` config
- [Plugins](/core/plugins/), the `NativeBlade` facade
- [Analytics](/mobile/analytics/), the other Google SDK plugin (and the AD_ID interaction)
