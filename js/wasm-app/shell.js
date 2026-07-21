import { register, renderAll, getComponent, updateActive as updateAll } from './component-registry.js';
import * as bottomNav from './components/bottom-nav/bottom-nav.js';
import * as topBar from './components/top-bar/top-bar.js';
import * as drawer from './components/drawer/drawer.js';
import * as modal from './components/modal/modal.js';
import { goBack, navigateReplace } from './router.js';

let appFrame = null;

export function init(frame, navigateFn) {
    appFrame = frame;

    bottomNav.setHandler((path) => navigateReplace(path, { transition: 'none' }));
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
}

export function updateActive(path) {
    updateAll(path);
}
