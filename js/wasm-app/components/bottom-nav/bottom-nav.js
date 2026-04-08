import { svg } from '../icons.js';

let el = null;
let onNavigate = null;

export function setHandler(fn) {
    onNavigate = fn;
}

export function render(data, activePath, appFrame) {
    if (!data) {
        if (el) el.style.display = 'none';
        return;
    }

    if (!el) {
        el = document.createElement('nav');
        el.id = 'bottom-nav';
        document.body.appendChild(el);
        el.addEventListener('click', (e) => {
            const link = e.target.closest('[data-path]') || e.target.closest('[data-nav]');
            if (link && onNavigate) {
                e.preventDefault();
                onNavigate(link.dataset.path || link.dataset.nav);
            }
        });
    }

    if (data.bg) el.style.setProperty('background', data.bg);
    if (data.borderColor) el.style.setProperty('border-top-color', data.borderColor);

    if (data.slotHtml) {
        el.innerHTML = data.slotHtml;
        el.style.display = 'block';
        return;
    }

    const items = data?.children?.filter(c => c.type === 'tab') || [];
    if (!items.length) {
        if (el) el.style.display = 'none';
        return;
    }

    const normalized = activePath === '/' ? '/' : (activePath || '/').replace(/\/$/, '');
    const activeColor = data.activeColor || '#a855f7';
    const color = data.color || '#6b7280';

    el.innerHTML = '<div class="nav-items">' + items.map(item => {
        const isActive = item.href === normalized;
        const itemColor = isActive ? activeColor : color;
        return `<a data-path="${item.href}" class="${isActive ? 'active' : ''}" style="color:${itemColor}">${svg(item.icon)}<span>${item.label}</span></a>`;
    }).join('') + '</div>';

    el.style.display = 'block';
}

export function updateActive(path) {
    if (!el) return;
    const normalized = path === '/' ? '/' : path.replace(/\/$/, '');
    el.querySelectorAll('[data-path]').forEach(a => {
        a.classList.toggle('active', a.dataset.path === normalized);
    });
}
