import { register, renderAll, getComponent, updateActive as updateAll } from './component-registry.js';
import * as bottomNav from './components/bottom-nav/bottom-nav.js';
import * as topBar from './components/top-bar/top-bar.js';
import * as drawer from './components/drawer/drawer.js';
import * as modal from './components/modal/modal.js';
import { goBack } from './router.js';

let appFrame = null;

export function init(frame, navigateFn) {
    appFrame = frame;

    bottomNav.setHandler(navigateFn);
    topBar.setHandler(navigateFn);
    topBar.setBackHandler(() => { goBack(); return null; });
    drawer.setHandler(navigateFn);

    register('bottom-nav', bottomNav);
    register('header', {
        render: (data, activePath, frame) => {
            const drawerComp = getComponent('drawer');
            const hasDrawerNow = drawerComp?.hasDrawer?.() ?? false;
            topBar.setMenuHandler(hasDrawerNow ? () => drawerComp.toggle() : null);
            topBar.render(data, activePath, frame);
        }
    });
    register('drawer', drawer);
    register('modal', modal);
}

export function applyConfig(config, activePath) {
    const comps = config.components || {};
    drawer.close();
    renderAll(comps, activePath, appFrame);
    pushSafeArea();
}

export function updateActive(path) {
    updateAll(path);
}

export function getSafeArea() {
    const header = document.getElementById('top-bar');
    const nav = document.getElementById('bottom-nav');
    return {
        top: header && header.style.display !== 'none' ? header.offsetHeight : 0,
        bottom: nav && nav.style.display !== 'none' ? nav.offsetHeight : 0,
    };
}

function pushSafeArea() {
    const sa = getSafeArea();
    try {
        appFrame.contentWindow?.postMessage({
            type: 'nativeblade-safe-area',
            top: sa.top,
            bottom: sa.bottom,
        }, '*');
    } catch {}
    // Also push when iframe loads (srcdoc set after applyConfig)
    appFrame.addEventListener('load', function onLoad() {
        appFrame.removeEventListener('load', onLoad);
        const fresh = getSafeArea();
        try {
            appFrame.contentWindow?.postMessage({
                type: 'nativeblade-safe-area',
                top: fresh.top,
                bottom: fresh.bottom,
            }, '*');
        } catch {}
    });
}
