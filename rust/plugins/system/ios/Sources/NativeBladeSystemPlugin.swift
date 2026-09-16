import SwiftRs
import Tauri
import UIKit

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
}

@_cdecl("init_plugin_nativeblade_system")
func initPlugin() -> Plugin {
    return NativeBladeSystemPlugin()
}
