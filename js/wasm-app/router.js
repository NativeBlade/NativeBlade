import { request } from '../runtime/wasm-server.js';
import { schedulePersist } from './state-store.js';
import { applyConfig } from './shell.js';
import { handleNativeAction } from './bridge.js';
import { extractShellConfig, inject } from './interceptor.js';
import { abort as abortHttpBridge } from '../runtime/http-bridge.js';
import { setOnBridgeComplete } from '../runtime/request-handler.js';
import { init as initAutoUpdate } from './auto-update.js';
import { init as initScheduler } from './scheduler.js';

let appFrame = null;
let splash = null;
let currentPath = '/';
let historyStack = [];
let navigationVersion = 0;
let pendingMessageId = null;
let transition = 'none';
let autoUpdateInitialized = false;
let defaultBridgeCallback = null;

export function goBack() {
    if (historyStack.length > 0) {
        const prev = historyStack.pop();
        navigateInternal(prev);
    }
}

export function canGoBack() {
    return historyStack.length > 0;
}

export function getCurrentPath() {
    return currentPath;
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

    defaultBridgeCallback = (result) => {
        if (pendingMessageId !== null && !result.bridgePending) {
            try {
                appFrame.contentWindow.postMessage({
                    type: 'nativeblade-response',
                    id: pendingMessageId,
                    result: { text: result.text, httpStatusCode: result.httpStatusCode }
                }, '*');
            } catch {}
            pendingMessageId = null;
        }
    };
    setOnBridgeComplete(defaultBridgeCallback);

    window.addEventListener('message', async (event) => {
        const { type } = event.data || {};

        if (type === 'nativeblade-request') {
            const { id, path, options } = event.data;
            try {
                const result = await request(path, options);
                if (result.bridgePending) {
                    pendingMessageId = id;
                    return;
                }
                appFrame.contentWindow.postMessage({
                    type: 'nativeblade-response', id,
                    result: { text: result.text, httpStatusCode: result.httpStatusCode }
                }, '*');
                if (options.method && options.method !== 'GET') schedulePersist();
            } catch (err) {
                appFrame.contentWindow.postMessage({
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
    abortHttpBridge();
    pendingMessageId = null;
    if (currentPath !== path) {
        historyStack.push(currentPath);
    }
    return navigateInternal(path, options);
}

export async function navigateReplace(path, options = {}) {
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

    // First navigation: full srcdoc swap so the iframe boots up Alpine,
    // Livewire, all the CSS, etc. After that, we never re-create the
    // document — only swap the body content.
    if (isFirstRender || !appFrame.contentDocument || !appFrame.contentDocument.body) {
        appFrame.srcdoc = html;
        return;
    }

    // SPA-style swap: parse the new HTML, replace ONLY the body content.
    // The iframe's <head> (CSS, scripts), Alpine state, Livewire connection,
    // Tauri bridges all stay alive. No re-parse, no flash.
    const newDoc = new DOMParser().parseFromString(html, 'text/html');
    const newBody = newDoc.body;

    if (!newBody) {
        // Malformed response, fall back to full swap.
        appFrame.srcdoc = html;
        return;
    }

    const doc = appFrame.contentDocument;
    const win = appFrame.contentWindow;
    const oldBody = doc.body;

    // Update <title> so back-button history shows the right page title.
    if (newDoc.title && doc.title !== newDoc.title) {
        doc.title = newDoc.title;
    }

    // Honour transition: animate the new body in. The animate.css classes
    // were already loaded by the first render so they're cached in <head>.
    const transitionMap = {
        'fade': 'fadeIn',
        'slide': 'slideFadeInRight',
        'slide-left': 'slideFadeInLeft',
        'slide-up': 'slideFadeInUp',
        'slide-down': 'slideFadeInDown',
        'zoom': 'zoomIn',
        'flip': 'flipInY',
        'bounce': 'bounceIn',
        'back': 'backInRight',
        'blur': 'blurIn',
        'pop': 'popIn',
    };
    const animClass = transitionMap[pageTransition] || (pageTransition !== 'none' ? pageTransition : null);

    // Strip any animate__* classes from the new body's incoming class so we
    // start from a clean state before adding fresh ones below.
    const baseClass = (newBody.getAttribute('class') || '').replace(/animate__\S+/g, '').trim();
    oldBody.setAttribute('class', baseClass);

    // Copy any inline body style (rare but possible).
    const newStyle = newBody.getAttribute('style');
    if (newStyle) oldBody.setAttribute('style', newStyle); else oldBody.removeAttribute('style');

    // Replace body content. innerHTML is fast and resets all event listeners
    // attached to children; Alpine/Livewire re-bind below.
    oldBody.innerHTML = newBody.innerHTML;

    // Browsers don't execute <script> tags inserted via innerHTML. Re-create
    // each one so they actually run.
    oldBody.querySelectorAll('script').forEach((stale) => {
        const fresh = doc.createElement('script');
        for (const attr of stale.attributes) fresh.setAttribute(attr.name, attr.value);
        fresh.textContent = stale.textContent;
        stale.parentNode.replaceChild(fresh, stale);
    });

    // Trigger entrance animation. We had to remove animate__ classes above and
    // are adding them now so the browser sees them as a fresh change and the
    // CSS @keyframes actually re-run. A reflow read forces the style recalc
    // between the remove and the add.
    if (animClass) {
        void oldBody.offsetWidth;
        const animClasses = ['animate__animated', 'animate__' + animClass, 'animate__faster'];
        oldBody.classList.add(...animClasses);
        // Clean up after the animation finishes so the next navigation starts
        // from a clean class list.
        setTimeout(() => {
            oldBody.classList.remove(...animClasses);
        }, 500);
    }

    // Re-scan with Alpine for new x-data / x-show / etc.
    if (win.Alpine && typeof win.Alpine.initTree === 'function') {
        win.Alpine.initTree(oldBody);
    }

    // Tell Livewire to rescan and bind to the freshly inserted components.
    if (win.Livewire) {
        try {
            if (typeof win.Livewire.rescan === 'function') {
                win.Livewire.rescan();
            } else if (typeof win.Livewire.start === 'function') {
                // Livewire 3: components auto-discover. Trigger a manual init.
                win.Livewire.start();
            }
        } catch {}
    }

    // Reset scroll to the top, like a normal page navigation.
    if (doc.documentElement) doc.documentElement.scrollTop = 0;
    if (doc.body) doc.body.scrollTop = 0;
}
