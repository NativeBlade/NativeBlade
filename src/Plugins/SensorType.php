<?php

namespace NativeBlade\Plugins;

/**
 * The sensors NativeBlade can read, with their units (Expo convention).
 */
enum SensorType: string
{
    /** Acceleration including gravity, in g (1g = 9.81 m/s²). x/y/z. */
    case ACCELEROMETER = 'accelerometer';

    /** Rotation rate in rad/s. x/y/z. */
    case GYROSCOPE = 'gyroscope';

    /** Magnetic field in μT. x/y/z. */
    case MAGNETOMETER = 'magnetometer';

    /** Atmospheric pressure in hPa. `value`. */
    case BAROMETER = 'barometer';

    /** Ambient light in lux. `value`. Android only — reports unavailable on iOS. */
    case LIGHT = 'light';
}
