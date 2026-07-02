# Background Tasks

Periodic work that runs **even with the app closed** — the TaskManager. On
Android it rides WorkManager; on iOS, BGTaskScheduler. Requires
`Plugin::TASK_MANAGER`.

The design is the **courier model**: no PHP, no JS and no WebView ever run in
the background. The work itself is native Rust — a `fetch` GETs a URL and
parks the response on disk for the app to consume, a `post` fires a payload
(with an outbox that retries failures in order). PHP participates at the two
ends where it already lives: it *declares* tasks at build time and *consumes*
results when the app opens. That trade is deliberate: it removes every
webview/wasm-boot edge case from the background path. Logic that must run
while the app is closed belongs on your server; the courier fetches its
results.

## The guarantee, honestly

The promise is **eventually, guaranteed** — not "on time":

| Context | Mechanism | Timing |
|---|---|---|
| App open | in-process timer (Rust) | on the interval |
| App opens | catch-up for overdue tasks | immediate, late |
| Closed, Android | WorkManager | ≈interval (Doze defers; 15 min floor) |
| Closed, iOS | BGAppRefreshTask | opportunistic — the system decides, based on app usage; may be hours or never |

The floor everywhere is the catch-up on open. On iOS, background runs are a
bonus on top of it (and a task added to the config gains background runs from
the **second** launch on — BGTaskScheduler only registers identifiers known
at launch).

## Setup

```php
use NativeBlade\Config\BackgroundTask;
use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::plugins([Plugin::TASK_MANAGER, /* ... */]);

NativeBladeConfig::backgroundTasks([

    // Sync down: park the response, read it whenever you want.
    BackgroundTask::fetch('prices', 'https://api.myapp.com/prices')
        ->every(hours: 1)
        ->latestOnly()
        ->bearerFromSecure('api_token')
        ->requiresNetwork(),

    // Fire-and-forget with a native collector: hourly technician ping.
    BackgroundTask::post('tech-ping', 'https://api.myapp.com/technicians/ping')
        ->withLocation()
        ->every(hours: 1)
        ->bearerFromSecure('api_token')
        ->requiresNetwork(),
]);
```

Run `php artisan nativeblade:config` — it ships the manifest to the runtime
config and, on iOS, writes the required `BGTaskSchedulerPermittedIdentifiers`
and `UIBackgroundModes` into Info.plist.

The task **name** (first argument, lowercase `[a-z0-9_-]`, unique) is the
identity: it becomes the parking directory, the OS scheduler id and the key
you consume by.

## Consuming results

**Pull (the default):** ask for the latest parked result from any screen.

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;

public function loadPrices()
{
    return NativeBlade::getTask('prices')->toResponse();
}

#[On('nb:task')]
public function onTask($name, $found, $payload, $ranAt = null, $status = null, $error = null)
{
    if ($name === 'prices' && $found) {
        $this->prices = $payload;   // fetched possibly hours ago, app closed
    }
}
```

Idempotent — nothing is consumed; the payload stays until the next run
overwrites it.

**Push (optional):** declare `->handler(PricesFetched::class)` and every
queued result is delivered on app open, oldest first:

```php
// app/Native/Tasks/PricesFetched.php
use NativeBlade\Tasks\TaskResult;

class PricesFetched
{
    public function handle(TaskResult $result): void
    {
        // $result->name, $result->ranAt(), $result->json() / ->payload
        NativeBlade::setState('prices', $result->json());
    }
}
```

With `->latestOnly()` there is no queue — pair it with pull, not handlers.

## Builder methods

| Method | Description |
|---|---|
| `BackgroundTask::fetch($name, $url)` | GET; response parked for the app |
| `BackgroundTask::post($name, $url)` | POST fire-and-forget; failures queue in an outbox and are re-sent in order on the next run with connectivity |
| `->every(minutes, hours, days)` | Cadence (floor: 15 minutes) |
| `->latestOnly()` | Keep only the newest response |
| `->header($name, $value)` | Static header |
| `->bearerFromSecure($key)` | `Authorization: Bearer` read from secure storage at send time — never written to disk |
| `->body([...])` | Fixed body fields for post tasks |
| `->withLocation()` | Collect one location fix at send time (merged as `location`) |
| `->handler($class)` | Deliver queued results to this class on app open |
| `->requiresNetwork()` / `->requiresUnmetered()` / `->requiresCharging()` | OS-level constraints — the device is not even woken without them |
| `->runWhileOpen(bool)` | Also run on a timer while the app is open (default true) |
| `->catchUpOnOpen(bool)` | Run at open when a scheduled run was missed (default true) |

## Storage

Results live in the app sandbox (`<app_data_dir>/nativeblade/tasks/<name>/`)
as atomically-written JSON: `latest.json` + `meta.json`, a capped `results/`
queue for handler mode, and an `outbox/` for unsent posts. Survives reboot
and app updates; dies with uninstall. Payloads are capped at 1 MB; requests
time out at 20s so an iOS background window is never blown.

## Caveats that belong in your planning

- **`withLocation()` in background requires `ACCESS_BACKGROUND_LOCATION` on
  Android** (Play Console declaration form + demo video) and "Always"
  authorization on iOS. If tracking is your product, note that a visible
  foreground-service tracker is often *easier* to get approved than silent
  background pings — see the location-tracking plugin discussion.
- Without the permission (or without a fix in 15s) the task still runs,
  just without the `location` field.
- Handlers run on app open in request context — keep them fast; heavy
  processing belongs on the server that produced the payload.
- Post payloads carry a `sentAt` unix timestamp, and outbox retries preserve
  order — design the receiving endpoint to be idempotent anyway.

## See Also

- [SCHEDULER.md](SCHEDULER.md) — recurring PHP tasks while the app is open (time-based; TaskManager is reliability-based)
- [NETWORK.md](NETWORK.md) — connectivity events for in-app sync logic
- [PLUGINS.md](PLUGINS.md) — the `NativeBlade` facade
