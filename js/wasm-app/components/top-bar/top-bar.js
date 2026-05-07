import { svg } from '../icons.js';

let el = null;
let navigateFn = null;
let onMenuClick = null;
let onBackFn = null;

export function setHandler(fn) { navigateFn = fn; }
export function setMenuHandler(fn) { onMenuClick = fn; }
export function setBackHandler(fn) { onBackFn = fn; }

export function render(data, activePath, appFrame) {
    if (!data) {
        if (el) el.style.display = 'none';
        return;
    }

    if (!el) {
        el = document.createElement('header');
        el.id = 'top-bar';
        // Insert above the iframe area. After router.js wraps the iframe in
        // #nb-frame-container, the iframe is no longer a direct child of
        // <body> — we have to reference the container (or whichever direct
        // child of body holds the iframe).
        const app = document.getElementById('app');
        let ref = document.getElementById('nb-frame-container') || app;
        while (ref && ref.parentNode !== document.body) {
            ref = ref.parentNode;
        }
        if (ref) {
            document.body.insertBefore(el, ref);
        } else {
            document.body.insertBefore(el, document.body.firstChild);
        }
    }

    if (data.bg) el.style.setProperty('background', data.bg);
    if (data.borderColor) el.style.setProperty('border-bottom-color', data.borderColor);

    if (data.slotHtml) {
        el.innerHTML = data.slotHtml;
        el.style.display = 'block';
        bindClicks(el, appFrame);
        return;
    }

    const hasDrawer = !!onMenuClick;
    const actions = (data.children || []).filter(c => c.type === 'action');

    const menuBtn = hasDrawer
        ? `<button class="topbar-btn" data-menu="true">${svg('list')}</button>`
        : '';

    const backBtn = !hasDrawer && data.back === 'true'
        ? `<button class="topbar-btn" data-back="true">${svg('arrow-left')}</button>`
        : '';

    const actionsHtml = actions.map(a => {
        const badge = a.badge ? `<span class="topbar-badge">${a.badge}</span>` : '';
        const style = a.color ? ` style="color:${a.color}"` : '';
        return `<button class="topbar-btn" data-action="${a.action}"${style}>${svg(a.icon)}${badge}</button>`;
    }).join('');

    const titleStyle = data.color ? ` style="color:${data.color}"` : '';

    el.innerHTML = `<div class="topbar-inner">
        ${menuBtn}${backBtn}
        <span class="topbar-title"${titleStyle}>${data.title || ''}</span>
        <div class="topbar-actions">${actionsHtml}</div>
    </div>`;

    el.style.display = 'block';
    bindClicks(el, appFrame);
}

function bindClicks(el, appFrame) {
    el.onclick = (e) => {
        if (e.target.closest('[data-menu]')) {
            if (onMenuClick) onMenuClick();
            return;
        }
        if (e.target.closest('[data-back]')) {
            if (onBackFn) {
                const path = onBackFn();
                if (path && navigateFn) navigateFn(path);
            }
            return;
        }
        const navEl = e.target.closest('[data-nav]');
        if (navEl) {
            window.postMessage({ type: 'nativeblade-navigate', path: navEl.dataset.nav }, '*');
            return;
        }
        const actionEl = e.target.closest('[data-action]');
        if (actionEl?.dataset.action?.startsWith('/')) {
            appFrame?.contentWindow?.postMessage({ type: 'nativeblade-nb-action', url: actionEl.dataset.action }, '*');
        }
    };
}
