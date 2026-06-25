import Foundation
#if canImport(GoogleMobileAds)
import GoogleMobileAds
import UserMessagingPlatform
import AppTrackingTransparency
import UIKit
#endif
import SwiftRs
import Tauri

struct NBConsentArgs: Decodable {
    let testDeviceIds: [String]?
}

struct NBRewardedArgs: Decodable {
    let unit: String
    let id: String?
}

struct NBInterstitialArgs: Decodable {
    let unit: String
    let id: String?
    let minInterval: Double?
}

class AdMobPlugin: Plugin {
    // Google's reserved test ad unit ids, served in DEBUG builds so a developer
    // never risks clicking a live ad (account ban).
    private static let testRewarded = "ca-app-pub-3940256099942544/1712485313"
    private static let testInterstitial = "ca-app-pub-3940256099942544/4411468910"

    private static var lastShown: [String: TimeInterval] = [:]

    // Set once test devices are registered: real ad units then serve test ads
    // on those devices, so we stop substituting the test unit and exercise the
    // real id safely.
    private static var hasTestDevices = false

    #if canImport(GoogleMobileAds)
    private var rewardedAd: RewardedAd?
    private var interstitialAd: InterstitialAd?
    private var contentDelegate: FullScreenDelegate?
    #endif

    @objc public override func load(webview: WKWebView) {
        super.load(webview: webview)
        #if canImport(GoogleMobileAds)
        MobileAds.shared.start(completionHandler: nil)
        #endif
    }

    @objc public func requestConsent(_ invoke: Invoke) {
        #if canImport(GoogleMobileAds)
        let testIds = (try? invoke.parseArgs(NBConsentArgs.self))?.testDeviceIds ?? []
        if !testIds.isEmpty {
            Self.hasTestDevices = true
            MobileAds.shared.requestConfiguration.testDeviceIdentifiers = testIds
        }

        ATTrackingManager.requestTrackingAuthorization { _ in
            DispatchQueue.main.async {
                let params = RequestParameters()
                // Force the EEA consent form in debug for registered test
                // devices, mirroring the Android side.
                if Self.isDebug && !testIds.isEmpty {
                    let debugSettings = DebugSettings()
                    debugSettings.geography = .EEA
                    debugSettings.testDeviceIdentifiers = testIds
                    params.debugSettings = debugSettings
                }
                ConsentInformation.shared.requestConsentInfoUpdate(with: params) { error in
                    let finish: () -> Void = {
                        invoke.resolve([
                            "canRequestAds": ConsentInformation.shared.canRequestAds,
                            "error": error?.localizedDescription as Any,
                        ])
                    }
                    if let root = Self.rootViewController() {
                        ConsentForm.loadAndPresentIfRequired(from: root) { _ in finish() }
                    } else {
                        finish()
                    }
                }
            }
        }
        #else
        invoke.resolve(["canRequestAds": false])
        #endif
    }

    @objc public func showRewarded(_ invoke: Invoke) {
        #if canImport(GoogleMobileAds)
        guard let args = try? invoke.parseArgs(NBRewardedArgs.self) else {
            invoke.resolve(Self.failure("invalid args"))
            return
        }
        let unit = (Self.isDebug && !Self.hasTestDevices) ? Self.testRewarded : args.unit

        RewardedAd.load(with: unit, request: Request()) { [weak self] ad, error in
            guard let self = self else { return }
            if let error = error {
                invoke.resolve(Self.failure(error.localizedDescription))
                return
            }
            guard let ad = ad, let root = Self.rootViewController() else {
                invoke.resolve(Self.failure("no ad or root view controller"))
                return
            }

            self.rewardedAd = ad
            var earned = false
            let delegate = FullScreenDelegate { reason in
                let reward = ad.adReward
                invoke.resolve([
                    "status": reason,
                    "earned": earned,
                    "amount": reward.amount,
                    "type": reward.type,
                ])
            }
            self.contentDelegate = delegate
            ad.fullScreenContentDelegate = delegate

            // The present handler is the reward callback: it fires only when the
            // user actually earns the reward (mirrors the Android show listener).
            // It runs before the dismiss delegate, so `earned` is set by then.
            ad.present(from: root) {
                earned = true
            }
        }
        #else
        invoke.resolve(Self.failure("unsupported"))
        #endif
    }

    @objc public func showInterstitial(_ invoke: Invoke) {
        #if canImport(GoogleMobileAds)
        guard let args = try? invoke.parseArgs(NBInterstitialArgs.self) else {
            invoke.resolve(Self.failure("invalid args"))
            return
        }

        if let minInterval = args.minInterval {
            let last = Self.lastShown[args.unit] ?? 0
            if Date().timeIntervalSince1970 - last < minInterval {
                invoke.resolve(["status": "capped"])
                return
            }
        }

        let unit = (Self.isDebug && !Self.hasTestDevices) ? Self.testInterstitial : args.unit

        InterstitialAd.load(with: unit, request: Request()) { [weak self] ad, error in
            guard let self = self else { return }
            if let error = error {
                invoke.resolve(Self.failure(error.localizedDescription))
                return
            }
            guard let ad = ad, let root = Self.rootViewController() else {
                invoke.resolve(Self.failure("no ad or root view controller"))
                return
            }

            self.interstitialAd = ad
            let delegate = FullScreenDelegate { reason in
                invoke.resolve(["status": reason])
            }
            self.contentDelegate = delegate
            ad.fullScreenContentDelegate = delegate

            Self.lastShown[args.unit] = Date().timeIntervalSince1970
            ad.present(from: root)
        }
        #else
        invoke.resolve(Self.failure("unsupported"))
        #endif
    }

    private static var isDebug: Bool {
        #if DEBUG
        return true
        #else
        return false
        #endif
    }

    private static func failure(_ message: String) -> [String: Any] {
        return ["status": "failed", "error": message]
    }

    #if canImport(GoogleMobileAds)
    private static func rootViewController() -> UIViewController? {
        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
        for scene in scenes {
            if let root = scene.windows.first(where: { $0.isKeyWindow })?.rootViewController {
                return root
            }
        }
        return scenes.first?.windows.first?.rootViewController
    }
    #endif
}

#if canImport(GoogleMobileAds)
class FullScreenDelegate: NSObject, FullScreenContentDelegate {
    private let onFinish: (String) -> Void
    private var finished = false

    init(_ onFinish: @escaping (String) -> Void) {
        self.onFinish = onFinish
    }

    private func finish(_ reason: String) {
        if finished { return }
        finished = true
        onFinish(reason)
    }

    func ad(_ ad: FullScreenPresentingAd, didFailToPresentFullScreenContentWithError error: Error) {
        finish("failed")
    }

    func adDidDismissFullScreenContent(_ ad: FullScreenPresentingAd) {
        finish("dismissed")
    }
}
#endif

@_cdecl("init_plugin_nativeblade_admob")
func initPlugin() -> Plugin {
    return AdMobPlugin()
}
