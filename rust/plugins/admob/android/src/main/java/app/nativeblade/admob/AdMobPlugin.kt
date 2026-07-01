package app.nativeblade.admob

import android.app.Activity
import android.content.pm.ApplicationInfo
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import app.tauri.annotation.Command
import app.tauri.annotation.InvokeArg
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
import com.google.android.gms.ads.AdError
import com.google.android.gms.ads.AdListener
import com.google.android.gms.ads.AdRequest
import com.google.android.gms.ads.AdSize
import com.google.android.gms.ads.AdView
import com.google.android.gms.ads.FullScreenContentCallback
import com.google.android.gms.ads.LoadAdError
import com.google.android.gms.ads.MobileAds
import com.google.android.gms.ads.RequestConfiguration
import com.google.android.gms.ads.interstitial.InterstitialAd
import com.google.android.gms.ads.interstitial.InterstitialAdLoadCallback
import com.google.android.gms.ads.rewarded.RewardedAd
import com.google.android.gms.ads.rewarded.RewardedAdLoadCallback
import com.google.android.ump.ConsentInformation
import com.google.android.ump.ConsentRequestParameters
import com.google.android.ump.UserMessagingPlatform

@InvokeArg
class ConsentArgs {
    var testDeviceIds: List<String> = emptyList()
}

@InvokeArg
class RewardedArgs {
    lateinit var unit: String
    var id: String? = null
}

@InvokeArg
class InterstitialArgs {
    lateinit var unit: String
    var id: String? = null
    var minInterval: Long? = null
}

@InvokeArg
class BannerArgs {
    lateinit var unit: String
    var id: String? = null
}

@TauriPlugin
class AdMobPlugin(private val activity: Activity) : Plugin(activity) {

    companion object {
        // Google's reserved test ad unit ids — served automatically in debug
        // builds so a developer never risks clicking a live ad (account ban).
        private const val TEST_REWARDED = "ca-app-pub-3940256099942544/5224354917"
        private const val TEST_INTERSTITIAL = "ca-app-pub-3940256099942544/1033173712"
        private const val TEST_BANNER = "ca-app-pub-3940256099942544/9214589741"

        // Per-unit last-shown timestamps for interstitial frequency capping.
        private val lastShown = HashMap<String, Long>()
    }

    // Set once test devices are registered: real ad units then serve test ads
    // on those devices, so we stop substituting Google's test unit and exercise
    // the real id safely.
    private var hasTestDevices: Boolean = false

    private var webViewRef: android.webkit.WebView? = null
    private var bannerView: AdView? = null
    private var bannerUnit: String? = null
    private var bannerBuiltWidth = 0
    private var bannerLayoutListener: View.OnLayoutChangeListener? = null

    private val debuggable: Boolean
        get() = (activity.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0

    override fun load(webView: android.webkit.WebView) {
        super.load(webView)
        webViewRef = webView
        MobileAds.initialize(activity) { }
    }

    @Command
    fun requestConsent(invoke: Invoke) {
        val args = invoke.parseArgs(ConsentArgs::class.java)

        if (args.testDeviceIds.isNotEmpty()) {
            hasTestDevices = true
            MobileAds.setRequestConfiguration(
                RequestConfiguration.Builder().setTestDeviceIds(args.testDeviceIds).build()
            )
        }

        val params = if (debuggable && args.testDeviceIds.isNotEmpty()) {
            val debugSettings = com.google.android.ump.ConsentDebugSettings.Builder(activity)
                .setDebugGeography(com.google.android.ump.ConsentDebugSettings.DebugGeography.DEBUG_GEOGRAPHY_EEA)
            for (id in args.testDeviceIds) debugSettings.addTestDeviceHashedId(id)
            ConsentRequestParameters.Builder().setConsentDebugSettings(debugSettings.build()).build()
        } else {
            ConsentRequestParameters.Builder().build()
        }

        val consentInformation = UserMessagingPlatform.getConsentInformation(activity)
        consentInformation.requestConsentInfoUpdate(
            activity,
            params,
            {
                UserMessagingPlatform.loadAndShowConsentFormIfRequired(activity) {
                    val result = JSObject()
                    result.put("canRequestAds", consentInformation.canRequestAds())
                    invoke.resolve(result)
                }
            },
            { error ->
                val result = JSObject()
                result.put("canRequestAds", consentInformation.canRequestAds())
                result.put("error", error.message)
                invoke.resolve(result)
            }
        )
    }

    @Command
    fun showRewarded(invoke: Invoke) {
        val args = invoke.parseArgs(RewardedArgs::class.java)
        val unit = if (debuggable && !hasTestDevices) TEST_REWARDED else args.unit

        activity.runOnUiThread {
            RewardedAd.load(activity, unit, AdRequest.Builder().build(), object : RewardedAdLoadCallback() {
                override fun onAdFailedToLoad(error: LoadAdError) {
                    invoke.resolve(failure(error.message))
                }

                override fun onAdLoaded(ad: RewardedAd) {
                    var earned = false
                    var amount = 0
                    var type = ""

                    ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                        override fun onAdFailedToShowFullScreenContent(error: AdError) {
                            invoke.resolve(failure(error.message))
                        }

                        override fun onAdDismissedFullScreenContent() {
                            val result = JSObject()
                            result.put("status", "dismissed")
                            result.put("earned", earned)
                            result.put("amount", amount)
                            result.put("type", type)
                            invoke.resolve(result)
                        }
                    }

                    ad.show(activity) { reward ->
                        earned = true
                        amount = reward.amount
                        type = reward.type
                    }
                }
            })
        }
    }

    @Command
    fun showInterstitial(invoke: Invoke) {
        val args = invoke.parseArgs(InterstitialArgs::class.java)
        val unit = if (debuggable && !hasTestDevices) TEST_INTERSTITIAL else args.unit

        val minInterval = args.minInterval
        if (minInterval != null) {
            val last = lastShown[args.unit] ?: 0L
            if (System.currentTimeMillis() - last < minInterval * 1000) {
                val result = JSObject()
                result.put("status", "capped")
                invoke.resolve(result)
                return
            }
        }

        activity.runOnUiThread {
            InterstitialAd.load(activity, unit, AdRequest.Builder().build(), object : InterstitialAdLoadCallback() {
                override fun onAdFailedToLoad(error: LoadAdError) {
                    invoke.resolve(failure(error.message))
                }

                override fun onAdLoaded(ad: InterstitialAd) {
                    ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                        override fun onAdFailedToShowFullScreenContent(error: AdError) {
                            invoke.resolve(failure(error.message))
                        }

                        override fun onAdDismissedFullScreenContent() {
                            val result = JSObject()
                            result.put("status", "dismissed")
                            invoke.resolve(result)
                        }
                    }
                    lastShown[args.unit] = System.currentTimeMillis()
                    ad.show(activity)
                }
            })
        }
    }

    @Command
    fun showBanner(invoke: Invoke) {
        val args = invoke.parseArgs(BannerArgs::class.java)
        val unit = if (debuggable && !hasTestDevices) TEST_BANNER else args.unit

        activity.runOnUiThread {
            // Showing again replaces the current banner (e.g. a new unit).
            removeBanner()

            if (webViewRef == null) {
                invoke.resolve(failure("no webview"))
                return@runOnUiThread
            }

            bannerUnit = unit
            attachBanner(unit, invoke)
            watchLayoutChanges()
        }
    }

    @Command
    fun hideBanner(invoke: Invoke) {
        activity.runOnUiThread {
            removeBanner()
            invoke.resolve()
        }
    }

    /**
     * UI thread only. Builds an adaptive banner for the current width, anchors
     * it at the bottom and shrinks the WebView to make room. `invoke` is null
     * when rebuilding after a width change, where there is no caller to answer.
     */
    private fun attachBanner(unit: String, invoke: Invoke?) {
        val webView = webViewRef ?: return
        val content = activity.findViewById<ViewGroup>(android.R.id.content)

        val metrics = activity.resources.displayMetrics
        val widthPx = content?.width?.takeIf { it > 0 } ?: metrics.widthPixels
        val adSize = AdSize.getCurrentOrientationAnchoredAdaptiveBannerAdSize(
            activity, (widthPx / metrics.density).toInt()
        )
        val heightPx = adSize.getHeightInPixels(activity)
        // The app is edge-to-edge, so the content view extends under the
        // navigation bar; anchor the banner above it (ads may not be obscured
        // by system bars).
        val navInset = bottomInsetPx()
        bannerBuiltWidth = widthPx

        val banner = AdView(activity)
        banner.adUnitId = unit
        banner.setAdSize(adSize)

        var resolved = false
        banner.adListener = object : AdListener() {
            override fun onAdLoaded() {
                if (resolved) return
                resolved = true
                if (invoke != null) {
                    val result = JSObject()
                    result.put("status", "shown")
                    invoke.resolve(result)
                }
            }

            override fun onAdFailedToLoad(error: LoadAdError) {
                // Refresh failures after the first fill keep the last ad on
                // screen; only tear down when the initial load fails.
                if (resolved) return
                resolved = true
                if (bannerView === banner) removeBanner()
                invoke?.resolve(failure(error.message))
            }
        }

        val params = FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT,
            heightPx,
            Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL
        )
        params.bottomMargin = navInset
        activity.addContentView(banner, params)

        // Reserve the space up front so the page lays out once, not again
        // when the ad fills.
        (webView.layoutParams as? ViewGroup.MarginLayoutParams)?.let {
            it.bottomMargin = navInset + heightPx
            webView.layoutParams = it
        }

        bannerView = banner
        banner.loadAd(AdRequest.Builder().build())
    }

    /**
     * Rebuild the banner when the usable width changes (rotation, resize) —
     * an adaptive banner is sized for the width it was loaded with. The
     * activity is not recreated on rotation (Tauri uses configChanges), so
     * this is the only signal. Registered once; a no-op while no banner shows.
     */
    private fun watchLayoutChanges() {
        if (bannerLayoutListener != null) return
        val content = activity.findViewById<ViewGroup>(android.R.id.content) ?: return

        val listener = View.OnLayoutChangeListener { v, left, _, right, _, _, _, _, _ ->
            val width = right - left
            if (bannerUnit == null || width == 0 || width == bannerBuiltWidth) {
                return@OnLayoutChangeListener
            }
            // Rebuilding mutates the hierarchy; defer until the layout pass ends.
            v.post {
                val unit = bannerUnit ?: return@post
                if (v.width == 0 || v.width == bannerBuiltWidth) return@post
                detachBanner()
                attachBanner(unit, null)
            }
        }
        bannerLayoutListener = listener
        content.addOnLayoutChangeListener(listener)
    }

    /** UI thread only. Removes the banner view and gives the WebView its space back. */
    private fun detachBanner() {
        bannerView?.let { banner ->
            (banner.parent as? ViewGroup)?.removeView(banner)
            banner.destroy()
        }
        bannerView = null
        bannerBuiltWidth = 0

        val webView = webViewRef ?: return
        (webView.layoutParams as? ViewGroup.MarginLayoutParams)?.let {
            if (it.bottomMargin != 0) {
                it.bottomMargin = 0
                webView.layoutParams = it
            }
        }
    }

    /** UI thread only. Fully stops the banner, including rebuild-on-rotation. */
    private fun removeBanner() {
        bannerUnit = null
        detachBanner()
    }

    private fun bottomInsetPx(): Int {
        val insets = activity.window.decorView.rootWindowInsets ?: return 0
        return if (android.os.Build.VERSION.SDK_INT >= 30) {
            insets.getInsets(android.view.WindowInsets.Type.navigationBars()).bottom
        } else {
            @Suppress("DEPRECATION")
            insets.systemWindowInsetBottom
        }
    }

    private fun failure(message: String?): JSObject {
        val result = JSObject()
        result.put("status", "failed")
        result.put("error", message ?: "ad failed")
        return result
    }
}
