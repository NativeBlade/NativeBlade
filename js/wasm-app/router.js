import { request } from '../runtime/wasm-server.js';
import { schedulePersist } from './state-store.js';
import { applyConfig } from './shell.js';
import { handleNativeAction } from './bridge.js';
import { extractShellConfig, inject } from './interceptor.js';
import { abort as abortHttpBridge } from '../runtime/http-bridge.js';
import { setOnBridgeComplete } from '../runtime/request-handler.js';
import { init as initAutoUpdate } from './auto-update.js';

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

    splash.style.display = 'none';
    appFrame.style.display = 'block';
    await applyConfig(config, path);

    if (!autoUpdateInitialized && config.update) {
        autoUpdateInitialized = true;
        initAutoUpdate(config.update);
    }

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

    if (animClass) {
        html = html.replace(/<body([^>]*)>/, '<body$1 class="animate__animated animate__' + animClass + ' animate__faster">');
    }

    appFrame.srcdoc = html;
}
