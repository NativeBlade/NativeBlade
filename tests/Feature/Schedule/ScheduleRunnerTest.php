<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Schedule;

use Illuminate\Console\Scheduling\Schedule;
use NativeBlade\Schedule\ScheduleRunner;
use NativeBlade\Tests\Feature\Commands\WithTempBasePath;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * ScheduleRunner is a thin bridge between Laravel's scheduler and the Tauri
 * side-channel. It:
 *   - boots console.php once (we use a tempdir with no routes/ so boot is a no-op)
 *   - enumerates registered schedules with their cron + lastRun
 *   - runs a specific schedule by name and persists the run timestamp in
 *     nativeblade_state via the `nativeblade` singleton
 *   - swallows failures in getLastRun (try/catch → null)
 */
final class ScheduleRunnerTest extends TestCase
{
    use WithTempBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
        $this->resetBootedFlag();
    }

    protected function tearDown(): void
    {
        $this->resetBootedFlag();
        $this->tearDownTempBasePath();
        parent::tearDown();
    }

    private function resetBootedFlag(): void
    {
        (new ReflectionClass(ScheduleRunner::class))
            ->getProperty('booted')
            ->setValue(null, false);
    }

    private function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    // ---------------------------------------------------------------
    // extractSchedules
    // ---------------------------------------------------------------

    #[Test]
    public function extract_schedules_returns_empty_when_none_registered(): void
    {
        self::assertSame([], ScheduleRunner::extractSchedules());
    }

    #[Test]
    public function extract_schedules_returns_named_events_with_cron_and_last_run(): void
    {
        $this->schedule()
            ->call(fn () => null)
            ->name('cleanup-task')
            ->everyFiveMinutes();

        $result = ScheduleRunner::extractSchedules();

        self::assertCount(1, $result);
        self::assertSame('cleanup-task', $result[0]['name']);
        self::assertSame('*/5 * * * *', $result[0]['cron']);
        self::assertNull($result[0]['lastRun']); // no runs yet
    }

    #[Test]
    public function extract_schedules_reports_last_run_after_run_by_name(): void
    {
        $this->schedule()
            ->call(fn () => null)
            ->name('nightly-cron')
            ->dailyAt('02:00');

        self::assertTrue(ScheduleRunner::runByName('nightly-cron'));

        $result = ScheduleRunner::extractSchedules();
        self::assertCount(1, $result);
        self::assertIsInt($result[0]['lastRun']);
        // Sanity: the timestamp should be within the last minute.
        self::assertGreaterThanOrEqual(time() - 60, $result[0]['lastRun']);
        self::assertLessThanOrEqual(time() + 5, $result[0]['lastRun']);
    }

    #[Test]
    public function extract_schedules_filters_out_events_whose_name_is_literally_callback(): void
    {
        // The runner explicitly skips $name === 'Callback' (legacy summary value).
        // $event->description is a public property on Illuminate\Console\Scheduling\Event.
        $event = $this->schedule()->call(fn () => null)->everyMinute();
        $event->description = 'Callback';

        $result = ScheduleRunner::extractSchedules();

        self::assertSame([], $result, 'Events described as "Callback" must be dropped');
    }

    #[Test]
    public function extract_schedules_surfaces_multiple_named_events_in_order(): void
    {
        $this->schedule()->call(fn () => null)->name('alpha')->everyMinute();
        $this->schedule()->call(fn () => null)->name('beta')->hourly();

        $result = ScheduleRunner::extractSchedules();
        self::assertCount(2, $result);
        self::assertSame(['alpha', 'beta'], array_column($result, 'name'));
        self::assertSame(['* * * * *', '0 * * * *'], array_column($result, 'cron'));
    }

    // ---------------------------------------------------------------
    // runByName
    // ---------------------------------------------------------------

    #[Test]
    public function run_by_name_invokes_the_matching_event_callback(): void
    {
        $counter = new \stdClass();
        $counter->count = 0;

        $this->schedule()
            ->call(function () use ($counter) {
                $counter->count++;
            })
            ->name('bump')
            ->everyMinute();

        self::assertTrue(ScheduleRunner::runByName('bump'));
        self::assertSame(1, $counter->count);
    }

    #[Test]
    public function run_by_name_returns_false_when_no_event_matches(): void
    {
        $this->schedule()->call(fn () => null)->name('known')->everyMinute();

        self::assertFalse(ScheduleRunner::runByName('unknown'));
    }

    #[Test]
    public function run_by_name_persists_timestamp_in_nativeblade_state(): void
    {
        $this->schedule()
            ->call(fn () => null)
            ->name('persist-me')
            ->everyMinute();

        ScheduleRunner::runByName('persist-me');

        $stored = $this->app->make('nativeblade')
            ->getState('schedule.last_run.persist-me');

        self::assertNotNull($stored);
        self::assertIsNumeric($stored);
        self::assertGreaterThanOrEqual(time() - 60, (int) $stored);
    }

    #[Test]
    public function run_by_name_only_runs_the_matching_event_not_siblings(): void
    {
        $ran = ['a' => 0, 'b' => 0];

        $this->schedule()->call(function () use (&$ran) { $ran['a']++; })->name('a')->everyMinute();
        $this->schedule()->call(function () use (&$ran) { $ran['b']++; })->name('b')->everyMinute();

        ScheduleRunner::runByName('b');

        self::assertSame(0, $ran['a']);
        self::assertSame(1, $ran['b']);
    }

    // ---------------------------------------------------------------
    // getLastRun (via extractSchedules) — error fallback
    // ---------------------------------------------------------------

    #[Test]
    public function get_last_run_returns_null_when_nativeblade_binding_is_missing(): void
    {
        $this->schedule()->call(fn () => null)->name('orphan')->everyMinute();

        // Unbind the singleton so app('nativeblade') throws; getLastRun must
        // catch and return null rather than blow up extractSchedules().
        $this->app->forgetInstance('nativeblade');
        $this->app->offsetUnset('nativeblade');

        $result = ScheduleRunner::extractSchedules();
        self::assertCount(1, $result);
        self::assertNull($result[0]['lastRun']);
    }

    // ---------------------------------------------------------------
    // boot() idempotence
    // ---------------------------------------------------------------

    #[Test]
    public function boot_requires_console_php_only_once_even_across_multiple_calls(): void
    {
        // Create a routes/console.php that increments a counter on require.
        $routesDir = base_path('routes');
        if (!is_dir($routesDir)) mkdir($routesDir, 0755, true);

        // Use a file-scoped static to count requires without global state leaking.
        $marker = base_path('routes/_marker.txt');
        file_put_contents($marker, '');
        file_put_contents(
            base_path('routes/console.php'),
            "<?php file_put_contents('" . addslashes($marker) . "', file_get_contents('" . addslashes($marker) . "') . 'x');"
        );

        ScheduleRunner::extractSchedules();
        ScheduleRunner::extractSchedules();
        ScheduleRunner::extractSchedules();

        // require_once ensures the marker was written exactly once.
        self::assertSame('x', file_get_contents($marker));
    }
}
