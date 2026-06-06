package app.nativeblade.analytics

import android.app.Activity
import android.os.Bundle
import app.tauri.annotation.Command
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.Plugin
import com.google.firebase.analytics.FirebaseAnalytics

@TauriPlugin
class AnalyticsPlugin(private val activity: Activity) : Plugin(activity) {

    private val analytics by lazy { FirebaseAnalytics.getInstance(activity) }

    @Command
    fun apply(invoke: Invoke) {
        val args = invoke.getArgs()
        val ops = args.optJSONArray("ops")
        if (ops == null) {
            invoke.resolve()
            return
        }

        for (i in 0 until ops.length()) {
            val op = ops.optJSONObject(i) ?: continue
            when (op.optString("op")) {
                "event" -> analytics.logEvent(op.optString("name"), bundleOf(op.optJSONObject("params")))
                "screen" -> {
                    val bundle = Bundle().apply {
                        putString(FirebaseAnalytics.Param.SCREEN_NAME, op.optString("name"))
                    }
                    analytics.logEvent(FirebaseAnalytics.Event.SCREEN_VIEW, bundle)
                }
                "userId" -> analytics.setUserId(op.optString("value").ifEmpty { null })
                "userProperty" -> analytics.setUserProperty(
                    op.optString("key"),
                    op.optString("value").ifEmpty { null }
                )
                "setEnabled" -> analytics.setAnalyticsCollectionEnabled(op.optBoolean("enabled", true))
            }
        }
        invoke.resolve()
    }

    private fun bundleOf(params: org.json.JSONObject?): Bundle {
        val bundle = Bundle()
        if (params == null) return bundle
        val keys = params.keys()
        while (keys.hasNext()) {
            val key = keys.next()
            when (val value = params.get(key)) {
                is String -> bundle.putString(key, value)
                is Int -> bundle.putLong(key, value.toLong())
                is Long -> bundle.putLong(key, value)
                is Double -> bundle.putDouble(key, value)
                is Boolean -> bundle.putLong(key, if (value) 1L else 0L)
                else -> bundle.putString(key, value.toString())
            }
        }
        return bundle
    }
}
