<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use NativeBlade\Plugins\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    #[Test]
    public function defaults_produce_a_minimum_viable_notification(): void
    {
        $payload = (new Notification())->toArray();

        self::assertSame('NativeBlade', $payload['title']);
        self::assertSame('', $payload['body']);
        self::assertArrayNotHasKey('sound', $payload);
        self::assertArrayNotHasKey('icon', $payload);
        self::assertArrayNotHasKey('id', $payload);
        self::assertArrayNotHasKey('schedule', $payload);
        // Android requires a channel — the builder defaults to 'default' so delivery survives on Android 8+.
        self::assertSame('default', $payload['channel']);
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $notification = new Notification();

        self::assertSame($notification, $notification->title('t'));
        self::assertSame($notification, $notification->body('b'));
        self::assertSame($notification, $notification->sound('bell.wav'));
        self::assertSame($notification, $notification->icon('ic_cat'));
        self::assertSame($notification, $notification->channel('messages'));
        self::assertSame($notification, $notification->id('reminder-1'));
        self::assertSame($notification, $notification->at(new DateTimeImmutable('2026-12-25 09:00:00', new DateTimeZone('UTC'))));
        self::assertSame($notification, $notification->every('day'));
        self::assertSame($notification, $notification->dailyAt('09:00'));
    }

    #[Test]
    public function it_overrides_every_field(): void
    {
        $payload = (new Notification())
            ->title('New message')
            ->body('Hello from Alice')
            ->sound('default')
            ->icon('ic_chat')
            ->channel('messages')
            ->id('msg-42')
            ->toArray();

        self::assertSame([
            'title' => 'New message',
            'body' => 'Hello from Alice',
            'sound' => 'default',
            'icon' => 'ic_chat',
            'channel' => 'messages',
            'id' => 'msg-42',
        ], $payload);
    }

    #[Test]
    public function at_serializes_in_utc_iso8601(): void
    {
        // Pass a local time (São Paulo, UTC-3) and confirm we get the UTC equivalent
        // back. The native layer parses Z-suffixed ISO 8601 deterministically.
        $when = new DateTimeImmutable('2026-12-25 09:00:00', new DateTimeZone('America/Sao_Paulo'));
        $payload = (new Notification())->at($when)->toArray();

        self::assertSame([
            'type' => 'at',
            'at' => '2026-12-25T12:00:00Z',
        ], $payload['schedule']);
    }

    #[Test]
    public function every_supports_all_documented_kinds(): void
    {
        foreach (['minute', 'hour', 'day', 'week', 'month'] as $kind) {
            $payload = (new Notification())->every($kind, 2)->toArray();
            self::assertSame([
                'type' => 'every',
                'kind' => $kind,
                'count' => 2,
            ], $payload['schedule']);
        }
    }

    #[Test]
    public function every_normalizes_case(): void
    {
        $payload = (new Notification())->every('Day')->toArray();
        self::assertSame('day', $payload['schedule']['kind']);
    }

    #[Test]
    public function every_rejects_unknown_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Notification())->every('decade');
    }

    #[Test]
    public function every_rejects_non_positive_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Notification())->every('day', 0);
    }

    #[Test]
    public function daily_at_parses_24_hour_strings(): void
    {
        $payload = (new Notification())->dailyAt('09:30')->toArray();
        self::assertSame([
            'type' => 'dailyAt',
            'time' => '09:30',
        ], $payload['schedule']);
    }

    #[Test]
    public function daily_at_rejects_malformed_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Notification())->dailyAt('9am');
    }

    #[Test]
    public function later_schedule_call_replaces_earlier_one(): void
    {
        // Devs sometimes chain at() then realise they want every() — the
        // last call should win, not produce a malformed combined payload.
        $payload = (new Notification())
            ->at(new DateTimeImmutable('2026-12-25 09:00:00', new DateTimeZone('UTC')))
            ->every('day')
            ->toArray();

        self::assertSame('every', $payload['schedule']['type']);
    }
}
