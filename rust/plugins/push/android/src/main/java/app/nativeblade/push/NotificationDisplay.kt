package app.nativeblade.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat

/**
 * Shared helper for posting a local notification through the system
 * NotificationManager. Used both by FCM push delivery and by the local
 * `notify` / scheduled-work code path so channel creation, intent
 * wiring, and small-icon resolution live in one place.
 */
object NotificationDisplay {
    const val DEFAULT_CHANNEL = "nativeblade_default_v2"

    /**
     * Show a notification immediately. [tag] is the deterministic numeric
     * id derived from the user-provided string id (so cancel-by-id works);
     * pass `null` to generate a fresh one each time.
     */
    fun show(
        context: Context,
        title: String?,
        body: String?,
        channelId: String? = null,
        sound: String? = null,
        smallIconName: String? = null,
        tag: Int? = null,
    ) {
        if (title.isNullOrBlank() && body.isNullOrBlank()) return

        val effectiveChannel = channelId ?: DEFAULT_CHANNEL
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        ensureChannel(manager, effectiveChannel)

        val launchIntent = context.packageManager
            .getLaunchIntentForPackage(context.packageName)?.apply {
                flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            }
        val pendingIntent = launchIntent?.let {
            PendingIntent.getActivity(
                context,
                0,
                it,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }

        val smallIcon = resolveIcon(context, smallIconName)
            ?: (context.applicationInfo.icon.takeIf { it != 0 })
            ?: android.R.drawable.ic_dialog_info

        val builder = NotificationCompat.Builder(context, effectiveChannel)
            .setSmallIcon(smallIcon)
            .setContentTitle(title ?: "")
            .setContentText(body ?: "")
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)

        if (pendingIntent != null) builder.setContentIntent(pendingIntent)
        applySound(builder, context, sound)

        manager.notify(tag ?: System.currentTimeMillis().toInt(), builder.build())
    }

    private fun ensureChannel(manager: NotificationManager, channelId: String) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        if (manager.getNotificationChannel(channelId) != null) return

        val channel = NotificationChannel(
            channelId,
            channelId.replaceFirstChar { it.titlecase() },
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply {
            enableLights(true)
            enableVibration(true)
        }
        manager.createNotificationChannel(channel)
    }

    private fun resolveIcon(context: Context, name: String?): Int? {
        if (name.isNullOrBlank()) return null
        val res = context.resources.getIdentifier(name, "drawable", context.packageName)
        return if (res != 0) res else null
    }

    private fun applySound(
        builder: NotificationCompat.Builder,
        context: Context,
        sound: String?,
    ) {
        when {
            sound.isNullOrBlank() -> {
                builder.setDefaults(NotificationCompat.DEFAULT_SOUND)
            }
            sound == "default" -> {
                builder.setDefaults(NotificationCompat.DEFAULT_SOUND)
            }
            else -> {
                val res = context.resources.getIdentifier(sound, "raw", context.packageName)
                if (res != 0) {
                    val uri = Uri.parse("android.resource://${context.packageName}/$res")
                    builder.setSound(uri)
                } else {
                    builder.setDefaults(NotificationCompat.DEFAULT_SOUND)
                }
            }
        }
    }

    /**
     * Deterministic hash matching the JS-side `hashId`. Keeping this in
     * sync ensures the same user id maps to the same Android notification
     * tag across JS and native invocations.
     */
    fun hashId(userId: String): Int {
        var h = 5381
        for (c in userId) {
            h = ((h shl 5) + h + c.code) or 0
        }
        return if (h < 0) -h else h
    }
}
