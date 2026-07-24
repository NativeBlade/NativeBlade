import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

// shell-module.js registers window.__NB_SHELL_PROPS__ at eval and setFrame()
// calls camera.init() which touches document — neither exists in node. Stub the
// minimum FIRST, then dynamically import bridge (which transitively evaluates
// shell-module) so the window hook is installed against our stub.
globalThis.window = globalThis.window ?? {};
globalThis.document = globalThis.document ?? (() => {
    const el = { style: {}, files: [], addEventListener() {}, appendChild() {} };
    return {
        createElement: () => el,
        body: { appendChild() {} },
        documentElement: { style: { setProperty() {} } },
    };
})();

const { setFrame } = await import('../../../js/wasm-app/bridge.js');
const {
    shell_module_mount,
    shell_module_update,
    shell_module_command,
    shell_module_destroy,
    shell_component_render,
    shell_component_destroy,
    __setModuleLoaderForTest,
    __resetForTest,
} = await import('../../../js/wasm-app/actions/shell-module.js');

// A fake default-export module. Uses closures (not `this`) so the shallow clone
// mount() makes per instance still records into the same arrays.
function makeModule() {
    const calls = [];
    const state = { ctx: null };
    return {
        calls,
        state,
        mount(ctx, props) { state.ctx = ctx; calls.push(['mount', props]); },
        update(props) { calls.push(['update', props]); },
        command(name, args) { calls.push(['command', name, args]); },
        destroy() { calls.push(['destroy']); },
    };
}

describe('actions/shell-module', () => {
    let posts;      // messages posted to the app iframe via postToApp
    let modules;    // shell name -> fake module
    let warns;
    let origWarn;

    beforeEach(() => {
        __resetForTest();
        posts = [];
        setFrame({ contentWindow: { postMessage: (msg) => posts.push(msg) } });

        modules = {};
        __setModuleLoaderForTest((name) => modules[name]);

        warns = [];
        origWarn = console.warn;
        console.warn = (...a) => warns.push(a.join(' '));
    });

    afterEach(() => {
        console.warn = origWarn;
        __resetForTest();
    });

    const eventsOfType = (type) => posts.filter((m) => m.type === type);

    // ---- Bound path (HasNativeShell host) ---------------------------------

    it('mounts the module with the given props', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1', props: { url: 'a.mp4' } });

        assert.deepEqual(mod.calls, [['mount', { url: 'a.mp4' }]]);
        assert.ok(mod.state.ctx, 'ctx handed to mount');
    });

    it('emit fires BOTH the generic (global) and the id-scoped event when bound', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1' });

        mod.state.ctx.emit('ended', { at: 12 });

        const generic = eventsOfType('nativeblade-shell:player:ended');
        const scoped = eventsOfType('nativeblade-shell:player:c1:ended');
        assert.equal(generic.length, 1);
        assert.equal(scoped.length, 1);
        assert.equal(generic[0].at, 12);
    });

    it('set on a ride-along (throttle null) shell prop updates state, no push', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({
            shell: 'player', id: 'c1',
            shellProps: [{ name: 'position', throttle: null }],
        });

        mod.state.ctx.set('position', 42);

        assert.equal(eventsOfType('nativeblade-shell-prop').length, 0, 'ride-along never pushes');
        assert.deepEqual(window.__NB_SHELL_PROPS__(), { c1: { position: 42 } });
    });

    it('set on a throttled shell prop pushes immediately the first time', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({
            shell: 'player', id: 'c1',
            shellProps: [{ name: 'position', throttle: 500 }],
        });

        mod.state.ctx.set('position', 7);

        const pushes = eventsOfType('nativeblade-shell-prop');
        assert.equal(pushes.length, 1);
        assert.equal(pushes[0].key, 'position');
        assert.equal(pushes[0].value, 7);
    });

    it('set on an undeclared key is ignored with a warning', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1' });

        mod.state.ctx.set('nope', 1);

        assert.equal(eventsOfType('nativeblade-shell-prop').length, 0);
        assert.ok(warns.some((w) => w.includes("ctx.set('nope')")));
    });

    it('update applies the partial patch to the module', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1', props: { url: 'a' } });

        shell_module_update({ id: 'c1', props: { url: 'b' } });

        assert.deepEqual(mod.calls.at(-1), ['update', { url: 'b' }]);
    });

    it('command routes to the module by id', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1' });

        shell_module_command({ id: 'c1', command: 'seek', args: [30] });

        assert.deepEqual(mod.calls.at(-1), ['command', 'seek', [30]]);
    });

    it('command addresses by shell name when no id is given', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1', persist: true });

        shell_module_command({ shell: 'player', command: 'pause', args: [] });

        assert.deepEqual(mod.calls.at(-1), ['command', 'pause', []]);
    });

    it('destroy tears down the instance', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1' });

        shell_module_destroy({ id: 'c1' });

        assert.deepEqual(mod.calls.at(-1), ['destroy']);
        // A later command is a silent no-op — the instance is gone.
        shell_module_command({ id: 'c1', command: 'x', args: [] });
        assert.equal(mod.calls.filter((c) => c[0] === 'command').length, 0);
    });

    it('a persistent module is a singleton: remount under a new id rebinds, not stacks', async () => {
        const mod = modules['player'] = makeModule();
        await shell_module_mount({ shell: 'player', id: 'c1', persist: true });
        await shell_module_mount({ shell: 'player', id: 'c2', persist: true });

        // mount() ran once; the second call rebound the running instance to c2.
        assert.equal(mod.calls.filter((c) => c[0] === 'mount').length, 1);
        shell_module_command({ id: 'c2', command: 'ok', args: [] });
        assert.deepEqual(mod.calls.at(-1), ['command', 'ok', []]);
    });

    // ---- Host-less path (declarative <x-...>, no host) --------------------

    it('shell_component_render mounts host-less with the tag props', async () => {
        const mod = modules['tab-bar'] = makeModule();
        await shell_component_render('tab-bar', { message: 'Hi' });

        assert.deepEqual(mod.calls, [['mount', { message: 'Hi' }]]);
    });

    it('host-less emit fires ONLY the generic (global) event', async () => {
        const mod = modules['tab-bar'] = makeModule();
        await shell_component_render('tab-bar', { message: 'Hi' });

        mod.state.ctx.emit('tap', { i: 2 });

        assert.equal(eventsOfType('nativeblade-shell:tab-bar:tap').length, 1);
        // No id-scoped event: there is no host to address per instance.
        assert.equal(posts.filter((m) => m.type.includes(':__decl:')).length, 0);
    });

    it('host-less set is ignored and never leaks into the props snapshot', async () => {
        const mod = modules['tab-bar'] = makeModule();
        await shell_component_render('tab-bar', { message: 'Hi' });

        mod.state.ctx.set('active', 3);

        assert.ok(warns.some((w) => w.includes('no Livewire host')));
        assert.deepEqual(window.__NB_SHELL_PROPS__(), {}, 'host-less state stays out of the snapshot');
    });

    it('re-rendering the same declarative component updates instead of remounting', async () => {
        const mod = modules['tab-bar'] = makeModule();
        await shell_component_render('tab-bar', { message: 'a' });
        await shell_component_render('tab-bar', { message: 'b' });

        assert.equal(mod.calls.filter((c) => c[0] === 'mount').length, 1);
        assert.deepEqual(mod.calls.at(-1), ['update', { message: 'b' }]);
    });

    it('rendering a declarative component with null props destroys it', async () => {
        const mod = modules['tab-bar'] = makeModule();
        await shell_component_render('tab-bar', { message: 'a' });

        shell_component_destroy('tab-bar');

        assert.deepEqual(mod.calls.at(-1), ['destroy']);
    });
});
