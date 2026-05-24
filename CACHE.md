# Cache

NativeBlade auto-wires Laravel's `Cache::*` facade to a SQLite-backed store that **persists across app cold starts** on the device. You don't need to touch `config/cache.php` or `.env` — `Cache::put`, `Cache::get`, `Cache::remember`, `Cache::lock`, and the rest of the contract work out of the box.

## Quick start

```php
use Illuminate\Support\Facades\Cache;

// Store with TTL (seconds)
Cache::put('weather.lisbon', $payload, 3600);

// Read back
$payload = Cache::get('weather.lisbon');

// Memoize an expensive call (computes once, caches the result)
$weather = Cache::remember('weather.lisbon', 3600, function () {
    return Http::get('https://api.weather.com/lisbon')->json();
});

// Idempotency marker
Cache::put('order:processed:' . $orderId, true, 300);
if (Cache::has('order:processed:' . $orderId)) {
    return;
}

// Atomic lock (prevents two concurrent operations on the same key)
Cache::lock('sync.contacts', 30)->block(5, function () {
    syncContactsToServer();
});

// Drop a single key, or wipe everything
Cache::forget('weather.lisbon');
Cache::flush();
```

Everything else in the Laravel cache documentation applies — `increment()`, `decrement()`, `pull()`, `add()`, `forever()`, `rememberForever()`. Tags are **not** supported (see "Limitations").

## How it works under the hood

When NativeBlade boots, `NativeBladeServiceProvider::registerNativeCache()` does three things:

1. Registers a new cache store named `nativeblade` configured with the `database` driver against the `sqlite` connection.
2. Sets `config('cache.default') = 'nativeblade'`, so calls to the `Cache` facade go through this store by default.
3. Creates two tables on the `sqlite` connection if they don't exist:
   - `nativeblade_cache` — columns `key TEXT PRIMARY KEY`, `value TEXT`, `expiration INTEGER`
   - `nativeblade_cache_locks` — columns `key TEXT PRIMARY KEY`, `owner TEXT`, `expiration INTEGER`

The `sqlite` connection is the same one that powers `NativeBlade::setState` and any Eloquent models you wire to it. The physical file lives at `/app/database/database.sqlite` inside the PHP-WASM virtual filesystem and is checkpointed to IndexedDB transparently, so cache survives cold starts without any work from your side.

## State vs Cache: when to choose which

Both live in the same SQLite file. The contracts differ:

| | `NativeBlade::setState` | `Cache::*` |
|---|---|---|
| Lifetime semantics | Definitive — exists until you `forget()` it | Best-effort — can expire or be evicted |
| TTL support | No (lives until manually cleared) | Yes (per-key expiration) |
| Scoping | `scope` argument, bulk clear via `flush($scope)` | Tags (limited on database driver) |
| Storage table | `nativeblade_state` | `nativeblade_cache` |
| Use for | Identity, configuration, user preferences | Derived data, memoized computations, throttle counters |

**Rule of thumb:** if losing the value should be invisible to the user (you can just recompute), use `Cache`. If losing it would break the app (user gets logged out, locale resets), use `setState`.

```php
// State — identity / config / definitive truth
NativeBlade::setState('auth.user', $user);
NativeBlade::setState('locale.current', 'pt_BR');

// Cache — derived / disposable / recomputable
Cache::remember('feed:trending', 600, fn () => Trending::query()->limit(20)->get());
Cache::put('throttle:login:' . $ip, $attempts + 1, 60);
```

## Common patterns

### Memoize remote API calls

```php
public function fetchExchangeRates(): array
{
    return Cache::remember('rates:USD', 3600, function () {
        return Http::get('https://api.exchangerate.host/latest?base=USD')->json('rates');
    });
}
```

### Rate-limit on the client

```php
public function attemptUnlock(string $pin): bool
{
    $key = 'pin.attempts:' . auth()->id();
    $attempts = Cache::increment($key);
    Cache::put($key, $attempts, 300);

    if ($attempts > 5) {
        $this->message = __('Try again in 5 minutes.');
        return false;
    }

    return $this->verifyPin($pin);
}
```

### Idempotent push handlers

```php
public function handle(PushPayload $payload): void
{
    $key = 'push:handled:' . $payload->messageId;
    if (Cache::has($key)) {
        return;
    }
    Cache::put($key, true, 86400);

    $this->processPayload($payload);
}
```

### Cross-component locks

```php
public function syncTrail(): void
{
    Cache::lock('trail.sync', 30)->block(5, function () {
        // Only one component can run this block at a time across the whole app
        TrailSync::run();
    });
}
```

## Maintenance

### Clearing the cache

```bash
php artisan cache:clear
```

Or programmatically:

```php
Cache::flush();
```

### Pruning expired entries

Laravel's `DatabaseStore` does **not** auto-delete expired rows. They're filtered out on read (returning `null` past expiration), but they accumulate in the table. To clean them up:

```bash
php artisan cache:prune-stale-tags
```

You can also wire this into the NativeBlade schedule:

```php
// app/Console/Kernel.php or wherever you register schedules
Schedule::command('cache:prune-stale-tags')->daily();
```

In practice the cache table stays small on a device app, so pruning is rarely urgent.

## Overriding the default

NativeBlade only sets `cache.default = 'nativeblade'` during its own `boot()`. If you have a reason to use a different store, override it from your `AppServiceProvider` (which boots after NativeBlade's provider):

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    config(['cache.default' => 'array']);  // for tests
}
```

Or pick a store per call without changing the default:

```php
Cache::store('array')->put('ephemeral', $value, 60);
```

The `nativeblade` store remains registered either way, so `Cache::store('nativeblade')` always works.

## Limitations

- **Cache tags** are not supported. `Cache::tags(['users'])->put(...)` throws `BadMethodCallException: This cache store does not support tagging`, because Laravel's `DatabaseStore` (which the `nativeblade` driver delegates to) doesn't implement `TaggableStore`. Tag-capable drivers in Laravel core are `redis`, `memcached`, and `dynamodb` — none of which fit a device-local app. Workaround: key your entries with a prefix (`Cache::put("users:{$id}:profile", ...)`) and use a key pattern for bulk invalidation.
- **Concurrent writes** rely on SQLite's file lock. In normal NativeBlade single-process operation this is fine; if you're running scheduled jobs while the user navigates, lock contention is possible but rare.
- **Large blobs** (more than a few MB) are not a great fit. The cache row stores `value` as text and the whole SQLite file is checkpointed to IndexedDB after writes — bigger file means slower checkpoint. For binary blobs prefer `Storage::disk('nativeblade')` (see [STORAGE.md](STORAGE.md)).
