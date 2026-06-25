import Foundation
import StoreKit
import SwiftRs
import Tauri

struct NBProductsArgs: Decodable {
    let products: [String]?
    let id: String?
}

struct NBPurchaseArgs: Decodable {
    let product: String
    let id: String?
    let consumable: Bool?
    let external: String?
}

struct NBStatusArgs: Decodable {
    let products: [String]?
    let id: String?
}

// StoreKit 2 in-app purchases. The plugin starts the flow and hands back the
// signed transaction (JWS) as the receipt; the Laravel side validates it on a
// server before granting entitlement. Requires iOS 15 (set by Package.swift).
class PaymentsPlugin: Plugin {

    @objc public func queryProducts(_ invoke: Invoke) {
        let ids = (try? invoke.parseArgs(NBProductsArgs.self))?.products ?? []
        Task {
            do {
                let products = try await Product.products(for: ids)
                let list = products.map { Self.productPayload($0) }
                invoke.resolve(["products": list])
            } catch {
                invoke.resolve(["products": [], "error": error.localizedDescription])
            }
        }
    }

    @objc public func purchase(_ invoke: Invoke) {
        guard let args = try? invoke.parseArgs(NBPurchaseArgs.self) else {
            invoke.resolve(Self.failure("invalid args"))
            return
        }
        Task {
            do {
                let products = try await Product.products(for: [args.product])
                guard let product = products.first else {
                    invoke.resolve(Self.failure("product not found: \(args.product)"))
                    return
                }

                let result = try await product.purchase()
                switch result {
                case .success(let verification):
                    switch verification {
                    case .verified(let transaction):
                        let receipt = String(decoding: transaction.jsonRepresentation, as: UTF8.self)
                        await transaction.finish()
                        invoke.resolve([
                            "success": true,
                            "status": "purchased",
                            "productId": transaction.productID,
                            "receipt": receipt,
                        ])
                    case .unverified(_, let error):
                        invoke.resolve(Self.failure("unverified: \(error.localizedDescription)"))
                    }
                case .userCancelled:
                    invoke.resolve(["success": false, "status": "cancelled"])
                case .pending:
                    invoke.resolve(["success": false, "status": "pending"])
                @unknown default:
                    invoke.resolve(Self.failure("unknown purchase result"))
                }
            } catch {
                invoke.resolve(Self.failure(error.localizedDescription))
            }
        }
    }

    @objc public func restorePurchases(_ invoke: Invoke) {
        Task {
            // AppStore.sync surfaces the system restore sheet; entitlements are
            // then read from the on-device transaction history regardless of
            // whether the sync itself was cancelled.
            try? await AppStore.sync()

            var list: [[String: Any]] = []
            for await result in Transaction.currentEntitlements {
                if case .verified(let transaction) = result {
                    let receipt = String(decoding: transaction.jsonRepresentation, as: UTF8.self)
                    list.append([
                        "productId": transaction.productID,
                        "receipt": receipt,
                    ])
                }
            }
            invoke.resolve(["purchases": list])
        }
    }

    @objc public func subscriptionStatus(_ invoke: Invoke) {
        let filter = (try? invoke.parseArgs(NBStatusArgs.self))?.products ?? []
        Task {
            var list: [[String: Any]] = []
            for await result in Transaction.currentEntitlements {
                if case .verified(let transaction) = result {
                    if !filter.isEmpty && !filter.contains(transaction.productID) { continue }
                    let receipt = String(decoding: transaction.jsonRepresentation, as: UTF8.self)
                    var entry: [String: Any] = [
                        "productId": transaction.productID,
                        "active": transaction.revocationDate == nil,
                        "receipt": receipt,
                    ]
                    if let expires = transaction.expirationDate {
                        entry["expiresAt"] = expires.timeIntervalSince1970
                    }
                    list.append(entry)
                }
            }
            invoke.resolve(["entitlements": list])
        }
    }

    private static func productPayload(_ product: Product) -> [String: Any] {
        let typeString: String
        switch product.type {
        case .autoRenewable, .nonRenewable:
            typeString = "subscription"
        default:
            typeString = "product"
        }
        return [
            "id": product.id,
            "title": product.displayName,
            "name": product.displayName,
            "description": product.description,
            "price": product.displayPrice,
            "type": typeString,
        ]
    }

    private static func failure(_ message: String) -> [String: Any] {
        return ["success": false, "status": "failed", "error": message]
    }
}

@_cdecl("init_plugin_nativeblade_payments")
func initPlugin() -> Plugin {
    return PaymentsPlugin()
}
