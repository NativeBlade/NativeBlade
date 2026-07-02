import Foundation
import Network
import SwiftRs
import Tauri
import WebKit

// Connectivity status via NWPathMonitor. `connected` means the path is
// satisfied (usable internet), `type` is the primary interface, and
// `metered` maps to the path being expensive (cellular / personal hotspot).
class NetworkPlugin: Plugin {
    private let monitor = NWPathMonitor()
    private var lastEmitted: String?

    @objc public override func load(webview: WKWebView) {
        super.load(webview: webview)
        // Watch the default path for the whole app lifetime; the monitor is
        // cheap and keeps nb:network-changed flowing with no JS setup. The
        // handler dedupes, since the monitor can re-report the same path.
        monitor.pathUpdateHandler = { [weak self] path in
            guard let self = self else { return }
            let (connected, type, metered) = Self.read(path)
            let key = "\(connected)|\(type)|\(metered)"
            if key == self.lastEmitted { return }
            self.lastEmitted = key

            var data = JSObject()
            data["connected"] = connected
            data["type"] = type
            data["metered"] = metered
            self.trigger("network-changed", data: data)
        }
        monitor.start(queue: DispatchQueue(label: "nativeblade-network"))
    }

    @objc public func getStatus(_ invoke: Invoke) {
        let (connected, type, metered) = Self.read(monitor.currentPath)
        invoke.resolve([
            "connected": connected,
            "type": type,
            "metered": metered,
        ])
    }

    private static func read(_ path: NWPath) -> (Bool, String, Bool) {
        let connected = path.status == .satisfied
        let type: String
        if !connected {
            type = "none"
        } else if path.usesInterfaceType(.wifi) {
            type = "wifi"
        } else if path.usesInterfaceType(.cellular) {
            type = "cellular"
        } else if path.usesInterfaceType(.wiredEthernet) {
            type = "ethernet"
        } else {
            type = "unknown"
        }
        return (connected, type, path.isExpensive)
    }
}

@_cdecl("init_plugin_nativeblade_network")
func initPlugin() -> Plugin {
    return NetworkPlugin()
}
