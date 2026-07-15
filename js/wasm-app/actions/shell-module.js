// Native shell modules — the JS counterpart of PHP's HasNativeShell trait.
//
// A shell module is an app-provided ES module at
// `nativeblade-components/{name}/{name}.js` (the same `@components` alias and
// build pipeline custom shell components use — split it into as many files as
// you like, the bundler resolves imports). It runs HERE in the shell (the
// parent window, outside the app iframe) — so it survives SPA navigations and
// can keep video/audio running across screens when the component sets
// $shellPersist.
//
// Module contract (default export):
//   export default {
//       mount(ctx, props) {},      // instance created; props = PHP-owned values
//       update(props) {},          // PHP-owned #[NativeProp]s after each render
//       command(name, args) {},    // $this->shell('seek', 30)
//       destroy() {},              // navigation away / shellDestroy()
//   };
//
//   ctx.set(key, value)  — write a shell-owned prop (#[NativeProp(from:'shell')]).
//                          Values ride along to PHP on the NEXT natural request
//                          (zero extra requests); a prop declared with
//                          `throttle: N` ALSO pushes a Livewire update at most
//                          once per N ms.
//   ctx.emit(event, data) — human-paced event to the component, delivered as
//                          `nb:shell:{shell}:{event}` AND the instance-scoped
//                          `nb:shell:{shell}:{id}:{event}` (same double-emit
//                          pattern as realtime per-channel routing).
//
// Lifecycle: instances are keyed by the Livewire component id. Non-persistent
// instances are destroyed on frame swap (navigation); the incoming page's
// instances survive because their source window IS the frame being swapped in.

import { postToApp, onFrameSwap } from '../bridge.js';

const instances = new Map();   // component id -> instance
const moduleCache = new Map(); // shell name -> Promise<module>

// Snapshot read by the wasm host (request-handler.js) right before every PHP
// request and written to /tmp/__nb_shell_props.json — how `from: 'shell'`
// props reach hydrate without a single dedicated request.
if (typeof window !== 'undefined') {
    window.__NB_SHELL_PROPS__ = () => {
        const snapshot = {};
        for (const [id, inst] of instances) {
            if (Object.keys(inst.state).length) snapshot[id] = inst.state;
        }
        return snapshot;
    };
}

onFrameSwap((frame) => {
    const win = frame?.contentWindow;
    for (const [id, inst] of [...instances]) {
        // The new page's mounts arrive from the buffer frame BEFORE the swap,
        // so their source window matches the incoming frame and they survive.
        if (!inst.persist && inst.win && inst.win !== win) destroyInstance(id);
    }
});

async function loadModule(name) {
    if (moduleCache.has(name)) return moduleCache.get(name);
    const promise = (async () => {
        if (!/^[a-z0-9_-]+$/i.test(name)) {
            throw new Error(`invalid shell module name '${name}'`);
        }
        // Same load path as custom shell components (component-registry):
        // the @components alias resolves to the app's nativeblade-components/
        // folder and the bundler ships the module (and anything it imports).
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
    if (throttle == null) return; // ride-along only: PHP reads it at its next request

    const wait = (inst.lastPush[key] || 0) + throttle - Date.now();
    if (wait <= 0) {
        pushProp(inst, key);
    } else if (!inst.timers[key]) {
        // Trailing edge sends the LATEST value, not the one that armed the timer.
        inst.timers[key] = setTimeout(() => {
            delete inst.timers[key];
            pushProp(inst, key);
        }, wait);
    }
}

// --- action handlers (registered in ./index.js) ---------------------------

export async function shell_module_mount(payload, ctx) {
    const { shell, id, props = {}, shellProps = [], persist = false } = payload || {};
    if (!shell || !id) return;

    if (instances.has(id)) destroyInstance(id); // remount replaces

    const specs = {};
    for (const spec of shellProps) specs[spec.name] = spec.throttle ?? null;

    const inst = {
        id, shell, specs,
        module: null,
        state: {},          // shell-owned prop values (the ride-along source)
        persist: !!persist,
        win: ctx?.replyWindow || ctx?.appFrame?.contentWindow || null,
        timers: {},
        lastPush: {},
        pending: [],        // update/command arriving while the module loads
    };
    instances.set(id, inst);

    let module;
    try {
        module = await loadModule(shell);
    } catch (e) {
        console.error(`[NB] shell module '${shell}' failed to load`, e);
        if (instances.get(id) === inst) instances.delete(id);
        return;
    }
    if (instances.get(id) !== inst) return; // destroyed/replaced while loading

    inst.module = module;
    const moduleCtx = {
        id,
        shell,
        set: (key, value) => setShellProp(inst, key, value),
        emit: (event, data = {}) => {
            postToApp(`nativeblade-shell:${shell}:${event}`, { id, shell, ...data });
            postToApp(`nativeblade-shell:${shell}:${id}:${event}`, { id, shell, ...data });
        },
    };

    try { await module?.mount?.(moduleCtx, props); }
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
    const inst = instances.get(payload?.id);
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
