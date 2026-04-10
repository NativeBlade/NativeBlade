# Auto-Update

NativeBlade supports automatic update checking on all platforms.

## How It Works

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
