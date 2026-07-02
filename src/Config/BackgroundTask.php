<?php

namespace NativeBlade\Config;

/**
 * Fluent definition of one background task, declared via
 * `NativeBladeConfig::backgroundTasks([...])`.
 *
 * Tasks follow the courier model: the work is native (Rust) — a `fetch` GETs
 * a URL and parks the response for the app to consume, a `post` fires a
 * payload (with an outbox retrying failures). PHP declares here and consumes
 * later: pull with `NativeBlade::getTask($name)` (event `nb:task`), or push
 * with a `->handler()` class invoked on app open. No PHP runs in background.
 */
class BackgroundTask
{
    /** @var array<string, mixed> */
    private array $data;

    private function __construct(string $name, string $kind, string $url)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Background task name '{$name}' must be lowercase [a-z0-9_-] (it becomes a directory and a scheduler id)."
            );
        }

        $this->data = [
            'name' => $name,
            'kind' => $kind,
            'url' => $url,
            'everyMinutes' => 60,
        ];
    }

    /** GET the URL and park the response; consume via `getTask()` or a handler. */
    public static function fetch(string $name, string $url): static
    {
        return new static($name, 'fetch', $url);
    }

    /** POST a payload (fixed body + collected data) fire-and-forget, with an outbox for failures. */
    public static function post(string $name, string $url): static
    {
        return new static($name, 'post', $url);
    }

    /** Run cadence. OS schedulers floor this at 15 minutes. */
    public function every(int $minutes = 0, int $hours = 0, int $days = 0): static
    {
        $total = $minutes + ($hours * 60) + ($days * 24 * 60);
        if ($total < 15) {
            throw new \InvalidArgumentException(
                "Background task '{$this->data['name']}': minimum interval is 15 minutes (got {$total})."
            );
        }
        $this->data['everyMinutes'] = $total;
        return $this;
    }

    /** Keep only the newest response (typical for "current state" fetches like prices). */
    public function latestOnly(bool $latestOnly = true): static
    {
        $this->data['latestOnly'] = $latestOnly;
        return $this;
    }

    /** Static header sent on every run. */
    public function header(string $name, string $value): static
    {
        $this->data['headers'][] = [$name, $value];
        return $this;
    }

    /**
     * Authorization bearer read from secure storage at send time (never
     * written to the task manifest or the parking store).
     */
    public function bearerFromSecure(string $key): static
    {
        $this->data['bearerFromSecure'] = $key;
        return $this;
    }

    /** Fixed body fields for post tasks, merged with collected data. */
    public function body(array $fields): static
    {
        $this->data['body'] = $fields;
        return $this;
    }

    /** Collect a location fix at send time and merge it into the payload as `location`. */
    public function withLocation(bool $withLocation = true): static
    {
        $this->data['withLocation'] = $withLocation;
        return $this;
    }

    /**
     * Handler class (`app/Native/Tasks/...`) invoked on app open with each
     * queued result. Without a handler, consume with `NativeBlade::getTask()`.
     */
    public function handler(string $class): static
    {
        $this->data['handler'] = $class;
        return $this;
    }

    /** Only run with usable internet (OS-level constraint; the device is not even woken without it). */
    public function requiresNetwork(bool $requires = true): static
    {
        $this->data['requiresNetwork'] = $requires;
        return $this;
    }

    /** Only run on a non-metered connection (wifi/ethernet). */
    public function requiresUnmetered(bool $requires = true): static
    {
        $this->data['requiresUnmetered'] = $requires;
        return $this;
    }

    /** Only run while charging. */
    public function requiresCharging(bool $requires = true): static
    {
        $this->data['requiresCharging'] = $requires;
        return $this;
    }

    /** Also run on a timer while the app is open (default true). */
    public function runWhileOpen(bool $run = true): static
    {
        $this->data['runWhileOpen'] = $run;
        return $this;
    }

    /** Run immediately at app open when a scheduled run was missed (default true). */
    public function catchUpOnOpen(bool $catchUp = true): static
    {
        $this->data['catchUpOnOpen'] = $catchUp;
        return $this;
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
