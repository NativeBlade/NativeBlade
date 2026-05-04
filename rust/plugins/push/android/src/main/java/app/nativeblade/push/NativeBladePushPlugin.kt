package app.nativeblade.push

import android.Manifest
import android.app.Activity
import android.app.ActivityManager
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import android.webkit.WebView
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import app.tauri.annotation.Command
import app.tauri.annotation.Permission
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSArray
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging

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

        @Volatile
        var instance: NativeBladePushPlugin? = null
    }

    @Volatile
    private var active: Boolean = false

    override fun load(webView: WebView) {
        super.load(webView)
        instance = this

        // Request POST_NOTIFICATIONS unconditionally, even when Firebase is not
        // configured. Local notifications (tauri-plugin-notification) need this
        // permission too, and their own requestPermission() flow is broken
        // because the plugin's lateinit ActivityResultLauncher isn't always
        // initialized in time. Using ActivityCompat directly avoids that bug.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(activity, Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED
            ) {
                ActivityCompat.requestPermissions(activity, arrayOf(Manifest.permission.POST_NOTIFICATIONS), 0)
            }
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
        val result = JSObject()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            // Android 13+ requires runtime permission for notifications.
            // Tauri's Plugin base handles requesting permissions declared
            // in the @TauriPlugin annotation — we just report the current
            // state here. Developers should call the generic permission
            // request flow for "postNotifications" via the JS API.
            val granted = activity.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) ==
                android.content.pm.PackageManager.PERMISSION_GRANTED
            result.put("granted", granted)
        } else {
            // Pre-13 grants notification permission at install time.
            result.put("granted", true)
        }
        invoke.resolve(result)
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
