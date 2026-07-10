import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { shell, shell_write, shell_kill } from '../../../js/wasm-app/actions/shell.js';
import { makeCtx, Recorder, spy, flush } from '../helpers/ctx.js';

function makeShellApi({ stdout = '', stderr = '', code = 0, execute = null, spawn = null } = {}) {
    return {
        Command: {
            create: spy((_program, _args, _options) => {
                return {
                    execute: execute ?? (() => Promise.resolve({ stdout, stderr, code })),
                    spawn: spawn ?? (() => Promise.resolve()),
                };
            }),
        },
    };
}

function makeOsApi(platform = 'linux') {
    return { platform: () => Promise.resolve(platform) };
}

describe('actions/shell', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    it('posts an unsupported result on mobile', async () => {
        const ctx = makeCtx({ isMobile: true, post: rec.fn() });
        await shell({ command: 'ls', id: 'x' }, ctx);

        assert.deepEqual(rec.calls, [
            {
                type: 'nativeblade-shell-result',
                data: { stdout: '', stderr: 'not supported on this platform', exitCode: -1, id: 'x' },
            },
        ]);
    });

    it('posts plugin unavailable when shellApi is missing', async () => {
        const ctx = makeCtx({ isMobile: false, shellApi: null, post: rec.fn() });
        await shell({ command: 'ls' }, ctx);

        assert.equal(rec.calls[0].data.stderr, 'shell plugin not available');
        assert.equal(rec.calls[0].data.exitCode, -1);
    });

    it('posts plugin unavailable when command is missing', async () => {
        const ctx = makeCtx({ isMobile: false, shellApi: makeShellApi(), post: rec.fn() });
        await shell({}, ctx);

        assert.equal(rec.calls[0].data.stderr, 'shell plugin not available');
    });

    it('runs cmd /C on Windows', async () => {
        const shellApi = makeShellApi({ stdout: 'hi', stderr: '', code: 0 });
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('windows'),
            post: rec.fn(),
        });
        await shell({ command: 'dir' }, ctx);

        const createCall = shellApi.Command.create.calls[0];
        assert.equal(createCall[0], 'cmd');
        assert.deepEqual(createCall[1], ['/C', 'dir']);
    });

    it('runs sh -c on non-Windows', async () => {
        const shellApi = makeShellApi();
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('linux'),
            post: rec.fn(),
        });
        await shell({ command: 'ls' }, ctx);

        const createCall = shellApi.Command.create.calls[0];
        assert.equal(createCall[0], 'sh');
        assert.deepEqual(createCall[1], ['-c', 'ls']);
    });

    it('forwards cwd and env through options', async () => {
        const shellApi = makeShellApi();
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('linux'),
            post: rec.fn(),
        });
        await shell({ command: 'x', cwd: '/tmp', env: { A: '1' } }, ctx);

        assert.deepEqual(shellApi.Command.create.calls[0][2], { cwd: '/tmp', env: { A: '1' } });
    });

    it('posts stdout/stderr/exitCode from execute result', async () => {
        const shellApi = makeShellApi({ stdout: 'ok', stderr: 'warn', code: 2 });
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('linux'),
            post: rec.fn(),
        });
        await shell({ command: 'x', id: 'run1' }, ctx);

        assert.deepEqual(rec.calls[0], {
            type: 'nativeblade-shell-result',
            data: { stdout: 'ok', stderr: 'warn', exitCode: 2, id: 'run1' },
        });
    });

    it('maps execute errors to exitCode=-1 with the message as stderr', async () => {
        const shellApi = makeShellApi({ execute: () => Promise.reject(new Error('boom')) });
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('linux'),
            post: rec.fn(),
        });
        await shell({ command: 'x' }, ctx);

        assert.equal(rec.calls[0].data.stderr, 'boom');
        assert.equal(rec.calls[0].data.exitCode, -1);
    });

    it('honors the timeout and posts a timeout error if exceeded', async () => {
        const shellApi = makeShellApi({
            execute: () => new Promise(() => {}), // never resolves
        });
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('linux'),
            post: rec.fn(),
        });

        // 0.02s timeout — small to keep the test fast.
        const resultPromise = shell({ command: 'sleep 10', timeout: 0.02 }, ctx);
        // Give the timeout a chance to fire.
        await new Promise((r) => setTimeout(r, 40));
        await resultPromise;

        assert.equal(rec.calls[0].data.stderr, 'timeout after 0.02s');
        assert.equal(rec.calls[0].data.exitCode, -1);
    });

    it('openTerminal spawns wt.exe by default on Windows', async () => {
        const shellApi = makeShellApi({ spawn: () => Promise.resolve() });
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('windows'),
            post: rec.fn(),
        });
        await shell({ command: 'dir', openTerminal: true, cwd: 'C:/tmp' }, ctx);

        const call = shellApi.Command.create.calls[0];
        assert.equal(call[0], 'wt.exe');
        assert.ok(call[1].includes('-d'));
        assert.ok(call[1].includes('C:/tmp'));
        assert.ok(call[1].includes('cmd.exe'));
        assert.ok(call[1].includes('/K'));
        assert.ok(call[1].includes('dir'));
    });

    it('openTerminal uses osascript on macOS', async () => {
        const shellApi = makeShellApi({ spawn: () => Promise.resolve() });
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('macos'),
            post: rec.fn(),
        });
        await shell({ command: 'ls', openTerminal: true }, ctx);

        const call = shellApi.Command.create.calls[0];
        assert.equal(call[0], 'osascript');
        assert.equal(call[1][0], '-e');
        assert.ok(call[1][1].includes('Terminal'));
    });

    it('openTerminal tries gnome-terminal first on Linux', async () => {
        const shellApi = makeShellApi({ spawn: () => Promise.resolve() });
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('linux'),
            post: rec.fn(),
        });
        await shell({ command: 'ls', openTerminal: true }, ctx);

        const call = shellApi.Command.create.calls[0];
        assert.equal(call[0], 'gnome-terminal');
    });

    it('openTerminal falls through to the next terminal when one fails to spawn', async () => {
        let attempts = 0;
        const shellApi = {
            Command: {
                create: spy(() => ({
                    spawn: () => {
                        attempts++;
                        if (attempts < 3) return Promise.reject(new Error('not found'));
                        return Promise.resolve();
                    },
                })),
            },
        };
        const ctx = makeCtx({
            isMobile: false,
            shellApi,
            osApi: makeOsApi('linux'),
            post: rec.fn(),
        });

        await shell({ command: 'ls', openTerminal: true }, ctx);

        assert.equal(attempts, 3);
        assert.ok(shellApi.Command.create.callCount >= 3);
    });
});

// A shell API whose Command streams: stdout/stderr expose `.on('data', …)`
// and the command exposes `.on('close'|'error', …)`. `.spawn()` resolves to a
// Child with spy'd `write`/`kill`. The `_emit*` helpers drive the listeners.
function makeStreamingShellApi(childOverrides = {}) {
    const stdout = [], stderr = [], closeCbs = [], errorCbs = [];
    const child = {
        pid: 4242,
        write: spy(() => Promise.resolve()),
        kill: spy(() => Promise.resolve()),
        ...childOverrides,
    };
    const command = {
        stdout: { on: (ev, cb) => { if (ev === 'data') stdout.push(cb); } },
        stderr: { on: (ev, cb) => { if (ev === 'data') stderr.push(cb); } },
        on: (ev, cb) => { if (ev === 'close') closeCbs.push(cb); else if (ev === 'error') errorCbs.push(cb); },
        spawn: spy(() => Promise.resolve(child)),
    };
    return {
        Command: { create: spy(() => command) },
        _child: child,
        _emitStdout: (line) => stdout.forEach((cb) => cb(line)),
        _emitStderr: (line) => stderr.forEach((cb) => cb(line)),
        _emitClose: (code) => closeCbs.forEach((cb) => cb({ code })),
        _emitError: (err) => errorCbs.forEach((cb) => cb(err)),
    };
}

describe('actions/shell streaming spawn', () => {
    let rec;
    beforeEach(() => { rec = new Recorder(); });

    function ctxFor(shellApi, platform = 'linux') {
        return makeCtx({ isMobile: false, shellApi, osApi: makeOsApi(platform), post: rec.fn() });
    }

    it('spawns via the platform shell (sh -c) rather than execute', async () => {
        const shellApi = makeStreamingShellApi();
        await shell({ command: 'php artisan nativeblade:dev', spawn: true, id: 'p_dev' }, ctxFor(shellApi));

        const call = shellApi.Command.create.calls[0];
        assert.equal(call[0], 'sh');
        assert.deepEqual(call[1], ['-c', 'php artisan nativeblade:dev']);
        assert.equal(shellApi.Command.create.calls[0] !== undefined, true);
    });

    it('streams stdout lines as nativeblade-shell-data events', async () => {
        const shellApi = makeStreamingShellApi();
        await shell({ command: 'claude', spawn: true, id: 'p_out' }, ctxFor(shellApi));

        shellApi._emitStdout('{"type":"assistant"}');
        const data = rec.calls.find((c) => c.type === 'nativeblade-shell-data');
        assert.deepEqual(data.data, { chunk: '{"type":"assistant"}', stream: 'stdout', id: 'p_out' });
    });

    it('tags stderr lines with stream:stderr', async () => {
        const shellApi = makeStreamingShellApi();
        await shell({ command: 'x', spawn: true, id: 'p_err' }, ctxFor(shellApi));

        shellApi._emitStderr('warning: slow');
        const data = rec.calls.find((c) => c.type === 'nativeblade-shell-data');
        assert.deepEqual(data.data, { chunk: 'warning: slow', stream: 'stderr', id: 'p_err' });
    });

    it('posts nativeblade-shell-exit with the exit code on close', async () => {
        const shellApi = makeStreamingShellApi();
        await shell({ command: 'x', spawn: true, id: 'p_exit' }, ctxFor(shellApi));

        shellApi._emitClose(0);
        const exit = rec.calls.find((c) => c.type === 'nativeblade-shell-exit');
        assert.deepEqual(exit.data, { exitCode: 0, id: 'p_exit' });
    });

    it('shell_write feeds stdin with a trailing newline', async () => {
        const shellApi = makeStreamingShellApi();
        const ctx = ctxFor(shellApi);
        await shell({ command: 'x', spawn: true, id: 'p_in' }, ctx);

        await shell_write({ id: 'p_in', data: 'hello' }, ctx);
        assert.deepEqual(shellApi._child.write.calls[0], ['hello\n']);
    });

    it('shell_write omits the newline when newline is false', async () => {
        const shellApi = makeStreamingShellApi();
        const ctx = ctxFor(shellApi);
        await shell({ command: 'x', spawn: true, id: 'p_raw' }, ctx);

        await shell_write({ id: 'p_raw', data: 'raw', newline: false }, ctx);
        assert.deepEqual(shellApi._child.write.calls[0], ['raw']);
    });

    it('shell_kill terminates the tracked child', async () => {
        const shellApi = makeStreamingShellApi();
        const ctx = ctxFor(shellApi);
        await shell({ command: 'x', spawn: true, id: 'p_kill' }, ctx);

        await shell_kill({ id: 'p_kill' }, ctx);
        assert.equal(shellApi._child.kill.called, true);
    });

    it('shell_write on an unknown id is a silent no-op', async () => {
        const ctx = ctxFor(makeStreamingShellApi());
        await shell_write({ id: 'ghost', data: 'x' }, ctx);
        assert.equal(rec.calls.length, 0);
    });
});
