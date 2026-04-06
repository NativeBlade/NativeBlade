import { svg } from '../icons.js';

let el = null;
let overlay = null;
let navigateFn = null;
let currentConfig = null;

export function setHandler(fn) {
    navigateFn = fn;
}

export function open() {
    if (el) el.classList.add('open');
    if (overlay) overlay.classList.add('open');
}

export function close() {
    if (el) el.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
}

export function toggle() {
    if (el?.classList.contains('open')) close();
    else open();
}

export function hasDrawer() {
    return !!currentConfig;
}

export function render(data, activePath) {
    if (data === null) {
        currentConfig = null;
        if (el) el.classList.remove('open');
        return;
    }
    if (data) currentConfig = data;
    if (!currentConfig) return;

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'nb-drawer-overlay';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', close);
    }

    if (!el) {
        el = document.createElement('aside');
        el.id = 'nb-drawer';
        document.body.appendChild(el);
        el.addEventListener('click', (e) => {
            const item = e.target.closest('[data-href]');
            if (!item) return;
            e.preventDefault();
            close();
            if (navigateFn) navigateFn(item.dataset.href);
        });
    }

    if (currentConfig.bg) el.style.setProperty('--nb-drawer-bg', currentConfig.bg);
    if (currentConfig.color) el.style.setProperty('--nb-drawer-color', currentConfig.color);
    if (currentConfig.borderColor) el.style.setProperty('--nb-drawer-border', currentConfig.borderColor);

    const items = currentConfig.children?.filter(c => c.type === 'drawer-item') || [];
    const normalized = activePath === '/' ? '/' : (activePath || '/').replace(/\/$/, '');

    el.innerHTML = `
        <div class="drawer-header">${currentConfig.title || 'Menu'}</div>
        <div class="drawer-items">
            ${items.map(item => {
                const active = item.href === normalized ? ' active' : '';
                return `<a class="drawer-item${active}" data-href="${item.href}">${svg(item.icon)}<span>${item.label}</span></a>`;
            }).join('')}
        </div>
    `;
}

export function updateActive(path) {
    if (!el) return;
    const normalized = path === '/' ? '/' : path.replace(/\/$/, '');
    el.querySelectorAll('[data-href]').forEach(a => {
        a.classList.toggle('active', a.dataset.href === normalized);
    });
}
