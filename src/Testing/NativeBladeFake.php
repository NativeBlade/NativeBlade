<?php

namespace NativeBlade\Testing;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use NativeBlade\Bridge\SuspendGuard;
use NativeBlade\Http\WasmHttpHandler;
use NativeBlade\NativeResponse;
use NativeBlade\ShellConfig;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * The shell, faked for PHPUnit.
 *
 * Answers for a platform, stands in for the native database (an in-memory
 * SQLite) and the native disks (a temporary directory), records every native
 * call the code makes (HTTP, native queries, filesystem operations) and every
 * local query as information, and captures what goes to the shell: the
 * actions flushed with ->toResponse() and the NativeBlade::log() entries.
 *
 * @see \NativeBlade\Facades\NativeBlade::fake()
 */
class NativeBladeFake extends ShellConfig
{
    /** Connections whose queries are native calls on the device. */
    public const BRIDGE_DB_DRIVERS = ['nativeblade-db'];

    /** @var list<array<string, mixed>> everything recorded, in order */
    private array $log = [];

    /** @var list<array{action: string, data: array<string, mixed>}> */
    private array $pushed = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private string $fsRoot;

    public function __construct(
        private string $fakePlatform = 'android',
        private bool $fakeDev = false,
    ) {
        $this->fsRoot = rtrim(sys_get_temp_dir(), '/\\') . '/nativeblade-fake-' . Str::random(8);
    }

    /**
     * Build the fake from the app's real ShellConfig (keeping its shell config)
     * and hook it into the HTTP client, the database, the native disks and the
     * native response queue. NativeBlade::fake() calls this.
     */
    public static function install(Application $app, string $platform = 'android', bool $dev = false): static
    {
        $fake = new static($platform, $dev);

        $current = $app->bound('nativeblade') ? $app->make('nativeblade') : null;
        if ($current instanceof ShellConfig && !$current instanceof self) {
            $config = new ReflectionProperty(ShellConfig::class, 'config');
            $config->setValue($fake, $config->getValue($current));
        }

        $app->instance('nativeblade', $fake);

        // The guard runs here as it does inside the app: a native call from a
        // callback the runtime cannot pause in fails in PHPUnit the same way.
        $record = function (array $entry) use ($fake) {
            SuspendGuard::assertCanSuspend($entry['type']);
            $fake->record($entry);
        };

        Http::globalRequestMiddleware(function (RequestInterface $request) use ($fake) {
            SuspendGuard::assertCanSuspend('http');
            $body = (string) $request->getBody();
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }
            $fake->record([
                'type' => 'http',
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'body' => $body,
                'pool' => WasmHttpHandler::isPooling(),
            ]);

            return $request;
        });

        foreach (self::BRIDGE_DB_DRIVERS as $driver) {
            DB::extend($driver, fn (array $config, string $name) => new FakeNativeConnection($record, $config, $name));
        }
        foreach ((array) $app['config']->get('database.connections', []) as $name => $connection) {
            if (in_array($connection['driver'] ?? null, self::BRIDGE_DB_DRIVERS, true)) {
                DB::purge($name);
            }
        }

        // Local database: recorded as information, never a native call.
        $app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use ($fake, $app) {
            $driver = $app['config']->get("database.connections.{$event->connectionName}.driver");
            if (in_array($driver, self::BRIDGE_DB_DRIVERS, true)) {
                return;
            }
            $fake->record([
                'type' => 'db',
                'bridge' => false,
                'sql' => $event->sql,
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
            ]);
        });

        Storage::extend('nativeblade', function ($app, $config) use ($fake, $record) {
            // Laravel binds this closure to the FilesystemManager, so only
            // public members of the fake are reachable here.
            $adapter = new RecordingFilesystemAdapter($fake->fsRoot(), $record);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
        foreach ((array) $app['config']->get('filesystems.disks', []) as $name => $disk) {
            if (($disk['driver'] ?? null) === 'nativeblade') {
                Storage::forgetDisk($name);
            }
        }

        NativeResponse::recordTo(function (array $actions) use ($fake) {
            foreach ($actions as $action) {
                $fake->pushed[] = $action;
            }
        });

        return $fake;
    }

    // ------------------------------------------------------------------
    // Platform and environment
    // ------------------------------------------------------------------

    public function platform(): string
    {
        return $this->fakePlatform;
    }

    public function isDev(): bool
    {
        return $this->fakeDev;
    }

    /** Recorded instead of written to stderr. */
    public function log(string $message, array $context = [], string $level = 'info'): void
    {
        $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** Where the fake native disks write their files. */
    public function fsRoot(): string
    {
        return $this->fsRoot;
    }

    // ------------------------------------------------------------------
    // Recorded data
    // ------------------------------------------------------------------

    /** @internal */
    public function record(array $entry): void
    {
        $this->log[] = $entry;
    }

    /** Forget everything recorded so far; the stand-ins keep their state. */
    public function reset(): static
    {
        $this->log = [];
        $this->pushed = [];
        $this->logs = [];

        return $this;
    }

    /** @return list<array<string, mixed>> everything recorded, in order: native calls and local queries */
    public function sequence(): array
    {
        return $this->log;
    }

    /** @return list<array<string, mixed>> only the native calls: HTTP, native database, native filesystem */
    public function nativeCalls(): array
    {
        return array_values(array_filter($this->log, fn ($e) => $e['type'] !== 'db' || !empty($e['bridge'])));
    }

    /** @return list<array{type: string, method: string, url: string, body: string, pool: bool}> */
    public function httpCalls(): array
    {
        return array_values(array_filter($this->log, fn ($e) => $e['type'] === 'http'));
    }

    /** @return list<array<string, mixed>> every query, local and native; `bridge` tells which */
    public function queries(): array
    {
        return array_values(array_filter($this->log, fn ($e) => $e['type'] === 'db'));
    }

    /** @return list<array<string, mixed>> queries on native (`nativeblade-db`) connections only */
    public function nativeQueries(): array
    {
        return array_values(array_filter($this->log, fn ($e) => $e['type'] === 'db' && !empty($e['bridge'])));
    }

    /** @return list<array{type: string, op: string, path: string, baseDir: string}> */
    public function fsOps(): array
    {
        return array_values(array_filter($this->log, fn ($e) => $e['type'] === 'fs'));
    }

    /** @return list<array{action: string, data: array<string, mixed>}> actions flushed with toResponse() */
    public function pushed(): array
    {
        return $this->pushed;
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function logs(): array
    {
        return $this->logs;
    }

    public static function describe(array $entry): string
    {
        return match ($entry['type']) {
            'http' => $entry['method'] . ' ' . $entry['url'],
            'db' => $entry['sql'] . ' ' . json_encode($entry['bindings']),
            'fs' => $entry['op'] . ' ' . $entry['baseDir'] . ':' . $entry['path'],
            default => json_encode($entry),
        };
    }

    // ------------------------------------------------------------------
    // Assertions
    // ------------------------------------------------------------------

    public function assertHttpCalls(int $count): static
    {
        Assert::assertCount($count, $this->httpCalls(), $this->listing('HTTP calls', $this->httpCalls()));

        return $this;
    }

    public function assertHttpCallsAtMost(int $count): static
    {
        Assert::assertLessThanOrEqual($count, count($this->httpCalls()), $this->listing('HTTP calls', $this->httpCalls()));

        return $this;
    }

    /** $url is exact or a Str::is() pattern, such as "https://api.test/items*". */
    public function assertHttpCalled(string $method, string $url): static
    {
        $method = strtoupper($method);
        foreach ($this->httpCalls() as $call) {
            if ($call['method'] === $method && Str::is($url, $call['url'])) {
                return $this;
            }
        }

        Assert::fail("No HTTP call matched {$method} {$url}.\n" . $this->listing('HTTP calls', $this->httpCalls()));
    }

    /** Every query, local SQLite included. */
    public function assertQueries(int $count): static
    {
        Assert::assertCount($count, $this->queries(), $this->listing('queries', $this->queries()));

        return $this;
    }

    public function assertQueriesAtMost(int $count): static
    {
        Assert::assertLessThanOrEqual($count, count($this->queries()), $this->listing('queries', $this->queries()));

        return $this;
    }

    /** Queries on native connections only. */
    public function assertNativeQueries(int $count): static
    {
        Assert::assertCount($count, $this->nativeQueries(), $this->listing('native queries', $this->nativeQueries()));

        return $this;
    }

    public function assertNativeQueriesAtMost(int $count): static
    {
        Assert::assertLessThanOrEqual($count, count($this->nativeQueries()), $this->listing('native queries', $this->nativeQueries()));

        return $this;
    }

    public function assertFsOps(int $count): static
    {
        Assert::assertCount($count, $this->fsOps(), $this->listing('filesystem operations', $this->fsOps()));

        return $this;
    }

    public function assertFsOpsAtMost(int $count): static
    {
        Assert::assertLessThanOrEqual($count, count($this->fsOps()), $this->listing('filesystem operations', $this->fsOps()));

        return $this;
    }

    /**
     * @param  (Closure(array<string, mixed> $data): bool)|null  $matcher  receives the action's data
     */
    public function assertActionPushed(string $action, ?Closure $matcher = null): static
    {
        foreach ($this->pushed as $entry) {
            if ($entry['action'] === $action && ($matcher === null || $matcher($entry['data']))) {
                return $this;
            }
        }

        $suffix = $matcher === null ? '' : ' matching the given callback';
        Assert::fail("No '{$action}' action{$suffix} was pushed to the shell.\n" . $this->pushedListing());
    }

    public function assertActionNotPushed(string $action): static
    {
        foreach ($this->pushed as $entry) {
            if ($entry['action'] === $action) {
                Assert::fail("A '{$action}' action was pushed to the shell.\n" . $this->pushedListing());
            }
        }

        return $this;
    }

    public function assertNothingPushed(): static
    {
        Assert::assertSame([], $this->pushed, "Actions were pushed to the shell.\n" . $this->pushedListing());

        return $this;
    }

    public function assertNotificationScheduled(?string $id = null): static
    {
        return $this->assertActionPushed(
            'notification',
            fn (array $data) => $id === null || ($data['id'] ?? null) === $id,
        );
    }

    public function assertLogged(string $message, ?string $level = null): static
    {
        foreach ($this->logs as $log) {
            if ($log['message'] === $message && ($level === null || $log['level'] === $level)) {
                return $this;
            }
        }

        Assert::fail("No log entry '{$message}'" . ($level ? " at level {$level}" : '') . " was written.\n" . $this->logListing());
    }

    public function assertNotLogged(string $message): static
    {
        foreach ($this->logs as $log) {
            if ($log['message'] === $message) {
                Assert::fail("A log entry '{$message}' was written.\n" . $this->logListing());
            }
        }

        return $this;
    }

    private function listing(string $label, array $entries): string
    {
        if ($entries === []) {
            return "No {$label} were recorded.";
        }
        $lines = array_map(fn ($i, $e) => sprintf('  #%d %s', $i + 1, self::describe($e)), array_keys($entries), $entries);

        return "Recorded {$label}:\n" . implode("\n", $lines);
    }

    private function pushedListing(): string
    {
        if ($this->pushed === []) {
            return 'Nothing was pushed.';
        }
        $lines = array_map(fn ($e) => '  ' . $e['action'] . ' ' . json_encode($e['data']), $this->pushed);

        return "Pushed actions:\n" . implode("\n", $lines);
    }

    private function logListing(): string
    {
        if ($this->logs === []) {
            return 'Nothing was logged.';
        }
        $lines = array_map(fn ($l) => sprintf('  [%s] %s %s', $l['level'], $l['message'], json_encode($l['context'])), $this->logs);

        return "Log entries:\n" . implode("\n", $lines);
    }
}
