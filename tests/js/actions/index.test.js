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
    'clipboard_read',
    'clipboard_write',
    'geolocation',
    'vibrate',
    'impact',
    'selection',
    'biometric',
    'scan',
    'nfc_read',
    'open_url',
    'open_file',
    'os_info',
    'camera',
    'gallery',
    'file_picker',
    'file_save',
    'copy_file',
    'move_file',
    'upload',
    'navigate',
    'showModal',
    'hideModal',
    'shell',
    'exit',
    'log',
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
