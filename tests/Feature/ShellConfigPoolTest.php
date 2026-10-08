<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use Illuminate\Http\Client\Pool;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Http\WasmHttpHandler;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ShellConfig::pool() is the public bracket that wraps a Laravel Http::pool()
 * call with WasmHttpHandler's pool mode, so the requests made inside leave
 * PHP as one batch the shell can run in parallel.
 */
final class ShellConfigPoolTest extends TestCase
{
    protected function tearDown(): void
    {
        WasmHttpHandler::flushPool();
        parent::tearDown();
    }

    #[Test]
    public function pool_enables_pool_mode_for_the_duration_of_the_callback(): void
    {
        self::assertFalse(WasmHttpHandler::isPooling());

        $wasOnInsideCallback = null;
        NativeBlade::pool(function (Pool $pool) use (&$wasOnInsideCallback) {
            $wasOnInsideCallback = WasmHttpHandler::isPooling();

            return [];
        });

        self::assertTrue($wasOnInsideCallback, 'pool mode must be on inside the callback');
        self::assertFalse(WasmHttpHandler::isPooling(), 'and off again afterwards');
    }

    #[Test]
    public function pool_mode_is_left_even_when_the_callback_throws(): void
    {
        try {
            NativeBlade::pool(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertFalse(WasmHttpHandler::isPooling());
    }

    #[Test]
    public function pool_returns_http_pool_results_verbatim(): void
    {
        self::assertSame([], NativeBlade::pool(fn (Pool $pool) => []));
    }

    #[Test]
    public function pool_callback_receives_an_http_pool_builder(): void
    {
        $received = null;
        NativeBlade::pool(function (Pool $pool) use (&$received) {
            $received = $pool;

            return [];
        });

        self::assertInstanceOf(Pool::class, $received);
    }
}
