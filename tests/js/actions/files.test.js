import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { file_picker, file_save } from '../../../js/wasm-app/actions/files.js';
import { makeCtx, Recorder, spy, flush } from '../helpers/ctx.js';

// file_picker and file_save only use ctx.dialogApi — they can be exercised
// without the dynamic `@tauri-apps/api/core` import that copy_file/move_file
// perform at call time. Those two are covered by integration tests instead.

describe('actions/files', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    describe('file_picker', () => {
        it('is a no-op when dialogApi.open is missing', () => {
            file_picker({}, makeCtx({ dialogApi: null, post: rec.fn() }));
            assert.equal(rec.calls.length, 0);
        });

        it('forwards title, defaultPath, multiple, directory, filters', async () => {
            const open = spy(() => Promise.resolve(['/tmp/a']));
            const ctx = makeCtx({ dialogApi: { open }, post: rec.fn() });
            file_picker({
                title: 'Pick',
                defaultPath: '/tmp',
                multiple: true,
                directory: true,
                filters: [{ name: 'All', extensions: ['*'] }],
            }, ctx);
            await flush();

            assert.deepEqual(open.calls[0][0], {
                title: 'Pick',
                defaultPath: '/tmp',
                multiple: true,
                directory: true,
                filters: [{ name: 'All', extensions: ['*'] }],
            });
        });

        it('normalizes a single-path result into an array', async () => {
            const ctx = makeCtx({
                dialogApi: { open: () => Promise.resolve('/tmp/a') },
                post: rec.fn(),
            });
            file_picker({ id: 'x' }, ctx);
            await flush();

            assert.deepEqual(rec.calls, [
                { type: 'nativeblade-file-result', data: { paths: ['/tmp/a'], id: 'x' } },
            ]);
        });

        it('forwards an already-array result', async () => {
            const ctx = makeCtx({
                dialogApi: { open: () => Promise.resolve(['/a', '/b']) },
                post: rec.fn(),
            });
            file_picker({}, ctx);
            await flush();

            assert.deepEqual(rec.calls[0].data.paths, ['/a', '/b']);
        });

        it('treats a null result as an empty array', async () => {
            const ctx = makeCtx({
                dialogApi: { open: () => Promise.resolve(null) },
                post: rec.fn(),
            });
            file_picker({}, ctx);
            await flush();

            assert.deepEqual(rec.calls[0].data.paths, []);
        });

        it('emits empty paths on error', async () => {
            const ctx = makeCtx({
                dialogApi: { open: () => Promise.reject(new Error('denied')) },
                post: rec.fn(),
            });
            file_picker({ id: 'x' }, ctx);
            await flush();

            assert.deepEqual(rec.calls, [
                { type: 'nativeblade-file-result', data: { paths: [], id: 'x' } },
            ]);
        });
    });

    describe('file_save', () => {
        it('is a no-op when dialogApi.save is missing', () => {
            file_save({}, makeCtx({ dialogApi: null, post: rec.fn() }));
            assert.equal(rec.calls.length, 0);
        });

        it('forwards the chosen path with the id', async () => {
            const ctx = makeCtx({
                dialogApi: { save: () => Promise.resolve('/tmp/report.pdf') },
                post: rec.fn(),
            });
            file_save({ id: 'r', defaultName: 'report.pdf' }, ctx);
            await flush();

            assert.deepEqual(rec.calls, [
                { type: 'nativeblade-file-save-result', data: { path: '/tmp/report.pdf', id: 'r' } },
            ]);
        });

        it('uses defaultName as defaultPath when no defaultPath is given', async () => {
            const save = spy(() => Promise.resolve('/tmp/x'));
            const ctx = makeCtx({ dialogApi: { save }, post: rec.fn() });
            file_save({ defaultName: 'report.pdf' }, ctx);
            await flush();

            assert.equal(save.calls[0][0].defaultPath, 'report.pdf');
        });

        it('emits path=null on error', async () => {
            const ctx = makeCtx({
                dialogApi: { save: () => Promise.reject(new Error('x')) },
                post: rec.fn(),
            });
            file_save({ id: 'r' }, ctx);
            await flush();

            assert.deepEqual(rec.calls, [
                { type: 'nativeblade-file-save-result', data: { path: null, id: 'r' } },
            ]);
        });

        it('emits path=null when save resolves with a falsy value', async () => {
            const ctx = makeCtx({
                dialogApi: { save: () => Promise.resolve(null) },
                post: rec.fn(),
            });
            file_save({}, ctx);
            await flush();

            assert.equal(rec.calls[0].data.path, null);
        });
    });
});
