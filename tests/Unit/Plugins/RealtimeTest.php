<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Realtime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Realtime builder serializes subscribe/private/presence/stream/leave ops
 * into the exact array shape the JS realtime action consumes.
 */
final class RealtimeTest extends TestCase
{
    #[Test]
    public function it_starts_with_no_ops(): void
    {
        self::assertSame([], (new Realtime())->toArray());
    }

    #[Test]
    public function channel_types_carry_their_type(): void
    {
        $ops = (new Realtime())
            ->subscribe('news')
            ->private('user.1')
            ->presence('room')
            ->toArray();

        self::assertSame([
            ['op' => 'subscribe', 'channel' => 'news', 'type' => 'public'],
            ['op' => 'subscribe', 'channel' => 'user.1', 'type' => 'private'],
            ['op' => 'subscribe', 'channel' => 'room', 'type' => 'presence'],
        ], $ops);
    }

    #[Test]
    public function on_stamps_the_connection_on_the_following_ops(): void
    {
        $ops = (new Realtime())
            ->subscribe('a')             // default connection (unstamped)
            ->on('ws')->subscribe('b')   // targets the 'ws' connection
            ->toArray();

        self::assertSame([
            ['op' => 'subscribe', 'channel' => 'a', 'type' => 'public'],
            ['op' => 'subscribe', 'channel' => 'b', 'type' => 'public', 'connection' => 'ws'],
        ], $ops);
    }

    #[Test]
    public function stream_and_leave_serialize_with_their_id(): void
    {
        $ops = (new Realtime())
            ->stream('session.1', 's1')
            ->leave('session.1')
            ->toArray();

        self::assertSame([
            ['op' => 'stream', 'channel' => 'session.1', 'type' => 'stream', 'id' => 's1'],
            ['op' => 'leave', 'channel' => 'session.1'],
        ], $ops);
    }
}
