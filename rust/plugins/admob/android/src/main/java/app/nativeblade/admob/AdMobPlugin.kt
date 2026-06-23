package app.nativeblade.admob

import android.app.Activity
import android.content.Context
import app.tauri.annotation.Command
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
import com.google.android.gms.ads.rewarded.RewardItem
import com.google.android.gms.ads.rewarded.RewardedAd
import com.google.android.gms.ads.rewarded.RewardedAdLoadCallback
import com.google.android.ump.ConsentRequestParameters
import com.google.android.ump.UserMessagingPlatform

@TauriPlugin
class AdMobPlugin(private val activity: Activity) : Plugin(activity) {

    private val prefs by lazy {
        activity.getSharedPreferences("nativeblade_admob", Context.MODE_PRIVATE)
    }

    @Command
    fun requestAdConsent(invoke: Invoke) {
        activity.runOnUiThread {
            try {
                val params = ConsentRequestParameters.Builder().build()
                val consentInformation = UserMessagingPlatform.getConsentInformation(activity)
                consentInformation.requestConsentInfoUpdate(
                    activity,
                    params,
                    {
                        UserMessagingPlatform.loadAndShowConsentFormIfRequired(activity) { error ->
                            MobileAds.initialize(activity) {}
                            if (error != null) {
                                invoke.reject(error.message ?: "ad consent failed")
                            } else {
                                invoke.resolve()
                            }
                        }
                    },
                    { error ->
                        invoke.reject(error.message)
                    }
                )
            } catch (e: Exception) {
                invoke.reject(e.message ?: "ad consent failed")
            }
        }
    }

    @Command
    fun showRewarded(invoke: Invoke) {
        val args = invoke.getArgs()
        val unit = args.optString("unit")
        val id = args.optString("id").ifEmpty { null }

        if (unit.isBlank()) {
            invoke.resolve(result("failed", id = id, error = "missing rewarded ad unit"))
            return
        }

        activity.runOnUiThread {
            MobileAds.initialize(activity) {}

            RewardedAd.load(
                activity,
                unit,
                AdRequest.Builder().build(),
                object : RewardedAdLoadCallback() {
                    override fun onAdFailedToLoad(error: LoadAdError) {
                        invoke.resolve(result("failed", id = id, error = error.message))
                    }

                    override fun onAdLoaded(ad: RewardedAd) {
                        var reward: RewardItem? = null
                        var resolved = false

                        ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                            override fun onAdFailedToShowFullScreenContent(error: AdError) {
                                if (!resolved) {
                                    resolved = true
                                    invoke.resolve(result("failed", id = id, error = error.message))
                                }
                            }

                            override fun onAdDismissedFullScreenContent() {
                                if (!resolved) {
                                    resolved = true
                                    invoke.resolve(result("dismissed", id = id, reward = reward))
                                }
                            }
                        }

                        ad.show(activity) { item ->
                            reward = item
                        }
                    }
                }
            )
        }
    }

    @Command
    fun showInterstitial(invoke: Invoke) {
        val args = invoke.getArgs()
        val unit = args.optString("unit")
        val id = args.optString("id").ifEmpty { null }
        val minInterval = if (args.has("minInterval")) args.optLong("minInterval", 0L) else null

        if (unit.isBlank()) {
            invoke.resolve(result("failed", id = id, error = "missing interstitial ad unit"))
            return
        }

        val capKey = "interstitial:" + (id ?: unit)
        val now = System.currentTimeMillis() / 1000L
        if (minInterval != null && minInterval > 0L) {
            val lastShownAt = prefs.getLong(capKey, 0L)
            if (lastShownAt > 0L && now - lastShownAt < minInterval) {
                invoke.resolve(result("capped", id = id))
                return
            }
        }

        activity.runOnUiThread {
            MobileAds.initialize(activity) {}

            InterstitialAd.load(
                activity,
                unit,
                AdRequest.Builder().build(),
                object : InterstitialAdLoadCallback() {
                    override fun onAdFailedToLoad(error: LoadAdError) {
                        invoke.resolve(result("failed", id = id, error = error.message))
                    }

                    override fun onAdLoaded(ad: InterstitialAd) {
                        var resolved = false
                        ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                            override fun onAdShowedFullScreenContent() {
                                prefs.edit().putLong(capKey, System.currentTimeMillis() / 1000L).apply()
                            }

                            override fun onAdFailedToShowFullScreenContent(error: AdError) {
                                if (!resolved) {
                                    resolved = true
                                    invoke.resolve(result("failed", id = id, error = error.message))
                                }
                            }

                            override fun onAdDismissedFullScreenContent() {
                                if (!resolved) {
                                    resolved = true
                                    invoke.resolve(result("dismissed", id = id))
                                }
                            }
                        }

                        ad.show(activity)
                    }
                }
            )
        }
    }

    private fun result(
        status: String,
        id: String? = null,
        error: String? = null,
        reward: RewardItem? = null
    ): JSObject {
        val result = JSObject()
        result.put("status", status)
        result.put("id", id)
        if (error != null) {
            result.put("error", error)
        } else {
            result.put("error", null)
        }
        if (reward != null) {
            result.put("reward", JSObject().apply {
                put("earned", true)
                put("amount", reward.amount)
                put("type", reward.type)
            })
        }
        return result
    }
}
