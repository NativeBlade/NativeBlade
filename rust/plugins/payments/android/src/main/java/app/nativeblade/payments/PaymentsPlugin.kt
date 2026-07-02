package app.nativeblade.payments

import android.app.Activity
import android.content.Context
import android.content.SharedPreferences
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
import org.json.JSONArray
import org.json.JSONObject

@InvokeArg
class ProductsArgs {
    var products: List<String> = emptyList()
    var id: String? = null
}

@InvokeArg
class PurchaseArgs {
    var product: String? = null
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

    companion object {
        private const val KEY_CONSUMABLE_IDS = "consumable_ids"
        private const val KEY_PENDING_RESULTS = "pending_results"
    }

    // Play Billing reports purchase outcomes through this listener rather than
    // the launchBillingFlow callback, so the pending invoke is parked here and
    // resolved when the listener fires. The system sheet is modal, so a single
    // in-flight purchase at a time is enough.
    private var pendingPurchase: Invoke? = null
    private var pendingConsumable = false

    // Persisted plugin state: which product ids were bought as consumables
    // (so the boot reconcile knows to consume rather than acknowledge), and
    // outcomes settled outside a purchase() call, queued for drainPending.
    private val prefs: SharedPreferences
        get() = activity.getSharedPreferences("nativeblade_payments", Context.MODE_PRIVATE)

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
        ensureReady({ reconcileOwned() }, {})
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
            queryDetails(args.products) { details, error ->
                val arr = JSArray()
                for (d in details) arr.put(productToJson(d))
                val obj = JSObject()
                obj.put("products", arr)
                // Partial results are fine (one catalog answered); only surface
                // the billing error when it explains an empty list.
                if (error != null && details.isEmpty()) obj.put("error", error)
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
    fun drainPending(invoke: Invoke) {
        val raw: String?
        synchronized(this) {
            raw = prefs.getString(KEY_PENDING_RESULTS, null)
            prefs.edit().remove(KEY_PENDING_RESULTS).apply()
        }
        val arr = JSArray()
        if (raw != null) {
            val parsed = JSONArray(raw)
            for (i in 0 until parsed.length()) arr.put(parsed.getJSONObject(i))
        }
        val obj = JSObject()
        obj.put("results", arr)
        invoke.resolve(obj)
    }

    @Command
    fun purchase(invoke: Invoke) {
        // The store sheet is modal, so only one purchase runs at a time. A second
        // call while one is pending would overwrite pendingPurchase and leave the
        // first invoke unresolved, so reject it up front.
        if (pendingPurchase != null) {
            invoke.resolve(failure("purchase already in progress"))
            return
        }

        val args = invoke.parseArgs(PurchaseArgs::class.java)
        val productId = args.product?.trim()
        if (productId.isNullOrEmpty()) {
            invoke.resolve(failure("missing product id"))
            return
        }

        pendingPurchase = invoke
        pendingConsumable = args.consumable
        // Persist the consume intent before the sheet opens: if the payment
        // completes while the app is closed (or after a crash), the boot
        // reconcile still knows this product must be consumed, not acknowledged.
        if (args.consumable) rememberConsumable(productId)

        ensureReady({
            queryDetails(listOf(productId)) { details, error ->
                val product = details.firstOrNull()
                if (product == null) {
                    pendingPurchase = null
                    invoke.resolve(failure(
                        if (error != null) "product lookup failed: $error"
                        else "product not found: $productId"
                    ))
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

                activity.runOnUiThread {
                    val result = billingClient.launchBillingFlow(activity, flowParams)
                    if (result.responseCode != BillingClient.BillingResponseCode.OK) {
                        pendingPurchase = null
                        invoke.resolve(failure(result.debugMessage))
                    }
                }
            }
        }, { msg ->
            pendingPurchase = null
            invoke.resolve(failure(msg))
        })
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
    // not an error. The first billing error is reported alongside the results
    // so an empty list caused by a connection problem is distinguishable from
    // a genuinely unknown product id.
    private fun queryDetails(ids: List<String>, cb: (List<ProductDetails>, String?) -> Unit) {
        val collected = mutableListOf<ProductDetails>()
        var firstError: String? = null
        var remaining = 2
        for (type in listOf(BillingClient.ProductType.INAPP, BillingClient.ProductType.SUBS)) {
            val products = ids.map {
                QueryProductDetailsParams.Product.newBuilder()
                    .setProductId(it)
                    .setProductType(type)
                    .build()
            }
            val params = QueryProductDetailsParams.newBuilder().setProductList(products).build()
            billingClient.queryProductDetailsAsync(params) { result, queryResult ->
                synchronized(collected) {
                    if (result.responseCode != BillingClient.BillingResponseCode.OK && firstError == null) {
                        firstError = result.debugMessage.ifEmpty { "billing error ${result.responseCode}" }
                    }
                    collected.addAll(queryResult.productDetailsList)
                    remaining--
                    if (remaining == 0) cb(collected.toList(), firstError)
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
            billingClient.consumeAsync(params) { result, _ ->
                // A failed consume leaves the purchase unacknowledged; the boot
                // reconcile retries it (the consume intent is persisted). The
                // purchase itself succeeded either way, so still respond.
                if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                    forgetConsumable(purchase.products.firstOrNull())
                }
                respond()
            }
        } else if (purchase.isAcknowledged) {
            respond()
        } else {
            val params = AcknowledgePurchaseParams.newBuilder()
                .setPurchaseToken(purchase.purchaseToken)
                .build()
            billingClient.acknowledgePurchase(params) { _ -> respond() }
        }
    }

    /**
     * Settle purchases that completed outside a purchase() call: a pending
     * payment (slow card, cash voucher, parental approval) that cleared while
     * the app was closed, or a crash between the purchase and its
     * acknowledgement. Play auto-refunds any purchase not acknowledged within
     * three days, so this runs on every boot. Consumables (recognized by the
     * persisted intent from purchase()) are consumed; everything else is
     * acknowledged. Each settled outcome is queued and re-delivered through
     * drainPending as a late `nb:purchase-result`.
     */
    private fun reconcileOwned() {
        queryOwned { purchases ->
            for (p in purchases) {
                if (p.purchaseState != Purchase.PurchaseState.PURCHASED || p.isAcknowledged) continue
                val productId = p.products.firstOrNull() ?: continue

                if (consumableIds().contains(productId)) {
                    val params = ConsumeParams.newBuilder().setPurchaseToken(p.purchaseToken).build()
                    billingClient.consumeAsync(params) { result, _ ->
                        if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                            forgetConsumable(productId)
                            queueLateResult(p)
                        }
                    }
                } else {
                    val params = AcknowledgePurchaseParams.newBuilder()
                        .setPurchaseToken(p.purchaseToken)
                        .build()
                    billingClient.acknowledgePurchase(params) { result ->
                        if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                            queueLateResult(p)
                        }
                    }
                }
            }
        }
    }

    private fun queueLateResult(p: Purchase) {
        val entry = JSONObject()
        entry.put("success", true)
        entry.put("status", "purchased")
        entry.put("productId", p.products.firstOrNull())
        entry.put("receipt", p.originalJson)
        entry.put("token", p.purchaseToken)
        entry.put("signature", p.signature)
        synchronized(this) {
            val arr = JSONArray(prefs.getString(KEY_PENDING_RESULTS, "[]"))
            arr.put(entry)
            prefs.edit().putString(KEY_PENDING_RESULTS, arr.toString()).apply()
        }
    }

    private fun consumableIds(): Set<String> =
        prefs.getStringSet(KEY_CONSUMABLE_IDS, emptySet()) ?: emptySet()

    private fun rememberConsumable(productId: String) {
        prefs.edit().putStringSet(KEY_CONSUMABLE_IDS, consumableIds() + productId).apply()
    }

    private fun forgetConsumable(productId: String?) {
        if (productId == null) return
        prefs.edit().putStringSet(KEY_CONSUMABLE_IDS, consumableIds() - productId).apply()
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
