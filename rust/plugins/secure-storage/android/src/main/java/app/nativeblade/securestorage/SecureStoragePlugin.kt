package app.nativeblade.securestorage

import android.app.Activity
import android.content.Context
import android.content.SharedPreferences
import android.util.Base64
import app.tauri.annotation.Command
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
import com.google.crypto.tink.Aead
import com.google.crypto.tink.KeyTemplates
import com.google.crypto.tink.aead.AeadConfig
import com.google.crypto.tink.integration.android.AndroidKeysetManager

/**
 * Encrypted key-value storage using Google Tink (the modern replacement for
 * the deprecated androidx.security EncryptedSharedPreferences).
 *
 * The AEAD keyset is sealed by a master key held in the Android Keystore, so
 * the key never leaves the secure hardware. Only base64 ciphertext is written
 * to a plain SharedPreferences file; the key name is used as associated data
 * so a ciphertext cannot be moved between keys.
 */
@TauriPlugin
class SecureStoragePlugin(private val activity: Activity) : Plugin(activity) {

    private val prefs: SharedPreferences by lazy {
        activity.getSharedPreferences("nativeblade_secure", Context.MODE_PRIVATE)
    }

    // Built lazily so a Keystore hiccup does not crash plugin load. The exact
    // primitive-access call may need to match the pinned Tink version.
    private val aead: Aead by lazy {
        AeadConfig.register()
        val keysetHandle = AndroidKeysetManager.Builder()
            .withSharedPref(activity, "nativeblade_secure_keyset", "nativeblade_secure_keyset_prefs")
            .withKeyTemplate(KeyTemplates.get("AES256_GCM"))
            .withMasterKeyUri("android-keystore://nativeblade_secure_master")
            .build()
            .keysetHandle
        keysetHandle.getPrimitive(Aead::class.java)
    }

    @Command
    fun setItem(invoke: Invoke) {
        val args = invoke.getArgs()
        val key = args.getString("key", "") ?: ""
        val value = args.getString("value", "") ?: ""
        if (key.isEmpty()) {
            invoke.reject("key is required")
            return
        }
        try {
            val ciphertext = aead.encrypt(
                value.toByteArray(Charsets.UTF_8),
                key.toByteArray(Charsets.UTF_8)
            )
            prefs.edit().putString(key, Base64.encodeToString(ciphertext, Base64.NO_WRAP)).apply()
            invoke.resolve()
        } catch (e: Exception) {
            invoke.reject(e.message ?: "secure set failed")
        }
    }

    @Command
    fun getItem(invoke: Invoke) {
        val args = invoke.getArgs()
        val key = args.getString("key", "") ?: ""
        val stored = prefs.getString(key, null)
        if (stored == null) {
            invoke.resolve(JSObject().apply { put("value", null) })
            return
        }
        try {
            val ciphertext = Base64.decode(stored, Base64.NO_WRAP)
            val plaintext = aead.decrypt(ciphertext, key.toByteArray(Charsets.UTF_8))
            invoke.resolve(JSObject().apply { put("value", String(plaintext, Charsets.UTF_8)) })
        } catch (e: Exception) {
            // Corrupt or undecryptable entry: report absent instead of crashing.
            invoke.resolve(JSObject().apply { put("value", null) })
        }
    }

    @Command
    fun removeItem(invoke: Invoke) {
        val args = invoke.getArgs()
        val key = args.getString("key", "") ?: ""
        prefs.edit().remove(key).apply()
        invoke.resolve()
    }
}
