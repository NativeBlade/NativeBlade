<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a single system notification.
 *
 * Notification instances are constructed through a closure passed to
 * `NativeBlade::notification()` and converted to an action payload when
 * the enclosing NativeResponse is rendered.
 *
 * Notifications do not return a value to PHP — they are fire-and-forget
 * and therefore do not support `id()`.
 *
 * @see \NativeBlade\NativeResponse::notification()
 */
class Notification
{
    private string $title = 'NativeBlade';
    private string $body = '';
    private ?string $sound = null;
    private ?string $icon = null;

    /**
     * Android notification channel identifier.
     *
     * Android 8+ requires every notification to belong to a channel, so
     * NativeBlade defaults to `'default'` to guarantee delivery when the
     * dev doesn't set one explicitly. Ignored on iOS and desktop.
     */
    private ?string $channel = 'default';

    /**
     * Set the notification title shown above the body.
     */
    public function title(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the main notification text shown to the user.
     */
    public function body(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Set the sound played when the notification is delivered.
     *
     * @param  string  $sound  Platform sound name or `'default'`.
     */
    public function sound(string $sound): static
    {
        $this->sound = $sound;
        return $this;
    }

    /**
     * Set the small icon shown next to the notification.
     *
     * On Android this is a drawable resource name; on iOS it's an
     * attachment image path. Ignored on desktop.
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    /**
     * Set the Android notification channel.
     *
     * Android 8+ requires notifications to be posted to a channel.
     * Ignored on iOS and desktop.
     */
    public function channel(string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'title' => $this->title,
            'body' => $this->body,
        ];

        if ($this->sound !== null)   $payload['sound'] = $this->sound;
        if ($this->icon !== null)    $payload['icon'] = $this->icon;
        if ($this->channel !== null) $payload['channel'] = $this->channel;

        return $payload;
    }
}
