import { navigate, navigateReplace, goBack, canGoBack, getCurrentPath, setTransition } from './router.js';
import { handleNativeAction } from './bridge.js';
import { svg } from './components/icons.js';
import { onNavigate } from './navigation-events.js';

export const nb = {
    navigate,
    navigateReplace,
    goBack,
    canGoBack,
    getCurrentPath,
    icon: svg,
    bridge: (action, payload) => handleNativeAction(action, payload || {}, null),
    setTransition,
    // Fires after every completed navigation with { path, from, direction, transition }.
    onNavigate,
};

window.__nb = nb;
