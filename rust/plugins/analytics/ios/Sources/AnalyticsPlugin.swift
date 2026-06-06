import FirebaseAnalytics
import SwiftRs
import Tauri
import UIKit

enum ParamValue: Decodable {
    case string(String)
    case int(Int)
    case double(Double)
    case bool(Bool)

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if let b = try? c.decode(Bool.self) { self = .bool(b); return }
        if let i = try? c.decode(Int.self) { self = .int(i); return }
        if let d = try? c.decode(Double.self) { self = .double(d); return }
        self = .string((try? c.decode(String.self)) ?? "")
    }

    var asAny: Any {
        switch self {
        case .string(let s): return s
        case .int(let i): return i
        case .double(let d): return d
        case .bool(let b): return b
        }
    }
}

class AnalyticsOp: Decodable {
    var op: String
    var name: String?
    var key: String?
    var value: String?
    var enabled: Bool?
    var params: [String: ParamValue]?
}

class ApplyArgs: Decodable {
    var ops: [AnalyticsOp]
}

class AnalyticsPlugin: Plugin {
    @objc public func apply(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(ApplyArgs.self)

        for op in args.ops {
            switch op.op {
            case "event":
                var params: [String: Any] = [:]
                op.params?.forEach { params[$0.key] = $0.value.asAny }
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

        invoke.resolve()
    }
}

@_cdecl("init_plugin_nativeblade_analytics")
func initPlugin() -> Plugin {
    return AnalyticsPlugin()
}
