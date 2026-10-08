<?php

namespace NativeBlade\Testing;

use Closure;
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
use NativeBlade\Http\WasmHttpHandler;
use NativeBlade\NativeResponse;
use NativeBlade\ShellConfig;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * The runtime's execution model, simulated inside PHPUnit.
 *
 * On the device a request is re-run from scratch after every native call
 * (HTTP, database, filesystem), replaying the earlier calls from a cache, and
 * is abandoned when it makes more sequential calls than the runtime allows.
 * Code that passes Livewire::test() still breaks there when a call is not
 * deterministic or an action makes too many of them. NativeBlade::fake()
 * records every call an action makes, runs it twice to compare the sequences
 * the way the shell's replay detector does, enforces the same budgets, and
 * lets a test assert on the actions pushed to the shell and the log entries.
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

    /** @var list<array<string, mixed>> every bridge call, in order */
    private array $sequence = [];

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

        Http::globalRequestMiddleware(function (RequestInterface $request) use ($fake) {
            $fake->record([
                'type' => 'http',
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'pool' => WasmHttpHandler::isPooling(),
            ]);

            return $request;
        });

        $app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use ($fake) {
            $fake->record([
                'type' => 'db',
                'sql' => $event->sql,
                'bindings' => $event->bindings,
                'connection' => $event->connectionName,
            ]);
        });

        Storage::extend('nativeblade', function ($app, $config) use ($fake) {
            $adapter = new RecordingFilesystemAdapter(
                $fake->fsRoot,
                fn (array $op) => $fake->record(['type' => 'fs', ...$op]),
            );

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
    // Replay
    // ------------------------------------------------------------------

    /**
     * Run a Livewire action twice from the same component snapshot, exactly as
     * the runtime re-runs a request, and fail when the two runs do not make
     * the same native calls in the same order or when one of them exceeds a
     * budget. The first run's database writes are rolled back; the component
     * and the recorded calls reflect the second run.
     */
    public function replayCall(Testable $component, string $method, mixed ...$params): Testable
    {
        $state = \Livewire\invade($component)->lastState;

        $this->runTwice(
            fn () => $component->call($method, ...$params),
            function () use ($component, $state) {
                \Livewire\invade($component)->lastState = $state;
            },
        );

        return $component;
    }

    /**
     * Same as replayCall() for any code: a controller action, a job, a plain
     * closure. The closure must start from the same inputs each time; the
     * database is rolled back between the runs for it.
     */
    public function replay(Closure $action): mixed
    {
        return $this->runTwice($action, null);
    }

    private function runTwice(Closure $run, ?Closure $restore): mixed
    {
        $frozeClock = false;
        if (!Carbon::hasTestNow()) {
            Carbon::setTestNow(Carbon::now());
            $frozeClock = true;
        }

        try {
            $first = $this->recordRun(function () use ($run) {
                $connections = DB::getConnections();
                foreach ($connections as $connection) {
                    $connection->beginTransaction();
                }
                try {
                    $run();
                } finally {
                    foreach ($connections as $connection) {
                        $connection->rollBack();
                    }
                }
            });

            if ($restore !== null) {
                $restore();
            }

            $this->pushed = [];
            $this->logs = [];
            $result = null;
            $second = $this->recordRun(function () use ($run, &$result) {
                $result = $run();
            });
        } finally {
            if ($frozeClock) {
                Carbon::setTestNow();
            }
        }

        $this->sequence = $second;
        $this->assertSameSequence($first, $second);
        $this->assertWithinBudgets($second);

        return $result;
    }

    /** @return list<array<string, mixed>> the calls made during $fn */
    private function recordRun(Closure $fn): array
    {
        // Each run is a fresh PHP process on the device: nothing remembered
        // from the previous run survives, so the memo is dropped here too.
        $this->forgetRequestMemo();

        $start = count($this->sequence);
        $fn();

        return array_values(array_slice($this->sequence, $start));
    }

    private function assertSameSequence(array $first, array $second): void
    {
        $max = max(count($first), count($second));
        for ($i = 0; $i < $max; $i++) {
            $a = $first[$i] ?? null;
            $b = $second[$i] ?? null;
            if ($a !== null && $b !== null && self::key($a) === self::key($b)) {
                continue;
            }

            $was = $a === null ? 'nothing (the first run ended here)' : '`' . self::describe($a) . '`';
            $now = $b === null ? 'nothing (the second run ended here)' : '`' . self::describe($b) . '`';

            Assert::fail(sprintf(
                "Replay diverged at call #%d: was %s, now %s.\n"
                . 'PHP is re-run after every native call (HTTP, database, filesystem) and must make the same calls '
                . 'in the same order; something before this call is not deterministic (random values, the clock, '
                . 'state changed before the call).',
                $i + 1,
                $was,
                $now,
            ));
        }
    }

    private function assertWithinBudgets(array $sequence): void
    {
        $http = self::httpBudgetUnits($sequence);
        if ($http > self::HTTP_BUDGET) {
            Assert::fail(sprintf(
                'This action made %d sequential HTTP calls; the runtime abandons a request after %d. '
                . 'Batch independent calls with NativeBlade::pool() or split the work across requests.',
                $http,
                self::HTTP_BUDGET,
            ));
        }

        $db = count(array_filter($sequence, fn ($e) => $e['type'] === 'db'));
        if ($db > self::DB_BUDGET) {
            Assert::fail(sprintf(
                'This action ran %d queries; the runtime abandons a request after %d. '
                . 'Eager-load relations, cache lookups or split the work across requests.',
                $db,
                self::DB_BUDGET,
            ));
        }

        $fs = count(array_filter($sequence, fn ($e) => $e['type'] === 'fs'));
        if ($fs > self::FS_BUDGET) {
            Assert::fail(sprintf(
                'This action made %d filesystem operations; the runtime abandons a request after %d. '
                . 'Split the work across requests.',
                $fs,
                self::FS_BUDGET,
            ));
        }
    }

    /** Pooled calls cost one re-run together, as on the device. */
    private static function httpBudgetUnits(array $sequence): int
    {
        $units = 0;
        $inPool = false;
        foreach ($sequence as $entry) {
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

    private static function key(array $entry): string
    {
        return match ($entry['type']) {
            'http' => 'http|' . $entry['method'] . '|' . $entry['url'],
            'db' => 'db|' . $entry['sql'] . '|' . json_encode($entry['bindings']),
            'fs' => 'fs|' . $entry['op'] . '|' . $entry['baseDir'] . '|' . $entry['path'],
            default => json_encode($entry),
        };
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
    // Recorded data
    // ------------------------------------------------------------------

    /** @internal */
    public function record(array $entry): void
    {
        $this->sequence[] = $entry;
    }

    /** @return list<array<string, mixed>> every native call, in order (after replay: the final run) */
    public function sequence(): array
    {
        return $this->sequence;
    }

    /** @return list<array{type: string, method: string, url: string, pool: bool}> */
    public function httpCalls(): array
    {
        return array_values(array_filter($this->sequence, fn ($e) => $e['type'] === 'http'));
    }

    /** @return list<array{type: string, sql: string, bindings: array, connection: string}> */
    public function queries(): array
    {
        return array_values(array_filter($this->sequence, fn ($e) => $e['type'] === 'db'));
    }

    /** @return list<array{type: string, op: string, path: string, baseDir: string}> */
    public function fsOps(): array
    {
        return array_values(array_filter($this->sequence, fn ($e) => $e['type'] === 'fs'));
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
