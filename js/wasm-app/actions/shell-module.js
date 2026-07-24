// Native shell modules — JS counterpart of PHP's HasNativeShell trait.
// Default export is a setup function `(nb) => {...}` run in the shell window:
// the call is the mount, state lives in its closure, reactions register through
// nb (php.watch/listen/set/emit/props, element, context, onCleanup) and tear
// down automatically. See docs.nativeblade.dev/core/native-shell/.

import { postToApp, onFrameSwap } from '../bridge.js';
import { importAppComponent } from '../component-registry.js';

const instances = new Map();   // component id -> instance
const moduleCache = new Map(); // shell name -> Promise<module>

// Read by request-handler.js before every PHP request and written to
// /tmp/__nb_shell_props.json — how `from: 'shell'` props reach hydrate.
if (typeof window !== 'undefined') {
    window.__NB_SHELL_PROPS__ = () => {
        const snapshot = {};
        for (const [id, inst] of instances) {
            if (Object.keys(inst.state).length) snapshot[id] = inst.state;
        }
        return snapshot;
    };
}

// Registered lazily: this module sits in a circular import with bridge.js, so
// calling onFrameSwap at module evaluation time hits a TDZ ReferenceError.
let frameSwapGcRegistered = false;
function ensureFrameSwapGc() {
    if (frameSwapGcRegistered) return;
    frameSwapGcRegistered = true;
    onFrameSwap((frame) => {
        const win = frame?.contentWindow;
        for (const [id, inst] of [...instances]) {
            if (!inst.persist && inst.win && inst.win !== win) destroyInstance(id);
        }
    });
}

// Test seam: supplies a module's default export directly, bypassing the
// importAppComponent bundle/wasm-FS path (which needs a built app). Unit tests
// only — no production caller sets it.
let testModuleLoader = null;

async function loadModule(name) {
    if (moduleCache.has(name)) return moduleCache.get(name);
    const promise = (async () => {
        if (!/^[a-z0-9_-]+$/i.test(name)) {
            throw new Error(`invalid shell module name '${name}'`);
        }
        if (testModuleLoader) return testModuleLoader(name);
        const mod = await importAppComponent(name);
        if (!mod.default) throw new Error(`shell module '${name}' has no default export`);
        return mod.default;
    })();
    moduleCache.set(name, promise);
    promise.catch(() => moduleCache.delete(name));
    return promise;
}

function destroyInstance(id) {
    const inst = instances.get(id);
    if (!inst) return;
    instances.delete(id);
    for (const timer of Object.values(inst.timers)) clearTimeout(timer);
    for (const fn of inst.cleanups || []) {
        try { fn(); } catch (e) { console.error(`[NB] shell module '${inst.shell}' cleanup failed`, e); }
    }
    if (inst.el) {
        try { inst.el.remove(); } catch {}
        inst.el = null;
    }
}

// Optional positioning helper for module elements: fixed placement in one of
// the common spots, safe-area aware. Only what positioning needs — visual
// styles (and overrides) are the module's own, applied after the call.
function placeElement(el, position = 'top-center', { offset = 10, zIndex = 99999 } = {}) {
    if (!el) return el;
    el.style.position = 'fixed';
    el.style.zIndex = String(zIndex);

    if (position === 'center') {
        el.style.top = '50%';
        el.style.left = '50%';
        el.style.transform = 'translate(-50%, -50%)';
        return el;
    }

    const [vertical, horizontal = 'center'] = position.split('-');
    if (vertical === 'bottom') {
        el.style.bottom = `calc(env(safe-area-inset-bottom, 0px) + ${offset}px)`;
    } else {
        el.style.top = `calc(env(safe-area-inset-top, 0px) + ${offset}px)`;
    }

    if (horizontal === 'left') {
        el.style.left = `${offset}px`;
    } else if (horizontal === 'right') {
        el.style.right = `${offset}px`;
    } else {
        el.style.left = '50%';
        el.style.transform = 'translateX(-50%)';
    }
    return el;
}

function pushProp(inst, key) {
    inst.lastPush[key] = Date.now();
    postToApp('nativeblade-shell-prop', { id: inst.id, shell: inst.shell, key, value: inst.state[key] });
}

function setShellProp(inst, key, value) {
    if (!(key in inst.specs)) {
        const why = inst.hostless
            ? `has no Livewire host to receive it (mounted declaratively via <x-...>) — bind the component with HasNativeShell to sync props back to PHP`
            : `is not declared #[NativeProp(from: 'shell')]`;
        console.warn(`[NB] shell module '${inst.shell}': nb.php.set('${key}') ignored — ${why}`);
        return;
    }
    inst.state[key] = value;

    const throttle = inst.specs[key];
    if (throttle == null) return;

    const wait = (inst.lastPush[key] || 0) + throttle - Date.now();
    if (wait <= 0) {
        pushProp(inst, key);
    } else if (!inst.timers[key]) {
        inst.timers[key] = setTimeout(() => {
            delete inst.timers[key];
            pushProp(inst, key);
        }, wait);
    }
}

function fireWatchers(inst, props) {
    for (const key of Object.keys(props)) {
        const fns = inst.watchers[key];
        if (!fns) continue;
        for (const fn of fns) {
            try { fn(props[key], key); }
            catch (e) { console.error(`[NB] shell module '${inst.shell}' watch('${key}') failed`, e); }
        }
    }
}

function fireListeners(inst, name, args) {
    const fns = inst.listeners[name];
    if (!fns) return;
    for (const fn of fns) {
        try { fn(args, name); }
        catch (e) { console.error(`[NB] shell module '${inst.shell}' listen('${name}') failed`, e); }
    }
}

// The `nb` handle passed to a setup module: element (lazy root div, auto-removed),
// context (shell environment), php (message bridge, events + state, never RPC),
// onCleanup (teardown for what the module made itself).
function buildNb(inst, shell) {
    return {
        get element() {
            if (!inst.el) {
                inst.el = document.createElement('div');
                inst.el.id = `nb-${shell}`;
                document.body.appendChild(inst.el);
            }
            return inst.el;
        },
        context: {
            place: placeElement,
            get id() { return inst.id; },
            get shell() { return shell; },
        },
        php: {
            emit: (event, data = {}) => {
                // Generic name is global (any #[On] catches it); id-scoped name
                // addresses one bound instance, skipped when host-less.
                postToApp(`nativeblade-shell:${shell}:${event}`, { id: inst.id, shell, ...data });
                if (!inst.hostless) {
                    postToApp(`nativeblade-shell:${shell}:${inst.id}:${event}`, { id: inst.id, shell, ...data });
                }
            },
            set: (key, value) => setShellProp(inst, key, value),
            get props() { return { ...inst.lastProps }; },
            watch: (prop, fn) => { (inst.watchers[prop] ??= []).push(fn); },
            listen: (name, fn) => { (inst.listeners[name] ??= []).push(fn); },
        },
        onCleanup: (fn) => { inst.cleanups.push(fn); },
    };
}

export async function shell_module_mount(payload, ctx) {
    const { shell, id, owner = '', props = {}, shellProps = [], persist = false, hostless = false } = payload || {};
    if (!shell || !id) return;

    ensureFrameSwapGc();

    const specs = {};
    for (const spec of shellProps) specs[spec.name] = spec.throttle ?? null;

    // A persistent module is a singleton per shell name: navigating back gives a
    // NEW Livewire id, so rebind the running instance instead of stacking a mount.
    if (persist) {
        const existing = [...instances.values()].find(i => i.shell === shell && i.persist);
        if (existing) {
            if (owner && existing.owner && existing.owner !== owner) {
                console.warn(
                    `[NB] shell module '${shell}' is persistent and already owned by ${existing.owner}, `
                    + `but ${owner} also declares it — its props now silently overwrite the previous owner's. `
                    + `A persistent shell must have a SINGLE owner component living above navigation; `
                    + `other screens should use NativeBlade::shellCommand('${shell}', ...) instead.`
                );
            }
            instances.delete(existing.id);
            existing.id = id;
            existing.owner = owner;
            existing.specs = specs;
            existing.win = ctx?.replyWindow || ctx?.appFrame?.contentWindow || existing.win;
            instances.set(id, existing);
            return;
        }
    }

    if (instances.has(id)) destroyInstance(id);

    const inst = {
        id, shell, specs, owner,
        ready: false,
        state: {},
        persist: !!persist,
        hostless: !!hostless,
        win: ctx?.replyWindow || ctx?.appFrame?.contentWindow || null,
        timers: {},
        lastPush: {},
        pending: [],
        watchers: {},    // nb.php.watch:  prop -> [fn]
        listeners: {},   // nb.php.listen: name -> [fn]
        cleanups: [],    // nb.onCleanup
        lastProps: {},   // nb.php.props
        el: null,        // nb.element
        nb: null,
    };
    instances.set(id, inst);

    let exported;
    try {
        exported = await loadModule(shell);
    } catch (e) {
        console.error(`[NB] shell module '${shell}' failed to load`, e);
        if (instances.get(id) === inst) instances.delete(id);
        return;
    }
    if (instances.get(id) !== inst) return;

    Object.assign(inst.lastProps, props);

    if (typeof exported !== 'function') {
        console.error(`[NB] shell component '${shell}' must export a setup function: export default (nb) => {...}`);
        if (instances.get(id) === inst) instances.delete(id);
        return;
    }

    // The call IS the mount: registers reactions through nb, state in its closure.
    inst.nb = buildNb(inst, shell);
    try { exported(inst.nb); }
    catch (e) { console.error(`[NB] shell component '${shell}' setup failed`, e); }
    inst.ready = true;

    fireWatchers(inst, props);   // initial props through nb.php.watch
    for (const run of inst.pending.splice(0)) run();
}

export function shell_module_update(payload) {
    const inst = instances.get(payload?.id);
    if (!inst) return;
    const run = () => {
        const props = payload.props || {};
        Object.assign(inst.lastProps, props);
        fireWatchers(inst, props);
    };
    inst.ready ? run() : inst.pending.push(run);
}

export function shell_module_command(payload) {
    // Addressed by component id (owner's $this->shell(...)) or, without an id,
    // by shell NAME (NativeBlade::shellCommand from any screen/service).
    let inst = payload?.id ? instances.get(payload.id) : null;
    if (!inst && payload?.shell) {
        const matches = [...instances.values()].filter(i => i.shell === payload.shell);
        inst = matches.find(i => i.persist) || matches[0] || null;
        if (matches.length > 1) {
            console.warn(`[NB] shellCommand('${payload.shell}'): ${matches.length} instances running, targeting ${inst.persist ? 'the persistent one' : 'the first'} — address per-instance commands through the owner instead`);
        }
    }
    if (!inst) return;
    const run = () => fireListeners(inst, payload.command, payload.args || []);
    inst.ready ? run() : inst.pending.push(run);
}

export function shell_module_destroy(payload) {
    if (payload?.id) destroyInstance(payload.id);
}

// ---- Declarative summon (no HasNativeShell host) --------------------------
// A `<x-nativeblade-{name}>` reaches here from the component registry, keyed by
// NAME and host-less. The page config owns its lifecycle (present => mount/
// update, absent => destroy), so it mounts persist:true to opt out of the
// navigation GC and let the registry decide when it goes.

const declarativeIds = new Map(); // shell name -> synthetic instance id

export async function shell_component_render(name, props) {
    if (props == null) {
        shell_component_destroy(name);
        return;
    }

    const existing = declarativeIds.get(name);
    if (existing && instances.has(existing)) {
        shell_module_update({ id: existing, props });
        return;
    }

    const id = `__decl:${name}`;
    declarativeIds.set(name, id);
    await shell_module_mount({ shell: name, id, props, persist: true, hostless: true });
}

export function shell_component_destroy(name) {
    const id = declarativeIds.get(name);
    if (!id) return;
    declarativeIds.delete(name);
    destroyInstance(id);
}

// ---- Test seams -----------------------------------------------------------
// Unit tests only. __setModuleLoaderForTest injects module implementations;
// __resetForTest tears down all live instances and caches between tests.
export function __setModuleLoaderForTest(fn) {
    testModuleLoader = fn;
}

export function __resetForTest() {
    for (const id of [...instances.keys()]) destroyInstance(id);
    instances.clear();
    moduleCache.clear();
    declarativeIds.clear();
    testModuleLoader = null;
}
