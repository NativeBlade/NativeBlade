package app.nativeblade.system

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.core.view.WindowCompat
import app.tauri.annotation.Command
import app.tauri.annotation.InvokeArg
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.Plugin

@InvokeArg
class StatusBarStyleArgs {
    /** "dark" for dark icons on a light background, "light" for light icons on a dark one. */
    lateinit var style: String
}

@TauriPlugin
class NativeBladeSystemPlugin(private val activity: Activity) : Plugin(activity) {

    @Command
    fun openAppSettings(invoke: Invoke) {
        try {
            val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", activity.packageName, null)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            activity.startActivity(intent)
            invoke.resolve()
        } catch (e: Exception) {
            invoke.reject(e.message ?: "failed to open app settings")
        }
    }

    /**
     * Runtime counterpart of `->statusBar(style:)` in the build config: the
     * icon style of the status bar and the navigation bar, so a theme switch
     * inside the app keeps the clock, battery and signal readable. Edge-to-edge
     * stays on; the bars' background is still the WebView content.
     */
    @Command
    fun setStatusBarStyle(invoke: Invoke) {
        val args = try {
            invoke.parseArgs(StatusBarStyleArgs::class.java)
        } catch (e: Exception) {
            invoke.reject("style is required: \"dark\" or \"light\"")
            return
        }
        if (args.style != "dark" && args.style != "light") {
            invoke.reject("style must be \"dark\" or \"light\"")
            return
        }
        val lightIcons = args.style == "light"

        activity.runOnUiThread {
            try {
                val window = activity.window
                val controller = WindowCompat.getInsetsController(window, window.decorView)
                // "Light bars" in Android's naming means dark icons.
                controller.isAppearanceLightStatusBars = !lightIcons
                controller.isAppearanceLightNavigationBars = !lightIcons
                invoke.resolve()
            } catch (e: Exception) {
                invoke.reject(e.message ?: "failed to set the status bar style")
            }
        }
    }
}
