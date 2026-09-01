import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

// shell-module.js registers window.__NB_SHELL_PROPS__ at eval and setFrame()
// calls camera.init() which touches document — neither exists in node. Stub the
// minimum FIRST, then dynamically import bridge (which transitively evaluates
// shell-module) so the window hook is installed against our stub.
globalThis.window = globalThis.window ?? {};
globalThis.document = globalThis.document ?? {
    createElement: () => ({ style: {}, files: [], addEventListener() {}, appendChild() {}, remove() { this.removed = true; } }),
    body: { appendChild() {} },
    documentElement: { style: { setProperty() {} } },
};

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

describe('actions/shell-module', () => {
    let posts;      // messages posted to the app iframe via postToApp
    let modules;    // shell name -> setup function
    let warns, errors, origWarn, origError;

    beforeEach(() => {
        __resetForTest();
        posts = [];
        setFrame({ contentWindow: { postMessage: (msg) => posts.push(msg) } });

        modules = {};
        __setModuleLoaderForTest((name) => modules[name]);

        warns = [];
        errors = [];
        origWarn = console.warn;
        origError = console.error;
        console.warn = (...a) => warns.push(a.join(' '));
        console.error = (...a) => errors.push(a.map(String).join(' '));
    });

    afterEach(() => {
        console.warn = origWarn;
        console.error = origError;
        __resetForTest();
    });

    const eventsOfType = (type) => posts.filter((m) => m.type === type);

    // ---- Bound path (HasNativeShell host) ---------------------------------

    it('runs the setup with the initial props through watch', async () => {
        const seen = [];
        modules['player'] = (nb) => nb.php.watch('url', (u) => seen.push(u));
        await shell_module_mount({ shell: 'player', id: 'c1', props: { url: 'a.mp4' } });

        assert.deepEqual(seen, ['a.mp4']);
    });

    it('emit fires BOTH the generic (global) and the id-scoped event when bound', async () => {
        let nb;
        modules['player'] = (h) => { nb = h; };
        await shell_module_mount({ shell: 'player', id: 'c1' });

        nb.php.emit('ended', { at: 12 });

        const generic = eventsOfType('nativeblade-shell:player:ended');
        const scoped = eventsOfType('nativeblade-shell:player:c1:ended');
        assert.equal(generic.length, 1);
        assert.equal(scoped.length, 1);
        assert.equal(generic[0].at, 12);
    });

    it('set on a ride-along (throttle null) prop updates state, no push', async () => {
        let nb;
        modules['player'] = (h) => { nb = h; };
        await shell_module_mount({
            shell: 'player', id: 'c1',
            shellProps: [{ name: 'position', throttle: null }],
        });

        nb.php.set('position', 42);

        assert.equal(eventsOfType('nativeblade-shell-prop').length, 0);
        assert.deepEqual(window.__NB_SHELL_PROPS__(), { c1: { position: 42 } });
    });

    it('set on a throttled prop pushes immediately the first time', async () => {
        let nb;
        modules['player'] = (h) => { nb = h; };
        await shell_module_mount({
            shell: 'player', id: 'c1',
            shellProps: [{ name: 'position', throttle: 500 }],
        });

        nb.php.set('position', 7);

        const pushes = eventsOfType('nativeblade-shell-prop');
        assert.equal(pushes.length, 1);
        assert.equal(pushes[0].value, 7);
    });

    it('set on an undeclared key is ignored with a warning', async () => {
        let nb;
        modules['player'] = (h) => { nb = h; };
        await shell_module_mount({ shell: 'player', id: 'c1' });

        nb.php.set('nope', 1);

        assert.equal(eventsOfType('nativeblade-shell-prop').length, 0);
        assert.ok(warns.some((w) => w.includes("nb.php.set('nope')")));
    });

    it('watch fires again on update (partial patch)', async () => {
        const seen = [];
        modules['player'] = (nb) => nb.php.watch('url', (u) => seen.push(u));
        await shell_module_mount({ shell: 'player', id: 'c1', props: { url: 'a' } });

        shell_module_update({ id: 'c1', props: { url: 'b' } });
        assert.deepEqual(seen, ['a', 'b']);
    });

    it('nb.php.props reflects the last pushed props', async () => {
        let nb;
        modules['sp'] = (h) => { nb = h; };
        await shell_module_mount({ shell: 'sp', id: 's1', props: { title: 'A' } });
        assert.equal(nb.php.props.title, 'A');

        shell_module_update({ id: 's1', props: { title: 'B' } });
        assert.equal(nb.php.props.title, 'B');
    });

    it('listen fires on a command addressed by id', async () => {
        const seen = [];
        modules['player'] = (nb) => nb.php.listen('seek', (a) => seen.push(a));
        await shell_module_mount({ shell: 'player', id: 'c1' });

        shell_module_command({ id: 'c1', command: 'seek', args: [30] });
        assert.deepEqual(seen, [[30]]);
    });

    it('listen fires on a command addressed by shell name', async () => {
        const seen = [];
        modules['player'] = (nb) => nb.php.listen('pause', () => seen.push(true));
        await shell_module_mount({ shell: 'player', id: 'c1', persist: true });

        shell_module_command({ shell: 'player', command: 'pause', args: [] });
        assert.deepEqual(seen, [true]);
    });

    it('nb.element is lazy, stable, and auto-removed on destroy', async () => {
        let el, sameOnSecondAccess;
        modules['pill'] = (nb) => {
            el = nb.element;
            sameOnSecondAccess = nb.element === el;
        };
        await shell_module_mount({ shell: 'pill', id: 'e1' });

        assert.equal(el.id, 'nb-pill');
        assert.equal(sameOnSecondAccess, true);

        shell_module_destroy({ id: 'e1' });
        assert.equal(el.removed, true);
    });

    it('destroy runs onCleanup, removes the element, and stops commands', async () => {
        let cleaned = false, el;
        const seen = [];
        modules['player'] = (nb) => {
            el = nb.element;
            nb.onCleanup(() => { cleaned = true; });
            nb.php.listen('x', () => seen.push(1));
        };
        await shell_module_mount({ shell: 'player', id: 'c1' });

        shell_module_destroy({ id: 'c1' });
        assert.equal(cleaned, true);
        assert.equal(el.removed, true);

        shell_module_command({ id: 'c1', command: 'x', args: [] });
        assert.deepEqual(seen, [], 'a destroyed instance ignores commands');
    });

    it('a persistent module is a singleton: remount under a new id rebinds, not stacks', async () => {
        let mounts = 0;
        const seen = [];
        modules['player'] = (nb) => { mounts++; nb.php.listen('ok', () => seen.push(1)); };
        await shell_module_mount({ shell: 'player', id: 'c1', persist: true });
        await shell_module_mount({ shell: 'player', id: 'c2', persist: true });

        assert.equal(mounts, 1);
        shell_module_command({ id: 'c2', command: 'ok', args: [] });
        assert.deepEqual(seen, [1]);
    });

    it('rejects a default export that is not a setup function', async () => {
        modules['bad'] = { mount() {} };   // the old object form, no longer supported
        await shell_module_mount({ shell: 'bad', id: 'b1' });

        assert.ok(errors.some((e) => e.includes('must export a setup function')));
    });

    // ---- Host-less path (declarative <x-...>, no host) --------------------

    it('shell_component_render mounts host-less with the tag props', async () => {
        const seen = [];
        modules['tab-bar'] = (nb) => nb.php.watch('message', (m) => seen.push(m));
        await shell_component_render('tab-bar', { message: 'Hi' });

        assert.deepEqual(seen, ['Hi']);
    });

    it('host-less emit fires ONLY the generic (global) event', async () => {
        let nb;
        modules['tab-bar'] = (h) => { nb = h; };
        await shell_component_render('tab-bar', { message: 'Hi' });

        nb.php.emit('tap', { i: 2 });

        assert.equal(eventsOfType('nativeblade-shell:tab-bar:tap').length, 1);
        assert.equal(posts.filter((m) => m.type.includes(':__decl:')).length, 0);
    });

    it('host-less set is ignored and never leaks into the props snapshot', async () => {
        let nb;
        modules['tab-bar'] = (h) => { nb = h; };
        await shell_component_render('tab-bar', { message: 'Hi' });

        nb.php.set('active', 3);

        assert.ok(warns.some((w) => w.includes('no Livewire host')));
        assert.deepEqual(window.__NB_SHELL_PROPS__(), {});
    });

    it('re-rendering the same declarative component updates instead of remounting', async () => {
        let mounts = 0;
        const seen = [];
        modules['tab-bar'] = (nb) => { mounts++; nb.php.watch('message', (m) => seen.push(m)); };
        await shell_component_render('tab-bar', { message: 'a' });
        await shell_component_render('tab-bar', { message: 'b' });

        assert.equal(mounts, 1);
        assert.deepEqual(seen, ['a', 'b']);
    });

    it('rendering a declarative component with null props destroys it', async () => {
        let cleaned = false;
        modules['tab-bar'] = (nb) => nb.onCleanup(() => { cleaned = true; });
        await shell_component_render('tab-bar', { message: 'a' });

        shell_component_destroy('tab-bar');
        assert.equal(cleaned, true);
    });

    // ---- nb.animate ---------------------------------------------------------

    function fxEl() {
        return {
            style: {},
            classList: {
                s: new Set(),
                add(...c) { c.forEach((x) => this.s.add(x)); },
                remove(...c) { c.forEach((x) => this.s.delete(x)); },
            },
            _end: null,
            addEventListener(evt, fn) { if (evt === 'animationend') this._end = fn; },
            removeEventListener() {},
        };
    }

    it('nb.animate(el).enter adds the animate.css classes and clears them when the animation ends', async () => {
        let nb;
        modules['fx'] = (h) => { nb = h; };
        await shell_module_mount({ shell: 'fx', id: 'c1' });

        const el = fxEl();
        const p = nb.animate(el).enter('slideInLeft', { speed: 'fast' });

        assert.ok(el.classList.s.has('animate__animated'));
        assert.ok(el.classList.s.has('animate__slideInLeft'));
        assert.ok(el.classList.s.has('animate__fast'));

        el._end();
        await p;
        assert.ok(!el.classList.s.has('animate__slideInLeft'), 'enter clears its classes when done');
    });

    it('nb.animate(el).leave hides the element when the exit ends', async () => {
        let nb;
        modules['fx'] = (h) => { nb = h; };
        await shell_module_mount({ shell: 'fx', id: 'c1' });

        const el = fxEl();
        const p = nb.animate(el).leave('slideOutLeft');

        assert.notEqual(el.style.display, 'none');
        el._end();
        await p;
        assert.equal(el.style.display, 'none', 'leave hides the element on completion');
    });

    it('nb.animate with an infinite repeat resolves at once and keeps the looping class', async () => {
        let nb;
        modules['fx'] = (h) => { nb = h; };
        await shell_module_mount({ shell: 'fx', id: 'c1' });

        const el = fxEl();
        await nb.animate(el).enter('pulse', { repeat: 'infinite' });

        assert.ok(el.classList.s.has('animate__infinite'));
        assert.ok(el.classList.s.has('animate__pulse'), 'looping class stays until you animate it out');
    });

    it('a new nb.animate on the same element cancels the one in flight', async () => {
        let nb;
        modules['fx'] = (h) => { nb = h; };
        await shell_module_mount({ shell: 'fx', id: 'c1' });

        const el = fxEl();
        const first = nb.animate(el).enter('slideInLeft');
        assert.ok(el.classList.s.has('animate__slideInLeft'));

        const second = nb.animate(el).enter('fadeIn');   // supersedes the first
        await first;                                     // the cancelled promise resolves, never hangs

        assert.ok(!el.classList.s.has('animate__slideInLeft'), 'previous classes are removed');
        assert.ok(el.classList.s.has('animate__fadeIn'), 'the new animation is applied');

        el._end();
        await second;
        assert.ok(!el.classList.s.has('animate__fadeIn'), 'and it cleans up when done');
    });
});
