package app.nativeblade.review

import android.app.Activity
import app.tauri.annotation.Command
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.Plugin
import com.google.android.play.core.review.ReviewManagerFactory

@TauriPlugin
class InAppReviewPlugin(private val activity: Activity) : Plugin(activity) {

    @Command
    fun requestReview(invoke: Invoke) {
        try {
            val manager = ReviewManagerFactory.create(activity)
            manager.requestReviewFlow().addOnCompleteListener { request ->
                if (request.isSuccessful) {
                    val reviewInfo = request.result
                    manager.launchReviewFlow(activity, reviewInfo).addOnCompleteListener {
                        // Play never reports whether the user actually reviewed; a
                        // successful completion just means the flow finished (or was
                        // skipped due to quota). Resolve either way.
                        invoke.resolve()
                    }
                } else {
                    invoke.reject(request.exception?.message ?: "in-app review request failed")
                }
            }
        } catch (e: Exception) {
            invoke.reject(e.message ?: "in-app review failed")
        }
    }
}
