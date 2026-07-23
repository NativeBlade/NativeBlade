import { request } from '../runtime/wasm-server.js';
import { schedulePersist } from './state-store.js';
import { applyConfig } from './shell.js';
import { handleNativeAction, setFrame as setBridgeFrame } from './bridge.js';
import { extractShellConfig, inject } from './interceptor.js';
import { abort as abortHttpBridge } from '../runtime/http-bridge.js';
import { setOnBridgeComplete } from '../runtime/request-handler.js';
import { init as initAutoUpdate } from './auto-update.js';
import { init as initScheduler } from './scheduler.js';
import { setFrame as setPushFrame } from './push.js';
import { logScreenIfEnabled } from '../runtime/analytics-screen.js';
import { nativeNavBegin, nativeNavFinish } from './native-nav.js';
import { positionDesktopWindow } from './desktop-window.js';

let appFrame = null;
let bufferFrame = null;
let splash = null;
let currentPath = null;
let historyStack = [];
let navigationVersion = 0;
let pendingMessageId = null;
let transition = 'none';
let autoUpdateInitialized = false;
let defaultBridgeCallback = null;

// Promise-based request that ALSO awaits bridge (Http/DB/FS) fulfillment, so the
// caller gets the FINAL result, not a `bridgePending` stub. Used by the window
// relay so a satellite component can use the DB/filesystem/HTTP — the native work
// runs here, on the main window's runtime. Each call passes its OWN completion
// callback (`done`), so it never clobbers the main window's bridge callback (nor
// another satellite's). Serialized so two satellite requests can't interleave
// their re-runs through the shared php-wasm instance.
let requestFullQueue = Promise.resolve();
export function requestFull(path, options) {
    const run = () => new Promise((resolve) => {
        let settled = false;
        const done = (r) => { if (settled) return; settled = true; resolve(r); };
        request(path, options, done).then(
            (result) => { if (!result || !result.bridgePending) done(result); },
            (err) => done({ text: String(err && err.message || err), httpStatusCode: 500 })
        );
    });
    requestFullQueue = requestFullQueue.then(run, run);
    return requestFullQueue;
}

export function goBack() {
    // Drop any falsy entries defensively (stacks persisted before the null
    // guard existed, or future regressions) — backing into one 404s.
    while (historyStack.length > 0 && !historyStack[historyStack.length - 1]) {
        historyStack.pop();
    }
    if (historyStack.length > 0) {
        const prev = historyStack.pop();
        navigateInternal(prev, { direction: 'back' });
    } else {
        // Backing out of the root screen: the app decides what happens.
        // Delivered as nb:exit-requested — listen with #[On] and answer with
        // an alert/confirm, or NativeBlade::exit(). No listener = no-op.
        appFrame?.contentWindow?.postMessage({ type: 'nativeblade-exit-requested' }, '*');
    }
}

export function canGoBack() {
    return historyStack.length > 0;
}

export function getCurrentPath() {
    return currentPath || '/';
}

export function getPreviousPath() {
    return historyStack.length > 0 ? historyStack[historyStack.length - 1] : '/';
}

export function setTransition(t) {
    transition = t || 'none';
}

export function init(frame, splashEl) {
    appFrame = frame;
    splash = splashEl;

    // Wrap the iframe in a positioned container with overflow:hidden so the
    // slide animation can move the iframe past the viewport edges without
    // exposing the shell body background. Idempotent.
    setupFrameContainer();

    let pendingSource = null;

    defaultBridgeCallback = (result) => {
        if (pendingMessageId !== null && !result.bridgePending) {
            try {
                const target = pendingSource || appFrame.contentWindow;
                target.postMessage({
                    type: 'nativeblade-response',
                    id: pendingMessageId,
                    result: { text: result.text, httpStatusCode: result.httpStatusCode }
                }, '*');
            } catch {}
            pendingMessageId = null;
            pendingSource = null;
        }
    };
    setOnBridgeComplete(defaultBridgeCallback);

    window.addEventListener('message', async (event) => {
        const { type } = event.data || {};
        // The buffer's first-render mirror copy runs the same page as the
        // visible frame; drop its bridge traffic so page side effects
        // (Livewire boot, wire:init) don't execute twice. Real buffer loads
        // during navigation clear the flag and are served normally.
        if (bufferFrame?.dataset.nbMirror === '1'
            && event.source === bufferFrame.contentWindow) {
            return;
        }
        // Reply to whichever iframe asked. During navigation animations both
        // appFrame and bufferFrame may post messages; using event.source
        // routes the response back correctly.
        const source = event.source || appFrame.contentWindow;

        if (type === 'nativeblade-request') {
            const { id, path, options } = event.data;
            try {
                const result = await request(path, options);
                if (result.bridgePending) {
                    pendingMessageId = id;
                    pendingSource = source;
                    return;
                }
                source.postMessage({
                    type: 'nativeblade-response', id,
                    result: { text: result.text, httpStatusCode: result.httpStatusCode }
                }, '*');
                if (options.method && options.method !== 'GET') schedulePersist();
            } catch (err) {
                source.postMessage({
                    type: 'nativeblade-response', id,
                    result: { text: err.message, httpStatusCode: 500 }
                }, '*');
            }
        } else if (type === 'nativeblade-navigate') {
            const opts = event.data.transition ? { transition: event.data.transition } : {};
            if (event.data.replace) {
                await navigateReplace(event.data.path, opts);
            } else {
                await navigate(event.data.path, opts);
            }
        } else if (type === 'nativeblade-native') {
            // Reply to the frame that dispatched the action — during a slide
            // transition the incoming page fires wire:init actions from the
            // buffer before the swap, and its events must not land on the
            // outgoing page.
            handleNativeAction(event.data.action, event.data.payload, appFrame, event.source);
        }
    });
}

export async function runBoot() {
    return new Promise(async (resolve) => {
        const result = await request('/__nb/boot');

        if (!result.bridgePending) {
            resolve();
            return;
        }

        setOnBridgeComplete((completedResult) => {
            setOnBridgeComplete(defaultBridgeCallback);
            if (completedResult.bridgePending) {
                runBoot().then(resolve);
            } else {
                resolve();
            }
        });
    });
}

export async function navigate(path, options = {}) {
    if (currentPath === path && !options.force) return;
    abortHttpBridge();
    pendingMessageId = null;

    const goingBack = !options.direction
        && historyStack.length > 0
        && historyStack[historyStack.length - 1] === path;

    if (goingBack) {
        historyStack.pop();
        return navigateInternal(path, { ...options, direction: 'back' });
    }

    // currentPath is null until the very first navigation — pushing it would
    // put a null in the stack, and backing into it 404s (navigate to "null").
    if (currentPath && currentPath !== path) {
        historyStack.push(currentPath);
    }
    return navigateInternal(path, options);
}

export async function navigateReplace(path, options = {}) {
    if (currentPath === path && !options.force) return;
    abortHttpBridge();
    pendingMessageId = null;
    return navigateInternal(path, options);
}

async function navigateInternal(path, options = {}) {
    abortHttpBridge();
    currentPath = path;
    const version = ++navigationVersion;
    const response = await request(path, options);

    if (version !== navigationVersion) return;

    if (response.bridgePending) {
        setOnBridgeComplete((completedResult) => {
            setOnBridgeComplete(defaultBridgeCallback);
            if (!completedResult.bridgePending && completedResult.text) {
                renderPage(completedResult.text, path, options, version).then(armBackSentinel);
            }
        });
        return;
    }

    if (response.nativeblade) {
        for (const action of response.nativeblade) {
            handleNativeAction(action.action.replace('so:', ''), action.data, appFrame);
        }
        return;
    }

    await renderPage(response.text, path, options, version);
    armBackSentinel();
}

// The webview's joint session history gains an entry every time an iframe
// srcdoc is (re)assigned. Android's back gesture walks that joint history
// (WryActivity: canGoBack -> goBack), so without this the gesture re-navigates
// a dead iframe entry instead of reaching our popstate handler. Re-arming a
// same-document sentinel after every navigation keeps the TOP entry ours:
// gesture -> popstate -> app-level goBack().
function armBackSentinel() {
    try { history.pushState(null, '', location.href); } catch {}
}

async function renderPage(text, path, options, version) {
    if (!text || text.trim() === '') return;
    if (version !== navigationVersion) return;

    logScreenIfEnabled(path);

    let html = text;
    try {
        const m = html.match(/<html[^>]*\blang="([^"]+)"/i);
        if (m && m[1]) localStorage.setItem('nb:locale', m[1]);
    } catch {}
    const config = extractShellConfig(html);
    const pageTransition = options.transition || config.transition || transition;
    html = inject(html);

    const isFirstRender = appFrame.style.display !== 'block';
    splash.style.display = 'none';
    appFrame.style.display = 'block';
    await applyConfig(config, path);
    if (config.window?.anchor) positionDesktopWindow(config.window.anchor);

    if (!autoUpdateInitialized && config.update) {
        autoUpdateInitialized = true;
        initAutoUpdate(config.update);
    }

    if (config.schedules) {
        initScheduler(config.schedules);
    }

    if (isFirstRender || !appFrame.contentDocument || !appFrame.contentDocument.body) {
        appFrame.srcdoc = html;
        // The buffer keeps a mirror of the page for the transition system,
        // but that copy also *runs* (Livewire boots, wire:init fires), which
        // would execute every page side effect twice. Flag it so the message
        // listener drops its bridge traffic; the flag is cleared when a
        // navigation loads a real page into the buffer.
        bufferFrame.dataset.nbMirror = '1';
        bufferFrame.srcdoc = html;
        return;
    }

    if (!appFrame.contentDocument || !appFrame.contentDocument.body) {
        appFrame.srcdoc = html;
        bufferFrame.dataset.nbMirror = '1';
        bufferFrame.srcdoc = html;
        return;
    }

    const direction = options.direction || 'forward';
    const duration = 320;
    const easing = 'cubic-bezier(0.32, 0.72, 0, 1)';
    const slide = pageTransition === 'slide' || pageTransition === 'slide-left';
    const noTransition = pageTransition === 'none';

    const newBg = sampleBodyBg(html);
    const container = appFrame.parentNode;
    if (container && newBg) container.style.backgroundColor = newBg;

    if (noTransition) {
        bufferFrame.style.transition = 'none';
        bufferFrame.style.transform = 'translateX(100%)';
        bufferFrame.style.opacity = '1';
        bufferFrame.style.zIndex = '2';
        bufferFrame.style.pointerEvents = 'none';

        const loaded = new Promise((resolve) => {
            const onLoad = () => {
                bufferFrame.removeEventListener('load', onLoad);
                resolve();
            };
            bufferFrame.addEventListener('load', onLoad);
        });
        delete bufferFrame.dataset.nbMirror;
        bufferFrame.srcdoc = html;
        await loaded;
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

        void bufferFrame.offsetWidth;
        bufferFrame.style.transform = 'translateX(0)';
        appFrame.style.transform = 'translateX(-100%)';
        appFrame.style.opacity = '0';

        const oldFrame = appFrame;
        appFrame = bufferFrame;
        bufferFrame = oldFrame;
        try { setBridgeFrame(appFrame); } catch {}
        try { setPushFrame(appFrame); } catch {}

        appFrame.style.transition = '';
        appFrame.style.transform = 'translateX(0)';
        appFrame.style.opacity = '1';
        appFrame.style.zIndex = '1';
        appFrame.style.pointerEvents = 'auto';

        bufferFrame.style.transition = 'none';
        bufferFrame.style.transform = 'translateX(100%)';
        bufferFrame.style.opacity = '0';
        bufferFrame.style.zIndex = '0';
        bufferFrame.style.pointerEvents = 'none';
        bufferFrame.srcdoc = '';
        return;
    }

    // Native transition compositor (optional NATIVE_NAV plugin): freeze the
    // outgoing page as a native overlay, swap the DOM instantly beneath it,
    // and let the platform animate the overlay in its own style. Falls back
    // to the CSS transitions below when the plugin isn't installed.
    if (await nativeNavBegin(appFrame)) {
        bufferFrame.style.transition = 'none';
        bufferFrame.style.transform = 'translateX(0)';
        bufferFrame.style.opacity = '1';
        bufferFrame.style.zIndex = '2';
        bufferFrame.style.pointerEvents = 'none';

        const nativeLoaded = new Promise((resolve) => {
            const onLoad = () => {
                bufferFrame.removeEventListener('load', onLoad);
                resolve();
            };
            bufferFrame.addEventListener('load', onLoad);
        });
        delete bufferFrame.dataset.nbMirror;
        bufferFrame.srcdoc = html;
        await nativeLoaded;
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

        const oldFrame = appFrame;
        appFrame = bufferFrame;
        bufferFrame = oldFrame;
        try { setBridgeFrame(appFrame); } catch {}
        try { setPushFrame(appFrame); } catch {}

        appFrame.style.transition = '';
        appFrame.style.transform = 'translateX(0)';
        appFrame.style.opacity = '1';
        appFrame.style.zIndex = '1';
        appFrame.style.pointerEvents = 'auto';

        bufferFrame.style.transition = 'none';
        bufferFrame.style.transform = 'translateX(100%)';
        bufferFrame.style.opacity = '0';
        bufferFrame.style.zIndex = '0';
        bufferFrame.style.pointerEvents = 'none';
        bufferFrame.srcdoc = '';

        nativeNavFinish(direction, duration);
        return;
    }

    // Dual-iframe approach for slide and fade transitions. The buffer loads
    // the new page off-screen so the visible iframe never goes blank during
    // the animation.

    bufferFrame.style.transition = 'none';
    bufferFrame.style.zIndex = '2';
    bufferFrame.style.pointerEvents = 'none';

    if (slide) {
        bufferFrame.style.transform = direction === 'back' ? 'translateX(-100%)' : 'translateX(100%)';
        bufferFrame.style.opacity = '1';
    } else {
        // Fade: buffer overlays at translateX(0), hidden via opacity until
        // the new content has loaded.
        bufferFrame.style.transform = 'translateX(0)';
        bufferFrame.style.opacity = '0';
    }

    const loaded = new Promise((resolve) => {
        const onLoad = () => {
            bufferFrame.removeEventListener('load', onLoad);
            resolve();
        };
        bufferFrame.addEventListener('load', onLoad);
    });
    delete bufferFrame.dataset.nbMirror;
    bufferFrame.srcdoc = html;
    await loaded;

    // Give the new document a frame to settle (Livewire/Alpine scripts run).
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

    void bufferFrame.offsetWidth;
    bufferFrame.style.transition = slide
        ? `transform ${duration}ms ${easing}`
        : `opacity ${duration}ms ease`;
    appFrame.style.transition = slide
        ? `transform ${duration}ms ${easing}, opacity ${duration}ms ease`
        : `opacity ${duration}ms ease`;

    if (slide) {
        bufferFrame.style.transform = 'translateX(0)';
        appFrame.style.transform = direction === 'back' ? 'translateX(100%)' : 'translateX(-30%)';
        appFrame.style.opacity = direction === 'back' ? '1' : '0.6';
    } else {
        bufferFrame.style.opacity = '1';
        appFrame.style.opacity = '0';
    }

    await wait(duration);

    // Swap roles: bufferFrame becomes the new appFrame, old appFrame is reset
    // and becomes the buffer for next navigation.
    const oldFrame = appFrame;
    appFrame = bufferFrame;
    bufferFrame = oldFrame;

    // Tell the bridges which iframe is now active.
    try { setBridgeFrame(appFrame); } catch {}
    try { setPushFrame(appFrame); } catch {}

    // New current frame settles to clean state.
    appFrame.style.transition = '';
    appFrame.style.transform = 'translateX(0)';
    appFrame.style.opacity = '1';
    appFrame.style.zIndex = '1';
    appFrame.style.pointerEvents = 'auto';

    // Old frame goes back to the buffer pool: off-screen, blank, but kept
    // in the rendering tree (no display:none) so its scroll-view stays set
    // up for next time it becomes active.
    bufferFrame.style.transition = 'none';
    bufferFrame.style.transform = 'translateX(100%)';
    bufferFrame.style.opacity = '0';
    bufferFrame.style.zIndex = '0';
    bufferFrame.style.pointerEvents = 'none';
    bufferFrame.srcdoc = '';
}

function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

// Pull the body background colour out of an HTML string so we can paint the
// iframe container with the new page's bg, hiding the shell during slide.
const KNOWN_BG_COLORS = {
    'bg-white':     '#ffffff',
    'bg-black':     '#000000',
    'bg-gray-50':   '#f9fafb',
    'bg-gray-100':  '#f3f4f6',
    'bg-gray-200':  '#e5e7eb',
    'bg-gray-800':  '#1f2937',
    'bg-gray-900':  '#111827',
    'bg-gray-950':  '#030712',
    'bg-zinc-50':   '#fafafa',
    'bg-zinc-100':  '#f4f4f5',
    'bg-zinc-800':  '#27272a',
    'bg-zinc-900':  '#18181b',
    'bg-zinc-950':  '#09090b',
    'bg-slate-50':  '#f8fafc',
    'bg-slate-100': '#f1f5f9',
    'bg-slate-900': '#0f172a',
    'bg-neutral-50':  '#fafafa',
    'bg-neutral-100': '#f5f5f5',
    'bg-neutral-900': '#171717',
};

function sampleBodyBg(html) {
    const styleMatch = html.match(/<body[^>]*style="([^"]*)"/i);
    if (styleMatch) {
        const m = styleMatch[1].match(/background(?:-color)?\s*:\s*([^;]+)/i);
        if (m) return m[1].trim();
    }

    const classMatch = html.match(/<body[^>]*class="([^"]*)"/i);
    if (classMatch) {
        const cls = classMatch[1];

        // Tailwind arbitrary values: bg-[#0d1117], bg-[rgb(...)], bg-[hsl(...)], bg-[red].
        const arbitrary = cls.match(/bg-\[((?:#[0-9a-fA-F]{3,8})|(?:rgba?\([^\]]+\))|(?:hsla?\([^\]]+\))|(?:[a-zA-Z]+))\]/);
        if (arbitrary) return arbitrary[1];

        for (const [name, hex] of Object.entries(KNOWN_BG_COLORS)) {
            if (new RegExp('(?:^|\\s)' + name + '(?:\\s|$)').test(cls)) return hex;
        }
    }

    // Return null instead of white so the caller can skip painting the
    // container — a white default is the worst-case for dark-theme apps.
    return null;
}

// Wrap the original iframe in a CSS-grid container so both iframes can
// occupy the same cell (overlapping for slide animations) without using
// position:absolute. Avoiding `position: relative` on the container is
// critical for WKWebView — having the iframes inside a positioned ancestor
// breaks scroll-view ownership transfer when the active iframe changes,
// causing touch-drag scroll to be intercepted by the wrong (invisible)
// iframe. Grid gives us the same overlap visual without that side effect.
function setupFrameContainer() {
    if (!appFrame || !appFrame.parentNode) return;
    if (appFrame.parentNode.id === 'nb-frame-container') return;

    const container = document.createElement('div');
    container.id = 'nb-frame-container';
    container.style.cssText = 'display: grid; grid-template-columns: 1fr; grid-template-rows: 1fr; flex: 1; overflow: hidden; width: 100%; min-height: 0;';

    appFrame.parentNode.insertBefore(container, appFrame);
    container.appendChild(appFrame);

    // Current frame: occupies the single grid cell, full-bleed, interactive.
    Object.assign(appFrame.style, {
        gridColumn: '1',
        gridRow: '1',
        width: '100%',
        height: '100%',
        border: 'none',
        flex: '',
        zIndex: '1',
        transform: 'translateX(0)',
        opacity: '1',
        willChange: 'transform, opacity',
    });

    // Buffer frame: same grid cell, parked off-screen via transform.
    // CRITICAL: never display:none. The buffer must stay in the rendering
    // tree at all times so WKWebView's compositor sets up its scroll-view
    // from the start. Toggling display:none↔block on first use leaves the
    // iframe without scroll handling — the user has to navigate elsewhere
    // and back before scroll starts working. Hidden via opacity:0 +
    // pointerEvents:none + transform offscreen instead.
    bufferFrame = document.createElement('iframe');
    Object.assign(bufferFrame.style, {
        gridColumn: '1',
        gridRow: '1',
        width: '100%',
        height: '100%',
        border: 'none',
        display: 'block', // Important: subsequent navs check appFrame.style.display !== 'block' to detect isFirstRender; after the role swap this iframe BECOMES appFrame, so it needs display:block from the start or every nav after the first will wrongly take the isFirstRender path.
        zIndex: '0',
        transform: 'translateX(100%)',
        opacity: '0',
        pointerEvents: 'none',
        willChange: 'transform, opacity',
    });
    container.appendChild(bufferFrame);
}
