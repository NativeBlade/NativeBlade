// Central action registry. Maps action names (matching the PHP side's
// NativeResponse->push('type', ...)) to their handler functions.
//
// Each handler has signature: (payload, ctx) => void | Promise<void>
// where `ctx` provides Tauri APIs, platform flags, the appFrame, and helpers.
// See bridge.js#buildCtx for what the context carries.

import * as dialog from './dialog.js';
import * as notificationMod from './notification.js';
import * as clipboard from './clipboard.js';
import * as geolocationMod from './geolocation.js';
import * as haptics from './haptics.js';
import * as biometricMod from './biometric.js';
import * as barcode from './barcode.js';
import * as nfc from './nfc.js';
import * as opener from './opener.js';
import * as osMod from './os.js';
import * as cameraActions from './camera.js';
import * as mediaActions from './media.js';
import * as files from './files.js';
import * as uploadMod from './upload.js';
import * as navigation from './navigation.js';
import * as shellMod from './shell.js';
import * as system from './system.js';
import * as tauri from './tauri.js';
import * as updateMod from './update.js';
import * as review from './review.js';
import * as secure from './secure.js';
import * as shareMod from './share.js';
import * as analyticsMod from './analytics.js';
import * as admobMod from './admob.js';
import * as paymentsMod from './payments.js';
import * as networkMod from './network.js';
import * as tasksMod from './tasks.js';
import * as sensorsMod from './sensors.js';

export const actions = {
    // dialog
    alert: dialog.alert,
    confirm: dialog.confirm,

    // notification
    notification: notificationMod.notification,
    cancel_notification: notificationMod.cancel_notification,
    cancel_all_notifications: notificationMod.cancel_all_notifications,

    // clipboard
    clipboard_read: clipboard.clipboard_read,
    clipboard_write: clipboard.clipboard_write,

    // geolocation
    geolocation: geolocationMod.geolocation,

    // haptics
    vibrate: haptics.vibrate,
    impact: haptics.impact,
    selection: haptics.selection,

    // biometric
    biometric: biometricMod.biometric,

    // barcode
    scan: barcode.scan,
    scan_cancel: barcode.scan_cancel,

    // nfc
    nfc_read: nfc.nfc_read,

    // opener
    open_url: opener.open_url,
    open_file: opener.open_file,

    // os
    os_info: osMod.os_info,

    // camera
    camera: cameraActions.camera,
    gallery: cameraActions.gallery,

    // media (nativeblade-media plugin)
    pick_camera: mediaActions.pick_camera,
    pick_gallery: mediaActions.pick_gallery,
    pick_video: mediaActions.pick_video,

    // files
    file_picker: files.file_picker,
    file_save: files.file_save,
    copy_file: files.copy_file,
    move_file: files.move_file,

    // upload
    upload: uploadMod.upload,

    // navigation
    navigate: navigation.navigate,
    showModal: navigation.showModal,
    hideModal: navigation.hideModal,

    // shell (desktop only)
    shell: shellMod.shell,
    shell_write: shellMod.shell_write,
    shell_kill: shellMod.shell_kill,
    shell_kill_all: shellMod.shell_kill_all,

    // system
    exit: system.exit,
    log: system.log,
    minimize: system.minimize,
    maximize: system.maximize,
    unmaximize: system.unmaximize,
    toggle_maximize: system.toggle_maximize,
    hide: system.hide,
    show: system.show,

    // generic tauri invoke (for third-party Tauri plugins)
    tauri_invoke: tauri.tauri_invoke,

    // bundle update (OTA)
    check_update: updateMod.checkUpdate,
    force_update: updateMod.forceUpdate,

    // in-app review
    request_review: review.request_review,

    // secure storage
    set_secure: secure.set_secure,
    get_secure: secure.get_secure,
    forget_secure: secure.forget_secure,

    // sharing
    share: shareMod.share,

    // analytics
    analytics: analyticsMod.analytics,

    // admob
    request_ad_consent: admobMod.request_ad_consent,
    rewarded_ad: admobMod.rewarded_ad,
    interstitial_ad: admobMod.interstitial_ad,
    banner_ad: admobMod.banner_ad,
    hide_banner_ad: admobMod.hide_banner_ad,

    // payments (in-app purchases)
    query_products: paymentsMod.query_products,
    purchase: paymentsMod.purchase,
    restore_purchases: paymentsMod.restore_purchases,
    subscription_status: paymentsMod.subscription_status,

    // network (connectivity)
    network_status: networkMod.network_status,

    // background tasks (native courier)
    get_task: tasksMod.get_task,
    enqueue_task: tasksMod.enqueue_task,
    get_task_queue: tasksMod.get_task_queue,
    clear_task_queue: tasksMod.clear_task_queue,

    // sensors
    sensors: sensorsMod.sensors,
};
