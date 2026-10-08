<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Database;

use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Illuminate\Database\Query\Processors\PostgresProcessor;
use Illuminate\Database\Query\Processors\SQLiteProcessor;
use Illuminate\Database\Schema\Grammars\MySqlGrammar as MySqlSchemaGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar as PostgresSchemaGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar as SQLiteSchemaGrammar;
use Illuminate\Database\QueryException;
use NativeBlade\Bridge\NativeBridge;
use NativeBlade\Database\NativeConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * NativeConnection sends every query through NativeBridge and PHP waits for
 * the shell's reply. The tests answer the bridge with a queue of replies and
 * record each message, covering:
 *   - Constructor field parsing
 *   - getDefault{Query,Post,Schema} grammar/processor selection per driver
 *   - buildConnectionString formats
 *   - prepareBindings (public) with DateTime, bool, scalar passthrough
 *   - the message and result mapping for select/insert/update/delete/statement
 *   - Transaction level bookkeeping (BEGIN/COMMIT/ROLLBACK only at the outer level)
 *   - a shell error surfacing as a QueryException
 */
final class NativeConnectionTest extends TestCase
{
    /** @var list<mixed> replies handed out in order */
    private array $replies = [];

    /** @var list<array<string, mixed>> every message sent to the shell */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->replies = [];
        $this->calls = [];
        NativeBridge::handleWith(function (array $message) {
            $this->calls[] = $message;
            if ($this->replies === []) {
                return ['ok' => false, 'error' => 'no reply queued for ' . json_encode($message)];
            }

            return ['ok' => true, 'result' => array_shift($this->replies)];
        });
    }

    protected function tearDown(): void
    {
        NativeBridge::handleWith(null);
    }

    private function currentQueryIndex(): int
    {
        return count($this->calls);
    }

    /**
     * Kept for readability of the call sites: names the query a reply is
     * meant for. Replies are handed out in order.
     *
     * @param  array<int, mixed>  $bindings  Already prepared (DateTime as string, bool as int)
     */
    private function keyFor(string $type, string $sql, array $bindings, int $index): string
    {
        return $type . '|' . $sql . '|' . json_encode($bindings) . '|' . $index;
    }

    private function seedCache(string $key, mixed $result): void
    {
        $this->replies[] = $result;
    }

    private function makeConnection(string $driver = 'mysql', array $extra = []): NativeConnection
    {
        return new NativeConnection(array_merge([
            'native_driver' => $driver,
            'database' => 'app_db',
            'prefix' => 'pfx_',
        ], $extra));
    }

    private function invokePrivate(NativeConnection $conn, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass(NativeConnection::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($conn, ...$args);
    }

    private function invokeProtected(NativeConnection $conn, string $method): mixed
    {
        return $this->invokePrivate($conn, $method);
    }

    // ---------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------

    #[Test]
    public function constructor_sets_database_and_prefix_from_config(): void
    {
        $conn = $this->makeConnection('mysql');

        self::assertSame('app_db', $conn->getDatabaseName());
        self::assertSame('pfx_', $conn->getTablePrefix());
    }

    #[Test]
    public function constructor_defaults_driver_to_mysql(): void
    {
        $conn = new NativeConnection(['database' => 'x']);
        self::assertSame('mysql', $conn->getDriverName());
    }

    #[Test]
    public function get_driver_name_returns_native_driver(): void
    {
        self::assertSame('pgsql', $this->makeConnection('pgsql')->getDriverName());
        self::assertSame('sqlite', $this->makeConnection('sqlite')->getDriverName());
        self::assertSame('mariadb', $this->makeConnection('mariadb')->getDriverName());
    }

    #[Test]
    public function get_pdo_and_get_read_pdo_return_null(): void
    {
        $conn = $this->makeConnection();
        self::assertNull($conn->getPdo());
        self::assertNull($conn->getReadPdo());
    }

    // ---------------------------------------------------------------
    // Default grammar / processor / schema grammar selection
    // ---------------------------------------------------------------

    public static function driverGrammarProvider(): array
    {
        return [
            'pgsql'    => ['pgsql',    PostgresGrammar::class, PostgresProcessor::class, PostgresSchemaGrammar::class],
            'postgres' => ['postgres', PostgresGrammar::class, PostgresProcessor::class, PostgresSchemaGrammar::class],
            'sqlite'   => ['sqlite',   SQLiteGrammar::class,   SQLiteProcessor::class,   SQLiteSchemaGrammar::class],
            'mysql'    => ['mysql',    MySqlGrammar::class,    MySqlProcessor::class,    MySqlSchemaGrammar::class],
            'mariadb'  => ['mariadb',  MySqlGrammar::class,    MySqlProcessor::class,    MySqlSchemaGrammar::class],
            'default'  => ['oracle',   MySqlGrammar::class,    MySqlProcessor::class,    MySqlSchemaGrammar::class],
        ];
    }

    #[Test]
    #[DataProvider('driverGrammarProvider')]
    public function default_grammar_processor_and_schema_grammar_match_driver(
        string $driver,
        string $queryGrammarClass,
        string $processorClass,
        string $schemaGrammarClass,
    ): void {
        $conn = $this->makeConnection($driver);

        self::assertInstanceOf($queryGrammarClass, $this->invokeProtected($conn, 'getDefaultQueryGrammar'));
        self::assertInstanceOf($processorClass, $this->invokeProtected($conn, 'getDefaultPostProcessor'));
        self::assertInstanceOf($schemaGrammarClass, $this->invokeProtected($conn, 'getDefaultSchemaGrammar'));
    }

    // ---------------------------------------------------------------
    // buildConnectionString
    // ---------------------------------------------------------------

    #[Test]
    public function build_connection_string_formats_postgres_url(): void
    {
        $conn = $this->makeConnection('pgsql', [
            'username' => 'alice',
            'password' => 'secret',
            'host' => 'db.local',
            'port' => '6543',
            'database' => 'app',
        ]);

        $result = $this->invokePrivate($conn, 'buildConnectionString', [[
            'native_driver' => 'pgsql',
            'username' => 'alice',
            'password' => 'secret',
            'host' => 'db.local',
            'port' => '6543',
            'database' => 'app',
        ]]);

        self::assertSame('postgres://alice:secret@db.local:6543/app', $result);
    }

    #[Test]
    public function build_connection_string_formats_mysql_url(): void
    {
        $conn = $this->makeConnection('mysql');

        $result = $this->invokePrivate($conn, 'buildConnectionString', [[
            'native_driver' => 'mysql',
            'database' => 'nbdb',
        ]]);

        // defaults: root, empty password, 127.0.0.1:3306
        self::assertSame('mysql://root:@127.0.0.1:3306/nbdb', $result);
    }

    #[Test]
    public function build_connection_string_for_sqlite_is_just_the_path(): void
    {
        $conn = $this->makeConnection('sqlite');

        $result = $this->invokePrivate($conn, 'buildConnectionString', [[
            'native_driver' => 'sqlite',
            'database' => '/app/data.sqlite',
        ]]);

        self::assertSame('/app/data.sqlite', $result);
    }

    #[Test]
    public function build_connection_string_for_sqlite_falls_back_to_memory(): void
    {
        $conn = $this->makeConnection('sqlite');

        $result = $this->invokePrivate($conn, 'buildConnectionString', [[
            'native_driver' => 'sqlite',
        ]]);

        self::assertSame(':memory:', $result);
    }

    #[Test]
    public function build_connection_string_treats_mariadb_as_mysql_url_shape(): void
    {
        $conn = $this->makeConnection('mariadb');

        $result = $this->invokePrivate($conn, 'buildConnectionString', [[
            'native_driver' => 'mariadb',
            'username' => 'bob',
            'password' => 'pw',
            'host' => 'maria.host',
            'port' => '3307',
            'database' => 'app',
        ]]);

        self::assertSame('mysql://bob:pw@maria.host:3307/app', $result);
    }

    // ---------------------------------------------------------------
    // prepareBindings
    // ---------------------------------------------------------------

    #[Test]
    public function prepare_bindings_formats_datetimes_to_y_m_d_h_i_s(): void
    {
        $conn = $this->makeConnection();
        $dt = new \DateTimeImmutable('2026-04-17 12:34:56');

        self::assertSame(['2026-04-17 12:34:56'], $conn->prepareBindings([$dt]));
    }

    #[Test]
    public function prepare_bindings_casts_booleans_to_integers(): void
    {
        $conn = $this->makeConnection();
        self::assertSame([1, 0, 1], $conn->prepareBindings([true, false, true]));
    }

    #[Test]
    public function prepare_bindings_passes_through_strings_ints_nulls(): void
    {
        $conn = $this->makeConnection();
        self::assertSame(
            ['hello', 42, null, 3.14],
            $conn->prepareBindings(['hello', 42, null, 3.14])
        );
    }

    #[Test]
    public function prepare_bindings_mixes_datetimes_bools_and_scalars(): void
    {
        $conn = $this->makeConnection();
        $dt = new \DateTimeImmutable('2026-01-01 00:00:00');

        self::assertSame(
            ['a', 1, '2026-01-01 00:00:00', 7, 0],
            $conn->prepareBindings(['a', true, $dt, 7, false])
        );
    }

    // ---------------------------------------------------------------
    // Cache-hit paths for the CRUD bridge operations
    // ---------------------------------------------------------------

    #[Test]
    public function select_cache_hit_returns_rows_cast_to_objects(): void
    {
        $conn = $this->makeConnection('mysql');
        $sql = 'select * from users where id = ?';
        $bindings = [42];

        $key = $this->keyFor('select', $sql, $bindings, 0);
        $this->seedCache($key, [
            ['id' => 42, 'name' => 'Ada'],
            ['id' => 99, 'name' => 'Grace'],
        ]);

        $rows = $conn->select($sql, $bindings);

        self::assertCount(2, $rows);
        self::assertIsObject($rows[0]);
        self::assertSame(42, $rows[0]->id);
        self::assertSame('Ada', $rows[0]->name);
        self::assertSame('Grace', $rows[1]->name);
    }

    #[Test]
    public function select_cache_hit_with_non_array_result_returns_empty(): void
    {
        $conn = $this->makeConnection();
        $sql = 'select 1';

        $key = $this->keyFor('select', $sql, [], 0);
        $this->seedCache($key, null);

        self::assertSame([], $conn->select($sql));
    }

    #[Test]
    public function insert_cache_hit_stores_last_insert_id(): void
    {
        $conn = $this->makeConnection();
        $sql = 'insert into users (name) values (?)';
        $bindings = ['Ada'];

        $key = $this->keyFor('insert', $sql, $bindings, 0);
        $this->seedCache($key, ['lastInsertId' => 1337]);

        self::assertTrue($conn->insert($sql, $bindings));
        self::assertSame(1337, $conn->getLastInsertId());
    }

    #[Test]
    public function update_cache_hit_returns_affected_count(): void
    {
        $conn = $this->makeConnection();
        $sql = 'update users set name = ? where id = ?';
        $bindings = ['Ada', 1];

        $key = $this->keyFor('update', $sql, $bindings, 0);
        $this->seedCache($key, ['affected' => 5]);

        self::assertSame(5, $conn->update($sql, $bindings));
    }

    #[Test]
    public function delete_cache_hit_returns_affected_count(): void
    {
        $conn = $this->makeConnection();
        $sql = 'delete from users where id = ?';

        $key = $this->keyFor('delete', $sql, [7], 0);
        $this->seedCache($key, ['affected' => 1]);

        self::assertSame(1, $conn->delete($sql, [7]));
    }

    #[Test]
    public function statement_cache_hit_returns_bool_success(): void
    {
        $conn = $this->makeConnection();
        $key = $this->keyFor('statement', 'CREATE TABLE t (id int)', [], 0);
        $this->seedCache($key, true);

        self::assertTrue($conn->statement('CREATE TABLE t (id int)'));
    }

    #[Test]
    public function affecting_statement_cache_hit_returns_affected_int(): void
    {
        $conn = $this->makeConnection();
        $sql = 'TRUNCATE users';
        $key = $this->keyFor('statement', $sql, [], 0);
        $this->seedCache($key, ['affected' => 42]);

        self::assertSame(42, $conn->affectingStatement($sql));
    }

    #[Test]
    public function unprepared_delegates_to_statement_with_empty_bindings(): void
    {
        $conn = $this->makeConnection();
        $sql = 'ALTER TABLE users ADD COLUMN x int';
        $key = $this->keyFor('statement', $sql, [], 0);
        $this->seedCache($key, true);

        self::assertTrue($conn->unprepared($sql));
    }

    #[Test]
    public function the_message_carries_the_prepared_bindings_and_the_connection_details(): void
    {
        $conn = $this->makeConnection('pgsql', ['host' => 'db.local', 'port' => '5432', 'username' => 'u', 'password' => 'p']);
        $sql = 'update users set active = ?, seen_at = ? where id = ?';
        $dt = new \DateTimeImmutable('2026-04-17 09:00:00');
        $this->seedCache('update', ['affected' => 1]);

        self::assertSame(1, $conn->update($sql, [true, $dt, 5]));

        self::assertSame([
            'nativeblade' => 'db',
            'type' => 'update',
            'sql' => $sql,
            'bindings' => [1, '2026-04-17 09:00:00', 5],
            'driver' => 'pgsql',
            'connection' => 'postgres://u:p@db.local:5432/app_db',
        ], $this->calls[0]);
    }

    #[Test]
    public function a_shell_error_surfaces_as_a_query_exception(): void
    {
        $conn = $this->makeConnection();
        NativeBridge::handleWith(fn () => ['ok' => false, 'error' => 'no such table: users']);

        try {
            $conn->select('select * from users');
            self::fail('expected a QueryException');
        } catch (QueryException $e) {
            self::assertStringContainsString('no such table: users', $e->getMessage());
            self::assertStringContainsString('select * from users', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // Transaction bookkeeping
    // ---------------------------------------------------------------

    #[Test]
    public function begin_transaction_bridges_on_level_one_only(): void
    {
        $conn = $this->makeConnection();

        // BEGIN fires only for the first beginTransaction → need 1 cache hit
        $this->seedCache($this->keyFor('statement', 'BEGIN', [], 0), true);

        $conn->beginTransaction();
        self::assertSame(1, $conn->transactionLevel());

        // Nested begin: must NOT bridge (no reply queued → it would fail if it did)
        $conn->beginTransaction();
        self::assertSame(2, $conn->transactionLevel());

        // Only one message left PHP because only the outer begin is a call
        self::assertSame(1, $this->currentQueryIndex());
        self::assertSame('BEGIN', $this->calls[0]['sql']);
    }

    #[Test]
    public function commit_bridges_only_on_final_commit(): void
    {
        $conn = $this->makeConnection();

        $this->seedCache($this->keyFor('statement', 'BEGIN', [], 0), true);
        $this->seedCache($this->keyFor('statement', 'COMMIT', [], 1), true);

        $conn->beginTransaction();
        $conn->beginTransaction();

        // Inner commit should NOT bridge
        $conn->commit();
        self::assertSame(1, $conn->transactionLevel());

        // Outer commit SHOULD bridge
        $conn->commit();
        self::assertSame(0, $conn->transactionLevel());
    }

    #[Test]
    public function rollback_bridges_only_on_outer_level(): void
    {
        $conn = $this->makeConnection();

        $this->seedCache($this->keyFor('statement', 'BEGIN', [], 0), true);
        $this->seedCache($this->keyFor('statement', 'ROLLBACK', [], 1), true);

        $conn->beginTransaction();
        $conn->beginTransaction();
        $conn->rollBack();
        self::assertSame(1, $conn->transactionLevel());
        $conn->rollBack();
        self::assertSame(0, $conn->transactionLevel());
    }

    #[Test]
    public function transaction_helper_sends_begin_and_commit_and_rolls_back_on_failure(): void
    {
        $conn = $this->makeConnection();
        $this->replies = [true, ['lastInsertId' => 1], true, true, true];

        $result = $conn->transaction(function () use ($conn) {
            $conn->insert('insert into t (a) values (?)', [1]);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['BEGIN', 'insert into t (a) values (?)', 'COMMIT'], array_column($this->calls, 'sql'));
        self::assertSame(0, $conn->transactionLevel());

        $this->calls = [];
        try {
            $conn->transaction(fn () => throw new \RuntimeException('boom'));
            self::fail('expected the exception to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(['BEGIN', 'ROLLBACK'], array_column($this->calls, 'sql'));
        self::assertSame(0, $conn->transactionLevel());
    }

    #[Test]
    public function transaction_level_never_goes_negative(): void
    {
        $conn = $this->makeConnection();

        // No BEGIN has fired, but rollBack should still clamp at zero
        $conn->rollBack();
        $conn->rollBack();

        self::assertSame(0, $conn->transactionLevel());
    }
}
