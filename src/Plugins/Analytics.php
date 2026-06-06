<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a batch of Firebase Analytics operations.
 *
 * Collected via the `NativeBlade::analytics(function (Analytics $a) { ... })`
 * closure, then dispatched as a single `analytics` action whose `ops` the
 * native plugin applies in order. Mirrors the other closure builders
 * (Notification, Dialog, ...).
 */
class Analytics
{
    /** @var array<int, array<string, mixed>> */
    private array $ops = [];

    /** Log a custom event with optional parameters. */
    public function event(string $name, array $params = []): static
    {
        $this->ops[] = ['op' => 'event', 'name' => $name, 'params' => $params];
        return $this;
    }

    /** Log a screen view manually (auto screen tracking can also be enabled in config). */
    public function screen(string $name): static
    {
        $this->ops[] = ['op' => 'screen', 'name' => $name];
        return $this;
    }

    /** Set (or clear, with null) the analytics user id. */
    public function setUserId(?string $id): static
    {
        $this->ops[] = ['op' => 'userId', 'value' => $id];
        return $this;
    }

    /** Set (or clear, with null) a user property. */
    public function setUserProperty(string $key, ?string $value): static
    {
        $this->ops[] = ['op' => 'userProperty', 'key' => $key, 'value' => $value];
        return $this;
    }

    /** Turn analytics collection on at the SDK level (persists across launches). */
    public function enable(): static
    {
        $this->ops[] = ['op' => 'setEnabled', 'enabled' => true];
        return $this;
    }

    /** Turn analytics collection off at the SDK level (persists across launches). */
    public function disable(): static
    {
        $this->ops[] = ['op' => 'setEnabled', 'enabled' => false];
        return $this;
    }

    /** @return array{ops: array<int, array<string, mixed>>} */
    public function toArray(): array
    {
        return ['ops' => $this->ops];
    }
}
