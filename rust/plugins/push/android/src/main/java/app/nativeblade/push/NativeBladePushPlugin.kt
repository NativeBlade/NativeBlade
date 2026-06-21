package app.nativeblade.push

import android.Manifest
import android.app.Activity
import android.app.ActivityManager
import android.content.Context
import android.os.Build
import android.util.Log
import android.webkit.WebView
import androidx.core.app.NotificationManagerCompat
import androidx.work.Data
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import app.tauri.annotation.Command
import app.tauri.annotation.Permission
import app.tauri.annotation.PermissionCallback
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSArray
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
import app.tauri.plugin.PermissionState
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone
import java.util.UUID
import java.util.concurrent.TimeUnit

/**
 * Kotlin side of the NativeBlade push plugin.
 *
 * Responsibilities:
 * - Asks FCM for the current device token on load and forwards it
 * - Emits `nativeblade-push-token` whenever the token refreshes
 * - Emits `nativeblade-push` whenever a push is received while the app
 *   is running, OR drains the buffered queue on demand for cold starts
 * - Exposes `getToken`, `requestPermission`, and `drainPending` as
 *   invokable commands
 */
@TauriPlugin(
    permissions = [
        Permission(strings = [Manifest.permission.POST_NOTIFICATIONS], alias = "postNotifications")
    ]
)
class NativeBladePushPlugin(private val activity: Activity) : Plugin(activity) {

    companion object {
        private const val TAG = "NativeBladePush"
        private const val WORK_TAG = "nb_notification_v2"
        private val ALL_WORK_TAGS = listOf("nb_notification", "nb_notification_v2")

        @Volatile
        var instance: NativeBladePushPlugin? = null
    }

    @Volatile
    private var active: Boolean = false

    override fun load(webView: WebView) {
        super.load(webView)
        instance = this

        // Clear legacy WorkManager-scheduled notifications from older app
        // versions (one-shot and daily now run on AlarmManager). AlarmManager
        // alarms are intentionally NOT cancelled here: they must survive cold
        // starts so a reminder armed in one session still fires after the app
        // is reopened and closed again.
        try {
            val wm = WorkManager.getInstance(activity.applicationContext)
            for (tag in ALL_WORK_TAGS) {
                wm.cancelAllWorkByTag(tag)
            }
        } catch (e: Throwable) {
            Log.w(TAG, "Failed to clear pending notification work on load", e)
        }

        if (FirebaseApp.getApps(activity.applicationContext).isEmpty()) {
            Log.w(TAG, "Firebase not initialized, push plugin inert")
            return
        }

        active = true

        PendingPushes.latestToken?.let { emitToken(it) }

        try {
            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                val token = task.result
                if (task.isSuccessful && token != null) {
                    PendingPushes.setToken(token)
                    emitToken(token)
                } else if (!task.isSuccessful) {
                    Log.w(TAG, "FCM token fetch failed", task.exception)
                }
            }
        } catch (e: Throwable) {
            Log.w(TAG, "FCM token request crashed — plugin staying inert", e)
            active = false
        }
    }

    @Command
    fun getToken(invoke: Invoke) {
        val result = JSObject()
        result.put("token", if (active) PendingPushes.latestToken else null)
        invoke.resolve(result)
    }

    @Command
    fun requestPermission(invoke: Invoke) {
        // Pre-13 grants notification permission at install time.
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            invoke.resolve(JSObject().apply { put("granted", true) })
            return
        }
        if (getPermissionState("postNotifications") == PermissionState.GRANTED) {
            invoke.resolve(JSObject().apply { put("granted", true) })
            return
        }
        // Fires the system dialog on demand (called from JS after the splash
        // is gone), never during plugin load() when the WebView is still dark.
        requestPermissionForAlias("postNotifications", invoke, "postNotificationsCallback")
    }

    @PermissionCallback
    fun postNotificationsCallback(invoke: Invoke) {
        val granted = getPermissionState("postNotifications") == PermissionState.GRANTED
        invoke.resolve(JSObject().apply { put("granted", granted) })
    }

    @Command
    fun drainPending(invoke: Invoke) {
        val drained = PendingPushes.drain()
        val arr = JSArray()
        for (item in drained) {
            arr.put(mapToJsObject(item))
        }
        invoke.resolve(JSObject().apply { put("pending", arr) })
    }

    // -------------------------------------------------------------------
    // Local notifications
    //
    // These commands replace tauri-plugin-notification for the immediate
    // and scheduled cases on Android. WorkManager handles the scheduling
    // so the system stays responsible for waking us up at the right time
    // (no AlarmManager exact-alarm permission needed on 12+).
    // -------------------------------------------------------------------

    @Command
    fun notify(invoke: Invoke) {
        val args = invoke.getArgs()

        val userId = nullableString(args, "id") ?: UUID.randomUUID().toString()
        val title = nullableString(args, "title")
        val body = nullableString(args, "body")
        val channel = nullableString(args, "channel")
        val sound = nullableString(args, "sound")
        val icon = nullableString(args, "icon")
        val schedule = if (args.has("schedule") && !args.isNull("schedule")) {
            args.getJSONObject("schedule")
        } else null
        // Opt-in exact delivery (from NativeBlade::scheduleNotification). Honored
        // only when USE_EXACT_ALARM is granted; otherwise degrades to inexact.
        val exact = args.optBoolean("exact", false)

        val tag = NotificationDisplay.hashId(userId)

        if (schedule == null) {
            // Fire immediately on the calling thread — no need to detour
            // through WorkManager for an instant notification.
            NotificationDisplay.show(
                context = activity.applicationContext,
                title = title,
                body = body,
                channelId = channel,
                sound = sound,
                smallIconName = icon,
                tag = tag,
            )
            invoke.resolve(JSObject().apply { put("id", userId) })
            return
        }

        try {
            scheduleNotification(userId, tag, title, body, channel, sound, icon, schedule, exact)
            invoke.resolve(JSObject().apply { put("id", userId) })
        } catch (e: Throwable) {
            Log.w(TAG, "Failed to schedule notification '$userId'", e)
            invoke.reject("Failed to schedule notification: ${e.message}")
        }
    }

    @Command
    fun cancel(invoke: Invoke) {
        val userId = nullableString(invoke.getArgs(), "id")
        if (userId.isNullOrBlank()) {
            invoke.reject("Missing notification id")
            return
        }
        cancelById(userId)
        invoke.resolve()
    }

    @Command
    fun cancelAll(invoke: Invoke) {
        val ctx = activity.applicationContext
        val wm = WorkManager.getInstance(ctx)
        for (tag in ALL_WORK_TAGS) {
            wm.cancelAllWorkByTag(tag)
        }
        NotificationAlarms.cancelAll(ctx)
        NotificationManagerCompat.from(ctx).cancelAll()
        invoke.resolve()
    }

    private fun cancelById(userId: String) {
        val ctx = activity.applicationContext
        WorkManager.getInstance(ctx).cancelUniqueWork(workName(userId))
        NotificationAlarms.cancel(ctx, userId, NotificationDisplay.hashId(userId))
        NotificationManagerCompat.from(ctx).cancel(NotificationDisplay.hashId(userId))
    }

    private fun nullableString(obj: org.json.JSONObject, key: String): String? {
        if (!obj.has(key) || obj.isNull(key)) return null
        val value = obj.optString(key, "")
        return value.takeIf { it.isNotBlank() }
    }

    private fun scheduleNotification(
        userId: String,
        tag: Int,
        title: String?,
        body: String?,
        channel: String?,
        sound: String?,
        icon: String?,
        schedule: org.json.JSONObject,
        exact: Boolean,
    ) {
        val ctx = activity.applicationContext

        // One-shot and daily schedules go through AlarmManager, not
        // WorkManager: WorkManager defers deferrable work in Doze and would
        // silently drop an overnight reminder. Recurring `every` schedules
        // stay on WorkManager, where deferrable batching is acceptable.
        when (schedule.optString("type")) {
            "at" -> {
                val whenMs = parseIsoUtc(schedule.optString("at"))
                NotificationAlarms.schedule(
                    context = ctx, userId = userId, tag = tag,
                    triggerAtMs = whenMs.coerceAtLeast(System.currentTimeMillis()),
                    title = title, body = body, channel = channel, sound = sound, icon = icon,
                    dailyTime = null, exact = exact,
                )
                return
            }

            "dailyAt" -> {
                val time = schedule.optString("time").ifBlank { "09:00" }
                NotificationAlarms.schedule(
                    context = ctx, userId = userId, tag = tag,
                    triggerAtMs = NotificationAlarms.nextDailyTriggerMs(time),
                    title = title, body = body, channel = channel, sound = sound, icon = icon,
                    dailyTime = time, exact = exact,
                )
                return
            }
        }

        val workManager = WorkManager.getInstance(ctx)
        val workName = workName(userId)

        val data = Data.Builder().apply {
            putString(ScheduledNotificationWorker.KEY_TITLE, title)
            putString(ScheduledNotificationWorker.KEY_BODY, body)
            putString(ScheduledNotificationWorker.KEY_CHANNEL, channel)
            putString(ScheduledNotificationWorker.KEY_SOUND, sound)
            putString(ScheduledNotificationWorker.KEY_ICON, icon)
            putInt(ScheduledNotificationWorker.KEY_TAG, tag)
            putInt(ScheduledNotificationWorker.KEY_VERSION_MARKER, ScheduledNotificationWorker.CURRENT_VERSION)
        }.build()

        when (schedule.optString("type")) {
            "every" -> {
                val kind = schedule.optString("kind").ifBlank { "day" }
                val count = schedule.optInt("count", 1).coerceAtLeast(1)
                val intervalMinutes = when (kind) {
                    "minute" -> count.toLong()
                    "hour"   -> count.toLong() * 60
                    "day"    -> count.toLong() * 60 * 24
                    "week"   -> count.toLong() * 60 * 24 * 7
                    "month"  -> count.toLong() * 60 * 24 * 30
                    else     -> count.toLong() * 60 * 24
                }
                // WorkManager periodic minimum is 15 minutes — anything
                // shorter is silently clamped by the framework.
                val request = PeriodicWorkRequestBuilder<ScheduledNotificationWorker>(
                    intervalMinutes.coerceAtLeast(15), TimeUnit.MINUTES
                )
                    .setInputData(data)
                    .addTag(WORK_TAG)
                    .build()
                workManager.enqueueUniquePeriodicWork(
                    workName, ExistingPeriodicWorkPolicy.UPDATE, request
                )
            }

            else -> throw IllegalArgumentException("Unsupported schedule type")
        }
    }

    private fun workName(userId: String) = "nb_notification_$userId"

    private fun parseIsoUtc(iso: String?): Long {
        if (iso.isNullOrBlank()) return System.currentTimeMillis()
        val format = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US)
        format.timeZone = TimeZone.getTimeZone("UTC")
        return try {
            format.parse(iso)?.time ?: System.currentTimeMillis()
        } catch (e: Throwable) {
            System.currentTimeMillis()
        }
    }

    fun emitPush(payload: Map<String, Any?>) {
        if (!active) return
        trigger("nativeblade-push", mapToJsObject(payload))
    }

    fun emitToken(token: String) {
        if (!active) return
        val data = JSObject()
        data.put("token", token)
        trigger("nativeblade-push-token", data)
    }

    /**
     * Reports the current app lifecycle state so incoming pushes can
     * be tagged as `foreground`, `background`, or `cold`.
     */
    fun currentAppState(): String {
        val activityManager = activity.getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager
            ?: return "cold"
        val processes = activityManager.runningAppProcesses ?: return "cold"
        val packageName = activity.packageName
        for (process in processes) {
            if (process.processName == packageName) {
                return when (process.importance) {
                    ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND -> "foreground"
                    ActivityManager.RunningAppProcessInfo.IMPORTANCE_VISIBLE -> "foreground"
                    else -> "background"
                }
            }
        }
        return "cold"
    }

    private fun mapToJsObject(map: Map<String, Any?>): JSObject {
        val obj = JSObject()
        for ((key, value) in map) {
            when (value) {
                null -> obj.put(key, org.json.JSONObject.NULL)
                is Map<*, *> -> {
                    @Suppress("UNCHECKED_CAST")
                    obj.put(key, mapToJsObject(value as Map<String, Any?>))
                }
                is List<*> -> {
                    val arr = JSArray()
                    for (item in value) {
                        when (item) {
                            is Map<*, *> -> {
                                @Suppress("UNCHECKED_CAST")
                                arr.put(mapToJsObject(item as Map<String, Any?>))
                            }
                            else -> arr.put(item)
                        }
                    }
                    obj.put(key, arr)
                }
                else -> obj.put(key, value)
            }
        }
        return obj
    }
}
