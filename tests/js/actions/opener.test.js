import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { open_url, open_file } from '../../../js/wasm-app/actions/opener.js';
import { makeCtx, spy } from '../helpers/ctx.js';

describe('actions/opener', () => {
    describe('open_url', () => {
        it('forwards the url to openerApi.openUrl', () => {
            const openUrl = spy();
            open_url({ url: 'https://example.com' }, makeCtx({ openerApi: { openUrl } }));

            assert.deepEqual(openUrl.calls[0], ['https://example.com']);
        });

        it('defaults to empty string when url is missing', () => {
            const openUrl = spy();
            open_url({}, makeCtx({ openerApi: { openUrl } }));

            assert.deepEqual(openUrl.calls[0], ['']);
        });

        it('is a no-op when openerApi is unavailable', () => {
            assert.doesNotThrow(() => open_url({ url: 'x' }, makeCtx({ openerApi: null })));
        });
    });

    describe('open_file', () => {
        it('forwards the path to openerApi.openPath', () => {
            const openPath = spy();
            open_file({ path: '/tmp/a' }, makeCtx({ openerApi: { openPath } }));

            assert.deepEqual(openPath.calls[0], ['/tmp/a']);
        });

        it('defaults to empty string when path is missing', () => {
            const openPath = spy();
            open_file({}, makeCtx({ openerApi: { openPath } }));

            assert.deepEqual(openPath.calls[0], ['']);
        });

        it('is a no-op when openerApi is unavailable', () => {
            assert.doesNotThrow(() => open_file({ path: '/x' }, makeCtx({ openerApi: null })));
        });
    });
});
