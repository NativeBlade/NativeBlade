---
title: "Testing"
description: "Simulate the runtime's execution model in PHPUnit with NativeBlade::fake()."
---

# Testing

Most NativeBlade bugs only show up on the device. `Livewire::test()` runs an
action once, in one process, with a real HTTP client and a real database. The
app runs it differently: a request runs until its first native call that is
not in the cache (an HTTP request, a query on the native database, an
operation on a native disk), exits there, and is re-run from the same snapshot
once the shell has the result. The earlier calls are served from the cache.
What the request wrote before exiting (local SQLite, state, files) stays
written. The request is abandoned when it makes more sequential calls than the
runtime allows. Code that passes a normal test still breaks there when a call
is not deterministic, when a write before a call changes the next run, or when
an action makes too many calls.

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
  (faked or real), queries on connections with the `nativeblade-db` driver,
  and operations on disks with the `nativeblade` driver. Queries on the local
  SQLite are recorded as information, flagged `bridge: false`; on the device
  they run inside the app and are never a native call.
- **Stands in for the native database and disks.** Connections with the
  `nativeblade-db` driver become an in-memory SQLite, so a test can create
  tables on them and read rows back. Native disks write to a temporary
  directory, so the test can read the files back with `Storage::disk('native')`.
- **Captures what goes to the shell:** every action flushed with
  `->toResponse()` and every `NativeBlade::log()` entry, instead of sending
  them to a WebView that is not there.

## Replay

`replayCall($component, 'method', ...$params)` runs a Livewire action the way
the runtime runs a request. Run one goes until the first native call, carries
it out (that is the shell's job) and exits. Run two starts from the same
component snapshot, gets that call from the cache, goes until the second one
and exits. And so on until a run completes: an action with three native calls
runs four times. Nothing a run wrote is undone. The clock stands still inside
a run and moves one second forward between runs: on the device the runs are a
few hundred milliseconds apart, so a timestamp inside a call crosses a second
boundary now and then, and the step makes that happen every time instead of
once in a while. `advanceClockBetweenRuns(0)` freezes it, any other value sets
the step. A `Carbon::setTestNow()` the test made is restored afterwards. The
component, the recorded calls, the pushed actions and the logs reflect the
final run.

The test fails when a run does not make the same calls, in the same order, as
the run before it. As on the device, where the detector lives in the shell,
the violation ends the run like an exit and is reported once the run is over,
so a `catch` in the app cannot turn it into a different symptom:

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
| Queries on the native database (local SQLite does not count) | 20 |
| Filesystem operations on native disks | 20 |

```
This action made 11 sequential HTTP calls; the runtime abandons a request
after 10. Batch independent calls with NativeBlade::pool() or split the work
across requests.
```

Because nothing is undone between runs, the replay also catches the trap
that only shows on the device: a write before a native call that changes what
the next run does.

```php
if (NativeBlade::getState('sync.lock')) return;   // run two gives up here
NativeBlade::setState('sync.lock', true);
Http::get('https://api.example.com/sync');          // run one exits here
```

```
Replay diverged at call #1: was `GET https://api.example.com/sync`, now
nothing (the request completed without making it).
```

`replay(fn () => ...)` does the same for any code: a controller action, a job,
a plain closure. The closure is invoked once per run and must start from the
same inputs each time.

Three things to know. The exit point behaves like `exit()` even inside a
`catch (\Throwable $e)`: whatever the app does after it in that run is
undone. Native calls are refused, writes on local connections (the state
included) are rolled back at the end of the run, logs and pushed actions are
dropped, and the run never counts as completed, whatever it returned. A
transaction open at the exit point is lost, as it is on the device.
`Http::pool()` is never a stop point: its requests run together and count as
one call, as on the device. `Http::sequence()` fakes are consumed by the run
that reaches them; prefer fixed responses or callbacks with replay.

## Assertions

Counts and lookups over the recorded calls:

```php
$fake->assertHttpCalls(2);
$fake->assertHttpCallsAtMost(10);
$fake->assertHttpCalled('POST', 'https://api.example.com/items*');   // Str::is() pattern
$fake->assertNativeQueries(3);        // native database only: what the budget counts
$fake->assertNativeQueriesAtMost(20);
$fake->assertQueries(40);              // every query, local SQLite included
$fake->assertQueriesAtMost(60);
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

The raw data is available too: `sequence()`, `nativeCalls()`, `httpCalls()`, `queries()`, `nativeQueries()`,
`fsOps()`, `pushed()`, `logs()` and `fsRoot()`, the directory the native disks
write to.

## A request that dies

On the device a request can end before it completes: the user navigates
away, the app is closed, the budget runs out. What it wrote until then stays,
the rest never happens, and no response reaches the component. `abandonAt()`
reproduces that for the next replay:

```php
$fake->abandonAt(3)->replayCall($wizard, 'submit');
```

The third native call is carried out (the shell completes it, so a POST does
reach the server) and PHP never resumes. The run's writes before that call
stay, writes after it are undone, nothing is pushed or logged, and the
component keeps the state it had before the request. The recorded calls are
the ones made up to that point. A request with fewer calls than the number
completes normally. The setting is cleared after one replay.

## State that outlives a run

On the device every run is a fresh PHP process. In PHPUnit all runs share one
process, so a static property or a container singleton that a run mutates is
still mutated in the next run, where the device would start clean. The fake
cannot reset that on its own, the same way Laravel Octane cannot: PHP has no
generic reset for statics, and flushing every singleton would take the
database and Livewire with it. Two hooks put such state back before every
run:

```php
$fake->resetBetweenRuns(fn () => DeviceState::$timezone = null)
    ->flushBetweenRuns(SyncClock::class)   // container instance, rebuilt on next use
    ->replayCall($component, 'sync');
```

A counter in a static that feeds a request shows as a divergence without the
hook; a value cached in a static during the first run and reused by the
second is the opposite case, a divergence the device would have and the fake
cannot see. Keep per-request state in the request, or declare it here.

## Where to use it

Every Livewire action that talks to the network, the database or the native
disks is a candidate, especially sync and upload code. The replay catches a
random value or a timestamp in a request before it reaches a phone; the
budgets catch a loop that grew past the limit. Keep `Livewire::test()` for
what it is good at and add `replayCall()` for the actions the app cannot
afford to get wrong on the device.
