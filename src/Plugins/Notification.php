<?php

namespace NativeBlade\Plugins;

use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Fluent builder for a single system notification.
 *
 * Notification instances are constructed through a closure passed to
 * `NativeBlade::notification()` and converted to an action payload when
 * the enclosing NativeResponse is rendered.
 *
 * Notifications are fire-and-forget by default. Pass `->id('...')` to
 * tag a notification so it can be cancelled later via
 * `NativeBlade::cancelNotification($id)`.
 *
 * @see \NativeBlade\NativeResponse::notification()
 */
class Notification
{
    private const SCHEDULE_KINDS = ['minute', 'hour', 'day', 'week', 'month'];

    private string $title = 'NativeBlade';
    private string $body = '';
    private ?string $sound = null;
    private ?string $icon = null;
    private ?string $id = null;

    /**
     * Android notification channel identifier.
     *
     * Android 8+ requires every notification to belong to a channel, so
     * NativeBlade defaults to `'default'` to guarantee delivery when the
     * dev doesn't set one explicitly. Ignored on iOS and desktop.
     */
    private ?string $channel = 'default';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $schedule = null;

    /**
     * When true, the native layer schedules an *exact* alarm (fires on time
     * even in Doze) instead of the default inexact one. Set via
     * `NativeBlade::scheduleNotification()`; needs Permission::EXACT_ALARM on
     * Android, otherwise it degrades to inexact. No effect on iOS (already exact).
     */
    private bool $exact = false;

    /**
     * Set the notification title (top line, bold).
     */
    public function title(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the notification body (subtitle / message text).
     */
    public function body(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Sound played on delivery. Use `'default'` for the system default, or a
     * platform-specific identifier (Android: raw resource name; iOS: file in
     * the app bundle). Ignored on desktop.
     */
    public function sound(string $sound): static
    {
        $this->sound = $sound;
        return $this;
    }

    /**
     * Small icon shown next to the notification. On Android this is a
     * drawable resource name; on iOS it's a bundled attachment; on desktop
     * it's a bundled resource path. See PLUGINS.md for the desktop caveats.
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    /**
     * Android notification channel. The framework auto-creates the channel
     * on first use, so any string works. Ignored on iOS and desktop.
     */
    public function channel(string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * Tag the notification with an identifier so it can be cancelled later
     * via `NativeBlade::cancelNotification($id)`. Re-issuing a notification
     * with the same id replaces the existing one.
     */
    public function id(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Schedule the notification to fire once at the given instant.
     *
     * The DateTime is serialized in UTC ISO 8601 so the native layer can
     * parse it deterministically regardless of the device timezone.
     */
    public function at(DateTimeInterface $when): static
    {
        $utc = (clone $when)->setTimezone(new DateTimeZone('UTC'));
        $this->schedule = [
            'type' => 'at',
            'at' => $utc->format('Y-m-d\TH:i:s\Z'),
        ];
        return $this;
    }

    /**
     * Request exact delivery (fires on time even in Doze). Normally set for you
     * by `NativeBlade::scheduleNotification()`. Android needs Permission::EXACT_ALARM
     * or it degrades to inexact; no effect on iOS (already exact).
     */
    public function exact(bool $exact = true): static
    {
        $this->exact = $exact;
        return $this;
    }

    /**
     * Schedule the notification to repeat every N units of the given kind.
     *
     * @param  string  $kind  One of `'minute'`, `'hour'`, `'day'`, `'week'`, `'month'`.
     * @param  int     $count Number of units between firings (default 1).
     */
    public function every(string $kind, int $count = 1): static
    {
        $kind = strtolower($kind);
        if (!in_array($kind, self::SCHEDULE_KINDS, true)) {
            throw new InvalidArgumentException(
                "Unsupported schedule kind '{$kind}'. Use one of: " . implode(', ', self::SCHEDULE_KINDS)
            );
        }
        if ($count < 1) {
            throw new InvalidArgumentException("Schedule count must be >= 1, got {$count}.");
        }
        $this->schedule = [
            'type' => 'every',
            'kind' => $kind,
            'count' => $count,
        ];
        return $this;
    }

    /**
     * Schedule the notification to repeat every day at the given time
     * (`'HH:MM'` 24-hour). Ergonomic shortcut over `every('day')` when the
     * dev cares about the time-of-day rather than the interval.
     */
    public function dailyAt(string $time): static
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            throw new InvalidArgumentException(
                "Invalid time '{$time}'. Use 'HH:MM' 24-hour format (e.g. '09:00')."
            );
        }
        $this->schedule = [
            'type' => 'dailyAt',
            'time' => $time,
        ];
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

        if ($this->sound !== null)    $payload['sound'] = $this->sound;
        if ($this->icon !== null)     $payload['icon'] = $this->icon;
        if ($this->channel !== null)  $payload['channel'] = $this->channel;
        if ($this->id !== null)       $payload['id'] = $this->id;
        if ($this->schedule !== null) $payload['schedule'] = $this->schedule;
        if ($this->exact)             $payload['exact'] = true;

        return $payload;
    }
}
