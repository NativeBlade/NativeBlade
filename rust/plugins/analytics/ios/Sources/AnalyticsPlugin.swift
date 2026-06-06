import Foundation
#if canImport(FirebaseAnalytics)
import FirebaseAnalytics
import FirebaseCore
#endif
import SwiftRs
import Tauri

enum NBParamValue: Decodable {
    case string(String)
    case int(Int)
    case double(Double)
    case bool(Bool)

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let value = try? container.decode(Bool.self) {
            self = .bool(value)
        } else if let value = try? container.decode(Int.self) {
            self = .int(value)
        } else if let value = try? container.decode(Double.self) {
            self = .double(value)
        } else {
            self = .string((try? container.decode(String.self)) ?? "")
        }
    }

    var firebaseValue: Any {
        switch self {
        case .string(let value): return value
        case .int(let value): return value
        case .double(let value): return value
        case .bool(let value): return value
        }
    }
}

struct NBAnalyticsOp: Decodable {
    let op: String
    let name: String?
    let key: String?
    let value: String?
    let enabled: Bool?
    let params: [String: NBParamValue]?
}

struct NBApplyArgs: Decodable {
    let ops: [NBAnalyticsOp]
}

class AnalyticsPlugin: Plugin {
    @objc public func apply(_ invoke: Invoke) throws {
        // Compiled against Firebase only where the module is available; on the
        // macOS pass swift-rs runs (no Firebase there) this is a no-op so the
        // package still compiles.
        #if canImport(FirebaseAnalytics)
        // iOS has no google-services auto-init; configure on first use. Reads
        // GoogleService-Info.plist from the app bundle.
        if FirebaseApp.app() == nil {
            FirebaseApp.configure()
        }

        let args = try invoke.parseArgs(NBApplyArgs.self)

        for op in args.ops {
            switch op.op {
            case "event":
                var params: [String: Any] = [:]
                if let opParams = op.params {
                    for (paramKey, paramValue) in opParams {
                        params[paramKey] = paramValue.firebaseValue
                    }
                }
                Analytics.logEvent(op.name ?? "", parameters: params.isEmpty ? nil : params)
            case "screen":
                Analytics.logEvent(
                    AnalyticsEventScreenView,
                    parameters: [AnalyticsParameterScreenName: op.name ?? ""]
                )
            case "userId":
                Analytics.setUserID(op.value)
            case "userProperty":
                Analytics.setUserProperty(op.value, forName: op.key ?? "")
            case "setEnabled":
                Analytics.setAnalyticsCollectionEnabled(op.enabled ?? true)
            default:
                break
            }
        }
        #endif

        invoke.resolve()
    }
}

@_cdecl("init_plugin_nativeblade_analytics")
func initPlugin() -> Plugin {
    return AnalyticsPlugin()
}
