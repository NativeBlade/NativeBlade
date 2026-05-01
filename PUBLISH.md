# Publish

After running `php artisan nativeblade:build {platform}`, your artifacts are in `build/`. Here's how to publish each one.

## First-time signing setup

Both stores require signed binaries. Run the signing command once per platform (see [BUILD.md → Code Signing](BUILD.md#code-signing) for details):

```bash
php artisan nativeblade:sign android
php artisan nativeblade:sign ios
```

After this, every subsequent `nativeblade:build` produces a signed artifact ready for upload.

## Android

### Play Store

1. Make sure you ran `php artisan nativeblade:sign android` once
2. Build the AAB:
   ```bash
   php artisan nativeblade:build android
   ```
3. Go to [Google Play Console](https://play.google.com/console)
4. Create app → Upload `build/android/{version}.aab`
5. Fill store listing: description, screenshots, privacy policy URL
6. Fill Data Safety form
7. Set content rating
8. Submit for review

> The Play Store only accepts `.aab` (Android App Bundle). The `.apk` in `build/android/` is for direct distribution and testing.

### Direct distribution (sideload / internal testing)

If you don't want the Play Store, distribute the APK directly. To minimize size, build only the ABIs you need:

```bash
php artisan nativeblade:build android --targets=aarch64,armv7
```

Users install via `adb install build/android/{version}.apk` or by downloading the APK on their device (Settings → Install unknown apps must be enabled).

## iOS

### App Store

1. Make sure you ran `php artisan nativeblade:sign ios` once and configured automatic signing in Xcode
2. Build:
   ```bash
   php artisan nativeblade:build ios
   ```
3. Open the Xcode project at `src-tauri/gen/apple/`
4. Product → Archive
5. Distribute App → App Store Connect
6. Go to [App Store Connect](https://appstoreconnect.apple.com)
7. Fill app information: description, screenshots, privacy policy URL
8. Fill App Privacy details
9. Submit for review

### TestFlight

Same as App Store flow, but choose "TestFlight" distribution instead. Invite testers via email — no review needed for internal builds.

## Desktop

### Windows (.msi)

Distribute the `.msi` directly — users download and double-click to install. Includes Start Menu shortcut and uninstaller.

### macOS (.dmg)

Distribute the `.dmg` directly. For Gatekeeper approval:
```bash
# Notarize (requires Apple Developer account)
xcrun notarytool submit build/desktop/1.0.0.dmg \
  --apple-id your@email.com \
  --team-id XXXXX \
  --password app-specific-password
```

Without notarization, users need to right-click → Open on first launch.

### Linux (.deb + .rpm)

```bash
# Debian/Ubuntu
sudo dpkg -i build/desktop/1.0.0.deb

# Fedora/RHEL
sudo rpm -i build/desktop/1.0.0.rpm
```

Distribute via your website, GitHub Releases, or a PPA/repo.

## GitHub Releases

Easiest way to distribute desktop builds:

1. Tag your version: `git tag v1.0.0 && git push --tags`
2. Go to GitHub → Releases → Create release from tag
3. Upload all artifacts from `build/`
4. Users download the right file for their OS

## Version Bumping

Update version in `AppServiceProvider` before each build:

```php
NativeBladeConfig::desktop(fn ($c) => $c->version('1.1.0', 2));
NativeBladeConfig::android(fn ($c) => $c->version('1.1.0', 2));
NativeBladeConfig::ios(fn ($c) => $c->version('1.1.0', 2));
```

- **Version string** (`1.1.0`): what users see
- **Build number** (`2`): must increment every upload (Play Store and App Store reject duplicate build numbers)
