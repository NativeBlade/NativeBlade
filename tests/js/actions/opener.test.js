import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { open_url, open_file } from '../../../js/wasm-app/actions/opener.js';
import { makeCtx, spy } from '../helpers/ctx.js';

// Native path needs isTauri: true. Outside Tauri, open_url falls back to
// window.open (absent in Node → no-op) and open_file has no browser equivalent.
describe('actions/opener', () => {
    describe('open_url', () => {
        it('forwards the url to openerApi.openUrl', () => {
            const openUrl = spy();
            open_url({ url: 'https://example.com' }, makeCtx({ isTauri: true, openerApi: { openUrl } }));

            assert.deepEqual(openUrl.calls[0], ['https://example.com']);
        });

        it('defaults to empty string when url is missing', () => {
            const openUrl = spy();
            open_url({}, makeCtx({ isTauri: true, openerApi: { openUrl } }));

            assert.deepEqual(openUrl.calls[0], ['']);
        });

        it('does not call the native API outside Tauri', () => {
            const openUrl = spy();
            open_url({ url: 'https://example.com' }, makeCtx({ isTauri: false, openerApi: { openUrl } }));

            assert.equal(openUrl.called, false);
        });

        it('is a no-op when openerApi is unavailable', () => {
            assert.doesNotThrow(() => open_url({ url: 'x' }, makeCtx({ isTauri: true, openerApi: null })));
        });
    });

    describe('open_file', () => {
        it('forwards the path to openerApi.openPath', () => {
            const openPath = spy();
            open_file({ path: '/tmp/a' }, makeCtx({ isTauri: true, openerApi: { openPath } }));

            assert.deepEqual(openPath.calls[0], ['/tmp/a']);
        });

        it('defaults to empty string when path is missing', () => {
            const openPath = spy();
            open_file({}, makeCtx({ isTauri: true, openerApi: { openPath } }));

            assert.deepEqual(openPath.calls[0], ['']);
        });

        it('does not call the native API outside Tauri', () => {
            const openPath = spy();
            open_file({ path: '/tmp/a' }, makeCtx({ isTauri: false, openerApi: { openPath } }));

            assert.equal(openPath.called, false);
        });

        it('is a no-op when openerApi is unavailable', () => {
            assert.doesNotThrow(() => open_file({ path: '/x' }, makeCtx({ isTauri: true, openerApi: null })));
        });
    });
});
