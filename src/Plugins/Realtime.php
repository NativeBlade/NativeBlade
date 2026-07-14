<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for realtime subscriptions, collected via
 * `NativeBlade::realtime(fn (Realtime $r) => ...)->toResponse()`.
 *
 * Subscribe a component to the channels it cares about. Incoming messages arrive
 * on the `nb:realtime` Livewire event with four arguments — `$connection`,
 * `$channel`, `$event`, `$payload` — and, for convenience, ALSO on the specific
 * `nb:realtime:{channel}:{event}` event, so you can route either with one generic
 * listener or a dedicated `#[On]` per event:
 *
 * ```php
 * public function mount(int $matchId): void
 * {
 *     NativeBlade::realtime(fn (Realtime $r) =>
 *         $r->subscribe('chat.'.$matchId)          // this conversation
 *           ->presence('room.'.$matchId)           // who is online
 *     )->toResponse();
 * }
 *
 * #[On('nb:realtime')]
 * public function onMessage($connection, $channel, $event, $payload) { ... }
 * ```
 *
 * One WebSocket connection multiplexes MANY channels, so several open chats are
 * just several `subscribe()` calls on the same connection — no need for more than
 * one connection unless you're talking to a different server/protocol, which you
 * select with `on('name')` (a connection declared in
 * `NativeBladeConfig::realtimeConfig()`). The JS layer ref-counts subscriptions:
 * many components sharing a channel open it once; `leave()` (or unmount) releases
 * it, and the socket closes only when the last subscriber is gone.
 *
 * Channel types:
 *  - `subscribe()` — public channel, no auth.
 *  - `private()`   — requires auth (bearer token to the connection's authEndpoint).
 *  - `presence()`  — private + a live member list (delivered on `nb:realtime-presence`).
 *  - `stream()`    — an ACCUMULATING response (AI tokens, a live feed): coalesced
 *                    deltas on `nb:realtime-stream`, not discrete list messages.
 *
 * The optional `$id` is echoed back on the events, handy when one component holds
 * several subscriptions and wants to route without matching on the channel string.
 *
 * Real-world lifecycle (the part that matters past the happy path):
 *  - Connections drop; the driver auto-reconnects and re-subscribes your channels,
 *    but you MISSED whatever was sent during the gap. Listen for
 *    `nb:realtime-reconnected` ($connection) and re-fetch history since your last
 *    known id (an HTTP call to your backend). Also emitted: `nb:realtime-connected`
 *    and `nb:realtime-disconnected` ($connection, $reason).
 *
 *    ```php
 *    #[On('nb:realtime-reconnected')]
 *    public function resync($connection) { $this->loadSince($this->lastId); }
 *    ```
 *
 * Streams vs channels — reconnection behaves differently on purpose:
 *  - subscribe()/private()/presence() deliver DISCRETE messages you append to a
 *    list; a reconnect is a gap to backfill (above).
 *  - stream() is a single growing response. The driver coalesces its deltas
 *    (flushing a few times a second so the UI doesn't re-render per token) onto
 *    `nb:realtime-stream` ($connection, $streamId, $delta); `nb:realtime-stream-end`
 *    marks completion. A mid-stream drop is `nb:realtime-stream-error`
 *    ($streamId, $error) — treat it as "generation interrupted, offer retry", NOT
 *    a gap to backfill, since a partial generation can't be resumed.
 */
class Realtime
{
    /** @var array<int, array<string, mixed>> */
    private array $entries = [];

    /** Target connection for the ops that follow; null = the config default. */
    private ?string $connection = null;

    /**
     * Target a named connection (declared in `NativeBladeConfig::realtimeConfig()`).
     * Applies to every op chained after it, so group a connection's subscriptions
     * together.
     */
    public function on(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /** Subscribe to a public channel (no auth). */
    public function subscribe(string $channel, ?string $id = null): static
    {
        return $this->add('subscribe', 'public', $channel, $id);
    }

    /** Subscribe to a private channel — auth required (bearer token to the authEndpoint). */
    public function private(string $channel, ?string $id = null): static
    {
        return $this->add('subscribe', 'private', $channel, $id);
    }

    /** Subscribe to a presence channel — private plus a live member list on `nb:realtime-presence`. */
    public function presence(string $channel, ?string $id = null): static
    {
        return $this->add('subscribe', 'presence', $channel, $id);
    }

    /**
     * Open an accumulating stream (AI token streaming, a live data feed). Deltas
     * are coalesced by the driver (flushed a few times a second) and delivered on
     * `nb:realtime-stream` ($connection, $streamId, $delta), so the component
     * appends batched text instead of re-rendering per token; completion arrives
     * on `nb:realtime-stream-end` and a mid-stream drop on `nb:realtime-stream-error`
     * (retry, don't backfill). Feed input (e.g. the prompt) with `realtimeSend()`
     * on the same channel.
     */
    public function stream(string $channel, ?string $id = null): static
    {
        return $this->add('stream', 'stream', $channel, $id);
    }

    /**
     * Leave a channel. The JS layer only truly unsubscribes when the last
     * component holding it leaves, so this is safe to call on `unmount()`.
     */
    public function leave(string $channel): static
    {
        return $this->add('leave', null, $channel, null);
    }

    private function add(string $op, ?string $type, string $channel, ?string $id): static
    {
        $entry = ['op' => $op, 'channel' => $channel];
        if ($type !== null) {
            $entry['type'] = $type;
        }
        if ($this->connection !== null) {
            $entry['connection'] = $this->connection;
        }
        if ($id !== null) {
            $entry['id'] = $id;
        }
        $this->entries[] = $entry;

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->entries;
    }
}
