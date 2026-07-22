import './safe-area.css';
import './components/bottom-nav/bottom-nav.css';
import './components/top-bar/top-bar.css';
import './components/camera/camera.css';
import './components/drawer/drawer.css';
import './components/scanner/scanner.css';

import { boot, t, loadTranslations } from '../runtime/wasm-server.js';
import { init as initShell } from './shell.js';
import { init as initBridge, handleNativeAction } from './bridge.js';
import { init as initRouter, navigate, getCurrentPath, goBack, runBoot } from './router.js';
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
function getSatelliteId() {
    if (typeof window === 'undefined') return null;

    // Diagnostic: dump both signals + the raw internals so we can find where
    // the window label actually lives in this Tauri version.
    try {
        console.info('[NB detect] __NB_SATELLITE__ =', window.__NB_SATELLITE__);
        console.info('[NB detect] internals.metadata =',
            JSON.stringify(window.__TAURI_INTERNALS__ && window.__TAURI_INTERNALS__.metadata));
    } catch (e) {
        console.info('[NB detect] internals dump failed:', e);
    }

    if (window.__NB_SATELLITE__) return String(window.__NB_SATELLITE__);

    // Backup: scan the internals object for anything that looks like our label.
    try {
        const meta = window.__TAURI_INTERNALS__ && window.__TAURI_INTERNALS__.metadata;
        const candidates = [
            meta && meta.currentWindow && meta.currentWindow.label,
            meta && meta.currentWebview && meta.currentWebview.label,
            meta && meta.__currentWindow && meta.__currentWindow.label,
        ];
        for (const lbl of candidates) {
            if (typeof lbl === 'string' && lbl.indexOf('nb-window-') === 0) {
                return lbl.slice('nb-window-'.length);
            }
        }
    } catch (e) {}
    return null;
}

function bootSatellite(id) {
    console.info('[NB satellite] SATELLITE PATH ran, id=' + id
        + ' (via ' + (window.__NB_SATELLITE__ ? 'init-script' : 'label') + ')');

    const shellHasTauri = !!window.__TAURI_INTERNALS__;
    document.body.style.cssText = 'margin:0;background:#0a7d2e;color:#fff;font:16px system-ui,sans-serif';
    document.body.innerHTML =
        '<div style="padding:24px">'
        + '<div style="font-size:22px;font-weight:800;margin-bottom:12px">✅ SATELLITE WINDOW</div>'
        + 'id: <b>' + id + '</b><br>'
        + 'shell __TAURI__: <b>' + shellHasTauri + '</b>'
        + '<div id="nb-probe" style="margin-top:12px;opacity:.9">probing iframe reachability…</div>'
        + '</div>';

    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'width:90%;height:44px;margin:0 24px;border:0;background:#064d1c';
    iframe.srcdoc =
        '<body style="margin:0;color:#cfffd6;font:13px system-ui;padding:10px;background:#064d1c">'
        + '<' + 'script>'
        + 'var t = !!window.__TAURI_INTERNALS__;'
        + 'document.body.textContent = "iframe __TAURI__: " + t;'
        + 'parent.postMessage({ __nbProbe: true, iframeHasTauri: t }, "*");'
        + '</' + 'script></body>';
    document.body.appendChild(iframe);

    window.addEventListener('message', function (e) {
        if (e.data && e.data.__nbProbe) {
            console.info('[NB satellite] reachability:', { shellHasTauri, iframeHasTauri: e.data.iframeHasTauri });
            const el = document.getElementById('nb-probe');
            if (el) el.textContent = 'iframe __TAURI__: ' + e.data.iframeHasTauri
                + (e.data.iframeHasTauri ? '  (relay maybe unnecessary)' : '  (relay via shell — expected)');
        }
    });
}

async function main() {
    // Set synchronously by the window's initialization_script (Rust open_window)
    // BEFORE this bundle runs. A satellite must NEVER reach boot() below — a
    // second php-wasm deadlocks the shared IndexedDB and freezes both windows.
    const satelliteId = getSatelliteId();
    console.info('[NB flow] main() start —',
        '__NB_SATELLITE__=', window.__NB_SATELLITE__,
        '| tauri=', !!window.__TAURI_INTERNALS__,
        '| resolved satelliteId=', satelliteId);

    if (satelliteId) {
        console.info('[NB flow] → SATELLITE branch (no php-wasm)');
        bootSatellite(satelliteId);
        return;
    }

    console.info('[NB flow] → MAIN branch (booting php-wasm)');
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
