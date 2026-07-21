package app.nativeblade.nativenav

import android.app.Activity
import android.graphics.Bitmap
import android.graphics.Rect
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.view.PixelCopy
import android.view.ViewGroup
import android.view.animation.DecelerateInterpolator
import android.webkit.WebView
import android.widget.FrameLayout
import android.widget.ImageView
import app.tauri.annotation.Command
import app.tauri.annotation.InvokeArg
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.Plugin

@InvokeArg
class SnapshotArgs {
    var x: Float = 0f
    var y: Float = 0f
    var width: Float = 0f
    var height: Float = 0f
    var dpr: Float = 1f
}

@InvokeArg
class AnimateArgs {
    var direction: String = "forward"
    var duration: Long = 280
}

/**
 * The native transition compositor. `snapshot` freezes the current page as a
 * native ImageView pinned exactly over the app iframe region; the JS router
 * swaps the DOM instantly beneath it; `animate` moves the overlay away with
 * the platform's own animation (Material shared-axis here), rendered by the
 * native compositor — immune to webview/wasm main-thread jank.
 */
@TauriPlugin
class NativeNavPlugin(private val activity: Activity) : Plugin(activity) {

    private var overlay: ImageView? = null
    private var hostWebView: WebView? = null

    override fun load(webView: WebView) {
        super.load(webView)
        hostWebView = webView
    }

    @Command
    fun snapshot(invoke: Invoke) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            invoke.reject("PixelCopy requires API 26")
            return
        }
        val args = invoke.parseArgs(SnapshotArgs::class.java)
        val webView = hostWebView ?: run { invoke.reject("webview not ready"); return }

        activity.runOnUiThread {
            try {
                val loc = IntArray(2)
                webView.getLocationInWindow(loc)
                val left = loc[0] + (args.x * args.dpr).toInt()
                val top = loc[1] + (args.y * args.dpr).toInt()
                val w = (args.width * args.dpr).toInt()
                val h = (args.height * args.dpr).toInt()
                if (w <= 0 || h <= 0) {
                    invoke.reject("empty snapshot rect")
                    return@runOnUiThread
                }

                val bitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
                PixelCopy.request(
                    activity.window,
                    Rect(left, top, left + w, top + h),
                    bitmap,
                    { result ->
                        activity.runOnUiThread {
                            if (result == PixelCopy.SUCCESS) {
                                removeOverlay()
                                val img = ImageView(activity)
                                img.setImageBitmap(bitmap)
                                img.scaleType = ImageView.ScaleType.FIT_XY
                                val lp = FrameLayout.LayoutParams(w, h)
                                lp.leftMargin = left
                                lp.topMargin = top
                                (activity.window.decorView as ViewGroup).addView(img, lp)
                                overlay = img
                                invoke.resolve()
                            } else {
                                invoke.reject("PixelCopy failed: $result")
                            }
                        }
                    },
                    Handler(Looper.getMainLooper())
                )
            } catch (e: Exception) {
                invoke.reject(e.message ?: "snapshot failed")
            }
        }
    }

    @Command
    fun animate(invoke: Invoke) {
        val args = invoke.parseArgs(AnimateArgs::class.java)
        activity.runOnUiThread {
            val img = overlay ?: run { invoke.resolve(); return@runOnUiThread }
            overlay = null

            // Material shared-axis X: the outgoing page fades while sliding a
            // short distance — forward exits left, back exits right and further
            // (the previous page "was always there" beneath).
            val width = img.width.toFloat()
            val shift = if (args.direction == "back") width * 0.6f else -width * 0.18f

            img.animate()
                .translationX(shift)
                .alpha(0f)
                .setDuration(args.duration)
                .setInterpolator(DecelerateInterpolator(1.6f))
                .withEndAction {
                    (img.parent as? ViewGroup)?.removeView(img)
                    invoke.resolve()
                }
                .start()
        }
    }

    @Command
    fun cancel(invoke: Invoke) {
        activity.runOnUiThread {
            removeOverlay()
            invoke.resolve()
        }
    }

    private fun removeOverlay() {
        overlay?.let { (it.parent as? ViewGroup)?.removeView(it) }
        overlay = null
    }
}
