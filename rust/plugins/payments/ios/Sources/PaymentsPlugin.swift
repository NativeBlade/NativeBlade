import Foundation
import StoreKit
import SwiftRs
import Tauri
import WebKit

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

    private static let pendingResultsKey = "nativeblade_payments_pending_results"
    private var updatesTask: Task<Void, Never>?

    @objc public override func load(webview: WKWebView) {
        super.load(webview: webview)
        // Transactions that complete outside a purchase() call — Ask to Buy
        // approvals, SCA follow-ups, renewals, purchases made on another
        // device — arrive on Transaction.updates and MUST be finished, or
        // StoreKit re-delivers them on every launch. Each one is queued and
        // re-delivered through drainPending as a late `nb:purchase-result`.
        updatesTask = Task {
            for await result in Transaction.updates {
                guard case .verified(let transaction) = result else { continue }
                Self.queueLateResult(
                    productId: transaction.productID,
                    receipt: result.jwsRepresentation
                )
                await transaction.finish()
            }
        }
    }

    @objc public func drainPending(_ invoke: Invoke) {
        let defaults = UserDefaults.standard
        let results = defaults.array(forKey: Self.pendingResultsKey) as? [[String: Any]] ?? []
        defaults.removeObject(forKey: Self.pendingResultsKey)
        invoke.resolve(["results": results])
    }

    // Queue before finish(): if the app dies in between, the unfinished
    // transaction shows up on Transaction.updates again next launch, and the
    // duplicate queue entry is harmless (same receipt; grants dedupe on it).
    private static func queueLateResult(productId: String, receipt: String) {
        let defaults = UserDefaults.standard
        var list = defaults.array(forKey: pendingResultsKey) as? [[String: Any]] ?? []
        list.append([
            "success": true,
            "status": "purchased",
            "productId": productId,
            "receipt": receipt,
        ])
        defaults.set(list, forKey: pendingResultsKey)
    }

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
                        // The signed JWS is on the VerificationResult, not the
                        // decoded Transaction. Hand it back for server validation.
                        let receipt = verification.jwsRepresentation
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
                    list.append([
                        "productId": transaction.productID,
                        "receipt": result.jwsRepresentation,
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
                    var entry: [String: Any] = [
                        "productId": transaction.productID,
                        "active": transaction.revocationDate == nil,
                        "receipt": result.jwsRepresentation,
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
