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
// app/Providers/AppServiceProvider.php
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

Use `pool()` for multiple API calls — all resolve in a single re-execution:

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

## Scheduler

Recurring tasks using Laravel's native Schedule API, powered by Rust timers.

### Setup

Define schedules in `routes/console.php` (standard Laravel):

```php
use Illuminate\Support\Facades\Schedule;
use NativeBlade\Facades\NativeBlade;

Schedule::call(function () {
    $data = Http::get('https://api.myapp.com/prices')->json();
    NativeBlade::setState('prices', $data);
})->everyFiveMinutes()->name('sync-prices');

Schedule::call(function () {
    CacheService::cleanup();
})->daily()->name('cleanup');

Schedule::call(function () {
    $license = Http::get('https://api.myapp.com/license/check')->json();
    NativeBlade::setState('license', $license);
})->hourly()->name('license-check');
```

### How it works

1. PHP extracts schedule names + cron expressions on boot
2. JS passes them to Rust via `invoke('register_schedules')`
3. Rust creates async `tokio` timers — one per task
4. When a task is due, Rust emits an event → JS calls PHP via WASM
5. PHP executes the callback and saves `lastRun` timestamp

### Overdue tasks

If the app was closed when a task was due, it executes immediately on the next app open. The `lastRun` timestamp is persisted in SQLite state, so Rust can calculate if a task was missed.

### All Laravel Schedule methods work

```php
// Frequency
->everyMinute()              ->everyFiveMinutes()
->everyTenMinutes()          ->everyFifteenMinutes()
->everyThirtyMinutes()       ->hourly()
->hourlyAt(17)               ->everyTwoHours()
->daily()                    ->dailyAt('13:00')
->twiceDaily(1, 13)          ->weekly()
->monthly()                  ->quarterly()
->yearly()

// Constraints
->weekdays()                 ->weekends()
->mondays() ... ->saturdays()
->between('8:00', '17:00')
->when(fn () => $condition)
->skip(fn () => $condition)

// Callbacks
->before(fn () => ...)       ->after(fn () => ...)
->onSuccess(fn () => ...)    ->onFailure(fn () => ...)
->name('task-name')
```

### Platform behavior

| Platform | Behavior |
|----------|----------|
| Desktop | Rust timers run natively, even with window minimized |
| Android | Timers run in foreground |
| iOS | Timers run in foreground |

## Clock Sync

NativeBlade syncs the PHP WASM clock with the real system clock on every request. `now()`, `Carbon::now()`, and `time()` always return the correct time.

## Migrations

Standard Laravel migrations in `database/migrations/` run automatically on boot. No `php artisan migrate` needed.

```bash
php artisan make:model Task -m
```

The migration runs the next time the app opens. See [README.md](README.md#database) for details.
