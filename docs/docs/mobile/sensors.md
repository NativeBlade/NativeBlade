---
title: "Sensors"
description: "Read device sensors as live streams."
---

# Sensors

Raw device sensors, SensorManager on Android, CoreMotion on iOS. Requires
`Plugin::SENSORS`. **No permissions**: raw readings are unrestricted on both
platforms (that's why the pedometer, which does need permissions, is not in
v1, see the end).

Sensors are named by the `SensorType` enum, with units following the Expo
convention:

| `SensorType` | Payload | Unit | Platforms |
|---|---|---|---|
| `ACCELEROMETER` | `x, y, z` | g (1g = 9.81 m/s²) | Android, iOS |
| `GYROSCOPE` | `x, y, z` | rad/s | Android, iOS |
| `MAGNETOMETER` | `x, y, z` | μT | Android, iOS |
| `BAROMETER` | `value` | hPa | Android, iOS |
| `LIGHT` | `value` | lux | Android only |

On desktop and web every operation reports `available: false`, so the same
handler code runs everywhere.

## Reading

One closure, four verbs. `available()` and `read()` answer on `nb:sensor`;
the optional `id` is echoed back for routing:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Sensor;
use NativeBlade\Plugins\SensorType;

public function checkTilt()
{
    return NativeBlade::sensors(function (Sensor $s) {
        $s->available(SensorType::BAROMETER, id: 'baro');
        $s->read(SensorType::ACCELEROMETER, id: 'tilt');
    })->toResponse();
}

#[On('nb:sensor')]
public function onSensor($sensor, $available, $id = null, $x = null, $y = null, $z = null, $value = null, $timestamp = null, $error = null)
{
    if ($id === 'tilt' && $available) {
        $this->isFlat = abs($z) > 0.95;   // lying flat on the table
    }
}
```

A one-shot read that gets no sample within 2 seconds reports
`available: false` (light sensors only report on *change*, a device sitting
in a dark drawer may genuinely have nothing to say).

## Polling (watch)

`watch()` streams readings on `nb:sensor-changed` until `stop()`. One watch
per sensor, starting a new one replaces the previous:

```php
public function startLevel()
{
    return NativeBlade::sensors(fn (Sensor $s) =>
        $s->watch(SensorType::ACCELEROMETER, intervalMs: 300, id: 'level')
    )->toResponse();
}

public function stopLevel()
{
    return NativeBlade::sensors(fn (Sensor $s) => $s->stop(SensorType::ACCELEROMETER))
        ->toResponse();
}

#[On('nb:sensor-changed')]
public function onReading($sensor, $x = null, $y = null, $z = null, $value = null, $id = null)
{
    $this->bubbleX = $x; // bubble level, compass, inclinometer…
    $this->bubbleY = $y;
}
```

**Frequency honesty:** every reading crosses the native bridge into a
Livewire event, so the native floor is **100ms** and the sane range is
**200–500ms**, plenty for levels, compasses and orientation UI. Sixty-hertz
games are not what a PHP-driven app should attempt; if you ever need
high-frequency motion, handle it in JS (a custom plugin) and hand PHP the
conclusions. Remember to `stop()` when leaving the screen, watches don't
know your navigation.

## Events

| Event | Payload | When |
|---|---|---|
| `nb:sensor` | `sensor`, `available`, `id`, `x/y/z` or `value`, `timestamp`, `error` | Response to `available()` / `read()` (and a failed `watch()`) |
| `nb:sensor-changed` | same, minus `error` | Each throttled reading of a watch |

## Out of scope for v1

- **Pedometer**, needs `ACTIVITY_RECOGNITION` (Android, Play declaration)
  and `NSMotionUsageDescription` (iOS), plus its own counting semantics.
  Planned as a separate, permission-aware addition.
- **DeviceMotion** (fused orientation/attitude), composite of the raw
  sensors; compute what you need from them meanwhile.
- Ambient light on iOS, no public API exists; `LIGHT` reports unavailable.

## See Also

- [Plugins](/core/plugins/), the `NativeBlade` facade
- [Background Tasks](/mobile/tasks/), background collectors (`withLocation()`) share this plugin's philosophy
