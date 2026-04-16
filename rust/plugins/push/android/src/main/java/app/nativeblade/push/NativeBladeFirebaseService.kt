package app.nativeblade.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.app.PendingIntent
import android.os.Build
import androidx.core.app.NotificationCompat
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

        if (notification != null && currentAppState() == "foreground") {
            showSystemNotification(notification.title, notification.body, message.messageId)
        }
    }

    private fun showSystemNotification(title: String?, body: String?, messageId: String?) {
        if (title == null && body == null) return

        val channelId = "nativeblade_push"
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val existing = manager.getNotificationChannel(channelId)
            if (existing == null) {
                val channel = NotificationChannel(
                    channelId,
                    "Push notifications",
                    NotificationManager.IMPORTANCE_HIGH
                )
                manager.createNotificationChannel(channel)
            }
        }

        val launchIntent = packageManager.getLaunchIntentForPackage(packageName)?.apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pendingIntent = launchIntent?.let {
            PendingIntent.getActivity(
                this,
                0,
                it,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }

        val icon = applicationInfo.icon.takeIf { it != 0 } ?: android.R.drawable.ic_dialog_info
        val builder = NotificationCompat.Builder(this, channelId)
            .setSmallIcon(icon)
            .setContentTitle(title ?: "")
            .setContentText(body ?: "")
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)

        if (pendingIntent != null) builder.setContentIntent(pendingIntent)

        val notificationId = messageId?.hashCode() ?: System.currentTimeMillis().toInt()
        manager.notify(notificationId, builder.build())
    }

    private fun currentAppState(): String {
        val plugin = NativeBladePushPlugin.instance
        return plugin?.currentAppState() ?: "cold"
    }
}
