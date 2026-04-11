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
            if ($eventName === $name) {
                $event->run(app());
                app('nativeblade')->setState("schedule.last_run.{$name}", now()->timestamp);
                return true;
            }
        }

        return false;
    }
}
