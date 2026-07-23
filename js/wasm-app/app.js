import './safe-area.css';
import './components/bottom-nav/bottom-nav.css';
import './components/top-bar/top-bar.css';
import './components/camera/camera.css';
import './components/drawer/drawer.css';
import './components/scanner/scanner.css';

import { boot, t, loadTranslations, request } from '../runtime/wasm-server.js';
import { init as initShell } from './shell.js';
import { init as initBridge, handleNativeAction, postToApp } from './bridge.js';
import { inject } from './interceptor.js';
import { relayRequest, serveWindowRequests } from './window-relay.js';
import { init as initRouter, navigate, getCurrentPath, goBack, runBoot, requestFull } from './router.js';
import { init as initHotReload } from './hot-reload.js';
import { init as initStore, restoreToWasm, startAutoSync } from './state-store.js';
import { init as initPush } from './push.js';
import { init as initDeepLink } from './deep-link.js';
import { init as initPaymentsBoot } from './payments-boot.js';
import { init as initNetworkBoot } from './network-boot.js';
import { init as initTasksBoot } from './tasks-boot.js';
import { init as initSensorsBoot } from './sensors-boot.js';
import { init as initMedia } from './media.js';
import { init as initViewport } from './viewport.js';
import { checkAndDownload as checkBundlePush } from '../runtime/bundle-push.js';
import './nb.js';

const splash = document.getElementById('splash');
const appFrame = document.getElementById('app');
const status = document.getElementById('status') || { textContent: '', style: {} };

// SLICE 1 (WINDOWS.md): a satellite window loads the same frontend with
// ?nbWindow={id}. It must NOT boot php-wasm — for now it just proves the window
// opened and answers the reachability question (is Tauri reachable from the
// origin-null app iframe?). The relay + component render land in slice 2.
// Two synchronous signals, so satellite detection can't silently fail into a
// second php-wasm boot: the init-script global, then the Tauri window label.
// Two synchronous signals so detection can't fail into a second php-wasm boot:
// the init-script global (Rust open_window), then the Tauri window label.
function getSatelliteId() {
    if (typeof window === 'undefined') return null;
    if (window.__NB_SATELLITE__) return String(window.__NB_SATELLITE__);
    try {
        const meta = window.__TAURI_INTERNALS__ && window.__TAURI_INTERNALS__.metadata;
        const lbl = (meta && meta.currentWindow && meta.currentWindow.label) || '';
        if (lbl.indexOf('nb-window-') === 0) return lbl.slice('nb-window-'.length);
    } catch (e) {}
    return null;
}

// Slice 2: the satellite renders a real Livewire component. Its app iframe is
// origin-null (has no Tauri); this shell doc relays the iframe's requests over
// IPC to the main window's php-wasm and posts the responses back — livewire.js
// morphs locally, unaware the response crossed a window boundary.
async function bootSatellite(id) {
    document.body.style.cssText = 'margin:0';
    document.body.innerHTML = '';

    const frame = document.createElement('iframe');
    frame.id = 'app';
    frame.style.cssText = 'border:0;width:100vw;height:100vh;display:block';
    document.body.appendChild(frame);

    // Bridge so native actions dispatched by the component run with THIS window's
    // Tauri context (dialogs attach here, etc.).
    try { await initBridge(frame); } catch (e) { console.warn('[NB satellite] bridge init failed', e); }

    // Relay the iframe's traffic. Requests → main runtime → response back;
    // native actions execute in this satellite's shell.
    window.addEventListener('message', async function (e) {
        const d = e.data;
        if (!d || typeof d.type !== 'string') return;
        if (d.type === 'nativeblade-request') {
            const result = await relayRequest(d.path, d.options);
            frame.contentWindow && frame.contentWindow.postMessage(
                { type: 'nativeblade-response', id: d.id, result }, '*');
        } else if (d.type === 'nativeblade-native') {
            handleNativeAction(d.action, d.payload, frame, e.source);
        }
    });

    // Initial render: fetch the component's page via the relay, inject the
    // interceptor, boot it in the iframe.
    try {
        const result = await relayRequest('/__nb/window/' + id, { method: 'GET' });
        if (!result || !result.text) {
            document.body.innerHTML = '<div style="padding:20px;font:14px system-ui;color:#b00">'
                + 'window render failed for id=' + id + ' — status '
                + (result && result.httpStatusCode) + '</div>';
            return;
        }
        frame.srcdoc = inject(result.text);
    } catch (e) {
        console.error('[NB satellite] initial render failed', e);
    }
}

async function main() {
    // Set synchronously by the window's initialization_script (Rust open_window)
    // BEFORE this bundle runs. A satellite must NEVER reach boot() below — a
    // second php-wasm deadlocks the shared IndexedDB and freezes both windows.
    const satelliteId = getSatelliteId();
    if (satelliteId) {
        bootSatellite(satelliteId);
        return;
    }

    // Main window: service satellite windows' relayed requests on this runtime.
    // requestFull awaits bridge (DB/fs/HTTP) fulfillment so satellite components
    // can use them — the native work runs here.
    serveWindowRequests(requestFull).catch((e) => console.warn('[NB relay] serve setup failed', e));

    try {
        await loadTranslations();

        status.textContent = t('boot.update_checking') || 'Checking for updates...';
        await checkBundlePush((received, total) => {
            if (!total) return;
            const percent = Math.floor((received / total) * 100);
            status.textContent = t('boot.update_downloading', { percent }) || `Updating... ${percent}%`;
        }).catch(() => {});

        await boot((msg) => { status.textContent = msg; });

        status.textContent = t('boot.state');
        await initStore();
        await restoreToWasm();

        status.textContent = t('boot.rendering');
        initRouter(appFrame, splash);
        initViewport();
        initShell(appFrame, navigate);
        await initBridge(appFrame);
        await initPush(appFrame, handleNativeAction);
        await initDeepLink(appFrame, handleNativeAction);
        await initPaymentsBoot(appFrame);
        await initNetworkBoot();
        await initTasksBoot();
        await initSensorsBoot();
        await initMedia();
        initHotReload(navigate, getCurrentPath);
        startAutoSync();

        try {
            if (window.__TAURI_INTERNALS__) {
                const { listen } = await import('@tauri-apps/api/event');
                await listen('nativeblade-menu', (event) => {
                    const action = event.payload;
                    if (action.startsWith('/')) {
                        navigate(action);
                    } else {
                        handleNativeAction(action, {}, appFrame);
                    }
                });
            }
        } catch {}

        window.addEventListener('popstate', () => {
            goBack();
            history.pushState(null, '', location.href);
        });
        history.pushState(null, '', location.href);

        status.textContent = t('boot.loading') || 'Loading...';
        await runBoot();
        await navigate('/');
    } catch (err) {
        status.textContent = 'Error: ' + err.message;
        status.style.color = '#ef4444';
        console.error(err);
    }
}

main();
