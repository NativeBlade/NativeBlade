<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NativeBlade auto-wires Laravel's database cache driver against the same
 * `sqlite` connection used for state. Persistence + Laravel's full Cache
 * contract should just work; these tests pin both.
 */
final class NativeBladeCacheStoreTest extends TestCase
{
    #[Test]
    public function default_cache_store_is_nativeblade(): void
    {
        self::assertSame('nativeblade', config('cache.default'));
    }

    #[Test]
    public function nativeblade_store_config_targets_sqlite_connection_and_tables(): void
    {
        self::assertSame([
            'driver' => 'database',
            'connection' => 'sqlite',
            'table' => 'nativeblade_cache',
            'lock_connection' => 'sqlite',
            'lock_table' => 'nativeblade_cache_locks',
            'lock_lottery' => [2, 100],
        ], config('cache.stores.nativeblade'));
    }

    #[Test]
    public function put_and_get_roundtrip_returns_the_value(): void
    {
        Cache::put('greeting', 'hello world', 60);
        self::assertSame('hello world', Cache::get('greeting'));
    }

    #[Test]
    public function complex_payloads_serialize_through_unchanged(): void
    {
        $payload = ['user' => ['id' => 7, 'flags' => ['beta', 'pro']], 'count' => 42];
        Cache::put('session.payload', $payload, 60);
        self::assertSame($payload, Cache::get('session.payload'));
    }

    #[Test]
    public function forget_removes_the_key(): void
    {
        Cache::put('tmp', 'x', 60);
        self::assertTrue(Cache::has('tmp'));
        Cache::forget('tmp');
        self::assertFalse(Cache::has('tmp'));
    }

    #[Test]
    public function expired_entries_return_null(): void
    {
        Cache::put('about-to-expire', 'value', now()->subSecond());
        self::assertNull(Cache::get('about-to-expire'));
    }

    #[Test]
    public function remember_computes_once_and_caches_the_result(): void
    {
        $calls = 0;
        $compute = function () use (&$calls) {
            $calls++;
            return 'computed';
        };

        self::assertSame('computed', Cache::remember('memo', 60, $compute));
        self::assertSame('computed', Cache::remember('memo', 60, $compute));
        self::assertSame(1, $calls);
    }

    #[Test]
    public function cache_writes_land_in_the_sqlite_cache_table(): void
    {
        Cache::put('persisted', 'value', 60);

        // Laravel's DatabaseStore prepends the configured cache prefix to the
        // raw key. Match by suffix so the test stays independent of whatever
        // APP_NAME-derived prefix is in effect.
        $row = \Illuminate\Support\Facades\DB::connection('sqlite')
            ->selectOne("SELECT key FROM nativeblade_cache WHERE key LIKE '%persisted'");

        self::assertNotNull($row);
        self::assertStringEndsWith('persisted', $row->key);
    }
}
