<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for sensor operations, collected via
 * `NativeBlade::sensors(function (Sensor $s) { ... })`.
 *
 * Sensors are named by the `SensorType` enum — `ACCELEROMETER` (g),
 * `GYROSCOPE` (rad/s), `MAGNETOMETER` (μT), `BAROMETER` (hPa), `LIGHT`
 * (lux, Android only). One-shot answers arrive on
 * `nb:sensor`; watches stream on `nb:sensor-changed` at the requested
 * interval (100ms floor — sensor data crosses the native bridge into PHP,
 * so 200-500ms is the sane range). The optional `$id` is echoed back on the
 * events for routing.
 */
class Sensor
{
    /** @var array<int, array<string, mixed>> */
    private array $entries = [];

    /** Is this sensor present on the device? Answers instantly on `nb:sensor` with `available`. */
    public function available(SensorType $sensor, ?string $id = null): static
    {
        return $this->add('available', $sensor, $id);
    }

    /** One reading, now. Answers on `nb:sensor` (`available: false` when the sensor is missing). */
    public function read(SensorType $sensor, ?string $id = null): static
    {
        return $this->add('read', $sensor, $id);
    }

    /**
     * Start polling: readings stream on `nb:sensor-changed` every
     * `$intervalMs` until `stop()`. One watch per sensor (a new watch
     * replaces the previous).
     */
    public function watch(SensorType $sensor, int $intervalMs = 500, ?string $id = null): static
    {
        return $this->add('watch', $sensor, $id, ['intervalMs' => max($intervalMs, 100)]);
    }

    /** Stop a running watch. Silent no-op when nothing is being watched. */
    public function stop(SensorType $sensor): static
    {
        return $this->add('stop', $sensor, null);
    }

    private function add(string $op, SensorType $sensor, ?string $id, array $extra = []): static
    {
        $entry = array_merge(['op' => $op, 'sensor' => $sensor->value], $extra);
        if ($id !== null) {
            $entry['id'] = $id;
        }
        $this->entries[] = $entry;
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->entries;
    }
}
