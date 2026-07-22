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
function bootSatellite(id) {
    const shellHasTauri = !!window.__TAURI_INTERNALS__;
    document.body.innerHTML =
        '<div style="padding:16px;font:14px system-ui,sans-serif;color:#fff">'
        + '<b>NativeBlade satellite window</b><br>id: ' + id
        + '<br>shell __TAURI__: <b>' + shellHasTauri + '</b>'
        + '<div id="nb-probe" style="margin-top:8px;color:#8fdc8f">probing iframe…</div></div>';

    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'width:100%;height:60px;border:1px solid #333;margin-top:8px';
    iframe.srcdoc =
        '<body style="color:#8fdc8f;font:13px system-ui;padding:8px;background:#111">'
        + '<' + 'script>'
        + 'var t = !!window.__TAURI_INTERNALS__;'
        + 'document.body.textContent = "iframe __TAURI__: " + t;'
        + 'parent.postMessage({ __nbProbe: true, iframeHasTauri: t }, "*");'
        + '</' + 'script></body>';
    document.body.appendChild(iframe);

    window.addEventListener('message', function (e) {
        if (e.data && e.data.__nbProbe) {
            const verdict = { shellHasTauri, iframeHasTauri: e.data.iframeHasTauri };
            console.info('[NB satellite] reachability verdict:', verdict);
            const el = document.getElementById('nb-probe');
            if (el) el.textContent = 'iframe __TAURI__: ' + e.data.iframeHasTauri
                + (e.data.iframeHasTauri ? ' (unexpected — relay may be unnecessary)' : ' (expected — relay via shell)');
        }
    });
}

async function main() {
    const satelliteId = typeof location !== 'undefined'
        ? new URLSearchParams(location.search).get('nbWindow')
        : null;
    if (satelliteId) {
        bootSatellite(satelliteId);
        return;
    }

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
