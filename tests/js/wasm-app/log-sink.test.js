import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createSink, formatLine, LOG_FILE, ROTATED_FILE, ROTATE_AT } from '../../../js/wasm-app/log-sink.js';

// Fake @tauri-apps/plugin-fs: an in-memory file keyed by name, recording calls.
function makeFs({ existingSize = 0, failWrite = false, failRename = false } = {}) {
    const files = {};
    const calls = [];
    if (existingSize > 0) files[LOG_FILE] = 'x'.repeat(existingSize);
    return {
        files,
        calls,
        async stat(name, opts) {
            calls.push(['stat', name, opts]);
            if (!(name in files)) throw new Error('ENOENT');
            return { size: files[name].length };
        },
        async writeFile(name, bytes, opts) {
            calls.push(['writeFile', name, opts]);
            if (failWrite) throw new Error('disk full');
            const text = new TextDecoder().decode(bytes);
            files[name] = opts.append ? (files[name] || '') + text : text;
        },
        async rename(from, to, opts) {
            calls.push(['rename', from, to, opts]);
            if (failRename) throw new Error('nope');
            files[to] = files[from];
            delete files[from];
        },
        async remove(name, opts) {
            calls.push(['remove', name, opts]);
            delete files[name];
        },
    };
}

describe('wasm-app/log-sink', () => {
    describe('formatLine', () => {
        it('renders time, level, message and context on one line', () => {
            const line = formatLine({ at: '2026-10-08T12:00:00.000+00:00', level: 'warn', message: 'Retrying', context: { attempt: 3 } });
            assert.equal(line, '2026-10-08T12:00:00.000+00:00 [warn] Retrying {"attempt":3}\n');
        });

        it('tags PHP errors, indents continuation lines and defaults the level', () => {
            const line = formatLine({ at: 'T', source: 'php', message: 'Fatal\nStack' });
            assert.equal(line, 'T [php:info] Fatal\n    Stack\n');
        });

        it('omits an empty context and fills a missing timestamp', () => {
            const line = formatLine({ level: 'info', message: 'hi', context: {} });
            assert.match(line, /^\d{4}-\d{2}-\d{2}T[^ ]+ \[info\] hi\n$/);
        });
    });

    describe('createSink', () => {
        it('appends each entry to the log file in order, creating the directory first', async () => {
            const fs = makeFs();
            let ensured = 0;
            const sink = createSink({ fs, baseDir: 'AppLog', ensureDir: async () => { ensured++; } });

            sink({ at: 'T1', level: 'info', message: 'one' });
            await sink({ at: 'T2', level: 'info', message: 'two' });

            assert.equal(fs.files[LOG_FILE], 'T1 [info] one\nT2 [info] two\n');
            assert.equal(ensured, 1, 'the directory is created once');
            assert.deepEqual(fs.calls.filter((c) => c[0] === 'writeFile').map((c) => c[2]), [
                { baseDir: 'AppLog', append: true },
                { baseDir: 'AppLog', append: true },
            ]);
        });

        it('rotates the file once it would pass the size cap', async () => {
            const fs = makeFs({ existingSize: ROTATE_AT - 5 });
            const sink = createSink({ fs, baseDir: 'AppLog' });

            await sink({ at: 'T', level: 'info', message: 'this line does not fit' });

            assert.equal(fs.files[ROTATED_FILE].length, ROTATE_AT - 5, 'the full file was kept as .1');
            assert.equal(fs.files[LOG_FILE], 'T [info] this line does not fit\n');
            assert.deepEqual(fs.calls.find((c) => c[0] === 'rename')[3], { oldPathBaseDir: 'AppLog', newPathBaseDir: 'AppLog' });
        });

        it('falls back to truncating when the rotation rename fails', async () => {
            const fs = makeFs({ existingSize: ROTATE_AT, failRename: true });
            const sink = createSink({ fs, baseDir: 'AppLog' });

            await sink({ at: 'T', level: 'info', message: 'fresh' });

            assert.equal(fs.files[LOG_FILE], 'T [info] fresh\n');
            assert.ok(!(ROTATED_FILE in fs.files));
        });

        it('posts entries to the dev server when one is known', async () => {
            const posts = [];
            const sink = createSink({
                devServerUrl: 'http://192.168.1.36:1420',
                fetchFn: async (url, init) => { posts.push({ url, init }); return { ok: true }; },
            });

            await sink({ at: 'T', level: 'error', message: 'boom', context: { id: 7 } });

            assert.equal(posts.length, 1);
            assert.equal(posts[0].url, 'http://192.168.1.36:1420/__nb_log');
            assert.equal(posts[0].init.method, 'POST');
            assert.deepEqual(JSON.parse(posts[0].init.body), { at: 'T', level: 'error', message: 'boom', context: { id: 7 } });
            assert.equal(posts[0].init.keepalive, true);
        });

        it('never rejects: a failing write or post is swallowed, later entries still go through, and the write failure is reported once', async () => {
            const fs = makeFs({ failWrite: true });
            const bodies = [];
            const warnings = [];
            const sink = createSink({
                fs,
                baseDir: 'AppLog',
                devServerUrl: 'http://dev',
                fetchFn: async (_url, init) => { bodies.push(JSON.parse(init.body)); throw new Error('offline'); },
                warn: (...args) => warnings.push(args),
            });

            await sink({ message: 'a' });
            await sink({ message: 'b' });

            assert.equal(fs.calls.filter((c) => c[0] === 'writeFile').length, 2);
            assert.equal(warnings.length, 1, 'the write failure is reported once, not per entry');
            assert.match(warnings[0][0], /could not write nativeblade\.log/);
            assert.match(warnings[0][0], /fs:scope/);

            // Two entries plus the failure itself, so the dev terminal shows it.
            const failures = bodies.filter((b) => /could not write nativeblade\.log/.test(b.message));
            assert.deepEqual(bodies.filter((b) => !failures.includes(b)).map((b) => b.message), ['a', 'b']);
            assert.equal(failures.length, 1);
            assert.equal(failures[0].level, 'warn');
            assert.equal(failures[0].context.error, 'disk full');
        });

        it('does not warn when writes succeed', async () => {
            const warnings = [];
            const sink = createSink({ fs: makeFs(), baseDir: 'AppLog', warn: (...args) => warnings.push(args) });
            await sink({ message: 'a' });
            assert.deepEqual(warnings, []);
        });

        it('does nothing when neither a file system nor a dev server is available', async () => {
            const sink = createSink();
            await assert.doesNotReject(() => sink({ message: 'x' }));
        });
    });
});
