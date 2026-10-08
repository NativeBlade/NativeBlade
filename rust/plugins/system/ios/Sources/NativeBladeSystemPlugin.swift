import SwiftRs
import Tauri
import UIKit

struct NBStatusBarStyleArgs: Decodable {
    /// "dark" for dark icons on a light background, "light" for light icons on a dark one.
    let style: String
}

class NativeBladeSystemPlugin: Plugin {
    @objc public func openAppSettings(_ invoke: Invoke) {
        DispatchQueue.main.async {
            guard let url = URL(string: UIApplication.openSettingsURLString) else {
                invoke.reject("failed to build the app settings URL")
                return
            }
            UIApplication.shared.open(url, options: [:]) { success in
                if success {
                    invoke.resolve()
                } else {
                    invoke.reject("failed to open the app settings URL")
                }
            }
        }
    }

    /// Runtime counterpart of `->statusBar(style:)` in the build config. Relies on
    /// `UIViewControllerBasedStatusBarAppearance = false` in Info.plist, which
    /// nativeblade:config writes, so the application-level style applies.
    @objc public func setStatusBarStyle(_ invoke: Invoke) {
        guard let args = try? invoke.parseArgs(NBStatusBarStyleArgs.self),
              args.style == "dark" || args.style == "light" else {
            invoke.reject("style must be \"dark\" or \"light\"")
            return
        }
        let style: UIStatusBarStyle = args.style == "light" ? .lightContent : .darkContent

        DispatchQueue.main.async {
            UIApplication.shared.setStatusBarStyle(style, animated: true)
            invoke.resolve()
        }
    }
}

@_cdecl("init_plugin_nativeblade_system")
func initPlugin() -> Plugin {
    return NativeBladeSystemPlugin()
}
