import './safe-area.css';
import './components/bottom-nav/bottom-nav.css';
import './components/top-bar/top-bar.css';
import './components/camera/camera.css';
import './components/drawer/drawer.css';

import { boot, t } from '../runtime/wasm-server.js';
import { init as initShell } from './shell.js';
import { init as initBridge, handleNativeAction } from './bridge.js';
import { init as initRouter, navigate, getCurrentPath, goBack, runBoot } from './router.js';
import { init as initHotReload } from './hot-reload.js';
import { init as initStore, restoreToWasm, startAutoSync } from './state-store.js';
import { init as initPush } from './push.js';
import { init as initMedia } from './media.js';
import { init as initViewport } from './viewport.js';
import { checkAndDownload as checkBundlePush } from '../runtime/bundle-push.js';
import './nb.js';

const splash = document.getElementById('splash');
const appFrame = document.getElementById('app');
const status = document.getElementById('status');

async function main() {
    try {
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
        await initMedia();
        initHotReload(navigate, getCurrentPath);
        startAutoSync();

        // Bundle push runs in the background — never blocks boot. Downloaded
        // updates apply on the next reload, not the current session.
        checkBundlePush().catch(() => {});

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
