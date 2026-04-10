# Publish

After running `php artisan nativeblade:build {platform}`, your artifacts are in `build/`. Here's how to publish each one.

## Android

### Play Store

1. Go to [Google Play Console](https://play.google.com/console)
2. Create app → Upload the `.aab` from `build/android/`
3. Fill store listing: description, screenshots, privacy policy URL
4. Fill Data Safety form
5. Set content rating
6. Submit for review

> The Play Store only accepts `.aab` (Android App Bundle). The `.apk` is for direct distribution/testing only.

## iOS

### App Store

1. Open the Xcode project at `src-tauri/gen/apple/`
2. Select your signing team and provisioning profile
3. Product → Archive
4. Distribute App → App Store Connect
5. Go to [App Store Connect](https://appstoreconnect.apple.com)
6. Fill app information: description, screenshots, privacy policy URL
7. Fill App Privacy details
8. Submit for review

### TestFlight

Same as App Store flow, but choose "TestFlight" distribution instead. Invite testers via email.

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
