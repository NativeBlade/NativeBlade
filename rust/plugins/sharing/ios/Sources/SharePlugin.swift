import SwiftRs
import Tauri
import UIKit

class ShareArgs: Decodable {
    var text: String?
    var url: String?
}

class SharePlugin: Plugin {
    @objc public func share(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(ShareArgs.self)

        var items: [Any] = []
        if let text = args.text, !text.isEmpty {
            items.append(text)
        }
        if let urlStr = args.url, !urlStr.isEmpty, let url = URL(string: urlStr) {
            items.append(url)
        }
        if items.isEmpty {
            invoke.reject("nothing to share")
            return
        }

        DispatchQueue.main.async {
            guard let vc = self.manager.viewController else {
                invoke.reject("no view controller")
                return
            }
            let activityVC = UIActivityViewController(activityItems: items, applicationActivities: nil)
            // iPad presents the share sheet as a popover anchored to a source.
            if let popover = activityVC.popoverPresentationController {
                popover.sourceView = vc.view
                popover.sourceRect = CGRect(x: vc.view.bounds.midX, y: vc.view.bounds.midY, width: 0, height: 0)
                popover.permittedArrowDirections = []
            }
            vc.present(activityVC, animated: true)
            invoke.resolve()
        }
    }
}

@_cdecl("init_plugin_nativeblade_sharing")
func initPlugin() -> Plugin {
    return SharePlugin()
}
