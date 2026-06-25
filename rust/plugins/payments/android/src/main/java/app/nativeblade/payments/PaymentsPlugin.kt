package app.nativeblade.payments

import android.app.Activity
import android.webkit.WebView
import app.tauri.annotation.Command
import app.tauri.annotation.InvokeArg
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSArray
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
import com.android.billingclient.api.AcknowledgePurchaseParams
import com.android.billingclient.api.BillingClient
import com.android.billingclient.api.BillingClientStateListener
import com.android.billingclient.api.BillingFlowParams
import com.android.billingclient.api.BillingResult
import com.android.billingclient.api.ConsumeParams
import com.android.billingclient.api.PendingPurchasesParams
import com.android.billingclient.api.ProductDetails
import com.android.billingclient.api.Purchase
import com.android.billingclient.api.PurchasesUpdatedListener
import com.android.billingclient.api.QueryProductDetailsParams
import com.android.billingclient.api.QueryPurchasesParams

@InvokeArg
class ProductsArgs {
    var products: List<String> = emptyList()
    var id: String? = null
}

@InvokeArg
class PurchaseArgs {
    lateinit var product: String
    var id: String? = null
    var consumable: Boolean = false
    var external: String? = null
}

@InvokeArg
class RestoreArgs {
    var id: String? = null
}

@InvokeArg
class StatusArgs {
    var products: List<String> = emptyList()
    var id: String? = null
}

@TauriPlugin
class PaymentsPlugin(private val activity: Activity) : Plugin(activity) {

    // Play Billing reports purchase outcomes through this listener rather than
    // the launchBillingFlow callback, so the pending invoke is parked here and
    // resolved when the listener fires. The system sheet is modal, so a single
    // in-flight purchase at a time is enough.
    private var pendingPurchase: Invoke? = null
    private var pendingConsumable = false

    private val purchasesListener = PurchasesUpdatedListener { result, purchases ->
        val invoke = pendingPurchase ?: return@PurchasesUpdatedListener
        when (result.responseCode) {
            BillingClient.BillingResponseCode.OK -> {
                val purchase = purchases?.firstOrNull()
                if (purchase == null) {
                    pendingPurchase = null
                    invoke.resolve(failure("no purchase returned"))
                } else {
                    finalizePurchase(purchase, invoke)
                }
            }
            BillingClient.BillingResponseCode.USER_CANCELED -> {
                pendingPurchase = null
                val obj = JSObject()
                obj.put("success", false)
                obj.put("status", "cancelled")
                invoke.resolve(obj)
            }
            else -> {
                pendingPurchase = null
                invoke.resolve(failure(result.debugMessage))
            }
        }
    }

    private val billingClient: BillingClient = BillingClient.newBuilder(activity)
        .setListener(purchasesListener)
        .enablePendingPurchases(
            PendingPurchasesParams.newBuilder()
                .enableOneTimeProducts()
                .enablePrepaidPlans()
                .build()
        )
        .enableAutoServiceReconnection()
        .build()

    override fun load(webView: WebView) {
        super.load(webView)
        ensureReady({}, {})
    }

    @Command
    fun queryProducts(invoke: Invoke) {
        val args = invoke.parseArgs(ProductsArgs::class.java)
        if (args.products.isEmpty()) {
            val obj = JSObject()
            obj.put("products", JSArray())
            invoke.resolve(obj)
            return
        }
        ensureReady({
            queryDetails(args.products) { details ->
                val arr = JSArray()
                for (d in details) arr.put(productToJson(d))
                val obj = JSObject()
                obj.put("products", arr)
                invoke.resolve(obj)
            }
        }, { msg ->
            val obj = JSObject()
            obj.put("products", JSArray())
            obj.put("error", msg)
            invoke.resolve(obj)
        })
    }

    @Command
    fun purchase(invoke: Invoke) {
        val args = invoke.parseArgs(PurchaseArgs::class.java)
        ensureReady({
            queryDetails(listOf(args.product)) { details ->
                val product = details.firstOrNull()
                if (product == null) {
                    invoke.resolve(failure("product not found: ${args.product}"))
                    return@queryDetails
                }

                val offerToken = when (product.productType) {
                    BillingClient.ProductType.SUBS ->
                        product.subscriptionOfferDetails?.firstOrNull()?.offerToken
                    else ->
                        product.oneTimePurchaseOfferDetailsList?.firstOrNull()?.offerToken
                }

                val detailsParams = BillingFlowParams.ProductDetailsParams.newBuilder()
                    .setProductDetails(product)
                if (offerToken != null) detailsParams.setOfferToken(offerToken)

                val flowParams = BillingFlowParams.newBuilder()
                    .setProductDetailsParamsList(listOf(detailsParams.build()))
                    .build()

                pendingPurchase = invoke
                pendingConsumable = args.consumable

                activity.runOnUiThread {
                    val result = billingClient.launchBillingFlow(activity, flowParams)
                    if (result.responseCode != BillingClient.BillingResponseCode.OK) {
                        pendingPurchase = null
                        invoke.resolve(failure(result.debugMessage))
                    }
                }
            }
        }, { msg -> invoke.resolve(failure(msg)) })
    }

    @Command
    fun restorePurchases(invoke: Invoke) {
        ensureReady({
            queryOwned { purchases ->
                val arr = JSArray()
                for (p in purchases) arr.put(purchaseToJson(p))
                val obj = JSObject()
                obj.put("purchases", arr)
                invoke.resolve(obj)
            }
        }, { msg ->
            val obj = JSObject()
            obj.put("purchases", JSArray())
            obj.put("error", msg)
            invoke.resolve(obj)
        })
    }

    @Command
    fun subscriptionStatus(invoke: Invoke) {
        val args = invoke.parseArgs(StatusArgs::class.java)
        ensureReady({
            queryOwned { purchases ->
                val filter = args.products.toSet()
                val arr = JSArray()
                for (p in purchases) {
                    if (filter.isNotEmpty() && p.products.none { it in filter }) continue
                    val active = p.purchaseState == Purchase.PurchaseState.PURCHASED
                    for (pid in p.products) {
                        val obj = JSObject()
                        obj.put("productId", pid)
                        obj.put("active", active)
                        obj.put("receipt", p.originalJson)
                        obj.put("token", p.purchaseToken)
                        arr.put(obj)
                    }
                }
                val obj = JSObject()
                obj.put("entitlements", arr)
                invoke.resolve(obj)
            }
        }, { msg ->
            val obj = JSObject()
            obj.put("entitlements", JSArray())
            obj.put("error", msg)
            invoke.resolve(obj)
        })
    }

    private fun ensureReady(onReady: () -> Unit, onError: (String) -> Unit) {
        if (billingClient.isReady) {
            onReady()
            return
        }
        billingClient.startConnection(object : BillingClientStateListener {
            override fun onBillingSetupFinished(result: BillingResult) {
                if (result.responseCode == BillingClient.BillingResponseCode.OK) onReady()
                else onError(result.debugMessage)
            }

            override fun onBillingServiceDisconnected() {
                onError("billing service disconnected")
            }
        })
    }

    // The product type is unknown up front, so both catalogs are queried and
    // merged. A type that does not match comes back as an unfetched product,
    // not an error.
    private fun queryDetails(ids: List<String>, cb: (List<ProductDetails>) -> Unit) {
        val collected = mutableListOf<ProductDetails>()
        var remaining = 2
        for (type in listOf(BillingClient.ProductType.INAPP, BillingClient.ProductType.SUBS)) {
            val products = ids.map {
                QueryProductDetailsParams.Product.newBuilder()
                    .setProductId(it)
                    .setProductType(type)
                    .build()
            }
            val params = QueryProductDetailsParams.newBuilder().setProductList(products).build()
            billingClient.queryProductDetailsAsync(params) { _, queryResult ->
                synchronized(collected) {
                    collected.addAll(queryResult.productDetailsList)
                    remaining--
                    if (remaining == 0) cb(collected.toList())
                }
            }
        }
    }

    private fun queryOwned(cb: (List<Purchase>) -> Unit) {
        val collected = mutableListOf<Purchase>()
        var remaining = 2
        for (type in listOf(BillingClient.ProductType.INAPP, BillingClient.ProductType.SUBS)) {
            val params = QueryPurchasesParams.newBuilder().setProductType(type).build()
            billingClient.queryPurchasesAsync(params) { _, purchases ->
                synchronized(collected) {
                    collected.addAll(purchases)
                    remaining--
                    if (remaining == 0) cb(collected.toList())
                }
            }
        }
    }

    private fun finalizePurchase(purchase: Purchase, invoke: Invoke) {
        pendingPurchase = null

        if (purchase.purchaseState != Purchase.PurchaseState.PURCHASED) {
            val obj = JSObject()
            obj.put("success", false)
            obj.put(
                "status",
                if (purchase.purchaseState == Purchase.PurchaseState.PENDING) "pending" else "unspecified"
            )
            obj.put("productId", purchase.products.firstOrNull())
            invoke.resolve(obj)
            return
        }

        val respond = {
            val obj = JSObject()
            obj.put("success", true)
            obj.put("status", "purchased")
            obj.put("productId", purchase.products.firstOrNull())
            obj.put("receipt", purchase.originalJson)
            obj.put("token", purchase.purchaseToken)
            obj.put("signature", purchase.signature)
            invoke.resolve(obj)
        }

        if (pendingConsumable) {
            val params = ConsumeParams.newBuilder().setPurchaseToken(purchase.purchaseToken).build()
            billingClient.consumeAsync(params) { _, _ -> respond() }
        } else if (purchase.isAcknowledged) {
            respond()
        } else {
            val params = AcknowledgePurchaseParams.newBuilder()
                .setPurchaseToken(purchase.purchaseToken)
                .build()
            billingClient.acknowledgePurchase(params) { _ -> respond() }
        }
    }

    private fun productToJson(d: ProductDetails): JSObject {
        val obj = JSObject()
        obj.put("id", d.productId)
        obj.put("title", d.title)
        obj.put("name", d.name)
        obj.put("description", d.description)
        obj.put("type", if (d.productType == BillingClient.ProductType.SUBS) "subscription" else "product")
        obj.put("price", formattedPrice(d))
        return obj
    }

    private fun formattedPrice(d: ProductDetails): String {
        d.subscriptionOfferDetails?.firstOrNull()
            ?.pricingPhases?.pricingPhaseList?.firstOrNull()?.formattedPrice
            ?.let { return it }
        d.oneTimePurchaseOfferDetailsList?.firstOrNull()?.formattedPrice?.let { return it }
        @Suppress("DEPRECATION")
        d.oneTimePurchaseOfferDetails?.formattedPrice?.let { return it }
        return ""
    }

    private fun purchaseToJson(p: Purchase): JSObject {
        val obj = JSObject()
        obj.put("productId", p.products.firstOrNull())
        obj.put("receipt", p.originalJson)
        obj.put("token", p.purchaseToken)
        obj.put("signature", p.signature)
        obj.put("acknowledged", p.isAcknowledged)
        return obj
    }

    private fun failure(message: String?): JSObject {
        val obj = JSObject()
        obj.put("success", false)
        obj.put("status", "failed")
        obj.put("error", message ?: "purchase failed")
        return obj
    }
}
