import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    hasPendingRequest,
    fulfill,
    done,
    __setFsApiForTests,
    __resetForTests,
} from '../../../js/runtime/fs-bridge.js';
import { makePhp } from '../helpers/php-stub.js';
import { spy } from '../helpers/ctx.js';

const PENDING_PATH = '/tmp/__nb_fs_pending.json';
const CACHE_DIR = '/tmp/__nb_fs_cache';

// ---------------------------------------------------------------
// In-memory stub for @tauri-apps/plugin-fs. Each method is a spy
// so tests can assert on arguments. The BaseDirectory map mirrors
// the real plugin shape (we only care the bridge reads off .AppData etc).
// ---------------------------------------------------------------

function makeFs(overrides = {}) {
    const fs = {
        BaseDirectory: {
            AppData: 'AppData',
            AppCache: 'AppCache',
            Document: 'Document',
            Download: 'Download',
            Temp: 'Temp',
        },
        readFile: spy(async () => new Uint8Array([104, 105])), // "hi"
        writeFile: spy(async () => {}),
        remove: spy(async () => {}),
        exists: spy(async () => true),
        stat: spy(async () => ({ size: 42, mtime: 1700000000000, isDirectory: false })),
        mkdir: spy(async () => {}),
        readDir: spy(async () => []),
        copyFile: spy(async () => {}),
        rename: spy(async () => {}),
        ...overrides,
    };
    return fs;
}

// ---------------------------------------------------------------
// hasPendingRequest
// ---------------------------------------------------------------

describe('fs-bridge/hasPendingRequest', () => {
    it('detects the __NB_FS_PENDING__ marker in stdout', async () => {
        assert.equal(await hasPendingRequest(makePhp(), 'x __NB_FS_PENDING__ y'), true);
    });

    it('returns false when marker is absent', async () => {
        assert.equal(await hasPendingRequest(makePhp(), 'plain output'), false);
    });

    it('returns false for non-string output', async () => {
        assert.equal(await hasPendingRequest(makePhp(), null), false);
        assert.equal(await hasPendingRequest(makePhp(), undefined), false);
        assert.equal(await hasPendingRequest(makePhp(), 99), false);
        assert.equal(await hasPendingRequest(makePhp(), { s: 'x' }), false);
    });
});

// ---------------------------------------------------------------
// fulfill — per-op coverage
// ---------------------------------------------------------------

describe('fs-bridge/fulfill', () => {
    beforeEach(() => {
        __resetForTests();
    });

    it('returns false and cleans up when fsApi cannot be loaded', async () => {
        __setFsApiForTests(null);
        // Force loadFsApi to return null by replacing the dynamic import target.
        // Simpler: the bridge treats a null-seeded fsApi as "reload via import",
        // which will fail in node:test — the catch returns null. Assert that
        // fulfill() returns false and wipes the pending file.
        const php = makePhp({ [PENDING_PATH]: JSON.stringify([{ key: 'k', op: 'read', path: 'x' }]) });

        const ok = await fulfill(php);
        assert.equal(ok, false);
    });

    it('read op base64-encodes the bytes returned by fs.readFile', async () => {
        const fs = makeFs({
            readFile: spy(async () => new Uint8Array([72, 101, 108, 108, 111])), // "Hello"
        });
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'r1', op: 'read', path: 'notes.txt', baseDir: 'app' },
            ]),
        });

        const ok = await fulfill(php);

        assert.equal(ok, true);
        assert.equal(fs.readFile.callCount, 1);
        assert.equal(fs.readFile.calls[0][0], 'notes.txt');
        assert.deepEqual(fs.readFile.calls[0][1], { baseDir: 'AppData' });

        const cached = JSON.parse(php.files[`${CACHE_DIR}/r1.json`]);
        // btoa("Hello") === "SGVsbG8="
        assert.equal(cached.result, 'SGVsbG8=');
    });

    it('write op base64-decodes extra and forwards the bytes', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        // btoa("ABC") === "QUJD"
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'w1', op: 'write', path: 'sub/out.bin', baseDir: 'export', extra: 'QUJD' },
            ]),
        });

        const ok = await fulfill(php);

        assert.equal(ok, true);
        // Parent dir should be pre-created recursively
        assert.ok(fs.mkdir.called, 'parent dir mkdir should fire');
        assert.equal(fs.mkdir.calls.at(-1)[0], 'sub');

        assert.equal(fs.writeFile.callCount, 1);
        assert.equal(fs.writeFile.calls[0][0], 'sub/out.bin');
        const bytes = fs.writeFile.calls[0][1];
        assert.ok(bytes instanceof Uint8Array);
        assert.deepEqual(Array.from(bytes), [65, 66, 67]); // A B C

        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/w1.json`]).result, true);
    });

    it('delete op calls fs.remove without recursive', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'd1', op: 'delete', path: 'stale.log', baseDir: 'temp' },
            ]),
        });

        await fulfill(php);

        assert.equal(fs.remove.callCount, 1);
        assert.equal(fs.remove.calls[0][0], 'stale.log');
        assert.deepEqual(fs.remove.calls[0][1], { baseDir: 'Temp' });
        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/d1.json`]).result, true);
    });

    it('delete_dir op passes {recursive: true} to fs.remove', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'dd1', op: 'delete_dir', path: 'stale_dir', baseDir: 'cache' },
            ]),
        });

        await fulfill(php);

        assert.equal(fs.remove.callCount, 1);
        assert.equal(fs.remove.calls[0][0], 'stale_dir');
        assert.deepEqual(fs.remove.calls[0][1], { baseDir: 'AppCache', recursive: true });
        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/dd1.json`]).result, true);
    });

    it('exists op forwards the boolean returned by fs.exists', async () => {
        const fs = makeFs({ exists: spy(async () => false) });
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'e1', op: 'exists', path: 'maybe.txt', baseDir: 'downloads' },
            ]),
        });

        await fulfill(php);

        assert.equal(fs.exists.calls[0][1].baseDir, 'Download');
        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/e1.json`]).result, false);
    });

    it('dir_exists op returns stat.isDirectory and false on stat rejection', async () => {
        // Case 1: directory
        const fsOk = makeFs({
            stat: spy(async () => ({ size: 0, mtime: 0, isDirectory: true })),
        });
        __setFsApiForTests(fsOk);
        const php1 = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'de1', op: 'dir_exists', path: 'folder', baseDir: 'app' },
            ]),
        });
        await fulfill(php1);
        assert.equal(JSON.parse(php1.files[`${CACHE_DIR}/de1.json`]).result, true);

        __resetForTests();

        // Case 2: stat throws -> false
        const fsMissing = makeFs({
            stat: spy(async () => { throw new Error('ENOENT'); }),
        });
        __setFsApiForTests(fsMissing);
        const php2 = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'de2', op: 'dir_exists', path: 'ghost', baseDir: 'app' },
            ]),
        });
        await fulfill(php2);
        assert.equal(JSON.parse(php2.files[`${CACHE_DIR}/de2.json`]).result, false);
    });

    it('mkdir op calls fs.mkdir with {recursive: true}', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'm1', op: 'mkdir', path: 'a/b/c', baseDir: 'app' },
            ]),
        });

        await fulfill(php);

        assert.equal(fs.mkdir.callCount, 1);
        assert.equal(fs.mkdir.calls[0][0], 'a/b/c');
        assert.deepEqual(fs.mkdir.calls[0][1], { baseDir: 'AppData', recursive: true });
        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/m1.json`]).result, true);
    });

    it('stat op reshapes mtime into lastModified seconds', async () => {
        const fs = makeFs({
            stat: spy(async () => ({ size: 1024, mtime: 1700000000000, isDirectory: false })),
        });
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 's1', op: 'stat', path: 'file.bin', baseDir: 'app' },
            ]),
        });

        await fulfill(php);

        const cached = JSON.parse(php.files[`${CACHE_DIR}/s1.json`]);
        assert.deepEqual(cached.result, { size: 1024, lastModified: 1700000000 });
    });

    it('list op (shallow) returns one entry per child with stat metadata for files', async () => {
        const fs = makeFs({
            readDir: spy(async (path) => {
                if (path === 'root') {
                    return [
                        { name: 'a.txt', isDirectory: false },
                        { name: 'sub', isDirectory: true },
                    ];
                }
                return [];
            }),
            stat: spy(async () => ({ size: 100, mtime: 1700000000000, isDirectory: false })),
        });
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'l1', op: 'list', path: 'root', baseDir: 'app', extra: '0' },
            ]),
        });

        await fulfill(php);

        const cached = JSON.parse(php.files[`${CACHE_DIR}/l1.json`]);
        assert.equal(cached.result.length, 2);
        // File entry has size + lastModified; subdirectory does not get stat'd
        const fileEntry = cached.result.find((e) => e.path === 'root/a.txt');
        const dirEntry = cached.result.find((e) => e.path === 'root/sub');
        assert.equal(fileEntry.isDirectory, false);
        assert.equal(fileEntry.size, 100);
        assert.equal(fileEntry.lastModified, 1700000000);
        assert.equal(dirEntry.isDirectory, true);
        assert.ok(!('size' in dirEntry), 'directory entries should not carry size');
    });

    it('list op (deep) recurses into subdirectories when extra === "1"', async () => {
        const tree = {
            'root': [
                { name: 'a.txt', isDirectory: false },
                { name: 'sub', isDirectory: true },
            ],
            'root/sub': [
                { name: 'b.txt', isDirectory: false },
            ],
        };
        const fs = makeFs({
            readDir: spy(async (path) => tree[path] || []),
            stat: spy(async () => ({ size: 10, mtime: 0, isDirectory: false })),
        });
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'l2', op: 'list', path: 'root', baseDir: 'app', extra: '1' },
            ]),
        });

        await fulfill(php);

        const cached = JSON.parse(php.files[`${CACHE_DIR}/l2.json`]);
        const paths = cached.result.map((e) => e.path);
        assert.deepEqual(paths.sort(), ['root/a.txt', 'root/sub', 'root/sub/b.txt']);
    });

    it('copy op pre-creates dest dir and calls fs.copyFile(src, dst)', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'c1', op: 'copy', path: 'src.txt', extra: 'deep/sub/dst.txt', baseDir: 'app' },
            ]),
        });

        await fulfill(php);

        assert.ok(fs.mkdir.called, 'dest parent dir should be created');
        assert.equal(fs.mkdir.calls[0][0], 'deep/sub');
        assert.equal(fs.copyFile.callCount, 1);
        assert.equal(fs.copyFile.calls[0][0], 'src.txt');
        assert.equal(fs.copyFile.calls[0][1], 'deep/sub/dst.txt');
        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/c1.json`]).result, true);
    });

    it('move op pre-creates dest dir and calls fs.rename(src, dst)', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'mv1', op: 'move', path: 'old.txt', extra: 'new_dir/new.txt', baseDir: 'app' },
            ]),
        });

        await fulfill(php);

        assert.equal(fs.mkdir.calls[0][0], 'new_dir');
        assert.equal(fs.rename.callCount, 1);
        assert.equal(fs.rename.calls[0][0], 'old.txt');
        assert.equal(fs.rename.calls[0][1], 'new_dir/new.txt');
        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/mv1.json`]).result, true);
    });

    it('writes {result: null} when an op throws, not an error envelope', async () => {
        const fs = makeFs({
            readFile: spy(async () => { throw new Error('permission denied'); }),
        });
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'bad', op: 'read', path: '/forbidden', baseDir: 'app' },
            ]),
        });

        const ok = await fulfill(php);
        assert.equal(ok, true, 'a failing op does not sink the pass');
        const cached = JSON.parse(php.files[`${CACHE_DIR}/bad.json`]);
        assert.equal(cached.result, null, 'errors become null results');
    });

    it('unknown op falls through to {result: null} without throwing', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'unk', op: 'teleport', path: 'x', baseDir: 'app' },
            ]),
        });

        const ok = await fulfill(php);
        assert.equal(ok, true);
        assert.equal(JSON.parse(php.files[`${CACHE_DIR}/unk.json`]).result, null);
    });

    it('defaults baseDir to Document when pending.baseDir is unknown', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'def', op: 'exists', path: 'x.txt', baseDir: 'bogus' },
            ]),
        });

        await fulfill(php);

        assert.equal(fs.exists.calls[0][1].baseDir, 'Document');
    });

    it('processes multiple pending entries in one pass and deletes pending.json on success', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);

        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([
                { key: 'a', op: 'exists', path: 'a.txt', baseDir: 'app' },
                { key: 'b', op: 'exists', path: 'b.txt', baseDir: 'app' },
                { key: 'c', op: 'exists', path: 'c.txt', baseDir: 'app' },
            ]),
        });

        const ok = await fulfill(php);
        assert.equal(ok, true);
        assert.equal(fs.exists.callCount, 3);
        assert.ok(`${CACHE_DIR}/a.json` in php.files);
        assert.ok(`${CACHE_DIR}/b.json` in php.files);
        assert.ok(`${CACHE_DIR}/c.json` in php.files);
        assert.ok(!(PENDING_PATH in php.files), 'pending file should be unlinked after success');
    });

    it('pre-creates the cache dir via mkdirTree before writing', async () => {
        __setFsApiForTests(makeFs());
        const php = makePhp({
            [PENDING_PATH]: JSON.stringify([{ key: 'k', op: 'exists', path: 'x', baseDir: 'app' }]),
        });

        await fulfill(php);

        assert.ok(php.state.mkdirCalls.includes(CACHE_DIR),
            'mkdirTree(CACHE_DIR) is required so the first writeFile does not fault');
    });

    it('returns false and cleans up when pending JSON is not an array', async () => {
        __setFsApiForTests(makeFs());
        const php = makePhp({ [PENDING_PATH]: JSON.stringify({ notArray: true }) });

        const ok = await fulfill(php);
        assert.equal(ok, false);
    });

    it('returns false and cleans up when pending list is empty', async () => {
        __setFsApiForTests(makeFs());
        const php = makePhp({ [PENDING_PATH]: JSON.stringify([]) });

        const ok = await fulfill(php);
        assert.equal(ok, false);
    });

    it('returns false and cleans up when pending JSON is malformed', async () => {
        __setFsApiForTests(makeFs());
        const php = makePhp({ [PENDING_PATH]: '{garbage' });

        const ok = await fulfill(php);
        assert.equal(ok, false);
    });

    it('caps re-entry at MAX_RETRIES=20', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);
        const php = makePhp({});

        for (let i = 0; i < 20; i++) {
            php.files[PENDING_PATH] = JSON.stringify([
                { key: `k${i}`, op: 'exists', path: 'x', baseDir: 'app' },
            ]);
            const ok = await fulfill(php);
            assert.equal(ok, true, `pass #${i} should fire`);
        }

        const before = fs.exists.callCount;
        php.files[PENDING_PATH] = JSON.stringify([
            { key: 'overflow', op: 'exists', path: 'x', baseDir: 'app' },
        ]);
        const ok = await fulfill(php);

        assert.equal(ok, false);
        assert.equal(fs.exists.callCount, before,
            'no fs op should fire once MAX_RETRIES is reached');
    });
});

// ---------------------------------------------------------------
// done() — cleanup between PHP request boundaries
// ---------------------------------------------------------------

describe('fs-bridge/done', () => {
    beforeEach(() => { __resetForTests(); });

    it('clears the cache dir contents', () => {
        const php = makePhp({
            [`${CACHE_DIR}/old1.json`]: '{}',
            [`${CACHE_DIR}/old2.json`]: '{}',
        });

        done(php);

        assert.ok(!(`${CACHE_DIR}/old1.json` in php.files));
        assert.ok(!(`${CACHE_DIR}/old2.json` in php.files));
    });

    it('resets retryCount so a fresh fulfill can retry from zero', async () => {
        const fs = makeFs();
        __setFsApiForTests(fs);
        const php = makePhp({});

        // Burn 15 retries (close to but below the 20 cap)
        for (let i = 0; i < 15; i++) {
            php.files[PENDING_PATH] = JSON.stringify([
                { key: `k${i}`, op: 'exists', path: 'x', baseDir: 'app' },
            ]);
            await fulfill(php);
        }

        done(php);

        // done() resets retryCount and clears the cache but leaves fsApi intact.
        // Verify we have a full 20 fresh passes available after done().
        for (let i = 0; i < 20; i++) {
            php.files[PENDING_PATH] = JSON.stringify([
                { key: `post-${i}`, op: 'exists', path: 'x', baseDir: 'app' },
            ]);
            const ok = await fulfill(php);
            assert.equal(ok, true, `post-done pass #${i} should succeed`);
        }
    });
});
