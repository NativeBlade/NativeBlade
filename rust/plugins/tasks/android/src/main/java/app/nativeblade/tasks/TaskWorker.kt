package app.nativeblade.tasks

import android.content.Context
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONObject

/**
 * The app-closed executor. WorkManager wakes the process (no Activity, no
 * Tauri, no WebView); this collects what only the platform can provide (a
 * location fix, for tasks marked withLocation), loads the app's Rust library
 * and hands everything to `courier::run_task` via JNI. The Rust side does the
 * HTTP work and owns the parking store.
 */
class TaskWorker(context: Context, params: WorkerParameters) : Worker(context, params) {

    override fun doWork(): Result {
        val name = inputData.getString("name") ?: run {
            Log.w(TAG, "woke without a task name; failing permanently")
            return Result.failure()
        }
        Log.i(TAG, "background wake for '$name'")

        val prefs = applicationContext.getSharedPreferences(TasksPlugin.PREFS, Context.MODE_PRIVATE)
        val defJson = prefs.getString("task_$name", null) ?: run {
            Log.w(TAG, "'$name': no definition in prefs (app never registered?)")
            return Result.failure()
        }
        val dataDir = prefs.getString(TasksPlugin.KEY_DATA_DIR, null) ?: run {
            Log.w(TAG, "'$name': data dir unknown")
            return Result.failure()
        }
        val libName = prefs.getString(TasksPlugin.KEY_LIB_NAME, null) ?: run {
            Log.w(TAG, "'$name': native lib name unknown")
            return Result.failure()
        }

        try {
            System.loadLibrary(libName)
        } catch (e: UnsatisfiedLinkError) {
            Log.e(TAG, "'$name': failed to load lib$libName.so: ${e.message}")
            return Result.failure()
        }

        val def = JSONObject(defJson)
        val collected = JSONObject()
        if (def.optBoolean("withLocation")) {
            LocationCollect.oneFix(applicationContext)?.let { collected.put("location", it) }
                ?: Log.w(TAG, "'$name': no location fix (missing permission or timeout); sending without")
        }
        def.optString("bearerFromSecure").takeIf { it.isNotEmpty() }?.let { key ->
            SecureRead.read(applicationContext, key)?.let { collected.put("bearer", it) }
                ?: Log.w(TAG, "'$name': bearer '$key' unreadable; sending without Authorization")
        }

        val ok = try {
            runTaskNative(defJson, collected.toString(), dataDir)
        } catch (t: Throwable) {
            Log.e(TAG, "'$name': JNI call failed: ${t.message}")
            false
        }
        Log.i(TAG, "'$name': courier finished ok=$ok")

        // A failed run (offline mid-run, server 5xx) retries with backoff;
        // post payloads are already safe in the Rust outbox either way.
        return if (ok) Result.success() else Result.retry()
    }

    private companion object {
        const val TAG = "NBTasks"
    }

    private external fun runTaskNative(def: String, collected: String, dataDir: String): Boolean
}
