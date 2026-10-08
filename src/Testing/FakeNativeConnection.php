<?php

namespace NativeBlade\Testing;

use Closure;
use Illuminate\Database\SQLiteConnection;
use PDO;

/**
 * Stand-in for NativeConnection in tests: an in-memory SQLite so the test can
 * create tables and read rows back, with every query recorded as the native
 * call it would be on the device.
 */
final class FakeNativeConnection extends SQLiteConnection
{
    private int $level = 0;

    private bool $executing = false;

    /**
     * @param  Closure(array<string, mixed>): void  $record
     */
    public function __construct(private Closure $record, array $config, string $name)
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        parent::__construct($pdo, $config['database'] ?? '', $config['prefix'] ?? '', ['name' => $name] + $config);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return $this->recorded('select', $query, $bindings, fn () => parent::select($query, $bindings, $useReadPdo, $fetchUsing));
    }

    public function insert($query, $bindings = [])
    {
        return $this->recorded('insert', $query, $bindings, fn () => parent::insert($query, $bindings));
    }

    public function update($query, $bindings = [])
    {
        return $this->recorded('update', $query, $bindings, fn () => parent::update($query, $bindings));
    }

    public function delete($query, $bindings = [])
    {
        return $this->recorded('delete', $query, $bindings, fn () => parent::delete($query, $bindings));
    }

    public function statement($query, $bindings = [])
    {
        return $this->recorded('statement', $query, $bindings, fn () => parent::statement($query, $bindings));
    }

    public function affectingStatement($query, $bindings = [])
    {
        return $this->recorded('statement', $query, $bindings, fn () => parent::affectingStatement($query, $bindings));
    }

    public function unprepared($query)
    {
        return $this->recorded('statement', $query, [], fn () => parent::unprepared($query));
    }

    // Transactions mirror NativeConnection: only the outermost level is a call.

    public function beginTransaction()
    {
        $this->level++;
        if ($this->level === 1) {
            $this->recorded('statement', 'BEGIN', [], fn () => parent::beginTransaction());
        }
    }

    public function commit()
    {
        if ($this->level === 1) {
            $this->recorded('statement', 'COMMIT', [], fn () => parent::commit());
        }
        $this->level = max(0, $this->level - 1);
    }

    public function rollBack($toLevel = null)
    {
        if ($this->level === 1) {
            $this->recorded('statement', 'ROLLBACK', [], fn () => parent::rollBack());
        }
        $this->level = max(0, $this->level - 1);
    }

    /** Same shape as NativeConnection::transaction(): begin, run, commit or roll back. */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
            } catch (\Throwable $e) {
                $this->rollBack();
                if ($currentAttempt < $attempts && $this->causedByConcurrencyError($e)) {
                    continue;
                }
                throw $e;
            }

            $this->commit();

            return $result;
        }

        return null;
    }

    public function transactionLevel()
    {
        return $this->level;
    }

    private function recorded(string $type, string $sql, array $bindings, Closure $execute): mixed
    {
        // The parent implementations call each other (insert() runs through
        // statement()); only the outermost call is the native one.
        if ($this->executing) {
            return $execute();
        }

        ($this->record)([
            'type' => 'db',
            'bridge' => true,
            'kind' => $type,
            'sql' => $sql,
            'bindings' => $this->prepareBindings($bindings),
            'connection' => $this->getName(),
        ]);

        $this->executing = true;
        try {
            return $execute();
        } finally {
            $this->executing = false;
        }
    }
}
