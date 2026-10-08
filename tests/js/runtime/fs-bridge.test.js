import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { execute, __setFsApiForTests, __resetForTests } from '../../../js/runtime/fs-bridge.js';
import { spy } from '../helpers/ctx.js';

// In-memory stub for @tauri-apps/plugin-fs. Each method is a spy so tests can
// assert on arguments; BaseDirectory mirrors the real plugin's shape.
function makeFs(overrides = {}) {
    return {
        BaseDirectory: { AppData: 'AppData', AppCache: 'AppCache', Document: 'Document', Download: 'Download', Temp: 'Temp' },
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
}

const op = (fields) => ({ nativeblade: 'fs', baseDir: 'app', extra: '', ...fields });

describe('fs-bridge', () => {
    let fs;
    beforeEach(() => { __resetForTests(); fs = makeFs(); __setFsApiForTests(fs); });

    it('read returns the file base64 encoded from the mapped base directory', async () => {
        const result = await execute(op({ op: 'read', path: 'a.txt', baseDir: 'cache' }));

        assert.equal(result, btoa('hi'));
        assert.deepEqual(fs.readFile.calls[0], ['a.txt', { baseDir: 'AppCache' }]);
    });

    it('an unknown base directory falls back to Document', async () => {
        await execute(op({ op: 'exists', path: 'x', baseDir: 'nope' }));
        assert.deepEqual(fs.exists.calls[0], ['x', { baseDir: 'Document' }]);
    });

    it('write creates the parent directory and writes the decoded bytes', async () => {
        const result = await execute(op({ op: 'write', path: 'dir/sub/out.txt', extra: btoa('hello') }));

        assert.equal(result, true);
        assert.ok(fs.mkdir.calls.some(([dir, o]) => dir === 'dir/sub' && o.recursive === true));
        const [path, bytes, opts] = fs.writeFile.calls[0];
        assert.equal(path, 'dir/sub/out.txt');
        assert.equal(new TextDecoder().decode(bytes), 'hello');
        assert.deepEqual(opts, { baseDir: 'AppData' });
    });

    it('delete and delete_dir remove, the latter recursively', async () => {
        await execute(op({ op: 'delete', path: 'f' }));
        await execute(op({ op: 'delete_dir', path: 'd' }));

        assert.deepEqual(fs.remove.calls[0], ['f', { baseDir: 'AppData' }]);
        assert.deepEqual(fs.remove.calls[1], ['d', { baseDir: 'AppData', recursive: true }]);
    });

    it('exists, dir_exists, mkdir and stat map onto the plugin', async () => {
        assert.equal(await execute(op({ op: 'exists', path: 'f' })), true);
        assert.equal(await execute(op({ op: 'dir_exists', path: 'f' })), false, 'the stub stat is a file');
        assert.equal(await execute(op({ op: 'mkdir', path: 'd' })), true);
        assert.deepEqual(fs.mkdir.calls[0], ['d', { baseDir: 'AppData', recursive: true }]);
        assert.deepEqual(await execute(op({ op: 'stat', path: 'f' })), { size: 42, lastModified: 1700000000 });
    });

    it('dir_exists is false when stat fails', async () => {
        fs.stat = spy(async () => { throw new Error('ENOENT'); });
        assert.equal(await execute(op({ op: 'dir_exists', path: 'missing' })), false);
    });

    it('list walks the directory, deep when asked, with sizes for files', async () => {
        fs.readDir = spy(async (path) => path === 'root'
            ? [{ name: 'a.txt', isDirectory: false }, { name: 'sub', isDirectory: true }]
            : [{ name: 'b.txt', isDirectory: false }]);

        const shallow = await execute(op({ op: 'list', path: 'root', extra: '0' }));
        assert.deepEqual(shallow.map((e) => e.path), ['root/a.txt', 'root/sub']);
        assert.equal(shallow[0].size, 42);
        assert.equal(shallow[1].isDirectory, true);

        const deep = await execute(op({ op: 'list', path: 'root', extra: '1' }));
        assert.deepEqual(deep.map((e) => e.path), ['root/a.txt', 'root/sub', 'root/sub/b.txt']);
    });

    it('copy and move create the destination directory first', async () => {
        await execute(op({ op: 'copy', path: 'a', extra: 'out/b' }));
        await execute(op({ op: 'move', path: 'a', extra: 'moved/c' }));

        assert.deepEqual(fs.copyFile.calls[0], ['a', 'out/b', { baseDir: 'AppData' }]);
        assert.deepEqual(fs.rename.calls[0], ['a', 'moved/c', { baseDir: 'AppData' }]);
        assert.deepEqual(fs.mkdir.calls.map((c) => c[0]), ['out', 'moved']);
    });

    it('a plugin failure propagates so the bridge answers with the error', async () => {
        fs.readFile = spy(async () => { throw new Error('forbidden path'); });
        await assert.rejects(() => execute(op({ op: 'read', path: 'secret' })), /forbidden path/);
    });

    it('an unknown operation is refused', async () => {
        await assert.rejects(() => execute(op({ op: 'teleport', path: 'x' })), /Unknown filesystem operation 'teleport'/);
    });

    it('refuses when the fs plugin is not installed', async () => {
        __setFsApiForTests(null);
        __resetForTests();
        // With no override the dynamic import of @tauri-apps/plugin-fs fails in node.
        await assert.rejects(() => execute(op({ op: 'exists', path: 'x' })), /only available inside the app/);
    });
});
