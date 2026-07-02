# Network

Connectivity status and live change events. On Android the plugin reads the
default network from `ConnectivityManager`; on iOS it watches `NWPathMonitor`.
Requires `Plugin::NETWORK`.

A NativeBlade app is local-first — it keeps working offline. What this plugin
answers is the *other* question: whether the work that needs the outside world
(syncing, API calls, media uploads) should run now, wait, or take the cheap
path. The payload is the same everywhere:

| Field | Meaning |
|---|---|
| `connected` | Usable internet. On mobile this means the network is **validated** — a wifi hotspot stuck behind a captive portal reports `false`, not `true`. |
| `type` | `wifi` \| `cellular` \| `ethernet` \| `none` \| `unknown` |
| `metered` | The connection bills by usage (cellular, personal hotspot). The signal to postpone heavy transfers. |

## Platforms

All. On Android and iOS the native side answers; on desktop and web there is
no native plugin — the same calls answer from the browser's online flag with
`type: 'unknown'` and `metered: false`, so handler code runs everywhere
without branching.

## Setup

```php
use NativeBlade\Config\Plugin;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::plugins([Plugin::NETWORK, /* ... */]);
```

Run `php artisan nativeblade:config`. No permissions to declare:
`ACCESS_NETWORK_STATE` is always present on Android, and iOS needs nothing.

## Reading the status

Ask once, receive on `nb:network-status`:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;

public function checkNetwork()
{
    return NativeBlade::networkStatus()->toResponse();
}

#[On('nb:network-status')]
public function onNetworkStatus($connected, $type, $metered, $id = null)
{
    $this->canSyncMedia = $connected && !$metered;
}
```

## Reacting to changes

No call needed — the shell watches the network for the whole app lifetime and
dispatches `nb:network-changed` (same payload, deduped: only real changes
fire) to whatever page is open:

```php
#[On('nb:network-changed')]
public function onNetworkChanged($connected, $type, $metered)
{
    $this->offline = !$connected;

    if ($connected && $this->hasPendingSync) {
        $this->runSync(); // reconnect → flush the queue
    }
}
```

The classic pairing is an offline banner plus retry-on-reconnect: set a flag
when work fails or `connected` drops, and let the `nb:network-changed` handler
flush it when the connection returns.

> The event reaches Livewire components on the **current page**. For work that
> must run regardless of which screen is open, keep the decision in the
> handler cheap (set state, kick a sync) rather than screen-specific.

## Events

| Event | Payload | When |
|---|---|---|
| `nb:network-status` | `connected`, `type`, `metered`, `id` | Response to `networkStatus()` |
| `nb:network-changed` | `connected`, `type`, `metered` | Pushed on every real connectivity change |

`networkStatus()` takes an optional `$id` echoed back on `nb:network-status`
for routing concurrent requests. The `->toResponse()` rule applies: inside a
Livewire component action call `->toResponse()`; inside a push or deep-link
handler return the bare `NativeResponse`.

Out of scope for v1: signal strength, SSID (needs the location permission —
not worth the store-review cost), and VPN detection.

## See Also

- [PLUGINS.md](PLUGINS.md) — the `NativeBlade` facade
- [SCHEDULER.md](SCHEDULER.md) — periodic work that pairs with connectivity gating
