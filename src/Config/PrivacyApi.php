<?php

namespace NativeBlade\Config;

class PrivacyApi
{
    // === API Categories ===
    const FILE_TIMESTAMP = 'NSPrivacyAccessedAPICategoryFileTimestamp';
    const SYSTEM_BOOT_TIME = 'NSPrivacyAccessedAPICategorySystemBootTime';
    const DISK_SPACE = 'NSPrivacyAccessedAPICategoryDiskSpace';
    const ACTIVE_KEYBOARDS = 'NSPrivacyAccessedAPICategoryActiveKeyboards';
    const USER_DEFAULTS = 'NSPrivacyAccessedAPICategoryUserDefaults';

    // === File Timestamp Reasons ===
    /** Display file timestamps to the user */
    const FILE_TIMESTAMP_DISPLAY = 'DDA9.1';
    /** Access timestamps inside app group container */
    const FILE_TIMESTAMP_APP_GROUP = '0A2A.1';
    /** Third-party SDK needs timestamps */
    const FILE_TIMESTAMP_THIRD_PARTY = 'C617.1';

    // === System Boot Time Reasons ===
    /** Calculate elapsed time between events */
    const BOOT_TIME_ELAPSED = '35F9.1';
    /** Calculate absolute timestamps for events */
    const BOOT_TIME_TIMESTAMPS = '8FFB.1';
    /** Measure time taken for app tasks */
    const BOOT_TIME_MEASURE = '3D61.1';

    // === Disk Space Reasons ===
    /** Check available disk space before writing */
    const DISK_SPACE_WRITE_CHECK = 'E174.1';
    /** Display disk space information to user */
    const DISK_SPACE_DISPLAY = '85F4.1';
    /** Third-party SDK needs disk space info */
    const DISK_SPACE_THIRD_PARTY = '7D9E.1';
    /** Health research app disk space */
    const DISK_SPACE_HEALTH = 'B728.1';

    // === Active Keyboards Reasons ===
    /** App is a custom keyboard */
    const KEYBOARDS_CUSTOM = '54BD.1';
    /** Customize UI based on active keyboards */
    const KEYBOARDS_CUSTOMIZE_UI = 'AB6B.1';

    // === User Defaults Reasons ===
    /** Read/write user preferences for app functionality */
    const USER_DEFAULTS_APP = 'CA92.1';
    /** Third-party SDK uses UserDefaults */
    const USER_DEFAULTS_THIRD_PARTY = '1C8F.1';
}
