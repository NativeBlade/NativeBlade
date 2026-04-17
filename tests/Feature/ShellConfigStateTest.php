<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ShellConfig exposes a key/value store backed by the `sqlite` connection.
 * These tests use Testbench's in-memory sqlite DB and hit the real
 * `nativeblade_state` table created on first access.
 */
final class ShellConfigStateTest extends TestCase
{
    private ShellConfig $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shell = app('nativeblade');
    }

    #[Test]
    public function set_and_get_roundtrip_preserves_scalar_values(): void
    {
        $this->shell->setState('nb.str', 'hello');
        $this->shell->setState('nb.int', 42);
        $this->shell->setState('nb.bool', true);
        $this->shell->setState('nb.null', null);

        self::assertSame('hello', $this->shell->getState('nb.str'));
        self::assertSame(42, $this->shell->getState('nb.int'));
        self::assertSame(true, $this->shell->getState('nb.bool'));
        // null round-trips as default because json_decode('null') === null
        self::assertSame('fallback', $this->shell->getState('nb.null', 'fallback'));
    }

    #[Test]
    public function set_and_get_roundtrip_preserves_nested_arrays(): void
    {
        $payload = ['user' => ['id' => 7, 'name' => 'Jeff'], 'flags' => ['beta', 'pro']];
        $this->shell->setState('session', $payload);

        self::assertSame($payload, $this->shell->getState('session'));
    }

    #[Test]
    public function get_returns_default_for_missing_keys(): void
    {
        self::assertNull($this->shell->getState('absent'));
        self::assertSame('fallback', $this->shell->getState('absent', 'fallback'));
        self::assertSame([], $this->shell->getState('absent', []));
    }

    #[Test]
    public function set_state_replaces_existing_value(): void
    {
        $this->shell->setState('counter', 1);
        $this->shell->setState('counter', 2);
        $this->shell->setState('counter', 3);

        self::assertSame(3, $this->shell->getState('counter'));
    }

    #[Test]
    public function state_returns_all_rows_decoded(): void
    {
        $this->shell->setState('a', 1);
        $this->shell->setState('b', ['nested' => true]);

        $all = $this->shell->state();

        self::assertArrayHasKey('a', $all);
        self::assertArrayHasKey('b', $all);
        self::assertSame(1, $all['a']);
        self::assertSame(['nested' => true], $all['b']);
    }

    #[Test]
    public function state_with_scope_filters_by_scope(): void
    {
        $this->shell->setState('a', 1, 'cache');
        $this->shell->setState('b', 2, 'cache');
        $this->shell->setState('c', 3, 'persistent');

        $cache = $this->shell->state('cache');

        self::assertCount(2, $cache);
        self::assertArrayHasKey('a', $cache);
        self::assertArrayHasKey('b', $cache);
        self::assertArrayNotHasKey('c', $cache);
    }

    #[Test]
    public function forget_removes_only_the_given_key(): void
    {
        $this->shell->setState('keep', 'yes');
        $this->shell->setState('drop', 'bye');

        $this->shell->forget('drop');

        self::assertSame('yes', $this->shell->getState('keep'));
        self::assertNull($this->shell->getState('drop'));
    }

    #[Test]
    public function forget_is_idempotent_on_missing_keys(): void
    {
        $this->shell->forget('never-set');
        self::assertNull($this->shell->getState('never-set'));
    }

    #[Test]
    public function flush_with_scope_wipes_only_that_scope(): void
    {
        $this->shell->setState('a', 1, 'cache');
        $this->shell->setState('b', 2, 'cache');
        $this->shell->setState('c', 3, 'persistent');

        $this->shell->flush('cache');

        self::assertNull($this->shell->getState('a'));
        self::assertNull($this->shell->getState('b'));
        self::assertSame(3, $this->shell->getState('c'));
    }

    #[Test]
    public function flush_with_no_scope_wipes_everything(): void
    {
        $this->shell->setState('a', 1, 'cache');
        $this->shell->setState('b', 2, 'persistent');

        $this->shell->flush();

        self::assertSame([], $this->shell->state());
    }

    #[Test]
    public function default_scope_is_persistent(): void
    {
        $this->shell->setState('x', 'val'); // no explicit scope
        $this->shell->setState('y', 'val', 'other');

        $persistent = $this->shell->state('persistent');

        self::assertArrayHasKey('x', $persistent);
        self::assertArrayNotHasKey('y', $persistent);
    }

    #[Test]
    public function ensure_table_is_created_lazily_on_first_access(): void
    {
        // state() on a virgin connection must not throw — it should create
        // the table on demand via ensureTable().
        $result = $this->shell->state();
        self::assertSame([], $result);
    }

    #[Test]
    public function unicode_and_special_chars_round_trip_cleanly(): void
    {
        $this->shell->setState('greet', 'olá — 🎉 <tag>');
        self::assertSame('olá — 🎉 <tag>', $this->shell->getState('greet'));
    }
}
