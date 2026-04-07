import { request } from '../runtime/wasm-server.js';
import { schedulePersist } from './state-store.js';
import { applyConfig } from './shell.js';
import { handleNativeAction } from './bridge.js';
import { extractShellConfig, inject } from './interceptor.js';
import { abort as abortHttpBridge } from '../runtime/http-bridge.js';

let appFrame = null;
let splash = null;
let currentPath = '/';
let historyStack = [];
let navigationVersion = 0;

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

export function init(frame, splashEl) {
    appFrame = frame;
    splash = splashEl;

    window.addEventListener('message', async (event) => {
        const { type } = event.data || {};

        if (type === 'nativeblade-request') {
            const { id, path, options } = event.data;
            try {
                const result = await request(path, options);
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
            await navigate(event.data.path);
        } else if (type === 'nativeblade-native') {
            handleNativeAction(event.data.action, event.data.payload, appFrame);
        }
    });
}

export async function navigate(path, options = {}) {
    abortHttpBridge();
    if (currentPath !== path) {
        historyStack.push(currentPath);
    }
    return navigateInternal(path, options);
}

async function navigateInternal(path, options = {}) {
    abortHttpBridge();
    currentPath = path;
    const version = ++navigationVersion;
    const response = await request(path, options);

    if (version !== navigationVersion) return;

    if (response.nativeblade) {
        for (const action of response.nativeblade) {
            handleNativeAction(action.action.replace('so:', ''), action.data, appFrame);
        }
        return;
    }

    if (!response.text || response.text.trim() === '') {
        console.error('Empty response', response.errors);
        return;
    }

    let html = response.text;
    const config = extractShellConfig(html);
    html = inject(html);

    splash.style.display = 'none';
    appFrame.style.display = 'block';
    await applyConfig(config, path);

    appFrame.srcdoc = html;
}
