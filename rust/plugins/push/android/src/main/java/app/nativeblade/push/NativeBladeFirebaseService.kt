package app.nativeblade.push

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Receives FCM events on behalf of the host app.
 *
 * When a push arrives or the device token is refreshed, this service
 * either forwards the event directly to the NativeBlade plugin (if
 * the app is alive and the plugin has been instantiated), or buffers
 * it in [PendingPushes] so the plugin can drain it once it loads.
 *
 * Android creates this service on demand — even when the app was
 * fully killed — whenever Google Play Services delivers an FCM
 * intent with the matching action.
 */
class NativeBladeFirebaseService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        PendingPushes.setToken(token)
        NativeBladePushPlugin.instance?.emitToken(token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)

        val payload = mutableMapOf<String, Any?>(
            "id" to (message.messageId ?: ""),
            "data" to message.data,
            "state" to currentAppState(),
        )

        val notification = message.notification
        if (notification != null) {
            payload["notification"] = mapOf(
                "title" to notification.title,
                "body" to notification.body,
            )
        } else {
            payload["notification"] = mapOf<String, Any?>()
        }

        val plugin = NativeBladePushPlugin.instance
        if (plugin != null) {
            plugin.emitPush(payload)
        } else {
            PendingPushes.enqueue(payload)
        }
    }

    private fun currentAppState(): String {
        val plugin = NativeBladePushPlugin.instance
        return plugin?.currentAppState() ?: "cold"
    }
}
