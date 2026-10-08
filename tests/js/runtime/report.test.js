import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createReporter } from '../../../js/runtime/report.js';

function fakeConsole() {
    const calls = [];
    return {
        calls,
        warn: (...args) => calls.push(['warn', ...args]),
        error: (...args) => calls.push(['error', ...args]),
    };
}

describe('report', () => {
    it('posts a log action to the target window when one exists', () => {
        const posted = [];
        const consoleObj = fakeConsole();
        const report = createReporter({
            consoleObj,
            getTarget: () => ({ postMessage: (msg, origin) => posted.push([msg, origin]) }),
        });

        report('error', 'bridge gave up', { retries: 10 });

        assert.equal(posted.length, 1);
        const [msg, origin] = posted[0];
        assert.equal(origin, '*');
        assert.equal(msg.type, 'nativeblade-native');
        assert.equal(msg.action, 'log');
        assert.equal(msg.payload.level, 'error');
        assert.equal(msg.payload.message, 'bridge gave up');
        assert.deepEqual(msg.payload.context, { retries: 10 });
        assert.equal(msg.payload.source, 'shell');
        assert.match(msg.payload.at, /^\d{4}-\d{2}-\d{2}T/);
        assert.deepEqual(consoleObj.calls, [], 'the log action already prints to the console');
    });

    it('lets the caller tag the source (php output on stderr)', () => {
        const posted = [];
        const report = createReporter({ getTarget: () => ({ postMessage: (msg) => posted.push(msg) }) });
        report('error', 'Fatal error: x', {}, 'php');
        assert.equal(posted[0].payload.source, 'php');
    });

    it('falls back to the console when there is no window to post to', () => {
        const consoleObj = fakeConsole();
        const report = createReporter({ consoleObj, getTarget: () => null });

        report('error', 'boom', { a: 1 });
        report('warn', 'careful');

        assert.deepEqual(consoleObj.calls, [
            ['error', 'boom', { a: 1 }],
            ['warn', 'careful'],
        ]);
    });

    it('falls back to the console when posting throws or the target is unusable', () => {
        const consoleObj = fakeConsole();
        const throwing = createReporter({
            consoleObj,
            getTarget: () => ({ postMessage: () => { throw new Error('closed'); } }),
        });
        throwing('error', 'x');

        const noPost = createReporter({ consoleObj, getTarget: () => ({}) });
        noPost('warn', 'y');

        const throwingGetter = createReporter({ consoleObj, getTarget: () => { throw new Error('no window'); } });
        throwingGetter('error', 'z');

        assert.deepEqual(consoleObj.calls.map(c => c.slice(0, 2)), [['error', 'x'], ['warn', 'y'], ['error', 'z']]);
    });
});
