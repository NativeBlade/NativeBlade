<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Database;

use Illuminate\Database\Eloquent\Model;
use NativeBlade\Database\HasNativeDatabase;
use NativeBlade\Database\NativeConnection;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Model-under-test using HasNativeDatabase. Non-incrementing string primary
 * key because Eloquent's auto-increment path calls
 * $connection->getPdo()->lastInsertId(), which NativeConnection hard-codes
 * to null — swapping the key strategy keeps save() off that cliff. The
 * static $forced* hooks let tests pin down which connection/name the
 * trait's branching logic sees.
 */
class Widget extends Model
{
    use HasNativeDatabase;

    protected $table = 'widgets';
    protected $primaryKey = 'email';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    public $timestamps = false;

    public static mixed $forcedConnection = null;
    public static ?string $forcedConnectionName = null;

    public function getConnection()
    {
        return self::$forcedConnection ?? parent::getConnection();
    }

    public function getConnectionName()
    {
        return self::$forcedConnectionName ?? parent::getConnectionName();
    }
}

/**
 * NativeConnection subclass that records every call and serves pre-seeded
 * select results — so we can verify the trait's native branch emits
 * exactly one affectingStatement (the upsert) plus one select (where->first)
 * without going anywhere near the real cache/exit bridge.
 */
class SpyNativeConnection extends NativeConnection
{
    /** @var array<int, array{sql: string, bindings: array, type: string}> */
    public array $statements = [];

    /** @var array<int, array{sql: string, bindings: array}> */
    public array $selects = [];

    /** @var array<int, array<string, mixed>> */
    public array $nextSelectResult = [];

    public function __construct()
    {
        parent::__construct(['native_driver' => 'mysql', 'database' => 'test_db']);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $this->selects[] = ['sql' => $query, 'bindings' => $bindings];
        $result = $this->nextSelectResult;
        $this->nextSelectResult = [];
        return array_map(fn ($r) => (object) $r, $result);
    }

    public function affectingStatement($query, $bindings = [])
    {
        $this->statements[] = ['sql' => $query, 'bindings' => $bindings, 'type' => 'affecting'];
        return 1;
    }

    public function insert($query, $bindings = [])
    {
        $this->statements[] = ['sql' => $query, 'bindings' => $bindings, 'type' => 'insert'];
        return true;
    }

    public function statement($query, $bindings = [])
    {
        $this->statements[] = ['sql' => $query, 'bindings' => $bindings, 'type' => 'statement'];
        return true;
    }

    public function update($query, $bindings = [])
    {
        $this->statements[] = ['sql' => $query, 'bindings' => $bindings, 'type' => 'update'];
        return 1;
    }

    public function delete($query, $bindings = [])
    {
        $this->statements[] = ['sql' => $query, 'bindings' => $bindings, 'type' => 'delete'];
        return 1;
    }
}

final class HasNativeDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection()->getSchemaBuilder()->create('widgets', function ($table) {
            $table->string('email')->primary();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Widget::$forcedConnection = null;
        Widget::$forcedConnectionName = null;
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // ORM branch — trait delegates to Eloquent when connection isn't native.
    // ---------------------------------------------------------------

    #[Test]
    public function update_or_create_creates_row_when_attributes_do_not_match(): void
    {
        $w = Widget::updateOrCreate(['email' => 'a@b.c'], ['name' => 'Alice']);

        self::assertInstanceOf(Widget::class, $w);
        self::assertTrue($w->exists);
        self::assertSame('a@b.c', $w->email);
        self::assertSame('Alice', $w->name);
        self::assertCount(1, Widget::all());
    }

    #[Test]
    public function update_or_create_updates_row_when_attributes_match(): void
    {
        Widget::create(['email' => 'a@b.c', 'name' => 'Old', 'status' => 'pending']);

        $w = Widget::updateOrCreate(['email' => 'a@b.c'], ['name' => 'New']);

        self::assertSame('New', $w->name);
        self::assertSame('pending', Widget::find('a@b.c')->status, 'Unrelated columns must not be overwritten');
        self::assertCount(1, Widget::all(), 'updateOrCreate must not double-insert');
    }

    #[Test]
    public function first_or_create_returns_existing_row_untouched(): void
    {
        Widget::create(['email' => 'a@b.c', 'name' => 'Original']);

        $w = Widget::firstOrCreate(['email' => 'a@b.c'], ['name' => 'Ignored']);

        self::assertSame('Original', $w->name, 'values must not overwrite when record already exists');
        self::assertCount(1, Widget::all());
    }

    #[Test]
    public function first_or_create_creates_row_when_missing(): void
    {
        $w = Widget::firstOrCreate(['email' => 'a@b.c'], ['name' => 'Fresh']);

        self::assertTrue($w->exists);
        self::assertSame('Fresh', $w->name);
    }

    // ---------------------------------------------------------------
    // Native branch triggered by connection NAME — still runs against sqlite
    // so we can assert via query log that an UPSERT actually fired instead of
    // Eloquent's firstOrNew + save pair.
    // ---------------------------------------------------------------

    #[Test]
    public function native_branch_emits_upsert_when_connection_name_is_native(): void
    {
        $conn = $this->app['db']->connection();
        $conn->enableQueryLog();

        Widget::$forcedConnectionName = 'native';
        Widget::$forcedConnection = $conn; // real backend is sqlite; only the name is faked

        Widget::updateOrCreate(['email' => 'a@b.c'], ['name' => 'Bob']);

        $sqls = array_map(fn ($q) => strtolower($q['query']), $conn->getQueryLog());
        $conn->disableQueryLog();

        // Upsert shape is distinctive: "on conflict" (sqlite/pg) or "on duplicate key" (mysql).
        $hasUpsert = array_filter(
            $sqls,
            fn ($s) => str_contains($s, 'on conflict') || str_contains($s, 'duplicate key')
        );
        self::assertNotEmpty($hasUpsert, 'Native branch must emit an UPSERT statement');

        // And the row actually lands in the real table.
        self::assertTrue(Widget::where('email', 'a@b.c')->exists());
    }

    // ---------------------------------------------------------------
    // Native branch triggered by connection INSTANCE — full verification via spy.
    // ---------------------------------------------------------------

    #[Test]
    public function native_branch_on_update_or_create_emits_affecting_statement_plus_select(): void
    {
        $spy = new SpyNativeConnection();
        Widget::$forcedConnection = $spy;
        $spy->nextSelectResult = [['email' => 'a@b.c', 'name' => 'Bob']];

        $w = Widget::updateOrCreate(['email' => 'a@b.c'], ['name' => 'Bob']);

        self::assertCount(1, $spy->statements, 'Exactly one affectingStatement for the upsert');
        self::assertSame('affecting', $spy->statements[0]['type']);
        self::assertStringContainsString('insert', strtolower($spy->statements[0]['sql']));
        self::assertStringContainsString(
            'duplicate key',
            strtolower($spy->statements[0]['sql']),
            'MySqlGrammar upsert produces ON DUPLICATE KEY UPDATE',
        );

        self::assertCount(1, $spy->selects, 'Exactly one select for where->first()');
        self::assertStringContainsString('where', strtolower($spy->selects[0]['sql']));

        self::assertSame('Bob', $w->name);
    }

    #[Test]
    public function native_branch_on_update_or_create_returns_force_filled_instance_when_select_misses(): void
    {
        $spy = new SpyNativeConnection();
        Widget::$forcedConnection = $spy;
        $spy->nextSelectResult = []; // after upsert, where->first can't find the row

        $w = Widget::updateOrCreate(['email' => 'a@b.c'], ['name' => 'Alice']);

        self::assertInstanceOf(Widget::class, $w);
        self::assertSame('a@b.c', $w->email);
        self::assertSame('Alice', $w->name);
        self::assertFalse($w->exists, 'forceFill must not mark the model as persisted');
    }

    #[Test]
    public function native_branch_on_first_or_create_returns_existing_without_inserting(): void
    {
        $spy = new SpyNativeConnection();
        Widget::$forcedConnection = $spy;
        $spy->nextSelectResult = [['email' => 'a@b.c', 'name' => 'Existing']];

        $w = Widget::firstOrCreate(['email' => 'a@b.c'], ['name' => 'Ignored']);

        self::assertSame('Existing', $w->name);
        self::assertCount(1, $spy->selects);

        $inserts = array_filter($spy->statements, fn ($s) => $s['type'] === 'insert');
        self::assertCount(0, $inserts, 'firstOrCreate must not insert when the record already exists');
    }

    #[Test]
    public function native_branch_on_first_or_create_inserts_when_select_empty(): void
    {
        $spy = new SpyNativeConnection();
        Widget::$forcedConnection = $spy;
        $spy->nextSelectResult = []; // not found → takes the create path

        $w = Widget::firstOrCreate(['email' => 'a@b.c'], ['name' => 'Fresh']);

        self::assertSame('Fresh', $w->name);
        self::assertTrue($w->exists);
        self::assertCount(1, $spy->selects);

        $inserts = array_filter($spy->statements, fn ($s) => $s['type'] === 'insert');
        self::assertCount(1, $inserts);
    }

    #[Test]
    public function native_branch_merges_attributes_and_values_before_writing(): void
    {
        $spy = new SpyNativeConnection();
        Widget::$forcedConnection = $spy;
        $spy->nextSelectResult = [];

        Widget::firstOrCreate(
            ['email' => 'a@b.c'],
            ['name' => 'Alice', 'status' => 'active']
        );

        $insert = array_values(array_filter($spy->statements, fn ($s) => $s['type'] === 'insert'))[0];

        // All three columns must be present in the bindings — both attributes AND values.
        self::assertContains('a@b.c', $insert['bindings']);
        self::assertContains('Alice', $insert['bindings']);
        self::assertContains('active', $insert['bindings']);
    }
}
