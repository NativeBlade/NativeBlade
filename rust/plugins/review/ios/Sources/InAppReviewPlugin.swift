import StoreKit
import SwiftRs
import Tauri
import UIKit

class InAppReviewPlugin: Plugin {
    @objc public func requestReview(_ invoke: Invoke) {
        DispatchQueue.main.async {
            // Prefer the scene-based API; fall back to the legacy call when no
            // foreground window scene is available. The OS rate-limits the
            // prompt and may show nothing, and never reports the outcome.
            if let scene = UIApplication.shared.connectedScenes
                .first(where: { $0.activationState == .foregroundActive }) as? UIWindowScene {
                SKStoreReviewController.requestReview(in: scene)
            } else {
                SKStoreReviewController.requestReview()
            }
            invoke.resolve()
        }
    }
}

@_cdecl("init_plugin_nativeblade_review")
func initPlugin() -> Plugin {
    return InAppReviewPlugin()
}
