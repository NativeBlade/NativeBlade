---
title: "Testing"
description: "Simulate the runtime's execution model in PHPUnit with NativeBlade::fake()."
---

# Testing

Most NativeBlade bugs only show up on the device. `Livewire::test()` runs an
action once, in one process, with a real HTTP client and a real database. The
app runs it differently: PHP is re-run from scratch after every native call
(HTTP, database, filesystem), replaying the earlier calls from a cache, and
the request is abandoned when it makes more sequential calls than the runtime
allows. Code that passes a normal test still breaks there when a call is not
deterministic or an action makes too many calls.

`NativeBlade::fake()` brings that model into PHPUnit.

```php
use Livewire\Livewire;
use NativeBlade\Facades\NativeBlade;

public function test_sync_survives_the_runtime(): void
{
    Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    $fake = NativeBlade::fake(platform: 'android');

    $component = Livewire::test(Activities::class);

    $fake->replayCall($component, 'sync');

    $fake->assertHttpCalls(2)
        ->assertQueriesAtMost(20)
        ->assertActionPushed('notification')
        ->assertLogged('synced');
}
```

## What the fake does

- **Platform.** `NativeBlade::platform()`, `isAndroid()`, `isDesktop()` and
  the rest answer for the platform you pass. `dev: true` makes
  `NativeBlade::isDev()` true.
- **Records every native call** the code makes, in order: HTTP requests
  (faked or real), queries on every connection, and operations on disks that
  use the `nativeblade` driver. Those disks write to a temporary directory,
  so the test can read the files back with `Storage::disk('native')`.
- **Captures what goes to the shell:** every action flushed with
  `->toResponse()` and every `NativeBlade::log()` entry, instead of sending
  them to a WebView that is not there.

## Replay

`replayCall($component, 'method', ...$params)` runs a Livewire action twice
from the same component snapshot, exactly as the runtime re-runs a request.
The first run happens inside a database transaction that is rolled back; the
component, the recorded calls, the pushed actions and the logs reflect the
second run. Between the runs the clock is frozen, so only real
non-determinism shows.

The test fails when the two runs do not make the same calls in the same order:

```
Replay diverged at call #3: was `GET https://api.example.com/ping?r=GmxmcS`,
now `GET https://api.example.com/ping?r=hJ9XdA`.
PHP is re-run after every native call (HTTP, database, filesystem) and must
make the same calls in the same order; something before this call is not
deterministic (random values, the clock, state changed before the call).
```

It also fails when a run exceeds a budget. The limits mirror the runtime:

| Calls per request | Limit |
|---|---|
| Sequential HTTP calls (a `NativeBlade::pool()` counts as one) | 10 |
| Queries | 20 |
| Filesystem operations on native disks | 20 |

```
This action made 11 sequential HTTP calls; the runtime abandons a request
after 10. Batch independent calls with NativeBlade::pool() or split the work
across requests.
```

`replay(fn () => ...)` does the same for any code: a controller action, a job,
a plain closure. The closure must start from the same inputs each time.

Two things to know. `Http::sequence()` fakes are consumed by the first run, so
use fixed responses or callbacks with replay. Transaction statements are not
counted as queries.

## Assertions

Counts and lookups over the recorded calls:

```php
$fake->assertHttpCalls(2);
$fake->assertHttpCallsAtMost(10);
$fake->assertHttpCalled('POST', 'https://api.example.com/items*');   // Str::is() pattern
$fake->assertQueries(3);
$fake->assertQueriesAtMost(20);
$fake->assertFsOps(1);
$fake->assertFsOpsAtMost(20);
```

What reached the shell:

```php
$fake->assertActionPushed('navigate');
$fake->assertActionPushed('vibrate', fn (array $data) => $data['duration'] === 50);
$fake->assertActionNotPushed('exit');
$fake->assertNothingPushed();
$fake->assertNotificationScheduled('reminder-1');
$fake->assertLogged('synced');
$fake->assertLogged('Payment failed', 'error');
$fake->assertNotLogged('retrying');
```

A failed assertion lists what was recorded, so the message shows the calls or
actions the code actually made.

The raw data is available too: `sequence()`, `httpCalls()`, `queries()`,
`fsOps()`, `pushed()`, `logs()` and `fsRoot()`, the directory the native disks
write to.

## Where to use it

Every Livewire action that talks to the network, the database or the native
disks is a candidate, especially sync and upload code. The replay catches a
random value or a timestamp in a request before it reaches a phone; the
budgets catch a loop that grew past the limit. Keep `Livewire::test()` for
what it is good at and add `replayCall()` for the actions the app cannot
afford to get wrong on the device.
