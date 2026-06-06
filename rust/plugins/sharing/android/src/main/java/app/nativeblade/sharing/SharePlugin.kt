package app.nativeblade.sharing

import android.app.Activity
import android.content.Intent
import app.tauri.annotation.Command
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.Plugin

@TauriPlugin
class SharePlugin(private val activity: Activity) : Plugin(activity) {

    @Command
    fun share(invoke: Invoke) {
        val args = invoke.getArgs()
        val text = args.getString("text", "") ?: ""
        val url = args.getString("url", "") ?: ""

        val payload = listOf(text, url).filter { it.isNotEmpty() }.joinToString("\n")
        if (payload.isEmpty()) {
            invoke.reject("nothing to share")
            return
        }

        val sendIntent = Intent().apply {
            action = Intent.ACTION_SEND
            putExtra(Intent.EXTRA_TEXT, payload)
            type = "text/plain"
        }
        activity.startActivity(Intent.createChooser(sendIntent, null))
        invoke.resolve()
    }
}
