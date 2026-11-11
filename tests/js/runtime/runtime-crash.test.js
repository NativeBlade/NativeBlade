import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { explainRuntimeCrash } from '../../../js/runtime/runtime-crash.js';
import { createBridgeHandler, lastNativeCall, describeCall } from '../../../js/runtime/native-bridge.js';

describe('runtime-crash', () => {
    it('explains an Asyncify crash with the native call in flight', () => {
        const message = explainRuntimeCrash(new RuntimeError('unreachable'), 'HTTP GET https://a.test/x');
        assert.match(message, /PHP crashed during HTTP GET https:\/\/a\.test\/x \(unreachable\)/);
        assert.match(message, /usort, json_encode or preg_replace_callback/);
        assert.doesNotMatch(message, /reload/);
    });

    it('does not blame a callback when no native call was in flight', () => {
        const message = explainRuntimeCrash(new RuntimeError('memory access out of bounds'), null, { runtime: 'asyncify' });
        assert.match(message, /^PHP crashed \(memory access out of bounds\)/);
        assert.match(message, /No NativeBlade native call/);
        assert.match(message, /sleep, usleep, socket polling/);
        assert.doesNotMatch(message, /usort/);
    });

    it('points at a stack overflow on the JSPI build', () => {
        const message = explainRuntimeCrash(new RuntimeError('memory access out of bounds'), null, { runtime: 'jspi' });
        assert.match(message, /JSPI build/);
        assert.match(message, /stack overflow/);
        assert.doesNotMatch(message, /usleep/);
    });

    it('lists the functions php-wasm reports as missing from Asyncify', () => {
        const message = explainRuntimeCrash(new RuntimeError('unreachable'), 'query select 1', { missing: ['zend_call_function', 'php_usleep'] });
        assert.match(message, /missing from the Asyncify instrumentation: zend_call_function, php_usleep\./);
        assert.doesNotMatch(explainRuntimeCrash(new RuntimeError('unreachable'), null, { missing: [] }), /instrumentation/);
    });

    it('recognises the other crash signatures and works without details', () => {
        assert.match(explainRuntimeCrash(new Error('null function or function signature mismatch'), null), /^PHP crashed \(null function/);
        assert.match(explainRuntimeCrash('Aborted(Asyncify: stack overflow)', null), /PHP crashed/);
    });

    it('leaves ordinary errors alone', () => {
        assert.equal(explainRuntimeCrash(new Error('PHP not initialized'), 'query select 1'), null);
        assert.equal(explainRuntimeCrash(null, null), null);
    });

    it('the bridge remembers the last native call it dispatched', async () => {
        const handle = createBridgeHandler({ db: async () => [] });
        await handle(JSON.stringify({ nativeblade: 'db', sql: 'select * from t' }));
        assert.equal(lastNativeCall(), 'query select * from t');
        assert.equal(describeCall({ nativeblade: 'http_pool', requests: [1, 2] }), 'HTTP pool of 2 requests');
        assert.equal(describeCall({ nativeblade: 'fs', op: 'read', path: 'a.txt' }), 'filesystem read a.txt');
    });
});

class RuntimeError extends Error {}
