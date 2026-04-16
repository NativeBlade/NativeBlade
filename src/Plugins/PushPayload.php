<?php

namespace NativeBlade\Plugins;

/**
 * Immutable DTO representing a push notification delivered to the app.
 *
 * Dispatched to the developer's `onReceive` callback registered via
 * `AndroidPushNotificationConfig` or `IosPushNotificationConfig` in
 * the service provider. Use this inside your callback to route the
 * incoming push to the right handler based on `data` keys.
 *
 * ```
 * ->onReceive(function (PushPayload $payload) {
 *     return match ($payload->data['type'] ?? null) {
 *         'new_lesson' => NativeBlade::navigate('/lesson/' . $payload->data['lesson_id']),
 *         'chat'       => NativeBlade::navigate('/chat/' . $payload->data['room_id']),
 *         default      => null,
 *     };
 * });
 * ```
 */
class PushPayload
{
    /**
     * @param array<string, string> $data   Developer-controlled key/value payload from FCM/APNS.
     * @param array<string, mixed>  $notification  Optional `title` / `body` when the push
     *                                             included a visible notification.
     */
    public function __construct(
        public readonly string $id,
        public readonly array $data,
        public readonly array $notification,
        public readonly string $state,
    ) {}

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $data = $raw['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $notification = $raw['notification'] ?? [];
        if (!is_array($notification)) {
            $notification = [];
        }

        return new self(
            id: (string) ($raw['id'] ?? ''),
            data: $data,
            notification: $notification,
            state: (string) ($raw['state'] ?? 'foreground'),
        );
    }

    /**
     * Shortcut for `$payload->notification['title']`.
     */
    public function title(): ?string
    {
        return $this->notification['title'] ?? null;
    }

    /**
     * Shortcut for `$payload->notification['body']`.
     */
    public function body(): ?string
    {
        return $this->notification['body'] ?? null;
    }

    /**
     * Convenience accessor for a key in the `data` map.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
