<?php

namespace NativeBlade\Config;

/**
 * Fluent builder for the app's realtime connections, registered once in your
 * AppServiceProvider via `NativeBladeConfig::realtimeConfig(fn (RealtimeConfig $c) => ...)`.
 *
 * Each named connection picks a driver and its endpoint/credentials. The whole
 * block is serialized to the JS bridge at boot, where the matching client sets
 * it up: `laravel-echo` + `pusher-js` (reverb/pusher) or the browser `WebSocket`
 * (ws). A single connection multiplexes MANY channels — declare a second named
 * connection only when you need a different server or protocol (e.g. a Reverb
 * `app` for people chat and a raw `ws` `ai` for token streaming).
 *
 * Drivers:
 *  - reverb / pusher : key, host, port, scheme ('https'|'http'), forceTLS,
 *                      authEndpoint (for private/presence), cluster (pusher).
 *  - ws              : url ('wss://…'), plus an optional envelope map for how
 *                      raw frames encode {channel, event, data}.
 *
 * Delivery — how incoming events reach your app:
 *  - (default)       : every event is dispatched as a Livewire `#[On('nb:realtime')]`
 *                      event. Right for human-paced messages (chat, presence,
 *                      notifications).
 *  - 'deliver' => 'js' : events bypass Livewire/PHP and surface as a DOM
 *                      CustomEvent for public/js to consume
 *                      (`window.addEventListener('nb:realtime', e => e.detail)`).
 *                      REQUIRED for high-frequency feeds (game state, cursors,
 *                      telemetry): on the default path each frame is a full
 *                      php-wasm request, and tens per second exhaust the runtime.
 *
 * Nothing secret belongs here: the Pusher/Reverb "key" is a public client key,
 * and private/presence auth happens at runtime by calling `authEndpoint` with
 * the user's bearer token, never a baked-in secret.
 */
class RealtimeConfig
{
    /** @var array<string, array<string, mixed>> connection name -> settings (driver + options) */
    private array $connections = [];

    private ?string $default = null;

    /**
     * Register a named connection.
     *
     * The first connection registered becomes the default unless you set one
     * explicitly with `default()`.
     *
     * @param  string  $name     Handle used in `Realtime::on($name)` and echoed
     *                           back as `$connection` on `nb:realtime`.
     * @param  string  $driver   One of `reverb`, `pusher`, `ws`.
     * @param  array<string, mixed>  $options  Driver-specific settings (see class doc).
     */
    public function connection(string $name, string $driver, array $options = []): static
    {
        $this->connections[$name] = ['driver' => $driver] + $options;
        $this->default ??= $name;

        return $this;
    }

    /** Choose which connection `Realtime` uses when `on()` isn't called. */
    public function default(string $name): static
    {
        $this->default = $name;

        return $this;
    }

    /**
     * @return array{default: string|null, connections: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'default' => $this->default,
            'connections' => $this->connections,
        ];
    }
}
