package app.nativeblade.sensors

import android.app.Activity
import android.content.Context
import android.hardware.Sensor
import android.hardware.SensorEvent
import android.hardware.SensorEventListener
import android.hardware.SensorManager
import android.os.SystemClock
import app.tauri.annotation.Command
import app.tauri.annotation.InvokeArg
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin

@InvokeArg
class ReadArgs {
    lateinit var sensor: String
    var id: String? = null
}

@InvokeArg
class WatchArgs {
    lateinit var sensor: String
    var intervalMs: Long = 500
    var id: String? = null
}

/**
 * Raw sensor access. Units follow the Expo convention devs already know:
 * accelerometer in g, gyroscope in rad/s, magnetometer in μT, barometer in
 * hPa, light in lux. Watches are throttled JS-side friendly: the OS listener
 * may fire faster, but events are forwarded at most once per interval.
 */
@TauriPlugin
class SensorsPlugin(private val activity: Activity) : Plugin(activity) {

    private val manager: SensorManager
        get() = activity.getSystemService(Context.SENSOR_SERVICE) as SensorManager

    // One active watch listener per sensor type.
    private val watchers = HashMap<String, SensorEventListener>()

    @Command
    fun isAvailable(invoke: Invoke) {
        val args = invoke.parseArgs(ReadArgs::class.java)
        val obj = JSObject()
        obj.put("sensor", args.sensor)
        obj.put("id", args.id)
        obj.put("available", resolve(args.sensor) != null)
        invoke.resolve(obj)
    }

    @Command
    fun readSensor(invoke: Invoke) {
        val args = invoke.parseArgs(ReadArgs::class.java)
        val sensor = resolve(args.sensor) ?: run {
            invoke.resolve(unavailable(args.sensor, args.id))
            return
        }

        // One-shot: register, take the first sample, unregister.
        var done = false
        val listener = object : SensorEventListener {
            override fun onSensorChanged(event: SensorEvent) {
                if (done) return
                done = true
                manager.unregisterListener(this)
                invoke.resolve(payload(args.sensor, args.id, event))
            }

            override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}
        }
        manager.registerListener(listener, sensor, SensorManager.SENSOR_DELAY_UI)

        // A sensor that never fires (rare, but light sensors only report on
        // change) must not leak the invoke: give it two seconds.
        android.os.Handler(activity.mainLooper).postDelayed({
            if (!done) {
                done = true
                manager.unregisterListener(listener)
                invoke.resolve(unavailable(args.sensor, args.id))
            }
        }, 2000)
    }

    @Command
    fun watchSensor(invoke: Invoke) {
        val args = invoke.parseArgs(WatchArgs::class.java)
        val sensor = resolve(args.sensor) ?: run {
            invoke.resolve(unavailable(args.sensor, args.id))
            return
        }
        val interval = args.intervalMs.coerceAtLeast(100) // floor: PHP round-trips

        stop(args.sensor)
        var lastEmit = 0L
        val listener = object : SensorEventListener {
            override fun onSensorChanged(event: SensorEvent) {
                val now = SystemClock.elapsedRealtime()
                if (now - lastEmit < interval) return
                lastEmit = now
                trigger("sensor-changed", payload(args.sensor, args.id, event))
            }

            override fun onAccuracyChanged(sensor: Sensor?, accuracy: Int) {}
        }
        watchers[args.sensor] = listener
        manager.registerListener(listener, sensor, (interval * 1000).toInt())

        val ok = JSObject()
        ok.put("watching", true)
        invoke.resolve(ok)
    }

    @Command
    fun stopSensor(invoke: Invoke) {
        val args = invoke.parseArgs(ReadArgs::class.java)
        stop(args.sensor)
        invoke.resolve()
    }

    private fun stop(type: String) {
        watchers.remove(type)?.let { manager.unregisterListener(it) }
    }

    private fun resolve(type: String): Sensor? = when (type) {
        "accelerometer" -> manager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER)
        "gyroscope" -> manager.getDefaultSensor(Sensor.TYPE_GYROSCOPE)
        "magnetometer" -> manager.getDefaultSensor(Sensor.TYPE_MAGNETIC_FIELD)
        "barometer" -> manager.getDefaultSensor(Sensor.TYPE_PRESSURE)
        "light" -> manager.getDefaultSensor(Sensor.TYPE_LIGHT)
        else -> null
    }

    private fun payload(type: String, id: String?, event: SensorEvent): JSObject {
        val obj = JSObject()
        obj.put("sensor", type)
        obj.put("id", id)
        obj.put("available", true)
        obj.put("timestamp", System.currentTimeMillis() / 1000)
        when (type) {
            // Android reports m/s²; Expo-style g for portability.
            "accelerometer" -> {
                obj.put("x", event.values[0] / SensorManager.GRAVITY_EARTH)
                obj.put("y", event.values[1] / SensorManager.GRAVITY_EARTH)
                obj.put("z", event.values[2] / SensorManager.GRAVITY_EARTH)
            }
            "gyroscope", "magnetometer" -> {
                obj.put("x", event.values[0])
                obj.put("y", event.values[1])
                obj.put("z", event.values[2])
            }
            "barometer" -> obj.put("value", event.values[0])
            "light" -> obj.put("value", event.values[0])
        }
        return obj
    }

    private fun unavailable(type: String, id: String? = null): JSObject {
        val obj = JSObject()
        obj.put("sensor", type)
        obj.put("id", id)
        obj.put("available", false)
        return obj
    }
}
