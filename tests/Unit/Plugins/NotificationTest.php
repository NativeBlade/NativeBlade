<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

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
            ->toArray();

        self::assertSame([
            'title' => 'New message',
            'body' => 'Hello from Alice',
            'sound' => 'default',
            'icon' => 'ic_chat',
            'channel' => 'messages',
        ], $payload);
    }
}
