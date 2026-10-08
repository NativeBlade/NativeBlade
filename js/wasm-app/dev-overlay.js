// On-screen banner for errors while developing. A store build never shows it:
// showDevError() is a no-op unless the page was served by `nativeblade:dev`.
// The same entries still reach the console, the log file and the terminal
// through the `log` action; the overlay only makes them impossible to miss on
// a phone, where nobody is watching the WebView console.
//
// Leaf module apart from dev-mode.js; createDevOverlay() takes the document so
// tests can pass a fake one.

import { isDevMode } from '../runtime/dev-mode.js';

export const OVERLAY_ID = 'nb-dev-overlay';
export const MAX_ENTRIES = 5;

const ROOT_STYLE = [
    'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:2147483647',
    'max-height:50vh', 'overflow:auto', 'box-sizing:border-box',
    'padding:calc(env(safe-area-inset-top, 0px) + 10px) 12px 10px',
    'background:#b00020', 'color:#fff',
    'font:13px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
    'box-shadow:0 2px 8px rgba(0,0,0,.35)',
].join(';');

const ENTRY_STYLE = 'margin:8px 0 0;padding-top:8px;border-top:1px solid rgba(255,255,255,.35)';
const DETAIL_STYLE = 'margin:4px 0 0;white-space:pre-wrap;word-break:break-word;font:inherit;opacity:.95';
const BUTTON_STYLE = 'float:right;margin-left:12px;padding:2px 10px;border:1px solid rgba(255,255,255,.7);'
    + 'border-radius:4px;background:transparent;color:#fff;font:inherit;cursor:pointer';

export function createDevOverlay(doc) {
    let root = null;
    let list = null;

    function ensure() {
        if (root) return;
        root = doc.createElement('div');
        root.id = OVERLAY_ID;
        root.setAttribute('style', ROOT_STYLE);

        const button = doc.createElement('button');
        button.type = 'button';
        button.textContent = 'Dismiss';
        button.setAttribute('style', BUTTON_STYLE);
        button.addEventListener('click', () => clear());

        const title = doc.createElement('strong');
        title.textContent = 'NativeBlade (development)';

        list = doc.createElement('div');

        root.appendChild(button);
        root.appendChild(title);
        root.appendChild(list);
        doc.body.appendChild(root);
    }

    function clear() {
        if (root && root.parentNode) root.parentNode.removeChild(root);
        root = null;
        list = null;
    }

    return {
        show({ title, detail = '', context = null }) {
            ensure();
            const entry = doc.createElement('div');
            entry.className = 'nb-dev-overlay-entry';
            entry.setAttribute('style', ENTRY_STYLE);

            const heading = doc.createElement('strong');
            heading.textContent = title;
            entry.appendChild(heading);

            let text = detail || '';
            if (context && typeof context === 'object' && Object.keys(context).length > 0) {
                let json = '';
                try { json = JSON.stringify(context, null, 2); } catch { json = String(context); }
                text += (text ? '\n' : '') + json;
            }
            if (text) {
                const pre = doc.createElement('pre');
                pre.setAttribute('style', DETAIL_STYLE);
                pre.textContent = text;
                entry.appendChild(pre);
            }

            list.appendChild(entry);
            while (list.children.length > MAX_ENTRIES) list.removeChild(list.children[0]);
            return entry;
        },
        clear,
        get element() { return root; },
    };
}

let overlay = null;

/**
 * Show an error on screen when in development mode. Returns false (and does
 * nothing) in a store build or outside a document.
 */
export function showDevError(title, detail = '', context = null) {
    if (typeof document === 'undefined' || !isDevMode(document)) return false;
    try {
        overlay ??= createDevOverlay(document);
        overlay.show({ title, detail, context });
        return true;
    } catch {
        return false;
    }
}

export function __resetDevOverlayForTests() {
    overlay = null;
}
