<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Bridge;

use NativeBlade\Bridge\NativeBridge;
use NativeBlade\Bridge\NativeCallNotAllowed;
use NativeBlade\Bridge\SuspendGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CountsNatively implements \Countable
{
    public function count(): int
    {
        NativeBridge::call('db', ['sql' => 'select count(*)']);

        return 0;
    }
}

final class SerializesNatively implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return NativeBridge::call('http', ['url' => 'https://a.test/']);
    }
}

/**
 * NativeBridge refuses a call from inside a callback the Asyncify build
 * cannot pause in, with the same exception on every platform and in tests.
 */
final class SuspendGuardTest extends TestCase
{
    protected function setUp(): void
    {
        NativeBridge::handleWith(fn () => ['ok' => true, 'result' => 'answered']);
    }

    protected function tearDown(): void
    {
        NativeBridge::handleWith(null);
    }

    #[Test]
    public function a_plain_call_goes_through(): void
    {
        self::assertSame('answered', NativeBridge::call('http', []));
    }

    /** @return iterable<string, array{\Closure}> */
    public static function unpausableConstructs(): iterable
    {
        $call = fn () => NativeBridge::call('http', ['url' => 'https://a.test/']);

        yield 'usort' => [function () use ($call) { $a = [2, 1]; usort($a, fn ($x, $y) => $call() ? 0 : 0); }, 'usort()'];
        yield 'uasort' => [function () use ($call) { $a = [2, 1]; uasort($a, fn ($x, $y) => $call() ? 0 : 0); }, 'uasort()'];
        yield 'uksort' => [function () use ($call) { $a = [2, 1]; uksort($a, fn ($x, $y) => $call() ? 0 : 0); }, 'uksort()'];
        yield 'array_walk' => [function () use ($call) { $a = [1]; array_walk($a, function () use ($call) { $call(); }); }, 'array_walk()'];
        yield 'array_udiff' => [function () use ($call) { array_udiff([1], [2], fn () => $call() ? 0 : 0); }, 'array_udiff()'];
        yield 'json_encode' => [fn () => json_encode(new SerializesNatively()), 'json_encode()'];
        yield 'count' => [fn () => count(new CountsNatively()), 'count()'];
        yield 'preg_replace_callback' => [fn () => preg_replace_callback('/a/', fn () => $call() ?? 'b', 'a'), 'preg_replace_callback()'];
        yield 'ReflectionFunction::invoke' => [fn () => (new \ReflectionFunction($call))->invoke(), 'ReflectionFunction::invoke()'];
    }

    #[Test]
    #[DataProvider('unpausableConstructs')]
    public function a_call_inside_an_unpausable_construct_is_refused_with_the_construct_named(\Closure $run, string $where): void
    {
        try {
            $run();
            self::fail('expected NativeCallNotAllowed');
        } catch (NativeCallNotAllowed $e) {
            self::assertStringContainsString("inside {$where}", $e->getMessage());
            self::assertMatchesRegularExpression('/^(An HTTP request|A query on the native database) was made/', $e->getMessage());
            self::assertStringContainsString('collect the data first', $e->getMessage());
        }
    }

    #[Test]
    public function constructs_the_runtime_can_pause_in_are_left_alone(): void
    {
        $call = fn () => NativeBridge::call('db', ['sql' => 'select 1']);

        self::assertSame(['answered'], array_map(fn () => $call(), [1]));
        self::assertSame([1], array_filter([1], fn () => $call() === 'answered'));
        self::assertSame('answered', array_reduce([1], fn ($c) => $call(), null));
        self::assertSame('answered', call_user_func($call));
        self::assertSame('answered', (function () use ($call) { yield $call(); })()->current());
        $a = [2, 1];
        usort($a, fn ($x, $y) => $x <=> $y);
        self::assertSame('answered', $call(), 'after a usort, not inside it');
    }

    #[Test]
    public function the_message_names_the_kind_of_call(): void
    {
        try {
            count(new CountsNatively());
            self::fail('expected NativeCallNotAllowed');
        } catch (NativeCallNotAllowed $e) {
            self::assertStringContainsString('A query on the native database was made inside count()', $e->getMessage());
        }
    }

    #[Test]
    public function the_lists_record_where_they_were_measured(): void
    {
        self::assertStringContainsString('3.1.57', SuspendGuard::MEASURED_ON);
        self::assertSame('crash', SuspendGuard::UNPAUSABLE_FUNCTIONS['usort']);
        self::assertSame('hang', SuspendGuard::UNPAUSABLE_FUNCTIONS['preg_replace_callback']);
    }
}
