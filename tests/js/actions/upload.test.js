import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { upload } from '../../../js/wasm-app/actions/upload.js';
import { makeCtx, Recorder, spy, flush } from '../helpers/ctx.js';

describe('actions/upload', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('is a no-op without uploadApi', () => {
        upload({ path: '/a', url: 'https://x' }, makeCtx({ uploadApi: null, post: rec.fn() }));
        assert.equal(rec.calls.length, 0);
    });

    it('is a no-op without path', () => {
        const api = { upload: spy() };
        upload({ url: 'https://x' }, makeCtx({ uploadApi: api, post: rec.fn() }));
        assert.equal(api.upload.callCount, 0);
    });

    it('is a no-op without url', () => {
        const api = { upload: spy() };
        upload({ path: '/a' }, makeCtx({ uploadApi: api, post: rec.fn() }));
        assert.equal(api.upload.callCount, 0);
    });

    it('posts complete=success on success and progress events on progress callback', async () => {
        let progressCb = null;
        const uploadFn = spy((_url, _path, onProgress) => {
            progressCb = onProgress;
            return Promise.resolve();
        });
        const ctx = makeCtx({ uploadApi: { upload: uploadFn }, post: rec.fn() });

        upload({ path: '/a', url: 'https://x', id: 'u1' }, ctx);

        // First arg is url, second is path.
        assert.deepEqual(uploadFn.calls[0][0], 'https://x');
        assert.deepEqual(uploadFn.calls[0][1], '/a');
        // Fourth arg is headers (defaulting to {}).
        assert.deepEqual(uploadFn.calls[0][3], {});

        // Simulate a progress tick.
        progressCb({ progress: 42, total: 100 });
        assert.deepEqual(rec.calls[0], {
            type: 'nativeblade-upload-progress',
            data: { id: 'u1', progress: 42, total: 100 },
        });

        await flush();

        const completeCall = rec.calls.find(c => c.type === 'nativeblade-upload-complete');
        assert.ok(completeCall);
        assert.deepEqual(completeCall.data, { id: 'u1', success: true });
    });

    it('forwards custom headers', () => {
        const uploadFn = spy(() => new Promise(() => {}));
        const ctx = makeCtx({ uploadApi: { upload: uploadFn }, post: rec.fn() });

        upload(
            { path: '/a', url: 'https://x', headers: { Authorization: 'Bearer abc' } },
            ctx,
        );

        assert.deepEqual(uploadFn.calls[0][3], { Authorization: 'Bearer abc' });
    });

    it('posts complete=failure with error message on rejection', async () => {
        const ctx = makeCtx({
            uploadApi: {
                upload: () => Promise.reject(new Error('network')),
            },
            post: rec.fn(),
        });

        upload({ path: '/a', url: 'https://x', id: 'u1' }, ctx);
        await flush();

        const call = rec.calls.find(c => c.type === 'nativeblade-upload-complete');
        assert.deepEqual(call.data, { id: 'u1', success: false, error: 'network' });
    });
});
