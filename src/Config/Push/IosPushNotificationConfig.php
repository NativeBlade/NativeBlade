<?php

namespace NativeBlade\Config\Push;

use Closure;
use NativeBlade\Plugins\PushRegistry;

/**
 * Fluent builder for iOS-specific push notification configuration.
 *
 * Used inside `IosConfig::notification(fn ($push) => ...)`. Unlike
 * Android, iOS does not require any credential file on the device
 * itself — APNS registration is handled by the OS as long as the
 * "Push Notifications" capability is enabled in the Xcode project.
 *
 * Server-side APNS credentials (`.p8` auth key, team id, key id) live
 * on your own backend and are never shipped with the app.
 *
 * ```
 * NativeBladeConfig::ios(function (IosConfig $config) {
 *     $config->notification(function (IosPushNotificationConfig $push) {
 *         $push
 *             ->environment('production')  // 'sandbox' for TestFlight
 *             ->badge(true)
 *             ->sound('default')
 *             ->onTokenRefresh(fn ($token) => auth()->user()?->update(['push_token' => $token]))
 *             ->onReceive(function (PushPayload $payload) {
 *                 return match ($payload->data['type'] ?? null) {
 *                     'new_lesson' => NativeBlade::navigate('/lesson/' . $payload->data['lesson_id']),
 *                     default      => null,
 *                 };
 *             });
 *     });
 * });
 * ```
 */
class IosPushNotificationConfig
{
    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * APNS environment. `'production'` for App Store builds, `'sandbox'`
     * for development / TestFlight. Your backend must send pushes to
     * the matching endpoint when assembling APNS requests.
     */
    public function environment(string $env = 'production'): static
    {
        $this->config['environment'] = $env;
        return $this;
    }

    /**
     * Whether the plugin should automatically manage the app icon badge
     * number based on incoming pushes.
     */
    public function badge(bool $enabled = true): static
    {
        $this->config['badge'] = $enabled;
        return $this;
    }

    /**
     * Default notification sound. Pass a filename from the app bundle
     * (e.g. `'ping.caf'`) or `'default'` for the system sound.
     */
    public function sound(string $name = 'default'): static
    {
        $this->config['sound'] = $name;
        return $this;
    }

    /**
     * Register a callback invoked when the APNS device token is
     * delivered or refreshed. Use this to persist the token to your
     * backend so the server can send pushes to this device.
     *
     * @param  Closure(string): mixed  $callback
     */
    public function onTokenRefresh(Closure $callback): static
    {
        PushRegistry::setOnTokenRefresh($callback);
        return $this;
    }

    /**
     * Register a callback invoked every time a push notification is
     * received. The callback receives a `PushPayload` DTO with the
     * full event data. Return a `NativeResponse` (via `NativeBlade::*`)
     * to trigger native actions like navigation.
     *
     * @param  Closure(\NativeBlade\Plugins\PushPayload): mixed  $callback
     */
    public function onReceive(Closure $callback): static
    {
        PushRegistry::setOnReceive($callback);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }
}
