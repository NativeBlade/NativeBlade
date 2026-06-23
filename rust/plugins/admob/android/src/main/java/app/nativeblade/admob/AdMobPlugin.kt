package app.nativeblade.admob

import android.app.Activity
import android.content.pm.ApplicationInfo
import app.tauri.annotation.Command
import app.tauri.annotation.InvokeArg
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
import com.google.android.gms.ads.AdError
import com.google.android.gms.ads.AdRequest
import com.google.android.gms.ads.FullScreenContentCallback
import com.google.android.gms.ads.LoadAdError
import com.google.android.gms.ads.MobileAds
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

@TauriPlugin
class AdMobPlugin(private val activity: Activity) : Plugin(activity) {

    companion object {
        // Google's reserved test ad unit ids — served automatically in debug
        // builds so a developer never risks clicking a live ad (account ban).
        private const val TEST_REWARDED = "ca-app-pub-3940256099942544/5224354917"
        private const val TEST_INTERSTITIAL = "ca-app-pub-3940256099942544/1033173712"

        // Per-unit last-shown timestamps for interstitial frequency capping.
        private val lastShown = HashMap<String, Long>()
    }

    private val debuggable: Boolean
        get() = (activity.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0

    override fun load(webView: android.webkit.WebView) {
        super.load(webView)
        MobileAds.initialize(activity) { }
    }

    @Command
    fun requestConsent(invoke: Invoke) {
        val args = invoke.parseArgs(ConsentArgs::class.java)

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
        val unit = if (debuggable) TEST_REWARDED else args.unit

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
        val unit = if (debuggable) TEST_INTERSTITIAL else args.unit

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

    private fun failure(message: String?): JSObject {
        val result = JSObject()
        result.put("status", "failed")
        result.put("error", message ?: "ad failed")
        return result
    }
}
