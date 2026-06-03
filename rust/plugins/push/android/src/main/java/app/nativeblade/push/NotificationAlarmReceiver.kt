package app.nativeblade.push

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Fires when an AlarmManager notification alarm goes off. Shows the
 * notification and, for daily schedules, re-arms the next occurrence
 * (`setAndAllowWhileIdle` is one-shot, so a repeating daily reminder chains
 * itself forward a day at a time).
 */
class NotificationAlarmReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val ctx = context.applicationContext
        val userId = intent.getStringExtra(NotificationAlarms.EXTRA_USER_ID)
        val title = intent.getStringExtra(NotificationAlarms.EXTRA_TITLE)
        val body = intent.getStringExtra(NotificationAlarms.EXTRA_BODY)
        val channel = intent.getStringExtra(NotificationAlarms.EXTRA_CHANNEL)
        val sound = intent.getStringExtra(NotificationAlarms.EXTRA_SOUND)
        val icon = intent.getStringExtra(NotificationAlarms.EXTRA_ICON)
        val tag = intent.getIntExtra(NotificationAlarms.EXTRA_TAG, System.currentTimeMillis().toInt())
        val dailyTime = intent.getStringExtra(NotificationAlarms.EXTRA_DAILY_TIME)

        NotificationDisplay.show(
            context = ctx,
            title = title,
            body = body,
            channelId = channel,
            sound = sound,
            smallIconName = icon,
            tag = tag,
        )

        if (userId == null) return

        if (dailyTime != null) {
            NotificationAlarms.schedule(
                context = ctx,
                userId = userId,
                tag = tag,
                triggerAtMs = NotificationAlarms.nextDailyTriggerMs(dailyTime),
                title = title,
                body = body,
                channel = channel,
                sound = sound,
                icon = icon,
                dailyTime = dailyTime,
            )
        } else {
            NotificationAlarms.forget(ctx, userId)
        }
    }
}
