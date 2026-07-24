import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

// setFrame -> camera.init touches document; stub the minimum FIRST (see
// shell-module.test.js for the same reason), then dynamically import.
globalThis.window = globalThis.window ?? {};
globalThis.document = globalThis.document ?? (() => {
    const el = { style: {}, files: [], addEventListener() {}, appendChild() {} };
    return {
        createElement: () => el,
        body: { appendChild() {} },
        documentElement: { style: { setProperty() {} } },
    };
})();

const { setFrame } = await import('../../js/wasm-app/bridge.js');
const { adaptComponent } = await import('../../js/wasm-app/component-registry.js');
const shellModule = await import('../../js/wasm-app/actions/shell-module.js');

describe('component-registry adaptComponent', () => {
    beforeEach(() => {
        shellModule.__resetForTest();
        setFrame({ contentWindow: { postMessage() {} } });
    });
    afterEach(() => shellModule.__resetForTest());

    it('passes a classic render(data)/hide() component through untouched', () => {
        const classic = { render() {}, hide() {} };
        assert.equal(adaptComponent('bottom-nav', classic), classic);
    });

    it('leaves a component that has render even if it also has a default export', () => {
        const hybrid = { render() {}, default: {} };
        assert.equal(adaptComponent('x', hybrid), hybrid);
    });

    it('wraps a default-export module into a render(data) driver', () => {
        const mod = { default: { mount() {}, update() {}, destroy() {} } };
        const wrapped = adaptComponent('tab-bar', mod);

        assert.notEqual(wrapped, mod);
        assert.equal(typeof wrapped.render, 'function');
        assert.equal(wrapped.__shellModule, true);
    });

    it('the wrapper mounts the module through the host-less path', async () => {
        const calls = [];
        const mod = { default: { mount(ctx, props) { calls.push(['mount', props]); }, update() {}, destroy() {} } };
        shellModule.__setModuleLoaderForTest(() => mod.default);

        const wrapped = adaptComponent('tab-bar', mod);
        wrapped.render({ message: 'Hi' });
        await new Promise((r) => setImmediate(r)); // let the dynamic import + mount settle

        assert.deepEqual(calls, [['mount', { message: 'Hi' }]]);
    });
});
