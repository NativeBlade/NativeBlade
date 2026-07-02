import BackgroundTasks
import Foundation
import SwiftRs
import Tauri
import WebKit

struct RegisterArgs: Decodable {
    let tasksJson: String
    let dataDir: String
}

struct CollectArgs: Decodable {
    let withLocation: Bool?
    let bearerFromSecure: String?
}

// C entry into the Rust courier (staticlib linked into the binary).
@_silgen_name("nativeblade_tasks_run")
func nativeblade_tasks_run(_ def: UnsafePointer<CChar>, _ collected: UnsafePointer<CChar>, _ dataDir: UnsafePointer<CChar>) -> Int32

/**
 * iOS adapter of the task courier. BGAppRefreshTask is opportunistic — the
 * floor of the guarantee is the catch-up on open (Rust side); whatever the
 * system grants here is bonus. Handlers can only be registered for
 * identifiers known at launch, so the manifest is persisted and handlers are
 * registered from the PREVIOUS session's manifest at load(); new tasks gain
 * background runs from the next launch on.
 */
class TasksPlugin: Plugin {
    static let defaultsKey = "nativeblade_tasks_manifest"
    static let dataDirKey = "nativeblade_tasks_datadir"
    static let idPrefix = "app.nativeblade.task."

    @objc public override func load(webview: WKWebView) {
        super.load(webview: webview)
        for task in Self.storedTasks() {
            guard let name = task["name"] as? String else { continue }
            BGTaskScheduler.shared.register(
                forTaskWithIdentifier: Self.idPrefix + name,
                using: nil
            ) { bgTask in
                Self.handle(bgTask, name: name)
            }
        }
    }

    @objc public func registerTasks(_ invoke: Invoke) {
        guard let args = try? invoke.parseArgs(RegisterArgs.self),
              let data = args.tasksJson.data(using: .utf8),
              let tasks = try? JSONSerialization.jsonObject(with: data) as? [[String: Any]]
        else {
            invoke.reject("invalid args")
            return
        }

        let defaults = UserDefaults.standard
        defaults.set(tasks, forKey: Self.defaultsKey)
        defaults.set(args.dataDir, forKey: Self.dataDirKey)

        for task in tasks {
            if let name = task["name"] as? String {
                Self.submit(name: name, minutes: task["everyMinutes"] as? Double ?? 60)
            }
        }
        invoke.resolve()
    }

    /// Platform data for in-app runs. Location/Keychain collection on iOS is
    /// still pending — tasks run with what Rust has.
    @objc public func collect(_ invoke: Invoke) {
        invoke.resolve([:] as [String: Any])
    }

    private static func storedTasks() -> [[String: Any]] {
        UserDefaults.standard.array(forKey: defaultsKey) as? [[String: Any]] ?? []
    }

    private static func submit(name: String, minutes: Double) {
        let request = BGAppRefreshTaskRequest(identifier: idPrefix + name)
        request.earliestBeginDate = Date(timeIntervalSinceNow: minutes * 60)
        try? BGTaskScheduler.shared.submit(request)
    }

    private static func handle(_ bgTask: BGTask, name: String) {
        // Re-submit first so the chain survives whatever happens below.
        let tasks = storedTasks()
        let def = tasks.first { ($0["name"] as? String) == name }
        submit(name: name, minutes: def?["everyMinutes"] as? Double ?? 60)

        guard let def,
              let defData = try? JSONSerialization.data(withJSONObject: def),
              let defJson = String(data: defData, encoding: .utf8),
              let dataDir = UserDefaults.standard.string(forKey: dataDirKey)
        else {
            bgTask.setTaskCompleted(success: false)
            return
        }

        bgTask.expirationHandler = {
            bgTask.setTaskCompleted(success: false)
        }

        DispatchQueue.global(qos: .background).async {
            let ok = defJson.withCString { d in
                "{}".withCString { c in
                    dataDir.withCString { dir in
                        nativeblade_tasks_run(d, c, dir)
                    }
                }
            }
            bgTask.setTaskCompleted(success: ok == 1)
        }
    }
}

@_cdecl("init_plugin_nativeblade_tasks")
func initPlugin() -> Plugin {
    return TasksPlugin()
}
