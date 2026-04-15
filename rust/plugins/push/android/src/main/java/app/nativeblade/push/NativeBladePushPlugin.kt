package app.nativeblade.push

import android.Manifest
import android.app.Activity
import android.app.ActivityManager
import android.content.Context
import android.os.Build
import android.webkit.WebView
import app.tauri.annotation.Command
import app.tauri.annotation.Permission
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSArray
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
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
        @Volatile
        var instance: NativeBladePushPlugin? = null
    }

    override fun load(webView: WebView) {
        super.load(webView)
        instance = this

        // If the token was delivered before the plugin was instantiated,
        // emit it immediately so the JS listener can pick it up.
        PendingPushes.latestToken?.let { emitToken(it) }

        // Kick off an FCM token request. If it's already registered,
        // the callback fires synchronously; otherwise we'll get it via
        // onNewToken later.
        FirebaseMessaging.getInstance().token
            .addOnCompleteListener { task ->
                if (task.isSuccessful) {
                    val token = task.result
                    if (token != null) {
                        PendingPushes.setToken(token)
                        emitToken(token)
                    }
                }
            }
    }

    @Command
    fun getToken(invoke: Invoke) {
        val result = JSObject()
        result.put("token", PendingPushes.latestToken)
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

    /**
     * Called by [NativeBladeFirebaseService] when a push arrives while
     * the plugin is alive. Emits a Tauri event that the JS layer listens
     * for to trigger the developer's `onReceive` callback.
     */
    fun emitPush(payload: Map<String, Any?>) {
        trigger("nativeblade-push", mapToJsObject(payload))
    }

    /**
     * Called by [NativeBladeFirebaseService] when the device token
     * arrives or is refreshed.
     */
    fun emitToken(token: String) {
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
                null -> obj.put(key, JSObject.NULL)
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
