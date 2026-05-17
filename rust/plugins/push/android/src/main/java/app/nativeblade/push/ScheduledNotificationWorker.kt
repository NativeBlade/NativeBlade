package app.nativeblade.push

import android.content.Context
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters
import androidx.work.WorkManager

/**
 * WorkManager Worker that displays a notification on its run. Used for
 * both one-shot scheduled notifications (`OneTimeWorkRequest` with
 * `initialDelay`) and recurring ones (`PeriodicWorkRequest`).
 */
class ScheduledNotificationWorker(
    context: Context,
    params: WorkerParameters,
) : Worker(context, params) {

    override fun doWork(): Result {
        val version = inputData.getInt(KEY_VERSION_MARKER, 0)
        if (version < CURRENT_VERSION) {
            Log.i(TAG, "Cancelling orphan schedule (marker=$version, current=$CURRENT_VERSION) tags=$tags")
            try {
                val wm = WorkManager.getInstance(applicationContext)
                wm.cancelAllWorkByTag("nb_notification")
                wm.cancelAllWorkByTag("nb_notification_v2")
            } catch (e: Throwable) {
                Log.w(TAG, "Orphan cancel failed", e)
            }
            return Result.success()
        }

        val title = inputData.getString(KEY_TITLE)
        val body = inputData.getString(KEY_BODY)
        val channel = inputData.getString(KEY_CHANNEL)
        val sound = inputData.getString(KEY_SOUND)
        val icon = inputData.getString(KEY_ICON)
        val tag = inputData.getInt(KEY_TAG, System.currentTimeMillis().toInt())

        NotificationDisplay.show(
            context = applicationContext,
            title = title,
            body = body,
            channelId = channel,
            sound = sound,
            smallIconName = icon,
            tag = tag,
        )
        return Result.success()
    }

    companion object {
        private const val TAG = "NativeBladePush"

        const val KEY_TITLE = "title"
        const val KEY_BODY = "body"
        const val KEY_CHANNEL = "channel"
        const val KEY_SOUND = "sound"
        const val KEY_ICON = "icon"
        const val KEY_TAG = "tag"
        const val KEY_VERSION_MARKER = "_v"
        const val CURRENT_VERSION = 2
    }
}
