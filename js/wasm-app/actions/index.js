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

export const actions = {
    // dialog
    alert: dialog.alert,
    confirm: dialog.confirm,

    // notification
    notification: notificationMod.notification,

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

    // system
    exit: system.exit,
    log: system.log,

    // generic tauri invoke (for third-party Tauri plugins)
    tauri_invoke: tauri.tauri_invoke,
};
