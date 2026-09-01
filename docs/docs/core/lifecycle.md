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
it for a boot intro, set `window.nbSplash`. The app waits for it before revealing
the first screen, capped by a timeout so a broken intro can never block the boot.

php-wasm has no worker, so it boots on the main thread. An animation running
during that boot competes with it for frames and stutters partway through. The
runtime reads `window.nbSplash` at exactly one moment, once the first screen is
ready, which is also the first moment the thread is free. So set it to a
**function**: the runtime calls it at that moment, and the animation runs its
whole length on a free thread.

While the app boots, show a placeholder that stays smooth even while the thread is
blocked. Opacity is the safe choice, since the compositor keeps it going
regardless; a transform or a JS animation freezes with the thread.

```html
<div id="splash">
    <div class="mark">
        <div id="intro"></div>
        <svg id="pulse" width="48" height="48"><!-- your static logo --></svg>
    </div>
</div>
<style>
    #pulse { animation: nb-pulse 1.6s ease-in-out infinite; }
    .mark.playing #pulse { opacity: 0; animation: none; }
    #splash.done { opacity: 0; transform: scale(1.04); transition: opacity .24s, transform .24s; }
    @keyframes nb-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .45; } }
</style>
<script src="./lottie.min.js"></script>
<script>
    window.nbSplash = () => new Promise((resolve) => {
        setTimeout(resolve, 4000);                                    // local cap: a stall costs a beat
        const el = document.getElementById('intro');
        if (!el || !window.lottie) return resolve();
        document.querySelector('.mark').classList.add('playing');     // cross-fade the pulse out
        const anim = lottie.loadAnimation({ container: el, renderer: 'canvas', loop: false, autoplay: true, path: './splash.json' });
        anim.addEventListener('data_failed', resolve);
        anim.addEventListener('complete', () => {
            document.getElementById('splash').classList.add('done');  // fade out to your background
            setTimeout(resolve, 240);
        });
    });
</script>
```

Fading the splash out just before you resolve turns the handoff into a transition
instead of a hard cut, and the first screen paints onto a calm flat surface. The
`canvas` renderer keeps the animation off the DOM, which matters when Livewire is
about to morph the first screen. Bundle `lottie.min.js` and the animation JSON as
local assets; the app runs offline, so a CDN is not an option. A plain promise
still works if you do not need the free-thread timing.

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
