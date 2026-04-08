let el = null;
let overlay = null;
let storedData = null;

export function render(data) {
    storedData = data;

    if (!data || !data.slotHtml) {
        return;
    }

    ensureElements();
    applyStyles(data);
    el.innerHTML = data.slotHtml;
    bindClicks();
    injectAnimations();
}

export function show() {
    if (!storedData?.slotHtml) return;
    ensureElements();
    applyStyles(storedData);
    el.innerHTML = storedData.slotHtml;
    bindClicks();
    injectAnimations();
    overlay.style.display = 'flex';
}

export function hide() {
    if (overlay) overlay.style.display = 'none';
}

function ensureElements() {
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'nb-modal-overlay';
        Object.assign(overlay.style, {
            display: 'none',
            position: 'fixed',
            inset: '0',
            zIndex: '9999',
            justifyContent: 'center',
            alignItems: 'center',
        });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) hide();
        });
        document.body.appendChild(overlay);
    }

    if (!el) {
        el = document.createElement('div');
        el.id = 'nb-modal';
        overlay.appendChild(el);
    }
}

function applyStyles(data) {
    const pos = data.position || 'bottom';
    overlay.style.background = data.overlay || 'rgba(0,0,0,0.7)';

    if (pos === 'bottom') {
        overlay.style.alignItems = 'flex-end';
    } else if (pos === 'center') {
        overlay.style.alignItems = 'center';
    } else {
        overlay.style.alignItems = 'flex-start';
    }

    Object.assign(el.style, {
        width: '100%',
        maxWidth: '480px',
        background: data.bg || '#111111',
        borderRadius: pos === 'bottom' ? '24px 24px 0 0' : '16px',
        margin: pos === 'center' ? '0 16px' : '0',
        animation: pos === 'bottom' ? 'nb-modal-slide-up 0.3s ease-out' : 'nb-modal-fade-in 0.2s ease-out',
    });
}

function bindClicks() {
    el.addEventListener('click', (e) => {
        const dismiss = e.target.closest('[data-dismiss]');
        if (dismiss) {
            hide();
            return;
        }

        const nav = e.target.closest('[data-nav]');
        if (nav) {
            const replace = nav.hasAttribute('data-replace');
            window.postMessage({ type: 'nativeblade-navigate', path: nav.dataset.nav, replace }, '*');
            hide();
        }
    });
}

function injectAnimations() {
    if (!document.getElementById('nb-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'nb-modal-styles';
        style.textContent = `
            @keyframes nb-modal-slide-up { from { transform: translateY(100%) } to { transform: translateY(0) } }
            @keyframes nb-modal-fade-in { from { opacity: 0; transform: scale(0.95) } to { opacity: 1; transform: scale(1) } }
        `;
        document.head.appendChild(style);
    }
}
