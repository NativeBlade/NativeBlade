// Native shell modules — JS counterpart of PHP's HasNativeShell trait.
// Loads `nativeblade-components/{name}/{name}.js` (default export contract:
// mount(ctx, props) / update(props) / command(name, args) / destroy()) in the
// shell window, so instances survive SPA navigations. update(props) is a
// PARTIAL patch — only changed props; guard with `'key' in props`. See
// NATIVE-SHELL.md.

import { postToApp, onFrameSwap } from '../bridge.js';

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

async function loadModule(name) {
    if (moduleCache.has(name)) return moduleCache.get(name);
    const promise = (async () => {
        if (!/^[a-z0-9_-]+$/i.test(name)) {
            throw new Error(`invalid shell module name '${name}'`);
        }
        const mod = await import(`@components/${name}/${name}.js`);
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
    try { inst.module?.destroy?.(); } catch (e) { console.error(`[NB] shell module '${inst.shell}' destroy failed`, e); }
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
        console.warn(`[NB] shell module '${inst.shell}': '${key}' is not declared #[NativeProp(from: 'shell')] — ignored`);
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

export async function shell_module_mount(payload, ctx) {
    const { shell, id, owner = '', props = {}, shellProps = [], persist = false } = payload || {};
    if (!shell || !id) return;

    ensureFrameSwapGc();

    const specs = {};
    for (const spec of shellProps) specs[spec.name] = spec.throttle ?? null;

    // A persistent module is a singleton per shell name: navigating back gives
    // the component a NEW Livewire id, so match by name and rebind the running
    // instance to it instead of stacking a second mount. Props are applied by
    // the update action that follows in the same envelope.
    if (persist) {
        const existing = [...instances.values()].find(i => i.shell === shell && i.persist);
        if (existing) {
            if (owner && existing.owner && existing.owner !== owner) {
                console.warn(
                    `[NB] shell module '${shell}' is persistent and already owned by ${existing.owner}, `
                    + `but ${owner} also declares it — its props now silently overwrite the previous owner's. `
                    + `A persistent shell must have a SINGLE owner component living above navigation; `
                    + `other screens should use NativeBlade::shellCommand('${shell}', ...) instead (see NATIVE-SHELL.md).`
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
        module: null,
        state: {},
        persist: !!persist,
        win: ctx?.replyWindow || ctx?.appFrame?.contentWindow || null,
        timers: {},
        lastPush: {},
        pending: [],
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

    // The export is shared between instances — give each its own object so
    // `this` state (elements, timers) never leaks across mounts. Only real
    // classes get `new`: plain functions also have .prototype, so detect via
    // source text (classes throw if called without new; factories may rely on
    // being called without it).
    const isClass = typeof exported === 'function'
        && /^class\b/.test(Function.prototype.toString.call(exported));
    inst.module = typeof exported === 'function'
        ? (isClass ? new exported() : exported())
        : { ...exported };

    const moduleCtx = {
        shell,
        get id() { return inst.id; },
        place: placeElement,
        set: (key, value) => setShellProp(inst, key, value),
        emit: (event, data = {}) => {
            postToApp(`nativeblade-shell:${shell}:${event}`, { id: inst.id, shell, ...data });
            postToApp(`nativeblade-shell:${shell}:${inst.id}:${event}`, { id: inst.id, shell, ...data });
        },
    };

    try { await inst.module?.mount?.(moduleCtx, props); }
    catch (e) { console.error(`[NB] shell module '${shell}' mount failed`, e); }

    for (const run of inst.pending.splice(0)) run();
}

export function shell_module_update(payload) {
    const inst = instances.get(payload?.id);
    if (!inst) return;
    const run = () => {
        try { inst.module?.update?.(payload.props || {}); }
        catch (e) { console.error(`[NB] shell module '${inst.shell}' update failed`, e); }
    };
    inst.module ? run() : inst.pending.push(run);
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
    const run = () => {
        try { inst.module?.command?.(payload.command, payload.args || []); }
        catch (e) { console.error(`[NB] shell module '${inst.shell}' command '${payload.command}' failed`, e); }
    };
    inst.module ? run() : inst.pending.push(run);
}

export function shell_module_destroy(payload) {
    if (payload?.id) destroyInstance(payload.id);
}
