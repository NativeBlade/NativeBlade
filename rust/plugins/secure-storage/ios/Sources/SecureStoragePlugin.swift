import Security
import SwiftRs
import Tauri
import UIKit

class SetItemArgs: Decodable {
    var key: String
    var value: String
}

class KeyArgs: Decodable {
    var key: String
}

class SecureStoragePlugin: Plugin {
    private let service = "app.nativeblade.securestorage"

    private func baseQuery(_ key: String) -> [String: Any] {
        return [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
        ]
    }

    @objc public func setItem(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(SetItemArgs.self)
        let data = args.value.data(using: .utf8) ?? Data()

        // Upsert: clear any existing item, then add fresh.
        SecItemDelete(baseQuery(args.key) as CFDictionary)

        var add = baseQuery(args.key)
        add[kSecValueData as String] = data
        add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock

        let status = SecItemAdd(add as CFDictionary, nil)
        if status == errSecSuccess {
            invoke.resolve()
        } else {
            invoke.reject("keychain set failed: \(status)")
        }
    }

    @objc public func getItem(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(KeyArgs.self)
        var query = baseQuery(args.key)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        if status == errSecSuccess,
           let data = item as? Data,
           let value = String(data: data, encoding: .utf8) {
            invoke.resolve(["value": value])
        } else {
            // Absent or unreadable: report null so the JS bridge yields null.
            invoke.resolve([:])
        }
    }

    @objc public func removeItem(_ invoke: Invoke) throws {
        let args = try invoke.parseArgs(KeyArgs.self)
        SecItemDelete(baseQuery(args.key) as CFDictionary)
        invoke.resolve()
    }
}

@_cdecl("init_plugin_nativeblade_secure_storage")
func initPlugin() -> Plugin {
    return SecureStoragePlugin()
}
