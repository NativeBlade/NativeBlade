# Scheduler

Recurring tasks using Laravel's native Schedule API, powered by Rust native timers.

## Setup

Define schedules in `routes/console.php` (standard Laravel):

```php
use Illuminate\Support\Facades\Schedule;
use NativeBlade\Facades\NativeBlade;
use Illuminate\Support\Facades\Http;

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

## How It Works

1. PHP extracts schedule names + cron expressions on boot
2. JS passes them to Rust via `invoke('register_schedules')`
3. Rust creates async `tokio` timers — one per task
4. When a task is due, Rust emits an event → JS calls PHP via WASM
5. PHP executes the callback and saves `lastRun` timestamp

## Overdue Tasks

If the app was closed when a task was due, it executes immediately on the next app open. The `lastRun` timestamp is persisted in SQLite state, so Rust can calculate if a task was missed.

## Supported Laravel Schedule Methods

NativeBlade supports Laravel's scheduling frequencies, callbacks, and constraints
(`when()`, `skip()`, `between()`, `weekdays()`, and friends are all evaluated
before a task runs). See [Timezones](#timezones) for the one current gap.

### Frequency

```
->everyMinute()              ->everyFiveMinutes()
->everyTenMinutes()          ->everyFifteenMinutes()
->everyThirtyMinutes()       ->hourly()
->hourlyAt(17)               ->everyOddHour()
->everyTwoHours()            ->everyThreeHours()
->everyFourHours()           ->everySixHours()
->daily()                    ->dailyAt('13:00')
->twiceDaily(1, 13)          ->twiceDailyAt(1, 13, 15)
->weekly()                   ->weeklyOn(1, '8:00')
->monthly()                  ->monthlyOn(4, '15:00')
->twiceMonthly(1, 16, '13:00')
->quarterly()                ->yearly()
->yearlyOn(6, 1, '17:00')
```

### Constraints

```
->weekdays()                 ->weekends()
->sundays()                  ->mondays() ... ->saturdays()
->days([0, 3])
->between('8:00', '17:00')   ->unlessBetween('23:00', '4:00')
->when(fn () => true)         ->skip(fn () => false)
```

### Callbacks

```
->before(fn () => ...)        ->after(fn () => ...)
->onSuccess(fn () => ...)     ->onFailure(fn () => ...)
->name('task-name')
```

## Timezones

The native scheduler evaluates cron expressions in **UTC**. `->timezone(...)` is
not yet honored — a task set to `->dailyAt('09:00')->timezone('America/Sao_Paulo')`
fires at 09:00 UTC, not 09:00 São Paulo time. If you need a specific wall-clock
time, offset the expression to UTC yourself for now. Timezone-aware scheduling is
on the roadmap.

## Platform Behavior

| Platform | Behavior |
|----------|----------|
| Desktop | Rust timers run natively, even with window minimized |
| Android | Timers run in foreground |
| iOS | Timers run in foreground |

## What Works Inside Scheduled Tasks

- **State**: `NativeBlade::setState()`, `getState()`
- **HTTP Bridge**: `Http::get()`, `Http::post()`, `NativeBlade::pool()`
- **Storage**: `Storage::disk('native')->put()`, `get()`, `delete()`
- **Eloquent**: all queries and mutations
