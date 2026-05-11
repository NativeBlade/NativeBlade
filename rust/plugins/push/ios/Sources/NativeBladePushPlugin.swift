import ObjectiveC.runtime
import SwiftRs
import Tauri
import UIKit
import UserNotifications
import WebKit

// MARK: - Command arg structs

struct NotifyArgs: Decodable {
    let id: String?
    let title: String?
    let body: String?
    let sound: String?
    let icon: String?
    let channel: String?
    let schedule: NotifySchedule?
}

struct NotifySchedule: Decodable {
    let type: String
    let at: String?
    let kind: String?
    let count: Int?
    let time: String?
}

struct CancelArgs: Decodable {
    let id: String?
}

/// NativeBlade push notifications plugin for iOS.
///
/// Wires APNS (Apple Push Notification Service) into Tauri events that
/// the JS layer can listen for. The plugin is passive: it registers with
/// APNS at app boot, emits `nativeblade-push-token` when the device token
/// is delivered by the OS, and emits `nativeblade-push` whenever a push
/// is received.
///
/// ## iOS architecture caveats
///
/// - The device token arrives via
///   `UIApplicationDelegate.application(_:didRegisterForRemoteNotificationsWithDeviceToken:)`,
///   which a Tauri plugin cannot override directly because the AppDelegate
///   is owned by the app host. We use **method swizzling** (same
///   technique as Firebase iOS SDK) to patch the app delegate at runtime
///   and capture the token when it arrives.
///
/// - Incoming pushes are delivered via `UNUserNotificationCenterDelegate`.
///   This plugin assigns itself as the delegate on load. If you also use
///   `tauri-plugin-notification` for local notifications, be aware that
///   only one UN delegate can be set at a time — the plugin that loads
///   last wins. In practice this is fine for most apps since push and
///   local notifications don't usually share the same callback paths.
class NativeBladePushPlugin: Plugin {

    static weak var instance: NativeBladePushPlugin?

    private var active: Bool = false

    override init() {
        super.init()
        NativeBladePushPlugin.instance = self
    }

    override func load(webview: WKWebView) {
        super.load(webview: webview)

        guard hasAPSEntitlement() else {
            NSLog("nativeblade-push: aps-environment entitlement missing — plugin inert")
            return
        }

        active = true

        AppDelegateSwizzler.installIfNeeded()

        let center = UNUserNotificationCenter.current()
        AppDelegateSwizzler.previousUNDelegate = center.delegate
        center.delegate = AppDelegateSwizzler.shared

        if let token = PendingPushes.latestToken {
            emitToken(token)
        }

        DispatchQueue.main.async {
            UIApplication.shared.registerForRemoteNotifications()
        }
    }

    private func hasAPSEntitlement() -> Bool {
        guard let path = Bundle.main.path(forResource: "embedded", ofType: "mobileprovision") else {
            return true
        }
        guard let data = try? Data(contentsOf: URL(fileURLWithPath: path)) else {
            return true
        }
        if let str = String(data: data, encoding: .ascii) {
            return str.contains("aps-environment")
        }
        return true
    }

    // MARK: - Commands

    @objc public func getToken(_ invoke: Invoke) {
        if active, let token = PendingPushes.latestToken {
            invoke.resolve(["token": token])
        } else {
            invoke.resolve(["token": NSNull()])
        }
    }

    @objc public func requestPermission(_ invoke: Invoke) {
        let center = UNUserNotificationCenter.current()
        center.requestAuthorization(options: [.alert, .badge, .sound]) { granted, error in
            if let error = error {
                invoke.reject("Failed to request notification permissions: \(error.localizedDescription)")
                return
            }
            invoke.resolve(["granted": granted])
        }
    }

    @objc public func drainPending(_ invoke: Invoke) {
        invoke.resolve(["pending": PendingPushes.drain()])
    }

    // MARK: - Local notifications
    //
    // The notify/cancel commands route through UNUserNotificationCenter
    // directly. Replacing tauri-plugin-notification on iOS avoids the
    // wake-up-from-cold bug where scheduled local notifications would
    // re-launch the app process and dispatch actions while the webview
    // was still at about:blank.

    @objc public func notify(_ invoke: Invoke) {
        let args: NotifyArgs
        do {
            args = try invoke.parseArgs(NotifyArgs.self)
        } catch {
            invoke.reject("Invalid notify args: \(error.localizedDescription)")
            return
        }

        let userId = args.id ?? UUID().uuidString
        let identifier = "nb:" + userId

        let content = UNMutableNotificationContent()
        content.title = args.title ?? "NativeBlade"
        content.body = args.body ?? ""
        if let sound = args.sound {
            content.sound = sound == "default"
                ? .default
                : UNNotificationSound(named: UNNotificationSoundName(sound))
        } else {
            content.sound = .default
        }
        content.userInfo = ["nb_id": userId]

        let trigger = makeTrigger(args.schedule)

        let request = UNNotificationRequest(
            identifier: identifier,
            content: content,
            trigger: trigger
        )

        UNUserNotificationCenter.current().add(request) { error in
            if let error = error {
                invoke.reject("Failed to schedule notification: \(error.localizedDescription)")
                return
            }
            invoke.resolve(["id": userId])
        }
    }

    @objc public func cancel(_ invoke: Invoke) {
        let args: CancelArgs
        do {
            args = try invoke.parseArgs(CancelArgs.self)
        } catch {
            invoke.reject("Invalid cancel args: \(error.localizedDescription)")
            return
        }
        guard let userId = args.id else {
            invoke.reject("Missing notification id")
            return
        }
        let identifier = "nb:" + userId
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: [identifier])
        center.removeDeliveredNotifications(withIdentifiers: [identifier])
        invoke.resolve()
    }

    @objc public func cancelAll(_ invoke: Invoke) {
        let center = UNUserNotificationCenter.current()
        center.removeAllPendingNotificationRequests()
        center.removeAllDeliveredNotifications()
        invoke.resolve()
    }

    /// Convert our generic schedule descriptor (see PHP `Notification`
    /// builder) into the matching `UNNotificationTrigger`. A nil schedule
    /// means "fire now" — we use a 0.1s interval trigger because iOS
    /// rejects truly-immediate triggers in some build configurations.
    private func makeTrigger(_ schedule: NotifySchedule?) -> UNNotificationTrigger {
        guard let schedule = schedule else {
            return UNTimeIntervalNotificationTrigger(timeInterval: 0.1, repeats: false)
        }

        switch schedule.type {
        case "at":
            let date = ISO8601DateFormatter().date(from: schedule.at ?? "") ?? Date()
            let comps = Calendar.current.dateComponents(
                [.year, .month, .day, .hour, .minute, .second],
                from: date
            )
            return UNCalendarNotificationTrigger(dateMatching: comps, repeats: false)
        case "every":
            let count = schedule.count ?? 1
            let seconds: TimeInterval = {
                switch schedule.kind ?? "" {
                case "minute": return 60
                case "hour":   return 3600
                case "day":    return 86400
                case "week":   return 604800
                case "month":  return 2592000 // 30d approx — UN doesn't expose calendar months on intervals
                default:       return 86400
                }
            }() * Double(count)
            // UN requires interval >= 60 for repeating triggers.
            return UNTimeIntervalNotificationTrigger(
                timeInterval: max(seconds, 60),
                repeats: true
            )
        case "dailyAt":
            let parts = (schedule.time ?? "09:00").components(separatedBy: ":")
            var comps = DateComponents()
            comps.hour = parts.indices.contains(0) ? (Int(parts[0]) ?? 9) : 9
            comps.minute = parts.indices.contains(1) ? (Int(parts[1]) ?? 0) : 0
            return UNCalendarNotificationTrigger(dateMatching: comps, repeats: true)
        default:
            return UNTimeIntervalNotificationTrigger(timeInterval: 0.1, repeats: false)
        }
    }

    // MARK: - Emitters (called by the swizzled delegate)

    func emitPush(_ payload: [String: Any]) {
        guard active else { return }
        do {
            let data = try JSONSerialization.data(withJSONObject: payload, options: [])
            try trigger("nativeblade-push", data: String(data: data, encoding: .utf8) ?? "{}")
        } catch {
            NSLog("nativeblade-push: failed to serialize payload: \(error)")
        }
    }

    func emitToken(_ token: String) {
        guard active else { return }
        trigger("nativeblade-push-token", data: ["token": token])
    }
}

// MARK: - Payload extraction helpers

/// Converts the APNS userInfo dictionary into the normalized payload
/// that NativeBlade delivers to the JS layer.
///
/// APNS layout:
/// ```
/// userInfo = [
///   "aps": [
///     "alert": ["title": "...", "body": "..."],
///     "badge": 1,
///     "sound": "default"
///   ],
///   "type": "new_lesson",
///   "lesson_id": "42"
/// ]
/// ```
///
/// Output layout:
/// ```
/// {
///   "id": "<message id or generated uuid>",
///   "state": "foreground" | "background" | "cold",
///   "notification": { "title": "...", "body": "..." },
///   "data": { "type": "new_lesson", "lesson_id": "42" }
/// }
/// ```
func makePushPayload(
    from userInfo: [AnyHashable: Any],
    state: String
) -> [String: Any] {
    var notification: [String: Any] = [:]
    var data: [String: String] = [:]

    for (key, value) in userInfo {
        let keyString = String(describing: key)
        if keyString == "aps" {
            if let aps = value as? [String: Any] {
                if let alert = aps["alert"] as? [String: Any] {
                    if let title = alert["title"] as? String {
                        notification["title"] = title
                    }
                    if let body = alert["body"] as? String {
                        notification["body"] = body
                    }
                } else if let alertString = aps["alert"] as? String {
                    notification["body"] = alertString
                }
            }
            continue
        }
        // Everything outside `aps` is custom developer data. Coerce to String.
        data[keyString] = String(describing: value)
    }

    let messageId = (userInfo["gcm.message_id"] as? String)
        ?? (userInfo["id"] as? String)
        ?? UUID().uuidString

    return [
        "id": messageId,
        "state": state,
        "notification": notification,
        "data": data,
    ]
}

// MARK: - AppDelegate swizzle + UN delegate

/// Singleton that owns the swizzle state for the host app's UIApplication
/// delegate, and implements `UNUserNotificationCenterDelegate` so that
/// incoming pushes are routed into the plugin.
final class AppDelegateSwizzler: NSObject, UNUserNotificationCenterDelegate {
    static let shared = AppDelegateSwizzler()

    static weak var previousUNDelegate: UNUserNotificationCenterDelegate?

    private static var installed = false
    private static var originalImpl: IMP?

    static func installIfNeeded() {
        guard !installed else { return }
        installed = true

        guard let delegate = UIApplication.shared.delegate else {
            NSLog("nativeblade-push: no UIApplication.delegate to swizzle")
            return
        }

        let targetClass: AnyClass = type(of: delegate)
        let selector = #selector(UIApplicationDelegate.application(_:didRegisterForRemoteNotificationsWithDeviceToken:))

        guard let swizzledMethod = class_getInstanceMethod(
            AppDelegateSwizzler.self,
            #selector(nb_application(_:didRegisterForRemoteNotificationsWithDeviceToken:))
        ) else {
            NSLog("nativeblade-push: swizzle source method missing")
            return
        }

        if let originalMethod = class_getInstanceMethod(targetClass, selector) {
            originalImpl = method_getImplementation(originalMethod)
            method_setImplementation(originalMethod, method_getImplementation(swizzledMethod))
        } else {
            // AppDelegate doesn't implement the method — add ours directly.
            class_addMethod(
                targetClass,
                selector,
                method_getImplementation(swizzledMethod),
                method_getTypeEncoding(swizzledMethod)
            )
        }

        // Also swizzle failure callback so we don't silently swallow errors.
        let failureSelector = #selector(UIApplicationDelegate.application(_:didFailToRegisterForRemoteNotificationsWithError:))
        if let failureMethod = class_getInstanceMethod(
            AppDelegateSwizzler.self,
            #selector(nb_application(_:didFailToRegisterForRemoteNotificationsWithError:))
        ) {
            if let originalFailure = class_getInstanceMethod(targetClass, failureSelector) {
                method_setImplementation(originalFailure, method_getImplementation(failureMethod))
            } else {
                class_addMethod(
                    targetClass,
                    failureSelector,
                    method_getImplementation(failureMethod),
                    method_getTypeEncoding(failureMethod)
                )
            }
        }
    }

    /// Swizzled implementation — invoked in place of the original
    /// AppDelegate method. Captures the device token, emits it, then
    /// forwards to the previous implementation if there was one.
    @objc dynamic func nb_application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        let token = deviceToken.map { String(format: "%02x", $0) }.joined()
        PendingPushes.setToken(token)

        if let plugin = NativeBladePushPlugin.instance {
            plugin.emitToken(token)
        }

        if let original = AppDelegateSwizzler.originalImpl {
            typealias OriginalFn = @convention(c) (AnyObject, Selector, UIApplication, Data) -> Void
            let fn = unsafeBitCast(original, to: OriginalFn.self)
            fn(
                self,
                #selector(UIApplicationDelegate.application(_:didRegisterForRemoteNotificationsWithDeviceToken:)),
                application,
                deviceToken
            )
        }
    }

    @objc dynamic func nb_application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        NSLog("nativeblade-push: APNS registration failed: \(error.localizedDescription)")
    }

    // MARK: UNUserNotificationCenterDelegate

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        let payload = makePushPayload(
            from: notification.request.content.userInfo,
            state: "foreground"
        )
        deliver(payload)

        // Show the banner in-app too.
        if #available(iOS 14.0, *) {
            completionHandler([.banner, .sound, .badge])
        } else {
            completionHandler([.alert, .sound, .badge])
        }

        AppDelegateSwizzler.previousUNDelegate?.userNotificationCenter?(
            center,
            willPresent: notification,
            withCompletionHandler: completionHandler
        )
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let state = UIApplication.shared.applicationState == .active ? "foreground" : "cold"
        let payload = makePushPayload(
            from: response.notification.request.content.userInfo,
            state: state
        )
        deliver(payload)

        completionHandler()

        AppDelegateSwizzler.previousUNDelegate?.userNotificationCenter?(
            center,
            didReceive: response,
            withCompletionHandler: completionHandler
        )
    }

    private func deliver(_ payload: [String: Any]) {
        if let plugin = NativeBladePushPlugin.instance {
            plugin.emitPush(payload)
        } else {
            PendingPushes.enqueue(payload)
        }
    }
}

// MARK: - Plugin entry point

@_cdecl("init_plugin_nativeblade_push")
func initPlugin() -> Plugin {
    return NativeBladePushPlugin()
}
