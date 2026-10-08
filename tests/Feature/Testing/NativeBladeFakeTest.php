<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Testing;

use Illuminate\Http\Client\Pool;
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
 * A component whose single action can be shaped per test: how many HTTP
 * calls it makes, whether a call carries a random value (the classic replay
 * bug), and which side effects it has.
 */
final class SyncProbe extends Component
{
    public int $calls = 1;
    public bool $random = false;
    public bool $insert = false;
    public bool $write = false;
    public bool $log = false;
    public bool $push = false;

    public function sync(): mixed
    {
        for ($i = 0; $i < $this->calls; $i++) {
            $query = ['page' => $i];
            if ($this->random) {
                $query['r'] = Str::random(6);
            }
            Http::get('https://api.test/items', $query);
        }

        if ($this->insert) {
            DB::table('probe_rows')->insert(['name' => 'synced']);
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

final class NativeBladeFakeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('filesystems.disks.native', ['driver' => 'nativeblade', 'purpose' => 'app']);
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
    public function replay_passes_a_deterministic_action_and_records_its_calls(): void
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
    }

    #[Test]
    public function replay_fails_on_the_exact_call_that_is_not_deterministic(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['calls' => 2, 'random' => true]);

        try {
            $fake->replayCall($component, 'sync');
            self::fail('expected the replay to diverge');
        } catch (AssertionFailedError $e) {
            self::assertMatchesRegularExpression('/Replay diverged at call #[0-9]+/', $e->getMessage());
            self::assertStringContainsString('was `GET https://api.test/items?page=0&r=', $e->getMessage());
            self::assertStringContainsString('now `GET https://api.test/items?page=0&r=', $e->getMessage());
            self::assertStringContainsString('not deterministic', $e->getMessage());
        }
    }

    #[Test]
    public function replay_fails_when_an_action_exceeds_the_http_budget(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['calls' => NativeBladeFake::HTTP_BUDGET + 1]);

        try {
            $fake->replayCall($component, 'sync');
            self::fail('expected the budget to fail');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('made 11 sequential HTTP calls', $e->getMessage());
            self::assertStringContainsString('NativeBlade::pool()', $e->getMessage());
        }
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
    public function the_first_run_is_rolled_back_so_side_effects_happen_once(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['insert' => true]);

        $fake->replayCall($component, 'sync');

        self::assertSame(1, DB::table('probe_rows')->count());
        $fake->assertQueriesAtMost(NativeBladeFake::DB_BUDGET);
        $inserts = array_filter($fake->queries(), fn ($q) => str_contains($q['sql'], 'insert into "probe_rows"'));
        self::assertCount(1, $inserts);
    }

    #[Test]
    public function native_disk_operations_are_recorded_and_the_files_are_real(): void
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
        self::assertCount(1, $fake->pushed(), 'the rolled-back first run must not leave its push behind');
        self::assertCount(1, $fake->logs());
        self::assertSame(['calls' => 1], $fake->logs()[0]['context']);
    }

    #[Test]
    public function assertion_failures_list_what_was_recorded(): void
    {
        $fake = NativeBlade::fake();
        $fake->replay(fn () => Http::get('https://api.test/items'));

        try {
            $fake->assertActionPushed('navigate');
            self::fail('expected a failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString("No 'navigate' action was pushed", $e->getMessage());
            self::assertStringContainsString('Nothing was pushed.', $e->getMessage());
        }

        try {
            $fake->assertHttpCalled('POST', 'https://api.test/items');
            self::fail('expected a failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('#1 GET https://api.test/items', $e->getMessage());
        }

        $fake->assertNothingPushed();
    }

    #[Test]
    public function replay_of_a_closure_catches_a_random_value_too(): void
    {
        $fake = NativeBlade::fake();

        try {
            $fake->replay(fn () => Http::get('https://api.test/ping', ['r' => Str::random(4)]));
            self::fail('expected the replay to diverge');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Replay diverged at call #1', $e->getMessage());
        }
    }

    #[Test]
    public function notification_assertions_match_on_the_id(): void
    {
        $fake = NativeBlade::fake();

        $fake->replay(fn () => NativeBlade::scheduleNotification(
            fn ($n) => $n->id('reminder-1')->title('Hi')->body('There')->at(now()->addHour())
        )->toResponse());

        $fake->assertNotificationScheduled()->assertNotificationScheduled('reminder-1');

        try {
            $fake->assertNotificationScheduled('other');
            self::fail('expected a failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString("No 'notification' action matching", $e->getMessage());
        }
    }
}
