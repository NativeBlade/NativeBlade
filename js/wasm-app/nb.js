import { navigate, navigateReplace, goBack, canGoBack, getCurrentPath, setTransition } from './router.js';
import { handleNativeAction } from './bridge.js';
import { svg } from './components/icons.js';

export const nb = {
    navigate,
    navigateReplace,
    goBack,
    canGoBack,
    getCurrentPath,
    icon: svg,
    bridge: (action, payload) => handleNativeAction(action, payload || {}, null),
    setTransition,
};

window.__nb = nb;
