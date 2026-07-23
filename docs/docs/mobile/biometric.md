---
title: "Biometric"
description: "Face ID, Touch ID, and fingerprint authentication."
---

# Biometric

Backed by [`tauri-plugin-biometric`](https://v2.tauri.app/plugin/biometric/). Mobile only, on desktop the action posts back `success: false, error: 'Biometric not available'` so your `nb:biometric` handler can show a fallback (typically a password form).

**Setup (`app/Providers/AppServiceProvider.php`):**

```php
use NativeBlade\Config\Permission;

NativeBladeConfig::android(function (AndroidConfig $config) {
    $config->permissions([
        Permission::BIOMETRIC => 'Sign in with fingerprint or face',
        // ... other permissions
    ]);
});

NativeBladeConfig::ios(function (IosConfig $config) {
    $config->permissions([
        Permission::BIOMETRIC => 'Sign in with Face ID',
        // ... other permissions
    ]);
});
```

`Permission::BIOMETRIC` maps to `USE_BIOMETRIC` on Android and `NSFaceIDUsageDescription` on iOS. Run `php artisan nativeblade:config` after editing.

**Trigger the prompt, Blade:**
```blade
<button wire:nb-bridge="biometric"
        wire:nb-payload='{"reason":"Confirm your purchase","id":"checkout"}'>
    Confirm purchase
</button>
```

**Trigger from PHP:**
```php
use NativeBlade\Plugins\Biometric;

public function checkout()
{
    return NativeBlade::biometric(function (Biometric $b) {
        $b->id('checkout')
          ->reason('Confirm your purchase')
          ->allowDeviceCredential();
    })->toResponse();
}
```

**Builder methods:**

| Method | Description |
|---|---|
| `->reason($text)` | Explanation shown inside the system prompt (e.g. `'Sign in to NativeBlade'`). Default `'Authenticate'`. |
| `->id($tag)` | String tag echoed back on the `nb:biometric` event so a single listener can route multiple prompts (login vs checkout vs edit email). |
| `->allowDeviceCredential($allow = true)` | Allow the device PIN / pattern / passcode as a fallback when biometric hardware fails or isn't enrolled. Default `true`. Pass `false` if you require biometric specifically. |

**Result event:**

Listen with `#[On('nb:biometric')]`. The handler receives three arguments:

| Argument | Type | Meaning |
|---|---|---|
| `$success` | `bool` | `true` if the user authenticated, `false` on cancel, failure, or unavailable. |
| `$error` | `?string` | OS-provided error message when `$success` is false (e.g. `'User cancelled'`, `'Biometric not available'`). `null` on success. |
| `$id` | `?string` | The tag passed via `->id(...)`, or `null` if none. |

```php
use Livewire\Attributes\On;

#[On('nb:biometric')]
public function onBiometric($success, $error = null, $id = null)
{
    if (!$success) {
        $this->addError('biometric', $error);
        return;
    }

    match ($id) {
        'checkout'   => $this->completePayment(),
        'edit_email' => $this->unlockEmailEdit(),
        default      => null,
    };
}
```

### Recipe: biometric login

The bundled demo (`php artisan nativeblade:install --demo`) ships a working biometric login flow. The idea: after the first successful password login, save the user object to NativeBlade state so the biometric prompt can restore the session without re-checking credentials.

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Plugins\Biometric;

class Login extends Component
{
    public bool $biometricAvailable = false;
    public string $biometricEmail = '';

    public function mount()
    {
        $saved = NativeBlade::getState('biometric.user');
        if (is_array($saved) && !empty($saved['email'])) {
            $this->biometricAvailable = true;
            $this->biometricEmail = $saved['email'];
        }
    }

    public function login()
    {
        // ... validate password ...
        $user = ['name' => 'Admin', 'email' => $this->email];

        NativeBlade::setState('auth.user', $user);
        NativeBlade::setState('biometric.user', $user);

        return NativeBlade::navigate('/', replace: true)->toResponse();
    }

    public function biometricLogin()
    {
        return NativeBlade::biometric(function (Biometric $b) {
            $b->id('login')
              ->reason('Sign in to ' . $this->biometricEmail)
              ->allowDeviceCredential();
        })->toResponse();
    }

    #[On('nb:biometric')]
    public function onBiometric($success, $error = null, $id = null)
    {
        if ($id !== 'login') return;

        if (!$success) {
            $this->addError('biometric', $error ?: 'Authentication failed');
            return;
        }

        $saved = NativeBlade::getState('biometric.user');
        NativeBlade::setState('auth.user', $saved);

        return NativeBlade::navigate('/', replace: true)->toResponse();
    }
}
```

Show the button only when `$biometricAvailable` is true, that way the first login is always password, and subsequent visits get the biometric shortcut.

---

