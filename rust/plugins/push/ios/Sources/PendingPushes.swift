import Foundation

/// Thread-safe buffer for push payloads and device tokens that arrive
/// before the JS layer attaches its listener (cold start from a tapped
/// notification, or silent push delivered to a suspended app).
///
/// The Rust/JS layer drains this queue via the `drainPending` plugin
/// command once the app is ready, then continues receiving live events
/// via Tauri's event system.
///
/// Callers consult `latestToken` when the plugin loads so tokens that
/// arrived before plugin instantiation aren't lost.
final class PendingPushes {
    private static let lock = NSLock()
    private static var queue: [[String: Any]] = []
    private static var _latestToken: String?

    static var latestToken: String? {
        lock.lock()
        defer { lock.unlock() }
        return _latestToken
    }

    static func setToken(_ token: String) {
        lock.lock()
        defer { lock.unlock() }
        _latestToken = token
    }

    static func enqueue(_ payload: [String: Any]) {
        lock.lock()
        defer { lock.unlock() }
        queue.append(payload)
    }

    static func drain() -> [[String: Any]] {
        lock.lock()
        defer { lock.unlock() }
        let drained = queue
        queue.removeAll()
        return drained
    }
}
