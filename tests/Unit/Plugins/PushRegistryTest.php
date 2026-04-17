<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\PushPayload;
use NativeBlade\Plugins\PushRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PushRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        PushRegistry::reset();
    }

    protected function tearDown(): void
    {
        PushRegistry::reset();
    }

    #[Test]
    public function handle_receive_returns_null_when_no_callback_is_registered(): void
    {
        $result = PushRegistry::handleReceive(PushPayload::fromArray([]));
        self::assertNull($result);
    }

    #[Test]
    public function handle_token_refresh_returns_null_when_no_callback_is_registered(): void
    {
        self::assertNull(PushRegistry::handleTokenRefresh('token-abc'));
    }

    #[Test]
    public function the_registered_receive_callback_is_invoked_with_the_payload(): void
    {
        $received = null;
        PushRegistry::setOnReceive(function (PushPayload $p) use (&$received) {
            $received = $p;
            return 'handled';
        });

        $payload = PushPayload::fromArray(['id' => 'abc', 'data' => ['foo' => 'bar']]);
        $result = PushRegistry::handleReceive($payload);

        self::assertSame('handled', $result);
        self::assertSame($payload, $received);
    }

    #[Test]
    public function the_registered_token_refresh_callback_is_invoked_with_the_token(): void
    {
        $captured = null;
        PushRegistry::setOnTokenRefresh(function (string $token) use (&$captured) {
            $captured = $token;
            return 'ok';
        });

        $result = PushRegistry::handleTokenRefresh('token-xyz');

        self::assertSame('ok', $result);
        self::assertSame('token-xyz', $captured);
    }

    #[Test]
    public function reset_clears_both_callbacks(): void
    {
        PushRegistry::setOnReceive(fn() => 'receive');
        PushRegistry::setOnTokenRefresh(fn() => 'refresh');

        PushRegistry::reset();

        self::assertNull(PushRegistry::handleReceive(PushPayload::fromArray([])));
        self::assertNull(PushRegistry::handleTokenRefresh('t'));
    }

    #[Test]
    public function set_on_receive_replaces_the_previous_callback(): void
    {
        PushRegistry::setOnReceive(fn() => 'first');
        PushRegistry::setOnReceive(fn() => 'second');

        self::assertSame('second', PushRegistry::handleReceive(PushPayload::fromArray([])));
    }
}
