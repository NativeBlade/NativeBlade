<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Testing;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Storage\StoragePath;
use NativeBlade\Testing\NativeBladeFake;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

/** A component whose action touches every kind of native call. */
final class SyncProbe extends Component
{
    public int $calls = 1;
    public int $localQueries = 0;
    public int $nativeQueries = 0;
    public bool $write = false;
    public bool $log = false;
    public bool $push = false;

    public function sync(): mixed
    {
        for ($i = 0; $i < $this->localQueries; $i++) {
            DB::table('probe_rows')->where('id', $i)->first();
        }
        for ($i = 0; $i < $this->nativeQueries; $i++) {
            DB::connection('native')->table('remote_items')->where('id', $i)->first();
        }
        for ($i = 0; $i < $this->calls; $i++) {
            Http::get('https://api.test/items', ['page' => $i]);
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

        return $fake->reset();
    }

    private function expectFailure(callable $run, string ...$fragments): void
    {
        try {
            $run();
        } catch (AssertionFailedError $e) {
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }

            return;
        }

        self::fail('expected an assertion failure');
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
    public function http_calls_are_recorded_with_method_url_and_body(): void
    {
        $fake = NativeBlade::fake();
        $component = Livewire::test(SyncProbe::class, ['calls' => 2]);

        $component->call('sync');
        Http::post('https://api.test/items', ['name' => 'x']);

        $fake->assertHttpCalls(3)
            ->assertHttpCalled('GET', 'https://api.test/items?page=0')
            ->assertHttpCalled('get', 'https://api.test/items?page=*')
            ->assertHttpCallsAtMost(3);
        self::assertSame('{"name":"x"}', $fake->httpCalls()[2]['body']);
    }

    #[Test]
    public function pooled_calls_are_flagged(): void
    {
        $fake = NativeBlade::fake();

        NativeBlade::pool(fn (Pool $pool) => [$pool->get('https://api.test/a'), $pool->get('https://api.test/b')]);

        $fake->assertHttpCalls(2);
        self::assertTrue($fake->httpCalls()[0]['pool']);
        self::assertFalse(Http::get('https://api.test/c') === null);
        self::assertFalse($fake->httpCalls()[2]['pool']);
    }

    #[Test]
    public function local_queries_are_information_and_never_a_native_call(): void
    {
        $fake = NativeBlade::fake();

        Livewire::test(SyncProbe::class, ['localQueries' => 3])->call('sync');

        $fake->assertNativeQueries(0)->assertHttpCalls(1);
        self::assertGreaterThanOrEqual(3, count($fake->queries()));
        self::assertFalse($fake->queries()[0]['bridge']);
        self::assertSame($fake->httpCalls(), $fake->nativeCalls());
    }

    #[Test]
    public function native_queries_are_native_calls_on_a_database_real_enough_to_read_rows_back(): void
    {
        $fake = $this->fakeWithNativeTable();

        DB::connection('native')->table('remote_items')->insert(['name' => 'remote']);
        Livewire::test(SyncProbe::class, ['nativeQueries' => 2])->call('sync');

        $fake->assertNativeQueries(3)->assertNativeQueriesAtMost(20);
        self::assertSame('insert', $fake->nativeQueries()[0]['kind']);
        self::assertSame('native', $fake->nativeQueries()[0]['connection']);
        self::assertSame(1, DB::connection('native')->table('remote_items')->count());
    }

    #[Test]
    public function native_transactions_record_only_the_outer_level(): void
    {
        $fake = $this->fakeWithNativeTable();

        DB::connection('native')->transaction(function () {
            DB::connection('native')->transaction(fn () => DB::connection('native')->table('remote_items')->insert(['name' => 'x']));
        });

        self::assertSame(['BEGIN', 'insert into "remote_items" ("name") values (?)', 'COMMIT'], array_column($fake->nativeQueries(), 'sql'));
    }

    #[Test]
    public function native_disk_operations_are_recorded_and_the_files_are_real(): void
    {
        $fake = NativeBlade::fake();

        Livewire::test(SyncProbe::class, ['write' => true])->call('sync');
        Storage::disk('native')->put(native_path('docs/report.txt', StoragePath::DOWNLOADS), 'two');

        $fake->assertFsOps(2)->assertFsOpsAtMost(20);
        self::assertSame(['type' => 'fs', 'op' => 'write', 'path' => 'out.txt', 'baseDir' => 'app'], $fake->fsOps()[0]);
        self::assertSame('hello', Storage::disk('native')->get(native_path('out.txt')));
        self::assertFileExists($fake->fsRoot() . '/downloads/docs/report.txt');
    }

    #[Test]
    public function pushed_actions_and_logs_are_captured(): void
    {
        $fake = NativeBlade::fake();

        Livewire::test(SyncProbe::class, ['push' => true, 'log' => true])->call('sync');

        $fake->assertActionPushed('vibrate')
            ->assertActionPushed('vibrate', fn (array $data) => $data['duration'] === 50)
            ->assertActionNotPushed('navigate')
            ->assertLogged('synced')
            ->assertLogged('synced', 'info')
            ->assertNotLogged('failed');
        self::assertCount(1, $fake->pushed());
        self::assertSame(['calls' => 1], $fake->logs()[0]['context']);
    }

    #[Test]
    public function reset_forgets_the_recordings(): void
    {
        $fake = NativeBlade::fake();
        Http::get('https://api.test/a');
        NativeBlade::log('x');

        $fake->reset();

        $fake->assertHttpCalls(0)->assertNotLogged('x');
    }

    #[Test]
    public function assertion_failures_list_what_was_recorded(): void
    {
        $fake = NativeBlade::fake();
        Http::get('https://api.test/items');

        $this->expectFailure(
            fn () => $fake->assertActionPushed('navigate'),
            "No 'navigate' action was pushed",
            'Nothing was pushed.',
        );
        $this->expectFailure(
            fn () => $fake->assertHttpCalled('POST', 'https://api.test/items'),
            '#1 GET https://api.test/items',
        );

        $fake->assertNothingPushed();
    }

    #[Test]
    public function notification_assertions_match_on_the_id(): void
    {
        $fake = NativeBlade::fake();

        NativeBlade::scheduleNotification(
            fn ($n) => $n->id('reminder-1')->title('Hi')->body('There')->at(now()->addHour())
        )->toResponse();

        $fake->assertNotificationScheduled()->assertNotificationScheduled('reminder-1');

        $this->expectFailure(
            fn () => $fake->assertNotificationScheduled('other'),
            "No 'notification' action matching",
        );
    }
}
