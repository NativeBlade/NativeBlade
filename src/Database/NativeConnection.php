<?php

namespace NativeBlade\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Illuminate\Database\Query\Processors\PostgresProcessor;
use Illuminate\Database\Query\Processors\SQLiteProcessor;
use Illuminate\Database\Schema\Grammars\MySqlGrammar as MySqlSchemaGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar as PostgresSchemaGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar as SQLiteSchemaGrammar;
use NativeBlade\Bridge\NativeBridge;
use NativeBlade\Bridge\NativeBridgeException;

class NativeConnection extends Connection
{
    private string $nativeDriver;
    private string $connectionString;
    private int $transactionLevel = 0;

    public function __construct(array $config)
    {
        $this->nativeDriver = $config['native_driver'] ?? 'mysql';
        $this->connectionString = $this->buildConnectionString($config);
        $this->config = $config;
        $this->database = $config['database'] ?? '';
        $this->tablePrefix = $config['prefix'] ?? '';

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
        $this->useDefaultSchemaGrammar();
    }

    protected function getDefaultQueryGrammar()
    {
        return match ($this->nativeDriver) {
            'pgsql', 'postgres' => new PostgresGrammar($this),
            'sqlite' => new SQLiteGrammar($this),
            'mysql', 'mariadb' => new MySqlGrammar($this),
            default => new MySqlGrammar($this),
        };
    }

    protected function getDefaultPostProcessor()
    {
        return match ($this->nativeDriver) {
            'pgsql', 'postgres' => new PostgresProcessor,
            'sqlite' => new SQLiteProcessor,
            'mysql', 'mariadb' => new MySqlProcessor,
            default => new MySqlProcessor,
        };
    }

    protected function getDefaultSchemaGrammar()
    {
        return match ($this->nativeDriver) {
            'pgsql', 'postgres' => new PostgresSchemaGrammar($this),
            'sqlite' => new SQLiteSchemaGrammar($this),
            'mysql', 'mariadb' => new MySqlSchemaGrammar($this),
            default => new MySqlSchemaGrammar($this),
        };
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $result = $this->bridge('select', $query, $bindings);
        if (!is_array($result)) return [];

        return array_map(fn($row) => (object) $row, $result);
    }

    private int $lastInsertId = 0;

    public function insert($query, $bindings = [])
    {
        $result = $this->bridge('insert', $query, $bindings);
        $this->lastInsertId = (int) ($result['lastInsertId'] ?? 0);
        return (bool) $result;
    }

    public function getLastInsertId(): int
    {
        return $this->lastInsertId;
    }

    public function update($query, $bindings = [])
    {
        $result = $this->bridge('update', $query, $bindings);
        return (int) ($result['affected'] ?? 0);
    }

    public function delete($query, $bindings = [])
    {
        $result = $this->bridge('delete', $query, $bindings);
        return (int) ($result['affected'] ?? 0);
    }

    public function statement($query, $bindings = [])
    {
        $result = $this->bridge('statement', $query, $bindings);
        return (bool) $result;
    }

    public function affectingStatement($query, $bindings = [])
    {
        $result = $this->bridge('statement', $query, $bindings);
        return (int) ($result['affected'] ?? 0);
    }

    public function unprepared($query)
    {
        return $this->statement($query);
    }

    public function beginTransaction()
    {
        $this->transactionLevel++;
        if ($this->transactionLevel === 1) {
            $this->bridge('statement', 'BEGIN', []);
        }
        $this->fireConnectionEvent('beganTransaction');
    }

    public function commit()
    {
        if ($this->transactionLevel === 1) {
            $this->bridge('statement', 'COMMIT', []);
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null)
    {
        if ($this->transactionLevel === 1) {
            $this->bridge('statement', 'ROLLBACK', []);
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
        $this->fireConnectionEvent('rollingBack');
    }

    /**
     * Laravel's transaction() commits by poking the PDO and its own counter
     * directly, which never reaches the shell. Route it through our own
     * begin/commit/rollBack so BEGIN and COMMIT (or ROLLBACK) are sent.
     */
    public function transaction(\Closure $callback, $attempts = 1)
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
        return $this->transactionLevel;
    }

    public function getDriverName()
    {
        return $this->nativeDriver;
    }

    public function getPdo()
    {
        return null;
    }

    public function getReadPdo()
    {
        return null;
    }

    /**
     * Run the query in the shell (Rust, through sqlx) and return its result.
     * PHP waits for the reply; a failure becomes a QueryException as it would
     * with a PDO driver.
     */
    private function bridge(string $type, string $sql, array $bindings): mixed
    {
        $prepared = $this->prepareBindings($bindings);

        try {
            return NativeBridge::call('db', [
                'type' => $type,
                'sql' => $sql,
                'bindings' => $prepared,
                'driver' => $this->nativeDriver,
                'connection' => $this->connectionString,
            ]);
        } catch (NativeBridgeException $e) {
            throw new QueryException($this->getName() ?? $this->nativeDriver, $sql, $prepared, $e);
        }
    }

    public function prepareBindings(array $bindings)
    {
        $prepared = [];
        foreach ($bindings as $value) {
            if ($value instanceof \DateTimeInterface) {
                $prepared[] = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $prepared[] = (int) $value;
            } else {
                $prepared[] = $value;
            }
        }
        return $prepared;
    }

    private function buildConnectionString(array $config): string
    {
        $driver = $config['native_driver'] ?? 'mysql';

        return match ($driver) {
            'pgsql', 'postgres' => sprintf(
                'postgres://%s:%s@%s:%s/%s',
                $config['username'] ?? 'postgres',
                $config['password'] ?? '',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '5432',
                $config['database'] ?? '',
            ),
            'sqlite' => $config['database'] ?? ':memory:',
            'mysql', 'mariadb' => sprintf(
                'mysql://%s:%s@%s:%s/%s',
                $config['username'] ?? 'root',
                $config['password'] ?? '',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '3306',
                $config['database'] ?? '',
            ),
            // Mirror the default branches in getDefaultQueryGrammar /
            // getDefaultPostProcessor / getDefaultSchemaGrammar — unknown
            // drivers fall back to mysql so the constructor never blows up.
            default => sprintf(
                'mysql://%s:%s@%s:%s/%s',
                $config['username'] ?? 'root',
                $config['password'] ?? '',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '3306',
                $config['database'] ?? '',
            ),
        };
    }
}
