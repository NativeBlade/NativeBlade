---
title: "Overview"
description: "How app configuration works, and the config closure."
---

# Configuration

All configuration is done in PHP via your `AppServiceProvider` using the `NativeBladeConfig` facade.

```php
use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\DesktopConfig;
use NativeBlade\Config\IosConfig;
use NativeBlade\Config\Permission;
use NativeBlade\Config\PrivacyApi;
use NativeBlade\Facades\NativeBladeConfig;

NativeBladeConfig::name('My App');

NativeBladeConfig::desktop(function (DesktopConfig $config) {
    $config->identifier('com.myapp.app')
        ->version('1.0.0', 1)
        ->size(1200, 800)
        ->minSize(800, 600)
        ->resizable()
        ->icon('src-tauri/icons/logo.png')
        ->splashBackground('#0a0a0a');
});

NativeBladeConfig::android(function (AndroidConfig $config) {
    $config->identifier('com.myapp.app')
        ->version('1.0.0', 1)
        ->minSdk(28)
        ->targetSdk(35)
        ->orientation('portrait')
        ->statusBar(style: 'dark')
        ->splashBackground('#0a0a0a')
        ->permissions([
            Permission::CAMERA => 'Take photos for your profile',
            Permission::LOCATION => 'Show nearby content',
            Permission::NOTIFICATIONS => 'Receive updates and reminders',
        ]);
});

NativeBladeConfig::ios(function (IosConfig $config) {
    $config->identifier('com.myapp.app')
        ->version('1.0.0', 1)
        ->minIosVersion('15.0')
        ->orientation('portrait')
        ->statusBar(style: 'dark')
        ->splashBackground('#0a0a0a')
        ->permissions([
            Permission::CAMERA => 'Take photos for your profile',
            Permission::LOCATION => 'Show nearby content',
            Permission::PHOTOS => 'Select images from your library',
        ])
        ->privacyManifest([
            PrivacyApi::USER_DEFAULTS => PrivacyApi::USER_DEFAULTS_APP,
            PrivacyApi::FILE_TIMESTAMP => PrivacyApi::FILE_TIMESTAMP_THIRD_PARTY,
            PrivacyApi::SYSTEM_BOOT_TIME => PrivacyApi::BOOT_TIME_ELAPSED,
            PrivacyApi::DISK_SPACE => PrivacyApi::DISK_SPACE_WRITE_CHECK,
        ]);
});

NativeBladeConfig::transition('slide');
```

After changing config, regenerate:

```bash
php artisan nativeblade:config
```

