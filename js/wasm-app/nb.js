import { navigate, goBack, canGoBack, getCurrentPath } from './router.js';
import { svg } from './components/icons.js';

export const nb = {
    navigate,
    goBack,
    canGoBack,
    getCurrentPath,
    icon: svg,
};

window.__nb = nb;
