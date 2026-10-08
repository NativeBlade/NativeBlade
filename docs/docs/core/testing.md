---
title: "Testing"
description: "Test native calls, pushed actions and logs in PHPUnit with NativeBlade::fake()."
---

# Testing

Inside the app, `Http::`, the native database and the native disks are
answered by the shell. In PHPUnit there is no shell: the native connection
would have nothing to talk to, and nothing would capture the actions a
component pushes or the lines it logs. `NativeBlade::fake()` stands in for
the shell.

```php
use Livewire\Livewire;
use NativeBlade\Facades\NativeBlade;

public function test_sync_sends_and_notifies(): void
{
    Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);
    $fake = NativeBlade::fake(platform: 'android');

    Livewire::test(Activities::class)->call('sync');

    $fake->assertHttpCalls(2)
        ->assertHttpCalled('POST', 'https://api.example.com/items*')
        ->assertActionPushed('notification')
        ->assertLogged('synced');
}
```

## What the fake does

- **Platform.** `NativeBlade::platform()`, `isAndroid()`, `isDesktop()` and
  the rest answer for the platform you pass. `dev: true` makes
  `NativeBlade::isDev()` true.
- **Stands in for the native database and disks.** Connections with the
  `nativeblade-db` driver become an in-memory SQLite, so a test can create
  tables on them with `Schema::connection('native')` and read rows back.
  Disks with the `nativeblade` driver write to a temporary directory
  (`fsRoot()`), so the test can read the files back with
  `Storage::disk('native')`.
- **Records every native call**, in order: HTTP requests (faked or real),
  queries on native connections, and operations on native disks. Queries on
  the local SQLite are recorded too, flagged `bridge: false`, as information.
- **Captures what goes to the shell:** every action flushed with
  `->toResponse()` and every `NativeBlade::log()` entry, instead of sending
  them to a WebView that is not there.

`reset()` forgets the recordings and keeps the stand-ins.

## Assertions

Counts and lookups over the recorded calls:

```php
$fake->assertHttpCalls(2);
$fake->assertHttpCallsAtMost(10);
$fake->assertHttpCalled('POST', 'https://api.example.com/items*');   // Str::is() pattern
$fake->assertNativeQueries(3);        // native database only
$fake->assertNativeQueriesAtMost(20);
$fake->assertQueries(40);              // every query, local SQLite included
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

The raw data is available too: `sequence()`, `nativeCalls()`, `httpCalls()`
(with the request body), `queries()`, `nativeQueries()`, `fsOps()`,
`pushed()`, `logs()` and `fsRoot()`.

## Where to use it

Every Livewire action that talks to the network, the native database or the
native disks, and every action that pushes something to the shell. Keep
`Livewire::test()` for the component and add the fake's assertions for what
left the device and what came back to the shell.
