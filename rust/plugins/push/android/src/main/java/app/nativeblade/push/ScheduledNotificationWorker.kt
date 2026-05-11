package app.nativeblade.push

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

/**
 * WorkManager Worker that displays a notification on its run. Used for
 * both one-shot scheduled notifications (`OneTimeWorkRequest` with
 * `initialDelay`) and recurring ones (`PeriodicWorkRequest`).
 *
 * The user-visible payload is passed in via `inputData`, keyed by the
 * `KEY_*` constants below. We avoid serialising the whole JSObject so
 * the Worker can deserialize without a JSON dependency at run time.
 */
class ScheduledNotificationWorker(
    context: Context,
    params: WorkerParameters,
) : Worker(context, params) {

    override fun doWork(): Result {
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
        const val KEY_TITLE = "title"
        const val KEY_BODY = "body"
        const val KEY_CHANNEL = "channel"
        const val KEY_SOUND = "sound"
        const val KEY_ICON = "icon"
        const val KEY_TAG = "tag"
    }
}
