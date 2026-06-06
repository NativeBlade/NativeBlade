package app.nativeblade.push

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import java.util.Calendar

/**
 * Schedules local notifications through AlarmManager so they fire at (or very
 * near) the requested wall-clock time even while the app is closed and the
 * device is in Doze.
 *
 * WorkManager is deliberately NOT used for one-shot (`at`) and daily
 * (`dailyAt`) notifications: it batches deferrable work and skips exact timing
 * during Doze, which silently drops an overnight reminder.
 *
 * By default alarms are inexact (`setAndAllowWhileIdle`: a few minutes of slack,
 * no special permission). When the caller opts in via `exact = true` and the app
 * holds USE_EXACT_ALARM (Permission::EXACT_ALARM), they use
 * `setExactAndAllowWhileIdle` and land on the exact second even in deep Doze;
 * if exact isn't permitted at runtime it degrades back to inexact.
 *
 * Alarms do not survive a device reboot; the app re-arms them on next launch
 * through its own reconcile pass.
 */
object NotificationAlarms {
    private const val TAG = "NativeBladePush"
    private const val PREFS = "nb_notification_alarms"
    private const val KEY_IDS = "ids"

    const val EXTRA_USER_ID = "nb_user_id"
    const val EXTRA_TITLE = "title"
    const val EXTRA_BODY = "body"
    const val EXTRA_CHANNEL = "channel"
    const val EXTRA_SOUND = "sound"
    const val EXTRA_ICON = "icon"
    const val EXTRA_TAG = "tag"
    const val EXTRA_DAILY_TIME = "daily_time"
    const val EXTRA_EXACT = "exact"

    /**
     * Arm an alarm for [triggerAtMs]. When [dailyTime] is non-null the receiver
     * re-arms the next occurrence after firing, turning a one-shot alarm into a
     * self-chaining daily reminder. When [exact] is true the alarm fires on the
     * exact second even in Doze (needs the USE_EXACT_ALARM permission — the app
     * opts in via Permission::EXACT_ALARM); otherwise it is inexact (a few
     * minutes of slack, no permission required). The flag is carried in the
     * intent so a daily re-arm keeps the same precision.
     */
    fun schedule(
        context: Context,
        userId: String,
        tag: Int,
        triggerAtMs: Long,
        title: String?,
        body: String?,
        channel: String?,
        sound: String?,
        icon: String?,
        dailyTime: String?,
        exact: Boolean = false,
    ) {
        val intent = intentFor(context, userId).apply {
            putExtra(EXTRA_USER_ID, userId)
            putExtra(EXTRA_TITLE, title)
            putExtra(EXTRA_BODY, body)
            putExtra(EXTRA_CHANNEL, channel)
            putExtra(EXTRA_SOUND, sound)
            putExtra(EXTRA_ICON, icon)
            putExtra(EXTRA_TAG, tag)
            putExtra(EXTRA_EXACT, exact)
            if (dailyTime != null) putExtra(EXTRA_DAILY_TIME, dailyTime)
        }
        val pending = pendingIntent(context, tag, intent)
        try {
            val am = alarmManager(context)
            when {
                // Exact requested: use it when the OS allows (USE_EXACT_ALARM on
                // 12+), else fall back to inexact rather than dropping the alarm.
                exact && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ->
                    if (am.canScheduleExactAlarms()) {
                        am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMs, pending)
                    } else {
                        am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMs, pending)
                    }
                exact && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ->
                    am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMs, pending)
                exact ->
                    am.setExact(AlarmManager.RTC_WAKEUP, triggerAtMs, pending)
                // Default (inexact): Doze-piercing, no special permission.
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ->
                    am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMs, pending)
                else ->
                    am.set(AlarmManager.RTC_WAKEUP, triggerAtMs, pending)
            }
            remember(context, userId)
        } catch (e: Throwable) {
            Log.w(TAG, "Failed to set alarm for '$userId'", e)
        }
    }

    fun cancel(context: Context, userId: String, tag: Int) {
        val pending = pendingIntent(context, tag, intentFor(context, userId))
        alarmManager(context).cancel(pending)
        pending.cancel()
        forget(context, userId)
    }

    fun cancelAll(context: Context) {
        for (id in ids(context)) {
            val pending = pendingIntent(context, NotificationDisplay.hashId(id), intentFor(context, id))
            alarmManager(context).cancel(pending)
            pending.cancel()
        }
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().remove(KEY_IDS).apply()
    }

    /** Next epoch-millis matching `HH:MM` today, or tomorrow if already past. */
    fun nextDailyTriggerMs(time: String): Long {
        val parts = time.split(":")
        val hh = parts.getOrNull(0)?.toIntOrNull() ?: 9
        val mm = parts.getOrNull(1)?.toIntOrNull() ?: 0
        val now = Calendar.getInstance()
        val target = (now.clone() as Calendar).apply {
            set(Calendar.HOUR_OF_DAY, hh)
            set(Calendar.MINUTE, mm)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
            if (timeInMillis <= now.timeInMillis) add(Calendar.DAY_OF_YEAR, 1)
        }
        return target.timeInMillis
    }

    /** Drop a fired one-shot id from the active registry. */
    fun forget(context: Context, userId: String) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val set = HashSet(prefs.getStringSet(KEY_IDS, emptySet()) ?: emptySet())
        if (set.remove(userId)) {
            prefs.edit().putStringSet(KEY_IDS, set).apply()
        }
    }

    private fun remember(context: Context, userId: String) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val set = HashSet(prefs.getStringSet(KEY_IDS, emptySet()) ?: emptySet())
        set.add(userId)
        prefs.edit().putStringSet(KEY_IDS, set).apply()
    }

    private fun ids(context: Context): Set<String> =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getStringSet(KEY_IDS, emptySet()) ?: emptySet()

    private fun alarmManager(context: Context) =
        context.getSystemService(Context.ALARM_SERVICE) as AlarmManager

    // Distinct action per id keeps PendingIntents from collapsing into one
    // another, while the request code (the numeric tag) is what cancel matches.
    private fun intentFor(context: Context, userId: String): Intent =
        Intent(context, NotificationAlarmReceiver::class.java).apply {
            action = "app.nativeblade.push.FIRE.$userId"
        }

    private fun pendingIntent(context: Context, tag: Int, intent: Intent): PendingIntent {
        var flags = PendingIntent.FLAG_UPDATE_CURRENT
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            flags = flags or PendingIntent.FLAG_IMMUTABLE
        }
        return PendingIntent.getBroadcast(context, tag, intent, flags)
    }
}
