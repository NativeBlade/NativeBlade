package app.nativeblade.tasks

import android.app.Activity
import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf
import app.tauri.annotation.Command
import app.tauri.annotation.InvokeArg
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.Plugin
import org.json.JSONArray
import java.io.File
import java.util.concurrent.TimeUnit

@InvokeArg
class RegisterArgs {
    lateinit var tasksJson: String
    lateinit var dataDir: String
}

@InvokeArg
class CollectArgs {
    var withLocation: Boolean = false
    var bearerFromSecure: String? = null
}

/**
 * The thin Android adapter of the task courier. All it does is translate the
 * manifest into WorkManager schedules and persist what the TaskWorker needs
 * to run later in a process with no Activity and no Tauri: each task's
 * definition JSON, the parking data dir, and the app's native library name.
 * The work itself is Rust (see TaskWorker).
 */
@TauriPlugin
class TasksPlugin(private val activity: Activity) : Plugin(activity) {

    @Command
    fun registerTasks(invoke: Invoke) {
        val args = invoke.parseArgs(RegisterArgs::class.java)
        val prefs = activity.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val wm = WorkManager.getInstance(activity)

        val tasks = JSONArray(args.tasksJson)
        val names = mutableSetOf<String>()
        val editor = prefs.edit()
        editor.putString(KEY_DATA_DIR, args.dataDir)
        findLibName()?.let { editor.putString(KEY_LIB_NAME, it) }

        for (i in 0 until tasks.length()) {
            val task = tasks.getJSONObject(i)
            val name = task.getString("name")
            names.add(name)
            editor.putString("task_$name", task.toString())

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(
                    when {
                        task.optBoolean("requiresUnmetered") -> NetworkType.UNMETERED
                        task.optBoolean("requiresNetwork") -> NetworkType.CONNECTED
                        else -> NetworkType.NOT_REQUIRED
                    }
                )
                .setRequiresCharging(task.optBoolean("requiresCharging"))
                .build()

            val minutes = task.optLong("everyMinutes", 60).coerceAtLeast(15)
            val request = PeriodicWorkRequestBuilder<TaskWorker>(minutes, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .setInputData(workDataOf("name" to name))
                .build()

            wm.enqueueUniquePeriodicWork(WORK_PREFIX + name, ExistingPeriodicWorkPolicy.UPDATE, request)
        }

        // Cancel schedules for tasks removed from the config.
        val previous = prefs.getStringSet(KEY_NAMES, emptySet()) ?: emptySet()
        for (stale in previous - names) {
            wm.cancelUniqueWork(WORK_PREFIX + stale)
            editor.remove("task_$stale")
        }
        editor.putStringSet(KEY_NAMES, names)
        editor.apply()

        invoke.resolve()
    }

    /**
     * Platform data for an in-app run (the open-app timers in Rust ask here).
     * Off the main thread: a location fix can take seconds.
     */
    @Command
    fun collect(invoke: Invoke) {
        val args = invoke.parseArgs(CollectArgs::class.java)
        Thread {
            val obj = app.tauri.plugin.JSObject()
            args.bearerFromSecure?.let { key ->
                SecureRead.read(activity, key)?.let { obj.put("bearer", it) }
            }
            if (args.withLocation) {
                LocationCollect.oneFix(activity)?.let { obj.put("location", it) }
            }
            invoke.resolve(obj)
        }.start()
    }

    /**
     * The TaskWorker wakes in a process where nothing loaded the app's Rust
     * library, and it cannot know the crate name — discover the .so once here
     * (while the app is open) and persist it.
     */
    private fun findLibName(): String? {
        val dir = File(activity.applicationInfo.nativeLibraryDir)
        val libs = dir.list { _, n -> n.startsWith("lib") && n.endsWith(".so") } ?: return null
        val chosen = libs.firstOrNull { it.endsWith("_lib.so") } ?: libs.firstOrNull() ?: return null
        return chosen.removePrefix("lib").removeSuffix(".so")
    }

    companion object {
        const val PREFS = "nativeblade_tasks"
        const val KEY_DATA_DIR = "data_dir"
        const val KEY_LIB_NAME = "lib_name"
        const val KEY_NAMES = "task_names"
        const val WORK_PREFIX = "nb-task-"
    }
}
