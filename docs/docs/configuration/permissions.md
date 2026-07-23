---
title: "Permissions & Privacy"
description: "Declare permissions and the iOS privacy manifest."
---

# Permissions & Privacy

## Permissions

Use `Permission` constants for autocomplete:

```php
use NativeBlade\Config\Permission;

Permission::CAMERA
Permission::LOCATION
Permission::LOCATION_ALWAYS
Permission::LOCATION_COARSE
Permission::MICROPHONE
Permission::STORAGE
Permission::STORAGE_WRITE
Permission::PHOTOS
Permission::PHOTOS_ADD
Permission::NOTIFICATIONS
Permission::VIBRATE
Permission::BIOMETRIC
Permission::NFC
Permission::CONTACTS
Permission::CALENDAR
Permission::BLUETOOTH
```

## Privacy Manifest (iOS)

Required by Apple since 2024. Use `PrivacyApi` constants:

```php
use NativeBlade\Config\PrivacyApi;

// API Categories
PrivacyApi::USER_DEFAULTS
PrivacyApi::FILE_TIMESTAMP
PrivacyApi::SYSTEM_BOOT_TIME
PrivacyApi::DISK_SPACE
PrivacyApi::ACTIVE_KEYBOARDS

// Reason Codes (examples)
PrivacyApi::USER_DEFAULTS_APP           // App functionality
PrivacyApi::FILE_TIMESTAMP_DISPLAY      // Display to user
PrivacyApi::BOOT_TIME_ELAPSED           // Calculate elapsed time
PrivacyApi::DISK_SPACE_WRITE_CHECK      // Check before writing
```


