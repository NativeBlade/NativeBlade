<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use Illuminate\Http\Client\Pool;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Http\WasmHttpHandler;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * ShellConfig::pool() is the public bracket that wraps a Laravel Http::pool()
 * call with WasmHttpHandler's pool-mode toggle. These tests validate the
 * bracket: enablePool flips the flag on entry, flushPool clears it on exit,
 * and the user's callback sees $poolMode = true while it runs.
 *
 * We deliberately return an empty array from the pool callback so no real
 * HTTP requests fire — flushPool then hits the empty-queue short-circuit and
 * never calls exit(0).
 */
final class ShellConfigPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetHandlerStatics();
    }

    protected function tearDown(): void
    {
        $this->resetHandlerStatics();
        parent::tearDown();
    }

    private function resetHandlerStatics(): void
    {
        $ref = new ReflectionClass(WasmHttpHandler::class);
        $ref->getProperty('poolMode')->setValue(null, false);
        $ref->getProperty('pendingRequests')->setValue(null, []);
        $ref->getProperty('requestIndex')->setValue(null, 0);
    }

    private function readStatic(string $prop): mixed
    {
        return (new ReflectionClass(WasmHttpHandler::class))->getProperty($prop)->getValue();
    }

    #[Test]
    public function pool_enables_pool_mode_for_the_duration_of_the_callback(): void
    {
        self::assertFalse($this->readStatic('poolMode'));

        $wasOnInsideCallback = null;

        NativeBlade::pool(function (Pool $pool) use (&$wasOnInsideCallback) {
            $wasOnInsideCallback = $this->readStatic('poolMode');
            return []; // no requests → flushPool takes the empty-queue branch
        });

        self::assertTrue($wasOnInsideCallback, 'poolMode must be true inside the callback');
        self::assertFalse($this->readStatic('poolMode'), 'flushPool must restore the flag to false');
    }

    #[Test]
    public function pool_returns_http_pool_results_verbatim(): void
    {
        // Empty-queue branch returns [] from Http::pool; our wrapper must pass that through.
        $results = NativeBlade::pool(fn (Pool $pool) => []);
        self::assertSame([], $results);
    }

    #[Test]
    public function pool_resets_pending_queue_when_entering_even_if_stale_from_prior_run(): void
    {
        // Simulate left-over state from a previous aborted run.
        (new ReflectionClass(WasmHttpHandler::class))
            ->getProperty('pendingRequests')
            ->setValue(null, [['stale' => 'request']]);

        $snapshotInsideCallback = null;

        NativeBlade::pool(function (Pool $pool) use (&$snapshotInsideCallback) {
            $snapshotInsideCallback = $this->readStatic('pendingRequests');
            return [];
        });

        // enablePool() clears the queue on entry — the stale entry must be gone.
        self::assertSame([], $snapshotInsideCallback);
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
