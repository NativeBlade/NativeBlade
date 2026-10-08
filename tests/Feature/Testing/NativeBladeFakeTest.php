<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Testing;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Testing\NativeBladeFake;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

/**
 * A component whose single action can be shaped per test: how many native
 * calls it makes, whether a call carries a random value, whether it writes
 * before or after a call, and the classic lock-before-the-network trap.
 */
final class SyncProbe extends Component
{
    public int $calls = 1;
    public bool $random = false;
    public bool $lock = false;
    public bool $insertBefore = false;
    public bool $insertAfter = false;
    public int $localQueries = 0;
    public int $nativeQueries = 0;
    public bool $write = false;
    public bool $log = false;
    public bool $push = false;

    public function sync(): mixed
    {
        if ($this->lock) {
            // The trap: on the device the lock written before the HTTP call
            // survives the exit, and the re-run gives up.
            if (NativeBlade::getState('sync.lock')) {
                return 'already running';
            }
            NativeBlade::setState('sync.lock', true);
        }

        if ($this->insertBefore) {
            DB::table('probe_rows')->insert(['name' => 'before']);
        }

        for ($i = 0; $i < $this->localQueries; $i++) {
            DB::table('probe_rows')->where('id', $i)->first();
        }

        for ($i = 0; $i < $this->nativeQueries; $i++) {
            DB::connection('native')->table('remote_items')->where('id', $i)->first();
        }

        for ($i = 0; $i < $this->calls; $i++) {
            $query = ['page' => $i];
            if ($this->random) {
                $query['r'] = Str::random(6);
            }
            Http::get('https://api.test/items', $query);
        }

        if ($this->insertAfter) {
            DB::table('probe_rows')->insert(['name' => 'after']);
        }
        if ($this->write) {
            Storage::disk('native')->put(native_path('out.txt'), 'hello');
        }
        if ($this->log) {
            NativeBlade::log('synced', ['calls' => $this->calls]);
        }
        if ($this->push) {
            return NativeBlade::vibrate(50)->toResponse();
        }

        return null;
    }

    public function render(): string
    {
        return '<div>{{ $calls }}</div>';
    }
}

final class ProbeStatic
{
    public static int $n = 0;
}

final class NativeBladeFakeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('filesystems.disks.native', ['driver' => 'nativeblade', 'purpose' => 'app']);
        $app['config']->set('database.connections.native', ['driver' => 'nativeblade-db', 'native_driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('probe_rows', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        Http::fake(['api.test/*' => Http::response(['ok' => true])]);
    }

    /** The native connection only exists once the fake is installed. */
    private function fakeWithNativeTable(): NativeBladeFake
    {
        $fake = NativeBlade::fake();
        Schema::connection('native')->create('remote_items', function ($table) {
            $table->increments('id');
            $table->string('name');
        });

        return $fake;
    }

    private function expectReplayFailure(callable $run, string ...$fragments): void
    {
        try {
            $run();
        } catch (AssertionFailedError $e) {
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }

            return;
        }

        self::fail('expected the replay to fail');
    }

    #[Test]
    public function fake_takes_over_the_shell_with_the_given_platform_and_dev_flag(): void
    {
        $fake = NativeBlade::fake('windows', dev: true);

        self::assertInstanceOf(NativeBladeFake::class, $fake);
        self::assertSame($fake, app('nativeblade'));
        self::assertSame('windows', NativeBlade::platform());
        self::assertTrue(NativeBlade::isDesktop());
        self::assertFalse(NativeBlade::isMobile());
        self::assertTrue(NativeBlade::isDev());

        self::assertSame('android', NativeBlade::fake()->platform());
    }

    #[Test]
    public function a_deterministic_action_completes_and_its_calls_are_recorded(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['calls' => 2]);

        $fake->replayCall($component, 'sync');

        $fake->assertHttpCalls(2)
            ->assertHttpCalled('GET', 'https://api.test/items?page=0')
            ->assertHttpCalled('get', 'https://api.test/items?page=*')
            ->assertHttpCallsAtMost(NativeBladeFake::HTTP_BUDGET);
        self::assertSame('https://api.test/items?page=1', $fake->httpCalls()[1]['url']);
        self::assertFalse($fake->httpCalls()[0]['pool']);
        self::assertSame($fake->httpCalls(), $fake->nativeCalls());
    }

    #[Test]
    public function each_run_goes_one_native_call_further_and_earlier_calls_come_from_the_cache(): void
    {
        $fake = NativeBlade::fake();
        $runs = 0;
        $sent = 0;
        Http::fake(['api.test/*' => function () use (&$sent) { $sent++; return Http::response(['ok' => true]); }]);

        $result = $fake->replay(function () use (&$runs) {
            $runs++;
            Http::get('https://api.test/a');
            Http::get('https://api.test/b');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(3, $runs, 'two native calls: two runs that exit, one that completes');
        self::assertSame(2, $sent, 'each call reaches the network once; the re-runs read the cache');
        $fake->assertHttpCalls(2);
    }

    #[Test]
    public function replay_fails_on_the_exact_call_that_is_not_deterministic(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['calls' => 2, 'random' => true]);

        $this->expectReplayFailure(
            fn () => $fake->replayCall($component, 'sync'),
            'Replay diverged at call #',
            'was `GET https://api.test/items?page=0&r=',
            'now `GET https://api.test/items?page=0&r=',
            'not deterministic',
        );
    }

    #[Test]
    public function a_lock_written_before_the_network_call_makes_the_re_run_give_up(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['lock' => true]);

        $this->expectReplayFailure(
            fn () => $fake->replayCall($component, 'sync'),
            'Replay diverged at call #1: was `GET https://api.test/items?page=0`, now nothing (the request completed without making it)',
        );
    }

    #[Test]
    public function writes_before_a_native_call_happen_on_every_run_and_writes_after_it_once(): void
    {
        $fake = NativeBlade::fake();

        $fake->replayCall(Livewire::test(SyncProbe::class, ['insertBefore' => true]), 'sync');
        self::assertSame(2, DB::table('probe_rows')->where('name', 'before')->count(), 'two runs reached the insert');

        $fake->replayCall(Livewire::test(SyncProbe::class, ['insertAfter' => true]), 'sync');
        self::assertSame(1, DB::table('probe_rows')->where('name', 'after')->count(), 'only the completing run reached it');
    }

    #[Test]
    public function local_queries_are_information_and_never_a_native_call(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['localQueries' => 30]);

        $fake->replayCall($component, 'sync');

        $fake->assertNativeQueries(0)->assertHttpCalls(1);
        self::assertGreaterThanOrEqual(30, count($fake->queries()));
        self::assertFalse($fake->queries()[0]['bridge']);
    }

    #[Test]
    public function native_queries_count_against_the_budget_and_are_served_from_the_cache_on_re_runs(): void
    {
        $fake = $this->fakeWithNativeTable();

        $fake->replayCall(Livewire::test(SyncProbe::class, ['nativeQueries' => 5]), 'sync');
        $fake->assertNativeQueries(5)->assertNativeQueriesAtMost(NativeBladeFake::DB_BUDGET)->assertHttpCalls(1);
        self::assertTrue($fake->nativeQueries()[0]['bridge']);
        self::assertSame('native', $fake->nativeQueries()[0]['connection']);

        $this->expectReplayFailure(
            fn () => $fake->replayCall(Livewire::test(SyncProbe::class, ['nativeQueries' => NativeBladeFake::DB_BUDGET + 1]), 'sync'),
            'ran 21 queries on the native database',
        );
    }

    #[Test]
    public function the_native_database_is_real_enough_to_read_rows_back(): void
    {
        $fake = $this->fakeWithNativeTable();

        $fake->replay(function () {
            DB::connection('native')->table('remote_items')->insert(['name' => 'remote']);
        });

        self::assertSame(1, DB::connection('native')->table('remote_items')->count());
        self::assertSame('insert', $fake->nativeQueries()[0]['kind']);
    }

    #[Test]
    public function a_write_between_http_calls_is_caught_because_the_body_is_part_of_the_call(): void
    {
        $fake = NativeBlade::fake();
        DB::table('probe_rows')->insert([['name' => 'd1'], ['name' => 'd2'], ['name' => 'd3']]);
        Schema::table('probe_rows', fn ($table) => $table->boolean('pending')->default(true));

        // AVOID: marking a draft sent between calls shifts the next run's
        // sequence. On the device draft 2 would get draft 1's cached response.
        $this->expectReplayFailure(
            fn () => $fake->replay(function () {
                foreach (DB::table('probe_rows')->where('pending', true)->orderBy('id')->get() as $draft) {
                    Http::post('https://api.test/drafts', ['id' => $draft->id]);
                    DB::table('probe_rows')->where('id', $draft->id)->update(['pending' => false]);
                }
            }),
            'Replay diverged at call #1',
            'was `POST https://api.test/drafts body#',
            'now `POST https://api.test/drafts body#',
        );
    }

    #[Test]
    public function network_first_then_writes_replays_cleanly(): void
    {
        $fake = NativeBlade::fake();
        DB::table('probe_rows')->insert([['name' => 'd1'], ['name' => 'd2'], ['name' => 'd3']]);
        Schema::table('probe_rows', fn ($table) => $table->boolean('pending')->default(true));

        $fake->replay(function () {
            $drafts = DB::table('probe_rows')->where('pending', true)->orderBy('id')->get();
            $responses = $drafts->map(fn ($draft) => Http::post('https://api.test/drafts', ['id' => $draft->id]));
            foreach ($drafts as $i => $draft) {
                if ($responses[$i]->successful()) {
                    DB::table('probe_rows')->where('id', $draft->id)->update(['pending' => false]);
                }
            }
        });

        $fake->assertHttpCalls(3);
        self::assertSame(0, DB::table('probe_rows')->where('pending', true)->count());
    }

    #[Test]
    public function deleting_the_file_after_the_upload_does_not_resend_anything(): void
    {
        $fake = NativeBlade::fake();
        Storage::disk('native')->put(native_path('a.png'), 'bytes');
        $sent = 0;
        Http::fake(['api.test/*' => function () use (&$sent) { $sent++; return Http::response(['ok' => true]); }]);

        $fake->replay(function () {
            $bytes = Storage::disk('native')->get(native_path('a.png'));
            Http::post('https://api.test/upload', ['data' => base64_encode($bytes)]);
            Storage::disk('native')->delete(native_path('a.png'));
        });

        self::assertSame(1, $sent, 'the upload reached the network once');
        $fake->assertFsOps(2)->assertHttpCalls(1);
        self::assertSame(['read', 'delete'], array_column($fake->fsOps(), 'op'));
        self::assertFalse(Storage::disk('native')->exists(native_path('a.png')));
    }

    #[Test]
    public function a_multipart_upload_with_http_attach_is_the_same_call_on_every_run(): void
    {
        $fake = NativeBlade::fake();
        Storage::disk('native')->put(native_path('a.png'), 'png-bytes');
        $sent = [];
        Http::fake(['api.test/*' => function ($request) use (&$sent) { $sent[] = $request; return Http::response(['ok' => true]); }]);

        $fake->replay(function () {
            $bytes = Storage::disk('native')->get(native_path('a.png'));
            Http::attach('file', $bytes, 'a.png')->post('https://api.test/upload', ['answer' => 7]);
            Storage::disk('native')->delete(native_path('a.png'));
        });

        self::assertCount(1, $sent, 'the upload reached the network once');
        self::assertTrue($sent[0]->hasFile('file', 'png-bytes', 'a.png'));
        $fake->assertHttpCalls(1)->assertFsOps(2);
        self::assertStringContainsString('filename="a.png"', $fake->httpCalls()[0]['body'], 'the multipart body was recorded');
    }

    #[Test]
    public function the_native_disk_works_through_storage_directly(): void
    {
        $fake = NativeBlade::fake();

        Storage::disk('native')->put('plain.txt', 'one');
        Storage::disk('native')->put(native_path('docs/report.txt', \NativeBlade\Storage\StoragePath::DOWNLOADS), 'two');

        self::assertSame('one', Storage::disk('native')->get('plain.txt'));
        self::assertFileExists($fake->fsRoot() . '/downloads/docs/report.txt');
        self::assertSame(['write', 'write', 'read'], array_column($fake->fsOps(), 'op'));
        self::assertSame(['app', 'downloads', 'app'], array_column($fake->fsOps(), 'baseDir'));
    }

    #[Test]
    public function a_notification_scheduled_on_desktop_is_accepted_the_shell_keeps_it_while_the_app_runs(): void
    {
        $fake = NativeBlade::fake('windows');

        $fake->replay(fn () => NativeBlade::scheduleNotification(
            fn ($n) => $n->id('r1')->title('Later')->at(now()->addMinutes(5))
        )->toResponse());

        $fake->assertNotificationScheduled('r1');
        self::assertSame('at', $fake->pushed()[0]['data']['schedule']['type']);
    }

    #[Test]
    public function replay_fails_when_an_action_exceeds_the_http_budget(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['calls' => NativeBladeFake::HTTP_BUDGET + 1]);

        $this->expectReplayFailure(
            fn () => $fake->replayCall($component, 'sync'),
            'made 11 sequential HTTP calls',
            'NativeBlade::pool()',
        );
    }

    #[Test]
    public function pooled_calls_cost_one_budget_unit_together(): void
    {
        $fake = NativeBlade::fake();

        $fake->replay(fn () => NativeBlade::pool(fn (Pool $pool) => array_map(
            fn ($i) => $pool->get("https://api.test/items?page={$i}"),
            range(1, NativeBladeFake::HTTP_BUDGET + 2),
        )));

        $fake->assertHttpCalls(NativeBladeFake::HTTP_BUDGET + 2);
        self::assertTrue($fake->httpCalls()[0]['pool']);
    }

    #[Test]
    public function native_disk_operations_are_native_calls_and_the_files_are_real(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['write' => true]);

        $fake->replayCall($component, 'sync');

        $fake->assertFsOps(1)->assertFsOpsAtMost(NativeBladeFake::FS_BUDGET);
        self::assertSame(['type' => 'fs', 'op' => 'write', 'path' => 'out.txt', 'baseDir' => 'app'], $fake->fsOps()[0]);
        self::assertSame('hello', Storage::disk('native')->get(native_path('out.txt')));
        self::assertFileExists($fake->fsRoot() . '/app/out.txt');
    }

    #[Test]
    public function pushed_actions_and_logs_reflect_the_final_run_only(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['push' => true, 'log' => true]);

        $fake->replayCall($component, 'sync');

        $fake->assertActionPushed('vibrate')
            ->assertActionPushed('vibrate', fn (array $data) => $data['duration'] === 50)
            ->assertActionNotPushed('navigate')
            ->assertLogged('synced')
            ->assertLogged('synced', 'info')
            ->assertNotLogged('failed');
        self::assertCount(1, $fake->pushed());
        self::assertCount(1, $fake->logs());
        self::assertSame(['calls' => 1], $fake->logs()[0]['context']);
    }

    #[Test]
    public function app_code_cannot_swallow_the_exit(): void
    {
        $fake = NativeBlade::fake();
        $runs = 0;

        $result = $fake->replay(function () use (&$runs) {
            $runs++;
            try {
                Http::get('https://api.test/items');
            } catch (\Exception) {
                return 'swallowed';
            }

            return 'completed';
        });

        self::assertSame('completed', $result);
        self::assertSame(2, $runs);
    }

    #[Test]
    public function a_catch_throwable_cannot_change_what_happens_after_the_exit(): void
    {
        $fake = NativeBlade::fake();
        $runs = 0;

        $result = $fake->replay(function () use (&$runs) {
            $runs++;
            DB::table('probe_rows')->insert(['name' => 'before-exit']);
            try {
                Http::get('https://api.test/items');
            } catch (\Throwable) {
                // Everything here would never run on the device.
                DB::table('probe_rows')->insert(['name' => 'after-exit']);
                NativeBlade::setState('sync.failed', true);
                NativeBlade::log('unexpected error', [], 'error');
                Storage::disk('native')->put(native_path('after.txt'), 'x');
                NativeBlade::vibrate(10)->toResponse();

                return 'swallowed';
            }

            return 'completed';
        });

        self::assertSame('completed', $result, 'the swallowed run is not a completed request');
        self::assertSame(2, $runs);
        self::assertSame(2, DB::table('probe_rows')->where('name', 'before-exit')->count(), 'written before the exit on both runs');
        self::assertSame(0, DB::table('probe_rows')->where('name', 'after-exit')->count(), 'rolled back as the device never ran it');
        self::assertNull(NativeBlade::getState('sync.failed'));
        self::assertFileDoesNotExist($fake->fsRoot() . '/app/after.txt');
        $fake->assertNotLogged('unexpected error')->assertNothingPushed()->assertHttpCalls(1)->assertFsOps(0);
    }

    #[Test]
    public function data_set_up_inside_the_test_transaction_survives_a_swallowed_exit(): void
    {
        // What RefreshDatabase does: the whole test runs inside a transaction
        // opened before the replay.
        DB::beginTransaction();
        try {
            DB::table('probe_rows')->insert(['name' => 'fixture']);
            $fake = NativeBlade::fake();

            $fake->replay(function () {
                try {
                    Http::get('https://api.test/items');
                } catch (\Throwable) {
                    DB::table('probe_rows')->insert(['name' => 'after-exit']);
                }
                DB::table('probe_rows')->insert(['name' => 'completed']);
            });

            self::assertSame(1, DB::table('probe_rows')->where('name', 'fixture')->count(), 'the fixture outlives the swallowed exit');
            self::assertSame(0, DB::table('probe_rows')->where('name', 'after-exit')->count());
            self::assertSame(1, DB::table('probe_rows')->where('name', 'completed')->count());
            self::assertSame(1, DB::transactionLevel(), 'the test transaction is still open');
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function a_transaction_open_at_the_exit_point_is_lost_as_on_the_device(): void
    {
        $fake = NativeBlade::fake();

        $fake->replay(function () {
            DB::transaction(function () {
                DB::table('probe_rows')->insert(['name' => 'in-transaction']);
                Http::get('https://api.test/items');
            });
        });

        self::assertSame(1, DB::table('probe_rows')->where('name', 'in-transaction')->count(), 'only the completing run commits');
    }

    #[Test]
    public function the_clock_moves_between_runs_so_a_timestamp_in_a_call_diverges_every_time(): void
    {
        $fake = NativeBlade::fake();

        $this->expectReplayFailure(
            fn () => $fake->replay(fn () => Http::get('https://api.test/ping', ['t' => now()->timestamp])),
            'Replay diverged at call #1',
            'ping?t=',
        );
    }

    #[Test]
    public function the_clock_step_is_one_second_by_default_configurable_and_the_test_clock_is_restored(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        $fake = NativeBlade::fake();
        $seen = [];

        $fake->replay(function () use (&$seen) {
            $seen[] = now()->toDateTimeString();
            Http::get('https://api.test/a');
            Http::get('https://api.test/b');
        });
        self::assertSame(['2026-10-08 10:00:00', '2026-10-08 10:00:01', '2026-10-08 10:00:02'], $seen);
        self::assertSame('2026-10-08 10:00:00', now()->toDateTimeString(), 'the test clock is back where the test set it');

        $seen = [];
        $fake->advanceClockBetweenRuns(0)->replay(function () use (&$seen) {
            $seen[] = now()->toDateTimeString();
            Http::get('https://api.test/a');
        });
        self::assertSame(['2026-10-08 10:00:00', '2026-10-08 10:00:00'], $seen);

        Carbon::setTestNow();
        $fake->advanceClockBetweenRuns(1);
        $fake->replay(fn () => Http::get('https://api.test/a'));
        self::assertFalse(Carbon::hasTestNow(), 'a clock the test never froze is left unfrozen');
    }

    #[Test]
    public function a_divergence_is_reported_with_its_own_message_even_when_the_app_catches_throwable(): void
    {
        $fake = NativeBlade::fake();
        $symptom = null;

        $this->expectReplayFailure(
            fn () => $fake->replay(function () use (&$symptom) {
                try {
                    Http::post('https://api.test/answers/store', ['t' => now()->timestamp]);
                } catch (\Throwable $e) {
                    // What a network-error handler would do: mark it pending.
                    $symptom = 'stored as pending';
                    DB::table('probe_rows')->insert(['name' => 'pending']);
                }
            }),
            'Replay diverged at call #1: was `POST https://api.test/answers/store body#',
            'now `POST https://api.test/answers/store body#',
        );

        self::assertSame(0, DB::table('probe_rows')->where('name', 'pending')->count(), 'nothing after the violation survives');
    }

    #[Test]
    public function an_exceeded_budget_is_reported_with_its_own_message_even_when_the_app_catches_throwable(): void
    {
        $fake = NativeBlade::fake();

        $this->expectReplayFailure(
            fn () => $fake->replay(function () {
                try {
                    for ($i = 0; $i <= NativeBladeFake::HTTP_BUDGET; $i++) {
                        Http::get("https://api.test/items?page={$i}");
                    }
                } catch (\Throwable) {
                    NativeBlade::log('unexpected error', [], 'error');
                }
            }),
            'made 11 sequential HTTP calls',
        );
    }

    #[Test]
    public function state_that_outlives_a_run_is_put_back_with_the_between_run_hooks(): void
    {
        $fake = NativeBlade::fake();
        app()->singleton('probe.counter', fn () => new \ArrayObject(['n' => 0]));

        // Same process, so without the hooks the static and the singleton
        // carry over and the second run differs from the first.
        $this->expectReplayFailure(
            fn () => $fake->replay(function () {
                ProbeStatic::$n++;
                Http::get('https://api.test/a', ['n' => ProbeStatic::$n]);
            }),
            'Replay diverged at call #1: was `GET https://api.test/a?n=1`, now `GET https://api.test/a?n=2`',
        );

        ProbeStatic::$n = 0;
        $fake->resetBetweenRuns(fn () => ProbeStatic::$n = 0)
            ->flushBetweenRuns('probe.counter')
            ->replay(function () {
                ProbeStatic::$n++;
                $counter = app('probe.counter');
                $counter['n']++;
                Http::get('https://api.test/a', ['n' => ProbeStatic::$n, 'c' => $counter['n']]);
            });

        $fake->assertHttpCalled('GET', 'https://api.test/a?n=1&c=1');
    }

    #[Test]
    public function abandon_at_kills_the_request_right_after_the_given_native_call(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['calls' => 3, 'insertBefore' => true, 'insertAfter' => true, 'push' => true]);
        $sent = 0;
        Http::fake(['api.test/*' => function () use (&$sent) { $sent++; return Http::response(['ok' => true]); }]);

        $fake->abandonAt(2)->replayCall($component, 'sync');

        self::assertSame(2, $sent, 'the second call was carried out by the shell before PHP died');
        $fake->assertHttpCalls(2)->assertNothingPushed();
        self::assertSame(2, DB::table('probe_rows')->where('name', 'before')->count(), 'written by both runs that reached it');
        self::assertSame(0, DB::table('probe_rows')->where('name', 'after')->count(), 'never reached');
        self::assertSame(3, $component->get('calls'), 'no response reached the component');

        // One-shot: the next replay runs to completion.
        $fake->replayCall(Livewire::test(SyncProbe::class, ['calls' => 3]), 'sync');
        $fake->assertHttpCalls(3);
    }

    #[Test]
    public function abandon_at_beyond_the_last_call_lets_the_request_complete(): void
    {
        $fake = NativeBlade::fake();

        $result = $fake->abandonAt(5)->replay(function () {
            Http::get('https://api.test/a');

            return 'done';
        });

        self::assertSame('done', $result);
    }

    #[Test]
    public function assertion_failures_list_what_was_recorded(): void
    {
        $fake = NativeBlade::fake();
        $fake->replay(fn () => Http::get('https://api.test/items'));

        $this->expectReplayFailure(
            fn () => $fake->assertActionPushed('navigate'),
            "No 'navigate' action was pushed",
            'Nothing was pushed.',
        );
        $this->expectReplayFailure(
            fn () => $fake->assertHttpCalled('POST', 'https://api.test/items'),
            '#1 GET https://api.test/items',
        );

        $fake->assertNothingPushed();
    }

    #[Test]
    public function replay_of_a_closure_catches_a_random_value_too(): void
    {
        $fake = NativeBlade::fake();

        $this->expectReplayFailure(
            fn () => $fake->replay(fn () => Http::get('https://api.test/ping', ['r' => Str::random(4)])),
            'Replay diverged at call #1',
        );
    }

    #[Test]
    public function notification_assertions_match_on_the_id(): void
    {
        $fake = NativeBlade::fake();

        $fake->replay(fn () => NativeBlade::scheduleNotification(
            fn ($n) => $n->id('reminder-1')->title('Hi')->body('There')->at(now()->addHour())
        )->toResponse());

        $fake->assertNotificationScheduled()->assertNotificationScheduled('reminder-1');

        $this->expectReplayFailure(
            fn () => $fake->assertNotificationScheduled('other'),
            "No 'notification' action matching",
        );
    }
}
