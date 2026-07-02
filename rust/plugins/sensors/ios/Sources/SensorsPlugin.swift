import CoreMotion
import Foundation
import SwiftRs
import Tauri
import WebKit

struct ReadArgs: Decodable {
    let sensor: String
    let id: String?
}

struct WatchArgs: Decodable {
    let sensor: String
    let intervalMs: Double?
    let id: String?
}

/**
 * Raw sensor access via CoreMotion. Units follow the Expo convention:
 * accelerometer in g (CoreMotion native), gyroscope in rad/s, magnetometer
 * in μT, barometer in hPa (CMAltimeter reports kPa, converted). The ambient
 * light sensor has no public API on iOS — `light` reports unavailable.
 * No permissions: raw readings are unrestricted (NSMotionUsageDescription
 * is only needed by CMPedometer/DeviceMotion activity, which are not used).
 */
class SensorsPlugin: Plugin {
    private let motion = CMMotionManager()
    private let altimeter = CMAltimeter()
    private let queue = OperationQueue()
    // Sensors with an active watch; one-shots piggyback on the same underlying
    // CoreMotion stream and stop it unless a watch owns it.
    private var watching = Set<String>()

    @objc public func isAvailable(_ invoke: Invoke) {
        guard let args = try? invoke.parseArgs(ReadArgs.self) else {
            invoke.reject("invalid args")
            return
        }
        let ok: Bool
        switch args.sensor {
        case "accelerometer": ok = motion.isAccelerometerAvailable
        case "gyroscope": ok = motion.isGyroAvailable
        case "magnetometer": ok = motion.isMagnetometerAvailable
        case "barometer": ok = CMAltimeter.isRelativeAltitudeAvailable()
        default: ok = false
        }
        invoke.resolve(["sensor": args.sensor, "id": args.id as Any, "available": ok])
    }

    @objc public func readSensor(_ invoke: Invoke) {
        guard let args = try? invoke.parseArgs(ReadArgs.self) else {
            invoke.reject("invalid args")
            return
        }
        let started = start(args.sensor, id: args.id, intervalMs: 100, once: true) { [weak self] payload in
            if !(self?.watching.contains(args.sensor) ?? false) {
                self?.stop(args.sensor)
            }
            invoke.resolve(payload ?? Self.unavailable(args.sensor, args.id))
        }
        if !started {
            // Unsupported/missing sensor: start() never schedules the 2s
            // timeout, so answer here or the JS promise hangs forever.
            invoke.resolve(Self.unavailable(args.sensor, args.id))
        }
    }

    @objc public func watchSensor(_ invoke: Invoke) {
        guard let args = try? invoke.parseArgs(WatchArgs.self) else {
            invoke.reject("invalid args")
            return
        }
        let interval = max(args.intervalMs ?? 500, 100) // floor: PHP round-trips
        watching.insert(args.sensor)
        let started = start(args.sensor, id: args.id, intervalMs: interval, once: false) { [weak self] payload in
            if let payload = payload {
                self?.emit(payload)
            }
        }
        if started {
            invoke.resolve(["watching": true])
        } else {
            watching.remove(args.sensor)
            invoke.resolve(Self.unavailable(args.sensor, args.id))
        }
    }

    @objc public func stopSensor(_ invoke: Invoke) {
        guard let args = try? invoke.parseArgs(ReadArgs.self) else {
            invoke.reject("invalid args")
            return
        }
        watching.remove(args.sensor)
        stop(args.sensor)
        invoke.resolve()
    }

    @discardableResult
    private func start(
        _ sensor: String,
        id: String?,
        intervalMs: Double,
        once: Bool,
        handler: @escaping ([String: Any]?) -> Void
    ) -> Bool {
        let interval = intervalMs / 1000.0
        var fired = false
        let deliver: ([String: Any]?) -> Void = { payload in
            if once {
                if fired { return }
                fired = true
            }
            handler(payload)
        }

        switch sensor {
        case "accelerometer":
            guard motion.isAccelerometerAvailable else { return false }
            motion.accelerometerUpdateInterval = interval
            motion.startAccelerometerUpdates(to: queue) { data, _ in
                guard let a = data?.acceleration else { return }
                deliver(Self.xyz(sensor, id, a.x, a.y, a.z))
            }
        case "gyroscope":
            guard motion.isGyroAvailable else { return false }
            motion.gyroUpdateInterval = interval
            motion.startGyroUpdates(to: queue) { data, _ in
                guard let r = data?.rotationRate else { return }
                deliver(Self.xyz(sensor, id, r.x, r.y, r.z))
            }
        case "magnetometer":
            guard motion.isMagnetometerAvailable else { return false }
            motion.magnetometerUpdateInterval = interval
            motion.startMagnetometerUpdates(to: queue) { data, _ in
                guard let f = data?.magneticField else { return }
                deliver(Self.xyz(sensor, id, f.x, f.y, f.z))
            }
        case "barometer":
            guard CMAltimeter.isRelativeAltitudeAvailable() else { return false }
            altimeter.startRelativeAltitudeUpdates(to: queue) { data, _ in
                guard let kpa = data?.pressure.doubleValue else { return }
                deliver([
                    "sensor": sensor,
                    "id": id as Any,
                    "available": true,
                    "value": kpa * 10.0, // kPa → hPa
                    "timestamp": Int(Date().timeIntervalSince1970),
                ])
            }
        default:
            return false // "light" has no public API on iOS
        }

        if once {
            // Never leak the invoke if the sensor stays silent.
            DispatchQueue.main.asyncAfter(deadline: .now() + 2.0) {
                deliver(nil)
            }
        }
        return true
    }

    private func stop(_ sensor: String) {
        switch sensor {
        case "accelerometer": motion.stopAccelerometerUpdates()
        case "gyroscope": motion.stopGyroUpdates()
        case "magnetometer": motion.stopMagnetometerUpdates()
        case "barometer": altimeter.stopRelativeAltitudeUpdates()
        default: break
        }
    }

    private func emit(_ payload: [String: Any]) {
        var data = JSObject()
        for (k, v) in payload {
            if let b = v as? Bool { data[k] = b }
            else if let d = v as? Double { data[k] = d }
            else if let i = v as? Int { data[k] = i }
            else if let s = v as? String { data[k] = s }
        }
        trigger("sensor-changed", data: data)
    }

    private static func xyz(_ sensor: String, _ id: String?, _ x: Double, _ y: Double, _ z: Double) -> [String: Any] {
        return [
            "sensor": sensor,
            "id": id as Any,
            "available": true,
            "x": x,
            "y": y,
            "z": z,
            "timestamp": Int(Date().timeIntervalSince1970),
        ]
    }

    private static func unavailable(_ sensor: String, _ id: String? = nil) -> [String: Any] {
        return ["sensor": sensor, "id": id as Any, "available": false]
    }
}

@_cdecl("init_plugin_nativeblade_sensors")
func initPlugin() -> Plugin {
    return SensorsPlugin()
}
