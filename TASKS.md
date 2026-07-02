# Background Tasks

Periodic work that runs **even with the app closed** — the TaskManager. On
Android it rides WorkManager; on iOS, BGTaskScheduler. Requires
`Plugin::TASK_MANAGER`.

The design is the **courier model**: no PHP, no JS and no WebView ever run in
the background. The work itself is native Rust, in three kinds — named by
**where the payload comes from**, not the HTTP verb:

| Kind | HTTP | Payload comes from | Typical use |
|---|---|---|---|
| `fetch` | GET | the **server** — response parked for the app to read | prices, news, remote config |
| `post` | POST | **the run itself** — fixed `body()` + native collectors (`withLocation()`) | hourly technician ping |
| `queue` | POST | **the app**, dispatched at runtime with `NativeBlade::task()` | photos/mutations made offline |

`post` and `queue` speak the same HTTP; the difference is who authors the
payload. A `post` run builds its own; a `queue` run builds nothing — it only
flushes what was dispatched (empty outbox = successful no-op). `post` also
keeps an outbox, but just for its own failed sends. PHP participates at the
two ends where it already lives: it *declares* tasks at build time and
*consumes* results when the app opens. That trade is deliberate: it removes every
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

## Send-when-possible queues

The third kind, for data born at **runtime** — the user did things (possibly
offline) and the results must reach your server eventually:

```php
// Declared like any task; runs only flush the outbox (empty = no-op).
BackgroundTask::queue('photo-sync', 'https://api.myapp.com/photos/sync')
    ->every(minutes: 15)
    ->bearerFromSecure('api_token')
    ->requiresNetwork(),
```

The Laravel analogy: the provider declaration is your `config/queue.php`;
`NativeBlade::task()` is `dispatch()` — called anywhere, as often as needed,
offline included (that's the point):

```php
use NativeBlade\Plugins\Task;

public function savePhotos()
{
    return NativeBlade::task(function (Task $t) {
        $t->dispatch('photo-sync', [
            'thumb' => $this->thumbBase64,   // payloads: JSON objects up to 1 MB
            'takenAt' => now()->timestamp,
        ]);
        $t->dispatch('photo-sync', ['thumb' => $this->secondThumb]);
        $t->dispatch('audit-log', ['event' => 'photos_saved']);  // other queues too
    })->toResponse();
}

#[On('nb:task-queued')]
public function onQueued($name, $ok, $error = null) { /* parked (not yet sent) */ }
```

Dispatching **only parks** — if you want to send something right now, that's
what Laravel's `Http` is for; the task manager owns the *not-now*. Each
payload is written atomically to the queue's outbox with a `queuedAt`
timestamp and delivered on the queue's clock: the open-app timer, the
catch-up at open (a non-empty outbox counts as overdue, so pending entries
never wait out the interval after a fresh open), or an OS wake with the app
closed (`requiresNetwork` makes WorkManager fire when connectivity returns).
Each window sends everything pending in one sweep, oldest first, stopping at
the first failure so order is preserved. The outbox holds up to 100 entries
(oldest evicted beyond that).

To see what is still waiting to go out:

```php
public function checkQueue()
{
    return NativeBlade::getTaskOnQueue('photo-sync')->toResponse();
}

#[On('nb:task-queue')]
public function onQueue($name, $entries, $count, $error = null)
{
    // $entries = pending payloads, oldest first, each with its queuedAt.
    // Non-consuming: entries leave the list as runs deliver them.
    $this->pendingUploads = $count;
}
```

**Dispatching with an id is an upsert**: a pending entry with the same id is
replaced — the user edited the same photo three times offline? One entry
ships, with the final state, instead of three stale snapshots. The id also
rides inside the sent payload (an idempotency key for your server) and makes
entries targetable for discarding (a "cancel pending sync" action — results
and meta are untouched):

```php
NativeBlade::task(fn (Task $t) =>
    $t->dispatch('photo-sync', ['thumb' => $b64], id: "photo-{$photo->id}")
)->toResponse();

NativeBlade::clearTaskOnQueue('photo-sync')->toResponse();                       // everything
NativeBlade::clearTaskOnQueue('photo-sync', "photo-{$photo->id}")->toResponse(); // only this id
// ack on nb:task-queue-cleared: { name, removed }
```

Full-size photo/file upload is not what the 1 MB JSON payloads are for —
queue the metadata + a thumbnail, and upload the binary with the UPLOAD
plugin when the app is open (a file-upload courier is a planned extension).

## Builder methods

| Method | Description |
|---|---|
| `BackgroundTask::fetch($name, $url)` | GET; response parked for the app |
| `BackgroundTask::post($name, $url)` | POST fire-and-forget; failures queue in an outbox and are re-sent in order on the next run with connectivity |
| `BackgroundTask::queue($name, $url)` | Pure outbox: flushes what `NativeBlade::task()` dispatched at runtime |
| `->every(minutes, hours, days)` | Cadence (floor: 15 minutes) |
| `->latestOnly()` | Keep only the newest response |
| `->header($name, $value)` | Static header |
| `->bearerFromSecure($key)` | `Authorization: Bearer` read from secure storage at send time — never written to disk |
| `->body([...])` | Fixed body fields for post tasks |
| `->withLocation()` | Collect one location fix at send time (merged as `location`) |
| `->handler($class)` | Deliver queued results to this class on app open |
| `->requiresNetwork()` / `->requiresUnmetered()` / `->requiresCharging()` | OS-level constraints — the device is not even woken without them |
| `->runWhileOpen(bool)` | Also run on a timer while the app is open (default true) |
| `->catchUpOnOpen(bool)` | Run at open when a scheduled run was missed — or whenever the outbox has pending entries, regardless of the clock (default true) |

## Storage

Results live in the app sandbox (`<app_data_dir>/nativeblade/tasks/<name>/`)
as atomically-written JSON: `latest.json` + `meta.json`, a capped `results/`
queue for handler mode, and an `outbox/` for unsent posts. Survives reboot
and app updates; dies with uninstall. Payloads are capped at 1 MB; requests
time out at 20s so an iOS background window is never blown.

## Caveats that belong in your planning

- **`withLocation()` in background requires `Permission::BACKGROUND_LOCATION`
  on Android** (declare it alongside `Permission::LOCATION`; Play Console
  requires a declaration form + demo video) and `Permission::LOCATION_ALWAYS`
  ("Always" authorization) on iOS. If tracking is your product, note that a visible
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
