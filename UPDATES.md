# Auto-Update

NativeBlade has two complementary update mechanisms:

| | Shell Update | Bundle Push |
|---|---|---|
| **What updates** | The native binary (Rust, plugins, Tauri shell) | Just the Laravel bundle (PHP, Blade, Livewire, CSS, JS) |
| **Size** | 50–200MB | 5–15MB |
| **Desktop** | Tauri updater downloads + restarts | Auto-applied on next reload |
| **Mobile** | Modal → redirect to store | Auto-applied on next reload |
| **Store review** | Required (mobile) | **Not required** |
| **Frequency** | Rare (when plugins/Rust change) | Frequent (any Laravel-side fix) |

Most updates only need bundle push. Shell update kicks in when you change the `plugins([...])` declaration, native plugin code, or anything else that changes the binary.

## Shell Update — How It Works

| Platform | Behavior |
|----------|----------|
| **Desktop** | Tauri updater downloads and installs automatically |
| **Mobile** | Shows "Update Available" modal → redirects to store |

## Configuration

### Desktop

```php
NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->updateUrl('https://releases.myapp.com/version.json');
});
```

The Tauri updater checks the endpoint on boot. If a new version is found, it downloads and prompts the user to restart.

### Android

```php
NativeBladeConfig::android(function (AndroidConfig $config) {
    $config->updateUrl('https://releases.myapp.com/version.json')
        ->storeUrl('https://play.google.com/store/apps/details?id=com.myapp.app');
});
```

### iOS

```php
NativeBladeConfig::ios(function (IosConfig $config) {
    $config->updateUrl('https://releases.myapp.com/version.json')
        ->storeUrl('https://apps.apple.com/app/idXXXXXXXXX');
});
```

## Version Endpoint

Host a JSON file at your `updateUrl`:

```json
{
    "version": "1.1.0",
    "notes": "Bug fixes and performance improvements",
    "forceUpdate": false,
    "platforms": {
        "windows-x86_64": {
            "url": "https://releases.myapp.com/app-1.1.0.msi",
            "signature": "..."
        },
        "darwin-x86_64": {
            "url": "https://releases.myapp.com/app-1.1.0.dmg",
            "signature": "..."
        },
        "linux-x86_64": {
            "url": "https://releases.myapp.com/app-1.1.0.AppImage",
            "signature": "..."
        }
    }
}
```

The `version`, `notes`, and `forceUpdate` fields are used by the mobile update modal. The `platforms` object is used by the Tauri desktop updater.

## Mobile Update Modal

On mobile, 3 seconds after boot, the app fetches the version endpoint. If a newer version exists:

- Shows a modal with "Update Available" and the version number
- Shows release notes if provided
- "Update Now" button opens the store URL
- "Later" button dismisses (unless `forceUpdate: true`)

With `forceUpdate: true`, the "Later" button is hidden and the user must update to continue.

## Where to Host

- **GitHub Releases** — upload the JSON as a release asset
- **S3 / Cloudflare R2** — static file hosting
- **Your own server** — any URL that returns the JSON

## Desktop Signing

The Tauri updater requires signature verification. Generate a key pair:

```bash
npx tauri signer generate -w ~/.tauri/myapp.key
```

This creates a private key (for signing builds) and a public key (for the app to verify updates). Add the public key to your config:

```php
$config->updateUrl('https://releases.myapp.com/version.json');
```

The public key is automatically included in `tauri.conf.json` by `nativeblade:config`.

Sign your build artifacts with the private key before uploading:

```bash
npx tauri signer sign -k ~/.tauri/myapp.key build/desktop/1.1.0.msi
```

This generates a `.sig` file — include the signature content in the `platforms.*.signature` field of your version JSON.

---

## Bundle Push

Push Laravel updates to all installed apps without rebuilding the native shell or going through the App Store / Play Store. The shell stays the same; only `laravel-bundle.json.gz` is replaced.

### Configuration

```php
NativeBladeConfig::bundlePush(
    url: 'https://releases.myapp.com/version.json',
    autoApply: true,
);
```

Run `php artisan nativeblade:config` once to publish the runtime config to `public/nativeblade-config.json`. From there, every app boot checks the URL and downloads new bundles in the background.

### Manifest format

The same `version.json` your Tauri shell update uses, plus a `bundle` block:

```json
{
    "version": "1.1.0",
    "platforms": { "...": "..." },
    "bundle": {
        "version": "1.0.5",
        "url": "https://releases.myapp.com/laravel-bundle-1.0.5.json.gz",
        "minShellVersion": "1.0.0"
    }
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `bundle.version` | yes | Semver. Compared against the locally installed bundle version. |
| `bundle.url` | yes | URL to the new `laravel-bundle.json.gz`. |
| `bundle.minShellVersion` | no | Skip the bundle if the shell is older. Useful when the bundle calls into a plugin you only added in a newer shell. |

### Storage

Downloaded bundles are stored in IndexedDB (works on web, Tauri desktop, Android, iOS — no platform-specific code). On boot, the app loads from cache if present, otherwise falls back to the bundle that shipped in the binary.

### Rollback

If a downloaded bundle is corrupt or fails to decompress, the cache is cleared automatically and the app falls back to the binary's bundled version on the next boot.

### What works with bundle push

- Laravel routes, controllers, models, migrations, service providers
- Blade templates, Livewire components
- Public CSS/JS assets (Tailwind, Vite output)
- Composer dependencies (anything in `vendor/`)

### What does NOT work with bundle push

- Adding/removing plugins (`Plugin::CAMERA`, etc.) — changes Cargo features and native permissions
- Changing native plugin code (push, media, custom Tauri plugins)
- Changing iOS/Android `permissions()` config — affects Info.plist / AndroidManifest

For any of these, ship a shell update via the store (or notarized desktop updater).

### How to publish a bundle

For bundle push you don't need to rebuild the native shell. Use the dedicated bundle command:

```bash
# 1. Build only the Laravel bundle (fast — no Tauri build)
php artisan nativeblade:bundle --tag=1.0.5

# Output:
#   public/laravel-bundle.json.gz          (canonical, always overwritten)
#   public/laravel-bundle-1.0.5.json.gz    (versioned copy)
#
# 2. Upload public/laravel-bundle-1.0.5.json.gz to your CDN
#
# 3. Update version.json on your server:
{
    "bundle": {
        "version": "1.0.5",
        "url": "https://releases.myapp.com/laravel-bundle-1.0.5.json.gz"
    }
}
```

`nativeblade:bundle` skips the native build entirely — it only runs `composer install --no-dev` + the bundle script. Typical run is 10-30 seconds vs minutes for a full `nativeblade:build`.

### Recommendations

- Keep `minShellVersion` accurate — bumping it when you add a plugin saves users from a broken app
- Use a CDN with proper cache headers (e.g. CloudFront, Cloudflare R2)
- Version your bundle URLs (`bundle-1.0.5.json.gz`) so old shells keep working with old bundles

## User-driven update controls

By default the bundle check runs in the background on boot and silently downloads when a new version is available. If you want to expose the flow as a UI button ("Check for updates", "Update now"), wire up the dedicated actions on the facade.

### `NativeBlade::checkUpdate()`

Asks the JS bridge to probe the manifest and report back. Does NOT download. Result arrives via the `nb:update-check` Livewire event:

```php
use Livewire\Attributes\On;
use NativeBlade\Facades\NativeBlade;

class Settings extends Component
{
    public ?array $update = null;

    public function checkForUpdates()
    {
        return NativeBlade::checkUpdate()->toResponse();
    }

    #[On('nb:update-check')]
    public function onUpdateCheck($available, $currentVersion = null, $nextVersion = null, $reason = null, $error = null)
    {
        $this->update = compact('available', 'currentVersion', 'nextVersion', 'reason', 'error');
    }
}
```

`$reason` is one of:

| Value | Meaning |
|---|---|
| (absent) | An update is available; check `$nextVersion` |
| `up-to-date` | No newer bundle on the server |
| `not-configured` | `bundlePush(url: ...)` was never called |
| `fetch-failed` | Network error reaching the manifest |
| `invalid-manifest` | Manifest JSON missing `bundle.version` or `bundle.url` |
| `shell-too-old` | Bundle requires a newer shell version |

### `NativeBlade::forceUpdate()`

Triggers the full download + cache step. The new bundle is stored in IndexedDB and applies on the next app launch (it does NOT swap the running bundle). Result via `nb:update-applied`:

```php
public function applyUpdate()
{
    return NativeBlade::forceUpdate()->toResponse();
}

#[On('nb:update-applied')]
public function onUpdateApplied($applied, $version = null, $reason = null, $error = null)
{
    if ($applied) {
        $this->message = "Update ready! Restart the app to apply v{$version}.";
    } else {
        $this->message = "Update failed: " . ($reason ?? $error ?? 'unknown error');
    }
}
```

### Showing the current version

`NativeBlade::version()` and `NativeBlade::buildNumber()` read straight from your AppServiceProvider's `DesktopConfig::version`/`AndroidConfig::version`/`IosConfig::version` based on the running platform. No async, no event — synchronous accessors.

```blade
<p class="text-xs text-zinc-400">
    Version {{ NativeBlade::version() }} ({{ NativeBlade::buildNumber() }})
</p>
```

Returns `'dev'` / `0` when running in web dev mode without a declared version.

### Putting it together

```blade
<div class="space-y-3">
    <p>Current: v{{ NativeBlade::version() }} ({{ NativeBlade::buildNumber() }})</p>

    <button wire:click="checkForUpdates">Check for updates</button>

    @if($update?['available'])
        <p>New version: v{{ $update['nextVersion'] }}</p>
        <button wire:click="applyUpdate">Download now</button>
    @elseif($update)
        <p>{{ $update['reason'] === 'up-to-date' ? 'You are on the latest version.' : ($update['error'] ?? $update['reason']) }}</p>
    @endif
</div>
```
