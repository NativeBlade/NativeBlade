<?php

namespace NativeBlade\Notifications;

/**
 * Fluent builder for a single system notification.
 *
 * Notification instances are constructed through a closure passed to
 * `NativeBlade::notification()` and converted to an action payload when
 * the enclosing NativeResponse is rendered.
 *
 * @see \NativeBlade\NativeResponse::notification()
 */
class Notification
{
    /**
     * Title shown above the notification body on all platforms.
     */
    private string $title = 'NativeBlade';

    /**
     * Main notification text displayed to the user.
     */
    private string $body = '';

    /**
     * Sound played when the notification is delivered.
     *
     * Use `'default'` for the system default tone, or a platform-specific
     * sound identifier registered with your app.
     */
    private ?string $sound = null;

    /**
     * Small icon shown next to the notification.
     *
     * Android reads this from the app's drawable resources; iOS uses it
     * as an attachment image. Ignored on desktop.
     */
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
     * Set the notification title.
     *
     * @param  string  $title  Title text shown above the body.
     */
    public function title(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the notification body text.
     *
     * @param  string  $body  Main text shown to the user.
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
     *
     * @param  string  $icon  Resource identifier or absolute path.
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
     *
     * @param  string  $channel  Channel identifier (e.g. `'lessons'`).
     */
    public function channel(string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * Convert the builder to the payload shape expected by the JS bridge.
     *
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
