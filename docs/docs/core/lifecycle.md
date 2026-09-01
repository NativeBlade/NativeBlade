---
title: "Lifecycle"
description: "App and component lifecycle hooks."
---

# App Lifecycle

## Boot Sequence

```
1. Splash screen displayed
2. PHP WASM runtime boots
3. State restored from IndexedDB → SQLite
4. Migrations run automatically (pending only)
5. onBoot callback executes (splash still visible)
6. First page renders → splash hides
7. Scheduler starts (Rust timers registered)
8. Auto-update check (3s delay)
```

## Holding the splash for an intro animation

By default the splash hides the moment the first screen renders (step 6). To hold
it longer, for a Lottie intro or any animation, set `window.nbSplash` to a promise.
The app waits for it before revealing the first screen, capped by a timeout so a
broken animation can never block the boot.

Inside the splash, play the animation and resolve the promise when it completes:

```html
<div id="splash">
    <div id="splash-lottie" style="width:200px;height:200px"></div>
    <script src="./lottie.min.js"></script>
    <script>
        window.nbSplash = new Promise((resolve) => {
            const el = document.getElementById('splash-lottie');
            if (!el || !window.lottie) return resolve();
            const anim = lottie.loadAnimation({ container: el, path: './splash.json', loop: false, autoplay: true });
            anim.addEventListener('complete', resolve);
            anim.addEventListener('data_failed', resolve);
        });
    </script>
</div>
```

The app boots behind the splash, so the first screen is ready the instant the
animation finishes. The hold is capped at about eight seconds: if the promise
never resolves, the splash hides anyway, so a missing file or a broken animation
never bricks the boot. Bundle `lottie.min.js` and the animation JSON as local
assets, since the app runs offline and a CDN is not an option.

## onBoot Hook

Run code before the app becomes visible. The splash screen stays up until `onBoot` completes. Use it for license validation, data sync, essential API calls, or any setup that must finish before the user sees the app.

```php
use NativeBlade\Facades\NativeBladeConfig;
use NativeBlade\Facades\NativeBlade;
use Illuminate\Support\Facades\Http;

NativeBladeConfig::onBoot(function () {
    // Validate license
    $license = NativeBlade::getState('license');
    if (!$license || Carbon::parse($license['expires'])->isPast()) {
        $response = Http::get('https://api.myapp.com/license/check');
        NativeBlade::setState('license', $response->json());
    }

    // Sync essential config
    $config = Http::get('https://api.myapp.com/config')->json();
    NativeBlade::setState('app.config', $config);
});
```

### What works inside onBoot

- **State**: `NativeBlade::setState()`, `getState()`, `forget()`
- **HTTP Bridge**: `Http::get()`, `Http::post()`, `NativeBlade::pool()`
- **Storage**: `Storage::disk('native')->put()`, `get()`, `delete()`
- **Eloquent**: `User::all()`, `Task::where(...)->get()`
- **Any PHP code**: Carbon, Collections, Validation, etc.

### Parallel requests

Use `pool()` for multiple API calls, all resolve in a single re-execution:

```php
NativeBladeConfig::onBoot(function () {
    $responses = NativeBlade::pool(fn ($pool) => [
        $pool->get('https://api.myapp.com/user'),
        $pool->get('https://api.myapp.com/settings'),
        $pool->get('https://api.myapp.com/notifications'),
    ]);

    NativeBlade::setState('user', $responses[0]->json());
    NativeBlade::setState('settings', $responses[1]->json());
    NativeBlade::setState('notifications', $responses[2]->json());
});
```

`onBoot` runs through the same exit-and-replay bridge, so it re-runs from the top
on every HTTP call it makes. Keep the calls in a deterministic order and defer
side effects such as writes and deletes until after the responses are in.
See [keeping the call sequence deterministic](/core/http/#keep-the-call-sequence-deterministic).

## Migrations

Standard Laravel migrations in `database/migrations/` run automatically on boot. No `php artisan migrate` needed.

```bash
php artisan make:model Task -m
```

The migration runs the next time the app opens. See [Quick start](/core/database/) for details.

## Clock Sync

NativeBlade syncs the PHP WASM clock with the real system clock on every request. `now()`, `Carbon::now()`, and `time()` always return the correct time.
