<?php

namespace NativeBlade\Config\Push;

use Closure;
use NativeBlade\Plugins\PushRegistry;

/**
 * Fluent builder for Android-specific push notification configuration.
 *
 * Used inside `AndroidConfig::notification(fn ($push) => ...)`. Configures
 * FCM (Firebase Cloud Messaging) channel, visual style, and the PHP
 * callbacks that run every time a push or token event arrives.
 *
 * ```
 * NativeBladeConfig::android(function (AndroidConfig $config) {
 *     $config->notification(function (AndroidPushNotificationConfig $push) {
 *         $push
 *             ->fcmConfig(base_path('google-services.json'))
 *             ->channel('lessons', 'Lessons', importance: 'high')
 *             ->onTokenRefresh(fn ($token) => NativeBlade::setState('push.token', $token))
 *             ->onReceive(function (PushPayload $payload) {
 *                 return match ($payload->data['type'] ?? null) {
 *                     'new_lesson' => NativeBlade::navigate('/lesson/' . $payload->data['lesson_id']),
 *                     default      => null,
 *                 };
 *             });
 *     });
 * });
 * ```
 *
 * The notification icon is NOT configured here — NativeBlade's built-in
 * icon generator (`nativeblade:icon`) already produces the full Android
 * drawable set including the notification icon, and FCM falls back to
 * that automatically. If you need a different icon per push, set it on
 * the server side via the FCM `notification.icon` field.
 */
class AndroidPushNotificationConfig
{
    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * Absolute path to the `google-services.json` file downloaded from
     * the Firebase console.
     *
     * @deprecated Use `NativeBladeConfig::firebase(google-services.json path)`
     *             instead. That same file backs every Firebase service
     *             (Messaging, Analytics, ...), so it belongs at the top level,
     *             not under push. This still works for backward compatibility.
     */
    public function fcmConfig(string $path): static
    {
        $this->config['fcmConfig'] = $path;
        return $this;
    }

    /**
     * Register the default Android notification channel used for pushes
     * that don't specify their own.
     *
     * @param  string  $id          Channel identifier (e.g. `'lessons'`).
     * @param  ?string $name        Human-readable name shown in the system
     *                              notification settings. Defaults to the id.
     * @param  string  $importance  One of `'default'`, `'high'`, `'low'`, `'min'`.
     */
    public function channel(string $id, ?string $name = null, string $importance = 'default'): static
    {
        $this->config['channel'] = [
            'id' => $id,
            'name' => $name ?? $id,
            'importance' => $importance,
        ];
        return $this;
    }

    /**
     * Register a callback invoked when the FCM device token is
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
