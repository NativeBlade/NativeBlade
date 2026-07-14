# Realtime (WebSocket / Reverb / Pusher)

Driver-based realtime for NativeBlade apps: subscribe to channels and receive
pushed messages live, run presence (who is online), send ephemeral client events,
and consume accumulating streams (AI tokens, live feeds). One connection
multiplexes many channels; you pick the driver in config and plug in.

> **Why it is shaped this way.** The app's PHP runs in php-wasm (request/response),
> so a long-lived socket cannot live in PHP. It lives in the webview JS layer, and
> every incoming message is delivered to your Livewire component as a `#[On]`
> event — the same pipe native sensors and shell streaming use. A realtime app is
> always a **client**: the socket needs a **server** (your Reverb/Pusher/WS
> backend) on the other side.

## Status

- **Working:** `reverb` / `pusher` (public + private/presence channels via
  `realtimeAuth()`, whisper, streams, lifecycle) and raw `ws` (any external or
  custom WebSocket, bidirectional `send`, AI/token streaming with coalescing,
  auto-reconnect with backoff). Every message is delivered on both the generic
  `nb:realtime` and the pre-routed `nb:realtime:{channel}:{event}` — pick a style.

## 1. Configure connections

Declare your connections once in your `AppServiceProvider`. Each named connection
picks a driver and its endpoint. The first one registered is the default.

```php
use NativeBlade\Facades\NativeBladeConfig;
use NativeBlade\Config\RealtimeConfig;

NativeBladeConfig::realtimeConfig(function (RealtimeConfig $c) {
    // Laravel Reverb (or Pusher) for people-facing pub/sub:
    $c->connection('app', 'reverb', [
        'key'          => 'app-key',
        'host'         => 'rt.myapp.com',
        'port'         => 443,
        'scheme'       => 'https',                       // 'https' => forceTLS
        'authEndpoint' => 'https://api.myapp.com/broadcasting/auth', // private/presence
    ]);

    // A second connection only when the server/protocol differs (e.g. AI streaming):
    $c->connection('ai', 'ws', ['url' => 'wss://ai.myapp.com/stream']);
});
```

| Driver     | `options`                                                        |
|------------|------------------------------------------------------------------|
| `reverb`   | `key, host, port, scheme, forceTLS, authEndpoint`                |
| `pusher`   | `key, cluster, host?, port?, authEndpoint`                       |
| `ws`       | `url` (`wss://…`), for any raw/custom WebSocket                   |

Nothing secret belongs here: the Pusher/Reverb `key` is a public client key, and
private/presence auth happens at runtime against `authEndpoint` with the user's
bearer token.

## 2. Subscribe and receive

Subscribe in `mount()` and handle messages with `#[On('nb:realtime')]`. The
builder is flushed by `->toResponse()` like every other native action.

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Realtime;

public array $messages = [];

public function mount(int $roomId): void
{
    // Load history first (HTTP to your backend), then go live:
    $this->messages = /* GET /rooms/{roomId}/messages */;

    NativeBlade::realtime(fn (Realtime $r) =>
        $r->subscribe('chat.'.$roomId)      // public channel
    )->toResponse();
}

#[On('nb:realtime')]
public function onMessage($connection, $channel, $event, $payload): void
{
    if ($event === 'MessageSent') {
        $this->messages[] = $payload;
    }
}
```

`nb:realtime` carries `$connection`, `$channel`, `$event`, `$payload`. Route on
`$event` / `$channel`.

Prefer a dedicated listener per event? The same message is also delivered
**pre-routed**, so you can drop the `if ($event === …)`:

```php
#[On('nb:realtime:chat.42:MessageSent')]
public function shipped($payload): void { $this->messages[] = $payload; }
```

### Channel types

```php
$r->subscribe('news');          // public — no auth
$r->private('user.'.$id);       // private — auth required (bearer to authEndpoint)
$r->presence('room.'.$roomId);  // private + a live member list
```

For **private/presence** channels, hand the layer the user's bearer token once
after login (Echo POSTs it to your `authEndpoint`); pass `null` on logout:

```php
NativeBlade::realtimeAuth($userToken)->toResponse();
```

### Presence (who is online)

Presence members arrive on `nb:realtime-presence`:

```php
#[On('nb:realtime-presence')]
public function onPresence($connection, $channel, $event, $members = null, $user = null): void
{
    // $event: 'here' (=> $members), 'joining' (=> $user), 'leaving' (=> $user)
}
```

## 3. Send

How you send depends on the driver.

- **Reverb / Pusher:** the client only *receives* broadcasts. To send a **persisted**
  message, POST to your backend, which then `broadcast()`s it to everyone:

  ```php
  public function send(): void
  {
      NativeBlade::http()->post('https://api.myapp.com/rooms/'.$this->roomId.'/messages', [
          'body' => $this->draft,
      ]);
      $this->draft = '';
  }
  ```

- **Whisper** — ephemeral client events (typing indicators, cursors, presence
  pings) on a private/presence channel. Not persisted, rate-limited:

  ```php
  NativeBlade::realtimeWhisper('chat.'.$this->roomId, 'typing', ['user' => auth()->id()])->toResponse();
  ```

- **Native send** — on the `ws` driver, `realtimeSend()` is a real frame sent
  over the socket (bidirectional):

  ```php
  NativeBlade::realtimeSend('feed', 'move', ['x' => 3], 'ws')->toResponse();
  ```

## 4. Raw WebSocket feeds (`ws` driver)

Point a connection at any WebSocket — a third-party feed you don't control (a
crypto ticker, sports scores, an IoT gateway, a game server) or your own
non-Laravel backend. The framework opens/reconnects the socket, routes each frame
to Livewire, coalesces streams, and queues sends before the socket is open; the
frame **shapes are your server's** (bring-your-own-protocol).

```php
$c->connection('ticker', 'ws', ['url' => 'wss://stream.example.com/btcusd']);
```

```php
public function mount(): void
{
    NativeBlade::realtime(fn (Realtime $r) => $r->on('ticker')->subscribe('btcusd'))->toResponse();
}

#[On('nb:realtime')]
public function onFrame($connection, $channel, $event, $payload): void
{
    // $payload = the parsed JSON frame (or the raw string if it wasn't JSON).
    // If your frames are {channel, event, data}, $channel/$event are routed for you.
    $this->price = $payload['p'] ?? $this->price;
}

public function send(): void
{
    // Bidirectional: a real frame over the socket (string, or JSON-encoded object).
    NativeBlade::realtimeSend('btcusd', 'ping', ['nonce' => 1], 'ticker')->toResponse();
}
```

`subscribe()` also sends a `{"event":"subscribe","channel":"…"}` frame in case
your server needs one; servers that stream everything by default just ignore it.
The connection auto-reconnects with backoff, emitting the lifecycle events (§6).

## 5. Streams (AI tokens, live feeds)

A `stream()` is a single **growing** response, not discrete list messages. The
driver **coalesces** its deltas (flushing a few times a second) so your component
appends batched text instead of re-rendering per token.

```php
$r->on('ai')->stream('session.'.$sessionId);   // open the stream
// then send the prompt:
NativeBlade::realtimeSend('session.'.$sessionId, 'prompt', ['text' => $prompt], 'ai')->toResponse();
```

```php
public string $answer = '';

#[On('nb:realtime-stream')]
public function onDelta($connection, $streamId, $delta): void
{
    $this->answer .= $delta;                     // batched append, smooth typewriter
}

#[On('nb:realtime-stream-end')]
public function onDone($connection, $streamId): void { /* finalize / persist */ }

#[On('nb:realtime-stream-error')]
public function onStreamError($connection, $streamId, $error): void
{
    // Mid-stream drop = generation interrupted. Offer a retry — a partial
    // generation cannot be resumed. This is NOT a gap to backfill.
}
```

## 6. Reconnection (the part that matters past the happy path)

Connections drop; the driver auto-reconnects and re-subscribes your channels, but
you **missed** whatever was sent during the gap. Re-fetch history on reconnect:

```php
#[On('nb:realtime-reconnected')]
public function resync($connection): void
{
    $this->loadSince($this->lastMessageId);      // HTTP gap-fill since your last id
}
```

Also emitted: `nb:realtime-connected` (first connect) and
`nb:realtime-disconnected` ($connection, $reason).

**Channels vs streams behave differently on purpose:** a channel reconnect is a
gap to backfill (above); a stream drop is `nb:realtime-stream-error` → retry, not
backfill (a partial generation can't be resumed). The stream driver does **not**
silently reconnect-and-rerun.

## 7. Multiple connections and channels

- **Many channels, one connection** (e.g. a Tinder-style list of chats): just
  subscribe to each channel — `chat.1`, `chat.2`, `chat.77` — on the same
  connection. Reverb multiplexes them; the JS layer ref-counts, so a channel two
  components share opens once and closes when the last leaves. The conversation
  list can subscribe to a firehose channel (`user.{id}`) for unread badges.
- **Many connections** (e.g. people chat on Reverb + AI chat on a raw WS): declare
  two named connections and target one with `->on('name')`. Rule of thumb:
  **channels** separate things on the same server, **named connections** separate
  servers/protocols.

Leave a channel on `unmount()`:

```php
public function unmount(): void
{
    NativeBlade::realtimeLeave('chat.'.$this->roomId)->toResponse();
}
```

## 8. High-frequency feeds: deliver straight to JS (`deliver: 'js'`)

Every default realtime event becomes a Livewire `#[On]` dispatch — a full
php-wasm request. That's right for **human-paced** traffic (chat, presence,
notifications). It is **fatal** for a firehose: a multiplayer game broadcasting
state at ~20 Hz, live cursors, or a telemetry stream turns into tens of PHP
requests per second, each booting Laravel and writing to the runtime's virtual
filesystem — until it runs out of file descriptors and every request 500s
(`File descriptor value too large`).

For those, declare the connection `deliver: 'js'`. Its events **bypass
Livewire/PHP entirely** and surface as DOM `CustomEvent`s that your own
`public/js` consumes directly, at native speed:

```php
NativeBladeConfig::realtimeConfig(function (RealtimeConfig $c) {
    $c->connection('game', 'ws', [
        'url'     => 'wss://game.myapp.com/play',
        'deliver' => 'js',          // <- events go to public/js, never to PHP
    ]);
});
```

Consume them in a small module under `public/js/` (see ARCHITECTURE.md for the
modularity rule). The events keep the same names, delivered on `window`:

```js
// public/js/game/net.js
window.addEventListener('nb:realtime', (e) => {
    applyServerFrame(e.detail.payload);        // e.detail = { connection, channel, event, payload, id }
});
window.addEventListener('nb:realtime-connected',    () => setStatus('online'));
window.addEventListener('nb:realtime-reconnected',  () => rejoin());
window.addEventListener('nb:realtime-disconnected', () => setStatus('offline'));
```

That listener is the **whole** bridge — no capture-phase tricks, no patching
`Livewire.dispatch`, no frame-mirror detection. Events are delivered only to the
active app frame, so a background/navigation buffer frame never double-consumes.

**Sending** still goes through PHP at human rate — call a Livewire method from JS
for actions the server must validate:

```js
const wire = window.Livewire.find(root.closest('[wire\\:id]').getAttribute('wire:id'));
wire.$call('sendMove', movement);              // one request per user action, not per frame
```

…or, for a raw `ws` connection you publish on directly, use
`NativeBlade::realtimeSend(...)` from that Livewire method. The rule: **inbound
firehose → JS; outbound user actions → a normal Livewire call.**

## Events reference

Default connections deliver these as Livewire `#[On]` events; `deliver: 'js'`
connections deliver the identical set as `window` DOM `CustomEvent`s whose
`event.detail` holds the same arguments.

| Event                         | Arguments                                            |
|-------------------------------|------------------------------------------------------|
| `nb:realtime`                 | `$connection, $channel, $event, $payload`            |
| `nb:realtime-presence`        | `$connection, $channel, $event, $members?, $user?`   |
| `nb:realtime-stream`          | `$connection, $streamId, $delta`                     |
| `nb:realtime-stream-end`      | `$connection, $streamId`                             |
| `nb:realtime-stream-error`    | `$connection, $streamId, $error`                     |
| `nb:realtime-connected`       | `$connection`                                        |
| `nb:realtime-reconnected`     | `$connection`                                        |
| `nb:realtime-disconnected`    | `$connection, $reason`                               |

## Setup

- `laravel-echo` + `pusher-js` are added to the app's `package.json` automatically
  (new apps via the scaffold; existing apps via `php artisan nativeblade:update`,
  which syncs deps from the stub and runs `npm install`). They are dynamically
  imported, so they only load at runtime if the app actually uses realtime.
- Run `php artisan nativeblade:config` after changing `realtimeConfig()` — it
  publishes the connections into `public/nativeblade-config.json`, which the JS
  layer reads at boot.
- You need a realtime **server** (Laravel Reverb, Pusher, or your own WS broker).
  The NativeBlade app is only the client.
