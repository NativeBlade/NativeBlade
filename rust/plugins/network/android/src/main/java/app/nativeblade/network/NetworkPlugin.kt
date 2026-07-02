package app.nativeblade.network

import android.app.Activity
import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.webkit.WebView
import app.tauri.annotation.Command
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin

@TauriPlugin
class NetworkPlugin(private val activity: Activity) : Plugin(activity) {

    private val connectivity: ConnectivityManager
        get() = activity.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

    // Last status forwarded to JS. Android delivers callback bursts (several
    // onCapabilitiesChanged per network switch), so only real changes emit.
    @Volatile
    private var lastEmitted: String? = null

    override fun load(webView: WebView) {
        super.load(webView)
        // Watch the default network for the whole app lifetime; the callback
        // is cheap and keeps nb:network-changed flowing with no JS setup.
        connectivity.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) = emitIfChanged()
            override fun onLost(network: Network) = emitIfChanged()
            override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) =
                emitIfChanged()
        })
    }

    @Command
    fun getStatus(invoke: Invoke) {
        invoke.resolve(currentStatus())
    }

    private fun emitIfChanged() {
        val status = currentStatus()
        val key = status.toString()
        if (key == lastEmitted) return
        lastEmitted = key
        trigger("network-changed", status)
    }

    private fun currentStatus(): JSObject {
        val caps = connectivity.activeNetwork?.let { connectivity.getNetworkCapabilities(it) }
        // VALIDATED means actual internet, not just an interface that is up
        // (or a captive portal pretending to be one).
        val connected = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true
        val type = when {
            caps == null -> "none"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "cellular"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ethernet"
            else -> "unknown"
        }

        val obj = JSObject()
        obj.put("connected", connected)
        obj.put("type", type)
        obj.put("metered", connectivity.isActiveNetworkMetered)
        return obj
    }
}
