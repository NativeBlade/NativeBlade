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

struct NBBannerArgs: Decodable {
    let unit: String
    let id: String?
}

class AdMobPlugin: Plugin {
    // Google's reserved test ad unit ids, served in DEBUG builds so a developer
    // never risks clicking a live ad (account ban).
    private static let testRewarded = "ca-app-pub-3940256099942544/1712485313"
    private static let testInterstitial = "ca-app-pub-3940256099942544/4411468910"
    private static let testBanner = "ca-app-pub-3940256099942544/2435281174"

    private static var lastShown: [String: TimeInterval] = [:]

    // Set once test devices are registered: real ad units then serve test ads
    // on those devices, so we stop substituting the test unit and exercise the
    // real id safely.
    private static var hasTestDevices = false

    #if canImport(GoogleMobileAds)
    private var rewardedAd: RewardedAd?
    private var interstitialAd: InterstitialAd?
    private var contentDelegate: FullScreenDelegate?
    private var bannerView: BannerView?
    private var bannerDelegate: BannerAdDelegate?
    private var bannerUnit: String?
    private var bannerBuiltWidth: CGFloat = 0
    private var orientationObserver: NSObjectProtocol?
    // Callers waiting on the in-flight consent flow. Main thread only.
    private var consentWaiters: [Invoke] = []
    #endif
    private weak var webviewRef: WKWebView?

    @objc public override func load(webview: WKWebView) {
        super.load(webview: webview)
        webviewRef = webview
        #if canImport(GoogleMobileAds)
        MobileAds.shared.start(completionHandler: nil)
        #endif
    }

    @objc public func requestConsent(_ invoke: Invoke) {
        #if canImport(GoogleMobileAds)
        let testIds = (try? invoke.parseArgs(NBConsentArgs.self))?.testDeviceIds ?? []

        DispatchQueue.main.async {
            // Test device registration is a global SDK side effect that every
            // caller's later ad loads depend on, so apply it even for calls
            // that coalesce below.
            if !testIds.isEmpty {
                Self.hasTestDevices = true
                MobileAds.shared.requestConfiguration.testDeviceIdentifiers = testIds
            }

            // The ATT prompt and UMP form are modal and global: a second flow
            // started while one is up queues another form the user must
            // dismiss again. Coalesce concurrent calls into the in-flight flow.
            self.consentWaiters.append(invoke)
            if self.consentWaiters.count > 1 { return }

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
                            self.finishConsent(ConsentInformation.shared.canRequestAds, error?.localizedDescription)
                        }
                        if let root = Self.rootViewController() {
                            ConsentForm.loadAndPresentIfRequired(from: root) { _ in finish() }
                        } else {
                            finish()
                        }
                    }
                }
            }
        }
        #else
        invoke.resolve(["canRequestAds": false])
        #endif
    }

    #if canImport(GoogleMobileAds)
    /// Main thread only. Resolves every caller waiting on the shared consent flow.
    private func finishConsent(_ canRequestAds: Bool, _ error: String?) {
        let waiters = consentWaiters
        consentWaiters.removeAll()
        for invoke in waiters {
            invoke.resolve([
                "canRequestAds": canRequestAds,
                "error": error as Any,
            ])
        }
    }
    #endif

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

    @objc public func showBanner(_ invoke: Invoke) {
        #if canImport(GoogleMobileAds)
        guard let args = try? invoke.parseArgs(NBBannerArgs.self) else {
            invoke.resolve(Self.failure("invalid args"))
            return
        }
        let unit = (Self.isDebug && !Self.hasTestDevices) ? Self.testBanner : args.unit

        DispatchQueue.main.async {
            // Showing again replaces the current banner (e.g. a new unit).
            self.removeBanner()

            guard self.webviewRef?.superview != nil else {
                invoke.resolve(Self.failure("no webview"))
                return
            }

            self.bannerUnit = unit
            self.attachBanner(unit: unit, invoke: invoke)
            self.watchLayoutChanges()
        }
        #else
        invoke.resolve(Self.failure("unsupported"))
        #endif
    }

    @objc public func hideBanner(_ invoke: Invoke) {
        #if canImport(GoogleMobileAds)
        DispatchQueue.main.async {
            self.removeBanner()
            invoke.resolve()
        }
        #else
        invoke.resolve()
        #endif
    }

    #if canImport(GoogleMobileAds)
    /// Main thread only. Builds an adaptive banner for the current width,
    /// anchors it above the home indicator and shrinks the WebView to make
    /// room. `invoke` is nil when rebuilding after a rotation, where there is
    /// no caller to answer.
    private func attachBanner(unit: String, invoke: Invoke?) {
        guard let webview = webviewRef, let superview = webview.superview,
              let root = Self.rootViewController() else {
            invoke?.resolve(Self.failure("no webview"))
            return
        }

        let bounds = superview.bounds
        // Keep the banner above the home indicator (ads may not sit under
        // system UI).
        let bottomInset = superview.safeAreaInsets.bottom
        let adSize = currentOrientationAnchoredAdaptiveBanner(width: bounds.width)
        let height = adSize.size.height
        bannerBuiltWidth = bounds.width

        let banner = BannerView(adSize: adSize)
        banner.adUnitID = unit
        banner.rootViewController = root
        banner.frame = CGRect(
            x: 0,
            y: bounds.height - bottomInset - height,
            width: bounds.width,
            height: height
        )
        banner.autoresizingMask = [.flexibleWidth, .flexibleTopMargin]

        let delegate = BannerAdDelegate(
            onLoad: {
                invoke?.resolve(["status": "shown"])
            },
            onFail: { [weak self] message in
                if let self = self, self.bannerView === banner {
                    self.removeBanner()
                }
                invoke?.resolve(Self.failure(message))
            }
        )
        bannerDelegate = delegate
        banner.delegate = delegate

        superview.addSubview(banner)
        bannerView = banner

        // Reserve the space up front so the page lays out once, not again
        // when the ad fills.
        var frame = webview.frame
        frame.size.height = banner.frame.minY - frame.origin.y
        webview.frame = frame

        banner.load(Request())
    }

    /// Rebuild the banner when the usable width changes (rotation) — an
    /// adaptive banner is sized for the width it was loaded with. Registered
    /// while a banner is showing; removeBanner() unregisters it.
    private func watchLayoutChanges() {
        if orientationObserver != nil { return }
        UIDevice.current.beginGeneratingDeviceOrientationNotifications()
        orientationObserver = NotificationCenter.default.addObserver(
            forName: UIDevice.orientationDidChangeNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            // The notification fires before the window finishes rotating; give
            // the layout pass time to settle before measuring the new width.
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.15) {
                guard let self = self, let unit = self.bannerUnit,
                      let width = self.webviewRef?.superview?.bounds.width,
                      width != self.bannerBuiltWidth else { return }
                self.detachBanner()
                self.attachBanner(unit: unit, invoke: nil)
            }
        }
    }

    /// Main thread only. Removes the banner view and gives the WebView its
    /// space back.
    private func detachBanner() {
        bannerView?.removeFromSuperview()
        bannerView = nil
        bannerDelegate = nil
        bannerBuiltWidth = 0

        if let webview = webviewRef, let superview = webview.superview {
            webview.frame = superview.bounds
        }
    }

    /// Main thread only. Fully stops the banner, including rebuild-on-rotation.
    private func removeBanner() {
        bannerUnit = nil
        detachBanner()

        // Stop watching for rotation: device-orientation notifications keep
        // the accelerometer reporting active, which costs battery for nothing
        // once no banner is showing.
        if let observer = orientationObserver {
            NotificationCenter.default.removeObserver(observer)
            orientationObserver = nil
            UIDevice.current.endGeneratingDeviceOrientationNotifications()
        }
    }
    #endif

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

class BannerAdDelegate: NSObject, BannerViewDelegate {
    private let onLoad: () -> Void
    private let onFail: (String) -> Void
    private var finished = false

    init(onLoad: @escaping () -> Void, onFail: @escaping (String) -> Void) {
        self.onLoad = onLoad
        self.onFail = onFail
    }

    func bannerViewDidReceiveAd(_ bannerView: BannerView) {
        if finished { return }
        finished = true
        onLoad()
    }

    func bannerView(_ bannerView: BannerView, didFailToReceiveAdWithError error: Error) {
        // Refresh failures after the first fill keep the last ad on screen;
        // only report when the initial load fails.
        if finished { return }
        finished = true
        onFail(error.localizedDescription)
    }
}
#endif

@_cdecl("init_plugin_nativeblade_admob")
func initPlugin() -> Plugin {
    return AdMobPlugin()
}
