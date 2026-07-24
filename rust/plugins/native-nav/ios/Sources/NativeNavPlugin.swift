import Foundation
import SwiftRs
import Tauri
import UIKit
import WebKit

class SnapshotArgs: Decodable {
    let x: Double
    let y: Double
    let width: Double
    let height: Double
    let dpr: Double
}

class AnimateArgs: Decodable {
    var direction: String = "forward"
    var duration: Double = 280
}

/// The native transition compositor. `snapshot` freezes the current page as a
/// `UIImageView` pinned exactly over the app's webview region; the JS router
/// swaps the DOM instantly beneath it; `animate` slides the overlay away with a
/// native push/pop rendered by UIKit — immune to webview/wasm main-thread jank.
/// `cancel` drops the overlay on the failure/cleanup path.
class NativeNavPlugin: Plugin {
    private var overlay: UIImageView?
    private var hostWebView: WKWebView?

    @objc public override func load(webview: WKWebView) {
        super.load(webview: webview)
        hostWebView = webview
    }

    @objc public func snapshot(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(SnapshotArgs.self)

        DispatchQueue.main.async {
            guard let webView = self.hostWebView else {
                invoke.reject("webview not ready")
                return
            }

            // WKWebView coordinates are points, and 1 CSS px == 1 point at the
            // default zoom, so the rect from getBoundingClientRect() maps
            // straight across — no manual `* dpr` for the overlay frame.
            let rect = CGRect(x: args.x, y: args.y, width: args.width, height: args.height)
            if rect.width <= 0 || rect.height <= 0 {
                invoke.reject("empty snapshot rect")
                return
            }

            guard let host = webView.window ?? webView.superview else {
                invoke.reject("no host view")
                return
            }

            let config = WKSnapshotConfiguration()
            config.rect = rect                 // region in the webview's coordinate space
            config.afterScreenUpdates = false  // freeze the CURRENT page, before the DOM swap

            webView.takeSnapshot(with: config) { image, error in
                DispatchQueue.main.async {
                    guard let image = image else {
                        invoke.reject("snapshot failed: \(error?.localizedDescription ?? "unknown")")
                        return
                    }

                    self.removeOverlay()
                    let img = UIImageView(image: image)
                    img.frame = webView.convert(rect, to: host)
                    img.contentMode = .scaleToFill
                    img.isUserInteractionEnabled = false
                    host.addSubview(img)   // pinned on top of the (now-swapped) page
                    self.overlay = img
                    invoke.resolve()
                }
            }
        }
    }

    @objc public func animate(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(AnimateArgs.self)

        DispatchQueue.main.async {
            guard let img = self.overlay else {
                invoke.resolve()
                return
            }
            self.overlay = nil

            // Shared-axis slide: forward exits a short way left, back exits
            // further right (the previous page "was always there" beneath).
            let width = img.bounds.width
            let shift: CGFloat = args.direction == "back" ? width * 0.6 : -width * 0.18

            UIView.animate(
                withDuration: args.duration / 1000.0,
                delay: 0,
                options: [.curveEaseOut],
                animations: {
                    img.transform = CGAffineTransform(translationX: shift, y: 0)
                    img.alpha = 0
                },
                completion: { _ in
                    img.removeFromSuperview()
                    invoke.resolve()
                }
            )
        }
    }

    @objc public func cancel(_ invoke: Invoke) throws {
        DispatchQueue.main.async {
            self.removeOverlay()
            invoke.resolve()
        }
    }

    private func removeOverlay() {
        overlay?.removeFromSuperview()
        overlay = nil
    }
}

@_cdecl("init_plugin_nativeblade_native_nav")
func initPlugin() -> Plugin {
    return NativeNavPlugin()
}
