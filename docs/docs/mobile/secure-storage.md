---
title: "Secure Storage"
description: "Encrypted key-value storage."
---

# Secure Storage

Backed by the NativeBlade `nativeblade-secure-storage` native plugin: the iOS **Keychain** and, on Android, **Google Tink** AEAD with the keyset sealed by the **Android Keystore** (the modern replacement for the now-deprecated EncryptedSharedPreferences). Mobile only. Requires `Plugin::SECURE_STORAGE`.

Use this for secrets that must survive at rest in encrypted, OS-protected storage: auth tokens, refresh tokens, subscription entitlements. It is **not** `setState()`, the regular state store is SQLite persisted to IndexedDB in plaintext, fine for preferences but wrong for credentials. Keep values small (tokens, keys), not blobs.

### Write and remove

```php
public function signIn()
{
    // ... validate ...
    return NativeBlade::setSecure('auth.token', $token)->toResponse();
}

public function signOut()
{
    return NativeBlade::forgetSecure('auth.token')->toResponse();
}
```

Values are strings. For structured data, `json_encode()` before storing and `json_decode()` what you read back.

### Read

Reading crosses into native code, so the value comes back asynchronously on the `nb:secure` Livewire event (the same pattern as `clipboardRead()` and `scan()`), not as a return value:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;

public function loadSession()
{
    return NativeBlade::getSecure('auth.token', id: 'auth')->toResponse();
}

#[On('nb:secure')]
public function onSecure($value = null, $id = null)
{
    if ($id === 'auth' && $value) {
        $this->restoreSession($value);
    }
}
```

`$value` is `null` when the key is absent. Pass `id` to route the result when a component reads more than one key in the same component.

> **Desktop is a no-op in v1.** There is no native keystore binding on desktop yet, so `setSecure()` / `forgetSecure()` do nothing and `getSecure()` returns `null`. Branch with `NativeBlade::isMobile()` if your desktop build needs a different path.

---

