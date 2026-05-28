// Bridges software-keyboard state into the loaded app.
//
// Safe-area insets are already handled by the shell: safe-area.css pads the
// shell body so the iframe occupies the safe region, and the app renders
// inside it without needing inset values of its own. We do NOT inject inset
// vars here (that would double-count against the shell padding).
//
// The keyboard is the one thing the app cannot detect on its own: we watch
// visualViewport and expose it as a `nb-keyboard-visible` body class plus a
// --nb-keyboard-height var. Purely additive: classList.toggle + setProperty,
// never touches the router's grid/buffer/transform/scroll setup.

let keyboardHeight = 0;

function frames() {
    return document.querySelectorAll('#nb-frame-container iframe');
}

function injectInto(frame) {
    try {
        const doc = frame.contentDocument;
        if (!doc || !doc.documentElement) return;
        doc.documentElement.style.setProperty('--nb-keyboard-height', keyboardHeight + 'px');
        if (doc.body) doc.body.classList.toggle('nb-keyboard-visible', keyboardHeight > 0);
    } catch {}
}

function applyAll() {
    frames().forEach(injectInto);
}

function onViewportResize() {
    if (!window.visualViewport) return;
    const h = Math.max(0, window.innerHeight - window.visualViewport.height - window.visualViewport.offsetTop);
    keyboardHeight = h > 100 ? h : 0;
    applyAll();
}

export function init() {
    frames().forEach((f) => f.addEventListener('load', () => injectInto(f)));

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', onViewportResize);
        window.visualViewport.addEventListener('scroll', onViewportResize);
    }
}
