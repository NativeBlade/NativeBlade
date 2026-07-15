import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { actions } from '../../../js/wasm-app/actions/index.js';

// The actions registry is the contract between PHP's NativeResponse and
// the JS bridge. If these action names drift, PHP will push an action the
// bridge can't resolve. Keep this list aligned with NativeResponse->push(...).
const EXPECTED_ACTIONS = [
    'alert',
    'confirm',
    'notification',
    'cancel_notification',
    'cancel_all_notifications',
    'clipboard_read',
    'clipboard_write',
    'geolocation',
    'vibrate',
    'impact',
    'selection',
    'biometric',
    'scan',
    'scan_cancel',
    'nfc_read',
    'open_url',
    'open_file',
    'os_info',
    'camera',
    'gallery',
    'pick_camera',
    'pick_gallery',
    'pick_video',
    'file_picker',
    'file_save',
    'copy_file',
    'move_file',
    'upload',
    'navigate',
    'showModal',
    'hideModal',
    'shell',
    'shell_write',
    'shell_kill',
    'shell_kill_all',
    'exit',
    'log',
    'minimize',
    'maximize',
    'unmaximize',
    'toggle_maximize',
    'hide',
    'show',
    'tauri_invoke',
    'check_update',
    'force_update',
    'request_review',
    'set_secure',
    'get_secure',
    'forget_secure',
    'share',
    'analytics',
    'request_ad_consent',
    'rewarded_ad',
    'interstitial_ad',
    'banner_ad',
    'hide_banner_ad',
    'query_products',
    'purchase',
    'restore_purchases',
    'subscription_status',
    'network_status',
    'get_task',
    'enqueue_task',
    'get_task_queue',
    'clear_task_queue',
    'sensors',
    'realtime',
    'realtime_send',
    'realtime_whisper',
    'realtime_leave',
    'realtime_auth',
    'shell_module_mount',
    'shell_module_update',
    'shell_module_command',
    'shell_module_destroy',
];

describe('actions/index', () => {
    it('exports a registry keyed by every expected action name', () => {
        for (const name of EXPECTED_ACTIONS) {
            assert.ok(name in actions, `actions registry is missing "${name}"`);
        }
    });

    it('every registered handler is a function', () => {
        for (const [name, handler] of Object.entries(actions)) {
            assert.equal(typeof handler, 'function', `actions.${name} must be a function`);
        }
    });

    it('has no stray entries beyond the expected set', () => {
        const registered = Object.keys(actions).sort();
        const expected = [...EXPECTED_ACTIONS].sort();
        assert.deepEqual(registered, expected);
    });
});
