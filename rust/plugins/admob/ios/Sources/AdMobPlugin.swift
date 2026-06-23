import AppTrackingTransparency
import Foundation
import GoogleMobileAds
import GoogleUserMessagingPlatform
import SwiftRs
import Tauri
import UIKit

private struct RewardedArgs: Decodable {
    let unit: String
    let id: String?
}

private struct InterstitialArgs: Decodable {
    let unit: String
    let id: String?
    let minInterval: UInt64?
}

private final class RewardedDelegate: NSObject, FullScreenContentDelegate {
    let invoke: Invoke
    let id: String?
    let rewardProvider: () -> [String: Any]?
    private var resolved = false

    init(invoke: Invoke, id: String?, rewardProvider: @escaping () -> [String: Any]?) {
        self.invoke = invoke
        self.id = id
        self.rewardProvider = rewardProvider
    }

    func ad(_ ad: FullScreenPresentingAd, didFailToPresentFullScreenContentWithError error: Error) {
        resolve(status: "failed", error: error.localizedDescription)
    }

    func adDidDismissFullScreenContent(_ ad: FullScreenPresentingAd) {
        resolve(status: "dismissed")
    }

    private func resolve(status: String, error: String? = nil) {
        guard !resolved else { return }
        resolved = true

        var payload: [String: Any] = ["status": status, "id": id as Any]
        payload["error"] = error as Any
        if let reward = rewardProvider() {
            payload["reward"] = reward
        }
        invoke.resolve(payload)
    }
}

private final class InterstitialDelegate: NSObject, FullScreenContentDelegate {
    let invoke: Invoke
    let id: String?
    let onShown: () -> Void
    private var resolved = false

    init(invoke: Invoke, id: String?, onShown: @escaping () -> Void) {
        self.invoke = invoke
        self.id = id
        self.onShown = onShown
    }

    func adWillPresentFullScreenContent(_ ad: FullScreenPresentingAd) {
        onShown()
    }

    func ad(_ ad: FullScreenPresentingAd, didFailToPresentFullScreenContentWithError error: Error) {
        resolve(status: "failed", error: error.localizedDescription)
    }

    func adDidDismissFullScreenContent(_ ad: FullScreenPresentingAd) {
        resolve(status: "dismissed")
    }

    private func resolve(status: String, error: String? = nil) {
        guard !resolved else { return }
        resolved = true
        invoke.resolve([
            "status": status,
            "id": id as Any,
            "error": error as Any,
        ])
    }
}

class AdMobPlugin: Plugin {
    private var retainedDelegates: [NSObject] = []

    @objc public func requestAdConsent(_ invoke: Invoke) {
        DispatchQueue.main.async {
            self.requestTrackingThenConsent(invoke)
        }
    }

    @objc public func showRewarded(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(RewardedArgs.self)
        guard !args.unit.isEmpty else {
            invoke.resolve(["status": "failed", "id": args.id as Any, "error": "missing rewarded ad unit"])
            return
        }

        DispatchQueue.main.async {
            Task {
                do {
                    let ad = try await RewardedAd.load(with: args.unit, request: Request())
                    var rewardPayload: [String: Any]? = nil
                    let delegate = RewardedDelegate(invoke: invoke, id: args.id) { rewardPayload }
                    self.retain(delegate)
                    ad.fullScreenContentDelegate = delegate
                    ad.present(from: self.rootViewController()) {
                        let reward = ad.adReward
                        rewardPayload = [
                            "earned": true,
                            "amount": reward.amount,
                            "type": reward.type,
                        ]
                    }
                } catch {
                    invoke.resolve([
                        "status": "failed",
                        "id": args.id as Any,
                        "error": error.localizedDescription,
                    ])
                }
            }
        }
    }

    @objc public func showInterstitial(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(InterstitialArgs.self)
        guard !args.unit.isEmpty else {
            invoke.resolve(["status": "failed", "id": args.id as Any, "error": "missing interstitial ad unit"])
            return
        }

        let capKey = "interstitial:\(args.id ?? args.unit)"
        let now = UInt64(Date().timeIntervalSince1970)
        if let minInterval = args.minInterval, minInterval > 0 {
            let lastShownAt = UInt64(UserDefaults.standard.integer(forKey: capKey))
            if lastShownAt > 0 && now - lastShownAt < minInterval {
                invoke.resolve(["status": "capped", "id": args.id as Any, "error": NSNull()])
                return
            }
        }

        DispatchQueue.main.async {
            Task {
                do {
                    let ad = try await InterstitialAd.load(with: args.unit, request: Request())
                    let delegate = InterstitialDelegate(invoke: invoke, id: args.id) {
                        UserDefaults.standard.set(Int(Date().timeIntervalSince1970), forKey: capKey)
                    }
                    self.retain(delegate)
                    ad.fullScreenContentDelegate = delegate
                    ad.present(from: self.rootViewController())
                } catch {
                    invoke.resolve([
                        "status": "failed",
                        "id": args.id as Any,
                        "error": error.localizedDescription,
                    ])
                }
            }
        }
    }

    private func requestTrackingThenConsent(_ invoke: Invoke) {
        if #available(iOS 14, *) {
            ATTrackingManager.requestTrackingAuthorization { _ in
                self.requestConsent(invoke)
            }
        } else {
            requestConsent(invoke)
        }
    }

    private func requestConsent(_ invoke: Invoke) {
        let parameters = RequestParameters()
        ConsentInformation.shared.requestConsentInfoUpdate(with: parameters) { error in
            if let error {
                invoke.reject(error.localizedDescription)
                return
            }

            ConsentForm.loadAndPresentIfRequired(from: self.rootViewController()) { formError in
                MobileAds.shared.start(completionHandler: nil)
                if let formError {
                    invoke.reject(formError.localizedDescription)
                } else {
                    invoke.resolve()
                }
            }
        }
    }

    private func rootViewController() -> UIViewController {
        if let root = UIApplication.shared.connectedScenes
            .compactMap({ $0 as? UIWindowScene })
            .flatMap({ $0.windows })
            .first(where: \.isKeyWindow)?
            .rootViewController {
            return root
        }

        return UIApplication.shared.windows.first?.rootViewController ?? UIViewController()
    }

    private func retain(_ delegate: NSObject) {
        retainedDelegates.append(delegate)
        if retainedDelegates.count > 8 {
            retainedDelegates.removeFirst(retainedDelegates.count - 8)
        }
    }
}

@_cdecl("init_plugin_nativeblade_admob")
func initPlugin() -> Plugin {
    return AdMobPlugin()
}
