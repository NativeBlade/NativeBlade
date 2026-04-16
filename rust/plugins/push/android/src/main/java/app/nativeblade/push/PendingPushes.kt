package app.nativeblade.push

import java.util.concurrent.ConcurrentLinkedQueue

/**
 * Thread-safe buffer for push payloads and token refreshes that arrive
 * before the JS layer attaches its listener (cold start from a tapped
 * notification).
 *
 * The Rust/JS layer drains this queue via the `drainPending` plugin
 * command once the app is ready, then continues receiving live events
 * via Tauri's event system.
 *
 * Callers also consult [latestToken] when the JS layer is ready so
 * tokens that arrived before attachment aren't lost.
 */
object PendingPushes {
    private val queue = ConcurrentLinkedQueue<Map<String, Any?>>()

    @Volatile
    var latestToken: String? = null
        private set

    fun enqueue(payload: Map<String, Any?>) {
        queue.add(payload)
    }

    fun setToken(token: String) {
        latestToken = token
    }

    fun drain(): List<Map<String, Any?>> {
        val drained = mutableListOf<Map<String, Any?>>()
        while (true) {
            val item = queue.poll() ?: break
            drained.add(item)
        }
        return drained
    }
}
