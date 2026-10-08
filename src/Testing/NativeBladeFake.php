<?php

namespace NativeBlade\Testing;

use Closure;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use Livewire\Features\SupportTesting\Testable;
use NativeBlade\Http\RequestKey;
use NativeBlade\Http\WasmHttpHandler;
use NativeBlade\NativeResponse;
use NativeBlade\ShellConfig;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * The runtime's execution model, simulated inside PHPUnit.
 *
 * On the device a request runs until its first native call that is not in
 * the cache (HTTP, a query on a `nativeblade-db` connection, an operation on
 * a native disk), exits there, and is re-run from the same snapshot once the
 * shell has the result; the earlier calls are served from the cache. What the
 * request wrote before exiting (local SQLite, state, files) stays written. A
 * request is abandoned when it makes more sequential calls than the runtime
 * allows.
 *
 * Code that passes Livewire::test() still breaks there when a call is not
 * deterministic, when a write before a call changes the next run, or when an
 * action makes too many calls. NativeBlade::fake() runs an action exactly
 * that way: N+1 runs, nothing undone, the sequence of calls compared between
 * runs and the same budgets enforced. It also captures the actions pushed to
 * the shell and the log entries.
 *
 * @see \NativeBlade\Facades\NativeBlade::fake()
 */
class NativeBladeFake extends ShellConfig
{
    /** Mirror of MAX_RETRIES in js/runtime/http-bridge.js. */
    public const HTTP_BUDGET = 10;

    /** Mirror of MAX_RETRIES in js/runtime/db-bridge.js. */
    public const DB_BUDGET = 20;

    /** Mirror of MAX_RETRIES in js/runtime/fs-bridge.js. */
    public const FS_BUDGET = 20;

    /** Connections whose queries are native calls on the device. */
    public const BRIDGE_DB_DRIVERS = ['nativeblade-db'];

    /** @var list<array<string, mixed>> everything recorded, in order: native calls and local queries */
    private array $log = [];

    /** @var list<array{action: string, data: array<string, mixed>}> */
    private array $pushed = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private string $fsRoot;

    // Replay state.
    private bool $replaying = false;
    private bool $exited = false;
    private int $callIndex = 0;

    /** @var list<array<string, mixed>> native calls of the current run */
    private array $runCalls = [];

    /** @var list<array<string, mixed>> native calls of the previous run */
    private array $previousCalls = [];

    /** @var array<int, mixed> results of the calls already completed, by call index */
    private array $cache = [];

    /** @var list<\Illuminate\Database\Connection> local connections with a transaction opened at the exit point */
    private array $postExitConnections = [];

    /** Divergence or budget message found during the current run, reported once the run is over. */
    private ?string $failure = null;

    /**
     * Transaction level of each connection when the run started. A test
     * wrapped in RefreshDatabase runs inside a transaction opened before the
     * replay; undoing a swallowed exit must roll back to that level, never
     * below it, or the data the test set up would vanish with it.
     *
     * @var array<string, int>
     */
    private array $baselineLevels = [];

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

        // HTTP: a Guzzle middleware, so a cached call never reaches the handler
        // (nor Http::fake()'s stub), exactly as the handler on the device
        // returns the cached response without a fetch.
        Http::globalMiddleware(function (callable $handler) use ($fake) {
            return function (RequestInterface $request, array $options) use ($handler, $fake) {
                $body = (string) $request->getBody();
                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }
                $entry = [
                    'type' => 'http',
                    'method' => $request->getMethod(),
                    'url' => (string) $request->getUri(),
                    'body' => $body,
                    'bodyHash' => RequestKey::bodyHash($body, $request->getHeaderLine('Content-Type')),
                    'pool' => WasmHttpHandler::isPooling(),
                ];

                $snapshot = $fake->bridge($entry, function () use ($handler, $request, $options) {
                    $response = $handler($request, $options)->wait();

                    return [
                        'status' => $response->getStatusCode(),
                        'headers' => $response->getHeaders(),
                        'body' => (string) $response->getBody(),
                    ];
                });

                return new FulfilledPromise(new Response($snapshot['status'], $snapshot['headers'], $snapshot['body']));
            };
        });

        // Native database: an in-memory SQLite whose every query is a native call.
        $bridge = fn (array $entry, Closure $execute) => $fake->bridge($entry, $execute);
        foreach (self::BRIDGE_DB_DRIVERS as $driver) {
            DB::extend($driver, fn (array $config, string $name) => new FakeNativeConnection($bridge, $config, $name));
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
            $fake->note([
                'type' => 'db',
                'bridge' => false,
                'sql' => $event->sql,
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
            ]);
        });

        // Native disks: every operation is a native call.
        Storage::extend('nativeblade', function ($app, $config) use ($fake, $bridge) {
            // Laravel binds this closure to the FilesystemManager, so only
            // public members of the fake are reachable here.
            $adapter = new RecordingFilesystemAdapter($fake->fsRoot(), $bridge);

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
    // The bridge
    // ------------------------------------------------------------------

    /**
     * Every native call comes through here. Outside a replay it just runs and
     * is recorded. Inside a replay it behaves like the device: a call already
     * completed is served from the cache; the first call that is not stops the
     * run after being carried out (that is what the shell would do), and the
     * request is re-run; a call whose content differs from the previous run
     * at the same position is a divergence.
     *
     * @internal
     */
    public function bridge(array $entry, Closure $execute): mixed
    {
        if (!$this->replaying) {
            $this->note($entry);

            return $execute();
        }

        if ($this->exited) {
            // The request already exited in this run; on the device nothing
            // runs after exit(0). Reached when a retry loop re-sends a call.
            throw new BridgePending();
        }

        $index = $this->callIndex++;
        $this->runCalls[] = $entry;
        $this->note($entry);

        $previous = $this->previousCalls[$index] ?? null;
        if ($previous !== null && self::key($previous) !== self::key($entry)) {
            // On the device the detector lives in the shell, outside PHP, so
            // no catch can swallow it. Here the violation is noted, the run
            // ends like an exit, and replay() fails afterwards, outside app
            // code, with this message.
            $this->abandon($this->divergence($index, $previous, $entry));
        }

        if (!empty($entry['pool'])) {
            // A pool is flushed as one batch on the device; it is never a
            // stop point here, and its calls are not cached.
            return $execute();
        }

        if (array_key_exists($index, $this->cache)) {
            return $this->cache[$index];
        }

        $this->cache[$index] = $execute();
        $this->exit();
        $overBudget = $this->overBudget($this->runCalls);
        if ($overBudget !== null) {
            $this->abandon($overBudget);
        }

        throw new BridgePending();
    }

    /** Ends the run as an exit would and keeps the reason for replay() to report. */
    private function abandon(string $reason): never
    {
        $this->failure ??= $reason;
        if (!$this->exited) {
            $this->exit();
        }

        throw new BridgePending();
    }

    /**
     * The point where the device calls exit(0). PHP has no uncatchable
     * throwable, so a `catch (\Throwable)` in app code can swallow the
     * BridgePending; the fake then makes everything after this point as if
     * it never ran: native calls are refused (see bridge()), database writes
     * on local connections are rolled back at the end of the run, and the
     * run is never treated as completed.
     */
    private function exit(): void
    {
        $this->exited = true;
        $this->postExitConnections = [];
        foreach (DB::getConnections() as $name => $connection) {
            $driver = $connection->getConfig('driver');
            if (in_array($driver, self::BRIDGE_DB_DRIVERS, true)) {
                continue;
            }
            try {
                $connection->beginTransaction();
                $this->postExitConnections[] = $connection;
            } catch (\Throwable) {
                // A connection that cannot open a transaction is left alone.
            }
        }
    }

    private function undoPostExitWrites(): void
    {
        foreach ($this->postExitConnections as $connection) {
            try {
                // Back to where the run started: a transaction the app opened
                // during the run is lost too, as it would be on the device,
                // while the test's own transaction (RefreshDatabase) survives.
                $baseline = $this->baselineLevels[$connection->getName()] ?? 0;
                if ($connection->transactionLevel() > $baseline) {
                    $connection->rollBack($baseline);
                }
            } catch (\Throwable) {
            }
        }
        $this->postExitConnections = [];
    }

    // ------------------------------------------------------------------
    // Replay
    // ------------------------------------------------------------------

    /**
     * Run a Livewire action the way the runtime runs a request: again and
     * again from the same component snapshot, each run going one native call
     * further than the last, until a run completes. Fails on the first call
     * that differs between two runs and when a run exceeds a budget. Nothing a
     * run wrote is undone. The component, the recorded calls, the pushed
     * actions and the logs reflect the final run.
     */
    public function replayCall(Testable $component, string $method, mixed ...$params): Testable
    {
        $state = \Livewire\invade($component)->lastState;

        $this->runReplay(
            fn () => $component->call($method, ...$params),
            function () use ($component, $state) {
                \Livewire\invade($component)->lastState = $state;
            },
        );

        return $component;
    }

    /**
     * Same as replayCall() for any code: a controller action, a job, a plain
     * closure. The closure is invoked once per run and must start from the
     * same inputs each time.
     */
    public function replay(Closure $action): mixed
    {
        return $this->runReplay($action, null);
    }

    /**
     * Seconds the clock moves forward between two runs. On the device the
     * runs are a few hundred milliseconds apart, so a timestamp in a call
     * crosses a second boundary now and then; a full second between runs
     * makes that happen every time instead of once in a while. 0 freezes it.
     */
    private float $clockStepSeconds = 1.0;

    public function advanceClockBetweenRuns(float $seconds): static
    {
        $this->clockStepSeconds = max(0.0, $seconds);

        return $this;
    }

    private function runReplay(Closure $run, ?Closure $restore): mixed
    {
        // The clock is held still inside a run and stepped between runs; the
        // test's own Carbon::setTestNow(), if any, is restored at the end.
        $previousTestNow = Carbon::hasTestNow() ? Carbon::getTestNow() : null;
        Carbon::setTestNow($previousTestNow ? $previousTestNow->copy() : Carbon::now());

        $this->replaying = true;
        $this->cache = [];
        $this->previousCalls = [];
        $maxRuns = 1 + self::HTTP_BUDGET + self::DB_BUDGET + self::FS_BUDGET;

        try {
            for ($runNumber = 1; $runNumber <= $maxRuns; $runNumber++) {
                if ($runNumber > 1 && $restore !== null) {
                    $restore();
                }
                if ($runNumber > 1 && $this->clockStepSeconds > 0) {
                    Carbon::setTestNow(Carbon::getTestNow()->copy()->addMilliseconds((int) round($this->clockStepSeconds * 1000)));
                }
                // A fresh PHP process on the device: nothing remembered from
                // the previous run survives.
                $this->forgetRequestMemo();
                $this->log = [];
                $this->pushed = [];
                $this->logs = [];
                $this->runCalls = [];
                $this->callIndex = 0;
                $this->exited = false;
                $this->baselineLevels = [];
                foreach (DB::getConnections() as $name => $connection) {
                    $this->baselineLevels[$name] = $connection->transactionLevel();
                }

                $result = null;
                $completed = false;
                $this->failure = null;
                try {
                    $result = $run();
                    // A run that exited and was caught by the app is not a
                    // completed request, whatever it returned.
                    $completed = !$this->exited;
                } catch (BridgePending) {
                    // The run stopped at its pending call; the next one goes further.
                } finally {
                    $this->undoPostExitWrites();
                }

                // Reported here, outside app code, so no catch can hide it.
                if ($this->failure !== null) {
                    Assert::fail($this->failure);
                }

                if ($completed) {
                    if (count($this->runCalls) < count($this->previousCalls)) {
                        Assert::fail($this->divergence(count($this->runCalls), $this->previousCalls[count($this->runCalls)], null));
                    }
                    $overBudget = $this->overBudget($this->runCalls);
                    if ($overBudget !== null) {
                        Assert::fail($overBudget);
                    }

                    return $result;
                }

                $this->previousCalls = $this->runCalls;
            }

            Assert::fail("The request did not complete after {$maxRuns} runs.");
        } finally {
            $this->replaying = false;
            Carbon::setTestNow($previousTestNow);
        }
    }

    private function divergence(int $index, array $was, ?array $now): string
    {
        $nowText = $now === null
            ? 'nothing (the request completed without making it)'
            : '`' . self::describe($now) . '`';

        return sprintf(
            "Replay diverged at call #%d: was `%s`, now %s.\n"
            . 'PHP is re-run after every native call (HTTP, native database, native filesystem) and must make the same '
            . 'calls in the same order; something before this call is not deterministic (random values, the clock, '
            . 'state written before the call that changes the next run).',
            $index + 1,
            self::describe($was),
            $nowText,
        );
    }

    /** The budget message when the calls exceed one, null when they fit. */
    private function overBudget(array $calls): ?string
    {
        $http = self::httpBudgetUnits($calls);
        if ($http > self::HTTP_BUDGET) {
            return sprintf(
                'This action made %d sequential HTTP calls; the runtime abandons a request after %d. '
                . 'Batch independent calls with NativeBlade::pool() or split the work across requests.',
                $http,
                self::HTTP_BUDGET,
            );
        }

        $db = count(array_filter($calls, fn ($e) => $e['type'] === 'db'));
        if ($db > self::DB_BUDGET) {
            return sprintf(
                'This action ran %d queries on the native database; the runtime abandons a request after %d. '
                . 'Eager-load relations, cache lookups or split the work across requests.',
                $db,
                self::DB_BUDGET,
            );
        }

        $fs = count(array_filter($calls, fn ($e) => $e['type'] === 'fs'));
        if ($fs > self::FS_BUDGET) {
            return sprintf(
                'This action made %d native filesystem operations; the runtime abandons a request after %d. '
                . 'Split the work across requests.',
                $fs,
                self::FS_BUDGET,
            );
        }

        return null;
    }

    /** Pooled calls cost one re-run together, as on the device. */
    private static function httpBudgetUnits(array $calls): int
    {
        $units = 0;
        $inPool = false;
        foreach ($calls as $entry) {
            if ($entry['type'] !== 'http') {
                $inPool = false;
                continue;
            }
            if (!empty($entry['pool'])) {
                if (!$inPool) {
                    $units++;
                    $inPool = true;
                }
                continue;
            }
            $inPool = false;
            $units++;
        }

        return $units;
    }

    /** Mirrors the cache keys of WasmHttpHandler, NativeConnection and NativeFilesystemAdapter. */
    private static function key(array $entry): string
    {
        return match ($entry['type']) {
            'http' => 'http|' . $entry['method'] . '|' . $entry['url'] . '|' . ($entry['bodyHash'] ?? md5($entry['body'] ?? '')),
            'db' => 'db|' . $entry['sql'] . '|' . json_encode($entry['bindings']),
            'fs' => 'fs|' . $entry['op'] . '|' . $entry['baseDir'] . '|' . $entry['path'],
            default => json_encode($entry),
        };
    }

    public static function describe(array $entry): string
    {
        return match ($entry['type']) {
            'http' => $entry['method'] . ' ' . $entry['url']
                . (($entry['body'] ?? '') !== '' ? ' body#' . substr($entry['bodyHash'] ?? md5($entry['body']), 0, 8) : ''),
            'db' => $entry['sql'] . ' ' . json_encode($entry['bindings']),
            'fs' => $entry['op'] . ' ' . $entry['baseDir'] . ':' . $entry['path'],
            default => json_encode($entry),
        };
    }

    // ------------------------------------------------------------------
    // Recorded data
    // ------------------------------------------------------------------

    /** @internal */
    public function note(array $entry): void
    {
        $this->log[] = $entry;
    }

    /** @return list<array<string, mixed>> everything recorded, in order (after a replay: the final run) */
    public function sequence(): array
    {
        return $this->log;
    }

    /** @return list<array<string, mixed>> only the native calls: HTTP, native database, native filesystem */
    public function nativeCalls(): array
    {
        return array_values(array_filter($this->log, fn ($e) => $e['type'] !== 'db' || !empty($e['bridge'])));
    }

    /** @return list<array{type: string, method: string, url: string, pool: bool}> */
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

    /** Queries on native connections only: the ones that count against the budget. */
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
