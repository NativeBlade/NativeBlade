<?php

namespace NativeBlade\Plugins;

/**
 * Fluent dispatcher for background-task queues.
 *
 * Collected via `NativeBlade::task(function (Task $t) { ... })`. Each
 * `dispatch()` parks one payload in the named queue's outbox (declared with
 * `BackgroundTask::queue(...)` in the provider — the Laravel analogy: the
 * provider declaration is `config/queue.php`, this is `dispatch()`). Entries
 * are delivered oldest-first as soon as connectivity allows, including via
 * an OS wake with the app closed.
 */
class Task
{
    /** @var array<int, array{name: string, payload: array<string, mixed>}> */
    private array $entries = [];

    /**
     * Park one payload (JSON object, up to 1 MB) in a queue task's outbox.
     * Call as many times as needed — order is preserved — and mix queues
     * freely. Each entry is acked individually on `nb:task-queued`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $name, array $payload): static
    {
        $this->entries[] = ['name' => $name, 'payload' => $payload];
        return $this;
    }

    /** @return array<int, array{name: string, payload: array<string, mixed>}> */
    public function toArray(): array
    {
        return $this->entries;
    }
}
