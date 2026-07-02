package app.nativeblade.tasks

import android.content.Context
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
        val name = inputData.getString("name") ?: return Result.failure()
        val prefs = applicationContext.getSharedPreferences(TasksPlugin.PREFS, Context.MODE_PRIVATE)
        val defJson = prefs.getString("task_$name", null) ?: return Result.failure()
        val dataDir = prefs.getString(TasksPlugin.KEY_DATA_DIR, null) ?: return Result.failure()
        val libName = prefs.getString(TasksPlugin.KEY_LIB_NAME, null) ?: return Result.failure()

        try {
            System.loadLibrary(libName)
        } catch (e: UnsatisfiedLinkError) {
            return Result.failure()
        }

        val def = JSONObject(defJson)
        val collected = JSONObject()
        if (def.optBoolean("withLocation")) {
            LocationCollect.oneFix(applicationContext)?.let { collected.put("location", it) }
        }
        def.optString("bearerFromSecure").takeIf { it.isNotEmpty() }?.let { key ->
            SecureRead.read(applicationContext, key)?.let { collected.put("bearer", it) }
        }

        val ok = try {
            runTaskNative(defJson, collected.toString(), dataDir)
        } catch (t: Throwable) {
            false
        }

        // A failed run (offline mid-run, server 5xx) retries with backoff;
        // post payloads are already safe in the Rust outbox either way.
        return if (ok) Result.success() else Result.retry()
    }

    private external fun runTaskNative(def: String, collected: String, dataDir: String): Boolean
}
