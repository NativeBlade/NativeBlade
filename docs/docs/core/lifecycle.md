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

## Migrations

Standard Laravel migrations in `database/migrations/` run automatically on boot. No `php artisan migrate` needed.

```bash
php artisan make:model Task -m
```

The migration runs the next time the app opens. See [Quick start](/core/database/) for details.

## Clock Sync

NativeBlade syncs the PHP WASM clock with the real system clock on every request. `now()`, `Carbon::now()`, and `time()` always return the correct time.
