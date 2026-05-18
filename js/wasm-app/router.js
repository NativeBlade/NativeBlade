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

export function goBack() {
    if (historyStack.length > 0) {
        const prev = historyStack.pop();
        navigateInternal(prev, { direction: 'back' });
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
            handleNativeAction(event.data.action, event.data.payload, appFrame);
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
    if (currentPath !== path) {
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
                renderPage(completedResult.text, path, options, version);
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

    renderPage(response.text, path, options, version);
}

async function renderPage(text, path, options, version) {
    if (!text || text.trim() === '') return;
    if (version !== navigationVersion) return;

    let html = text;
    const config = extractShellConfig(html);
    const pageTransition = options.transition || config.transition || transition;
    html = inject(html);

    const isFirstRender = appFrame.style.display !== 'block';
    splash.style.display = 'none';
    appFrame.style.display = 'block';
    await applyConfig(config, path);

    if (!autoUpdateInitialized && config.update) {
        autoUpdateInitialized = true;
        initAutoUpdate(config.update);
    }

    if (config.schedules) {
        initScheduler(config.schedules);
    }

    if (isFirstRender || !appFrame.contentDocument || !appFrame.contentDocument.body) {
        appFrame.srcdoc = html;
        bufferFrame.srcdoc = html;
        return;
    }

    if (!appFrame.contentDocument || !appFrame.contentDocument.body) {
        appFrame.srcdoc = html;
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
