import { describe, it, beforeEach, afterEach, mock } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import phpHmrPlugin, { formatAppLog } from '../../js/vite-plugin-php-hmr.js';

// Minimal stand-in for a Vite dev server: a watcher that emits file events and
// the middleware stack that serves /__php_changes.
function startPlugin(root) {
    const plugin = phpHmrPlugin(root);
    const watcher = new EventEmitter();
    watcher.add = () => {};
    const middlewares = [];

    plugin.configureServer({
        watcher,
        middlewares: { use: (fn) => middlewares.push(fn) },
        ws: { send: () => {} },
        httpServer: null,
        config: { server: {} },
    });

    // Drive the middleware stack with a fake request/response.
    const dispatch = (req) => {
        let body = null;
        let status = 200;
        const res = { setHeader() {}, end(data) { body = data; }, set statusCode(v) { status = v; }, get statusCode() { return status; } };
        for (const middleware of middlewares) {
            let calledNext = false;
            middleware(req, res, () => { calledNext = true; });
            if (!calledNext) break;
        }
        // Read lazily: a middleware may answer after an async request event.
        return { get body() { return body; }, get status() { return status; } };
    };

    const changesSince = (since = 0) => {
        let body = null;
        const req = { url: `/__php_changes?since=${since}`, method: 'GET' };
        const res = { statusCode: 200, setHeader() {}, end(data) { body = data; } };
        for (const middleware of middlewares) {
            let calledNext = false;
            middleware(req, res, () => { calledNext = true; });
            if (!calledNext) break;
        }
        return JSON.parse(body);
    };

    return { watcher, changesSince, dispatch };
}

function write(root, rel, content) {
    const file = join(root, rel);
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, content);
    return file;
}

describe('vite-plugin-php-hmr', () => {
    let root;
    let previousCssHot;

    beforeEach(() => {
        root = mkdtempSync(join(tmpdir(), 'nb-php-hmr-'));
        previousCssHot = process.env.NATIVEBLADE_CSS_HOT;
        process.env.NATIVEBLADE_CSS_HOT = '1';
        mock.timers.enable({ apis: ['setTimeout'] });
    });

    afterEach(() => {
        mock.timers.reset();
        if (previousCssHot === undefined) delete process.env.NATIVEBLADE_CSS_HOT;
        else process.env.NATIVEBLADE_CSS_HOT = previousCssHot;
        rmSync(root, { recursive: true, force: true });
    });

    it('does not push a build asset rewritten with the content it had at startup', () => {
        const css = write(root, 'public/build/assets/app.css', '.a{color:red}');
        const { watcher, changesSince } = startPlugin(root);

        writeFileSync(css, '.a{color:red}');
        watcher.emit('change', css);

        assert.equal(changesSince(0).changes.length, 0);
    });

    it('pushes a real change once and ignores identical rewrites after it', () => {
        const css = write(root, 'public/build/assets/app.css', '.a{color:red}');
        const { watcher, changesSince } = startPlugin(root);

        writeFileSync(css, '.a{color:blue}');
        watcher.emit('change', css);
        watcher.emit('change', css);

        const { changes, version } = changesSince(0);
        assert.equal(changes.length, 1);
        assert.equal(changes[0].wasmPath, '/app/public/build/assets/app.css');
        assert.equal(changes[0].content, '.a{color:blue}');
        assert.equal(version, 1);
    });

    it('ignores saving a Blade view without changing it', () => {
        const view = write(root, 'resources/views/welcome.blade.php', '<h1>Hi</h1>');
        const { watcher, changesSince } = startPlugin(root);

        watcher.emit('change', view);
        watcher.emit('change', view);
        writeFileSync(view, '<h1>Hello</h1>');
        watcher.emit('change', view);

        const contents = changesSince(0).changes.map((c) => c.content);
        assert.deepEqual(contents, ['<h1>Hi</h1>', '<h1>Hello</h1>']);
    });

    it('ignores a build asset that Vite deletes and writes back unchanged', () => {
        const css = write(root, 'public/build/assets/fonts.css', '@font-face{}');
        const { watcher, changesSince } = startPlugin(root);

        rmSync(css);
        watcher.emit('unlink', css);
        mock.timers.tick(30);
        write(root, 'public/build/assets/fonts.css', '@font-face{}');
        watcher.emit('add', css);
        mock.timers.tick(5000);

        assert.equal(changesSince(0).changes.length, 0);
    });

    it('pushes only the new content when a deleted file comes back different', () => {
        const css = write(root, 'public/build/assets/app.css', '.a{color:red}');
        const { watcher, changesSince } = startPlugin(root);

        rmSync(css);
        watcher.emit('unlink', css);
        write(root, 'public/build/assets/app.css', '.a{color:blue}');
        watcher.emit('add', css);
        mock.timers.tick(5000);

        const { changes } = changesSince(0);
        assert.deepEqual(changes.map((c) => [c.op, c.content]), [['add', '.a{color:blue}']]);
    });

    it('pushes a delete once the file stays gone past the grace period', () => {
        const view = write(root, 'resources/views/old.blade.php', 'old');
        const { watcher, changesSince } = startPlugin(root);
        watcher.emit('change', view);

        rmSync(view);
        watcher.emit('unlink', view);
        mock.timers.tick(999);
        assert.deepEqual(changesSince(0).changes.map((c) => c.op), ['change']);

        mock.timers.tick(1);
        assert.deepEqual(changesSince(0).changes.map((c) => c.op), ['change', 'unlink']);
    });

    it('pushes a file recreated after its delete was already published', () => {
        const css = write(root, 'public/build/assets/app.css', '.a{color:red}');
        const { watcher, changesSince } = startPlugin(root);

        rmSync(css);
        watcher.emit('unlink', css);
        mock.timers.tick(1000);
        write(root, 'public/build/assets/app.css', '.a{color:red}');
        watcher.emit('add', css);

        assert.deepEqual(changesSince(0).changes.map((c) => c.op), ['unlink', 'add']);
    });

    it('prints app log entries posted to /__nb_log in the terminal', async () => {
        const { dispatch } = startPlugin(root);
        const printed = [];
        const original = { log: console.log, error: console.error };
        // Only our lines: Node may print its own warnings through console.error meanwhile.
        const capture = (kind) => (line) => { if (String(line).includes('[nb:')) printed.push([kind, line]); };
        console.log = capture('log');
        console.error = capture('error');

        try {
            const req = new EventEmitter();
            req.method = 'POST';
            req.url = '/__nb_log';
            const result = dispatch(req);
            req.emit('data', JSON.stringify({ level: 'error', message: 'Payment failed', context: { id: 9 }, at: '2026-10-08T12:00:00.000+00:00' }));
            req.emit('end');
            await new Promise((r) => setImmediate(r));

            assert.equal(result.status, 204);
            assert.equal(printed.length, 1);
            assert.equal(printed[0][0], 'error');
            assert.match(printed[0][1], /\[nb:app:error\].*12:00:00\.000 Payment failed \{"id":9\}/);
        } finally {
            console.log = original.log;
            console.error = original.error;
        }
    });

    it('formats PHP errors with their own tag', () => {
        assert.match(formatAppLog({ source: 'php', level: 'error', message: 'Undefined variable' }), /^\x1b\[31m\[nb:php:error\]\x1b\[0m Undefined variable$/);
        assert.match(formatAppLog({ message: 'plain' }), /\[nb:app:info\]\x1b\[0m plain$/);
    });

    it('only returns changes newer than the requested version', () => {
        const view = write(root, 'resources/views/welcome.blade.php', 'one');
        const { watcher, changesSince } = startPlugin(root);

        watcher.emit('change', view);
        writeFileSync(view, 'two');
        watcher.emit('change', view);

        assert.deepEqual(changesSince(1).changes.map((c) => c.content), ['two']);
    });
});
