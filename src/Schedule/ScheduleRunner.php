<?php

namespace NativeBlade\Schedule;

use Illuminate\Console\Scheduling\Schedule;

class ScheduleRunner
{
    private static bool $booted = false;

    private static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        $consolePath = base_path('routes/console.php');
        if (file_exists($consolePath)) {
            require_once $consolePath;
        }
    }

    public static function extractSchedules(): array
    {
        self::boot();
        $schedule = app(Schedule::class);
        $events = $schedule->events();
        $schedules = [];

        foreach ($events as $event) {
            $name = $event->description ?? $event->getSummaryForDisplay();
            if (!$name || $name === 'Callback') continue;

            $lastRun = self::getLastRun($name);

            $schedules[] = [
                'name' => $name,
                'cron' => $event->expression,
                'lastRun' => $lastRun,
            ];
        }

        return $schedules;
    }

    private static function getLastRun(string $name): ?int
    {
        try {
            $value = app('nativeblade')->getState("schedule.last_run.{$name}");
            return $value ? (int) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function runByName(string $name): bool
    {
        self::boot();
        $schedule = app(Schedule::class);
        $events = $schedule->events();

        foreach ($events as $event) {
            $eventName = $event->description ?? $event->getSummaryForDisplay();
            if ($eventName !== $name) {
                continue;
            }

            // Advance last_run the moment the occurrence is considered, before any
            // filter decision. extractSchedules() feeds this timestamp back to the
            // native scheduler as `lastRun`, so a task whose when()/skip() rejects
            // the run still moves past this tick instead of re-firing on every open.
            app('nativeblade')->setState("schedule.last_run.{$name}", now()->timestamp);

            // run() does NOT evaluate when()/skip()/between()/environments() — those
            // constraints live in the filters/rejects arrays and only filtersPass()
            // checks them. Honor them so conditional schedules behave like Laravel's.
            if (! $event->filtersPass(app())) {
                return false;
            }

            $event->run(app());

            // Only a real execution updates last_success, keeping it distinct from
            // last_run (which advances on every considered tick, filtered or not).
            app('nativeblade')->setState("schedule.last_success.{$name}", now()->timestamp);

            return true;
        }

        return false;
    }
}
