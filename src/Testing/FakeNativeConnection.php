<?php

namespace NativeBlade\Testing;

use Closure;
use Illuminate\Database\SQLiteConnection;
use PDO;

/**
 * Stand-in for NativeConnection in tests. On the device every query on a
 * `nativeblade-db` connection is a native call that exits PHP; here each one
 * goes through the fake's bridge (recorded, replayed from the cache, or the
 * stop point of the current run) and runs on an in-memory SQLite so the test
 * can create tables and read rows back.
 */
final class FakeNativeConnection extends SQLiteConnection
{
    private int $level = 0;

    /**
     * @param  Closure(array<string, mixed>, Closure): mixed  $bridge
     */
    public function __construct(private Closure $bridge, array $config, string $name)
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        parent::__construct($pdo, $config['database'] ?? '', $config['prefix'] ?? '', ['name' => $name] + $config);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return $this->viaBridge('select', $query, $bindings, fn () => parent::select($query, $bindings, $useReadPdo, $fetchUsing));
    }

    public function insert($query, $bindings = [])
    {
        return $this->viaBridge('insert', $query, $bindings, fn () => parent::insert($query, $bindings));
    }

    public function update($query, $bindings = [])
    {
        return $this->viaBridge('update', $query, $bindings, fn () => parent::update($query, $bindings));
    }

    public function delete($query, $bindings = [])
    {
        return $this->viaBridge('delete', $query, $bindings, fn () => parent::delete($query, $bindings));
    }

    public function statement($query, $bindings = [])
    {
        return $this->viaBridge('statement', $query, $bindings, fn () => parent::statement($query, $bindings));
    }

    public function affectingStatement($query, $bindings = [])
    {
        return $this->viaBridge('statement', $query, $bindings, fn () => parent::affectingStatement($query, $bindings));
    }

    public function unprepared($query)
    {
        return $this->viaBridge('statement', $query, [], fn () => parent::unprepared($query));
    }

    // Transactions mirror NativeConnection: only the outermost level is a call.

    public function beginTransaction()
    {
        $this->level++;
        if ($this->level === 1) {
            $this->viaBridge('statement', 'BEGIN', [], fn () => parent::beginTransaction());
        }
    }

    public function commit()
    {
        if ($this->level === 1) {
            $this->viaBridge('statement', 'COMMIT', [], fn () => parent::commit());
        }
        $this->level = max(0, $this->level - 1);
    }

    public function rollBack($toLevel = null)
    {
        if ($this->level === 1) {
            $this->viaBridge('statement', 'ROLLBACK', [], fn () => parent::rollBack());
        }
        $this->level = max(0, $this->level - 1);
    }

    public function transactionLevel()
    {
        return $this->level;
    }

    private bool $executing = false;

    private function viaBridge(string $type, string $sql, array $bindings, Closure $execute): mixed
    {
        // The parent implementations call each other (insert() runs through
        // statement()); only the outermost call is the native one.
        if ($this->executing) {
            return $execute();
        }

        $run = function () use ($execute) {
            $this->executing = true;
            try {
                return $execute();
            } finally {
                $this->executing = false;
            }
        };

        return ($this->bridge)([
            'type' => 'db',
            'bridge' => true,
            'kind' => $type,
            'sql' => $sql,
            'bindings' => $this->prepareBindings($bindings),
            'connection' => $this->getName(),
        ], $run);
    }
}
