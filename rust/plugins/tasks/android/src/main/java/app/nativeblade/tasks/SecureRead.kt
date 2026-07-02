package app.nativeblade.tasks

import android.content.Context
import android.util.Base64
import com.google.crypto.tink.Aead
import com.google.crypto.tink.KeyTemplates
import com.google.crypto.tink.aead.AeadConfig
import com.google.crypto.tink.integration.android.AndroidKeysetManager

/**
 * Read-only mirror of the nativeblade-secure-storage cipher, for collecting
 * `bearerFromSecure` tokens in a background wake (no Tauri, no plugin
 * instances). The keyset parameters MUST stay in lockstep with
 * SecureStoragePlugin.kt (prefs `nativeblade_secure`, keyset
 * `nativeblade_secure_keyset` in `nativeblade_secure_keyset_prefs`, master
 * key `android-keystore://nativeblade_secure_master`, key name as associated
 * data) — a drift there silently breaks bearer collection here.
 */
object SecureRead {

    fun read(context: Context, key: String): String? {
        val stored = context
            .getSharedPreferences("nativeblade_secure", Context.MODE_PRIVATE)
            .getString(key, null) ?: return null

        return try {
            AeadConfig.register()
            val aead = AndroidKeysetManager.Builder()
                .withSharedPref(context, "nativeblade_secure_keyset", "nativeblade_secure_keyset_prefs")
                .withKeyTemplate(KeyTemplates.get("AES256_GCM"))
                .withMasterKeyUri("android-keystore://nativeblade_secure_master")
                .build()
                .keysetHandle
                .getPrimitive(Aead::class.java)

            String(
                aead.decrypt(Base64.decode(stored, Base64.NO_WRAP), key.toByteArray(Charsets.UTF_8)),
                Charsets.UTF_8
            )
        } catch (t: Throwable) {
            null // locked keystore / missing keyset: task runs without bearer
        }
    }
}
