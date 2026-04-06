export const code = `
    document.addEventListener('click', function(e) {
        var a = e.target.closest('a[href]');
        if (!a) return;
        var h = a.getAttribute('href');
        if (!h || h.startsWith('#') || h.startsWith('javascript')) return;
        e.preventDefault();
        window.parent.postMessage({ type: 'nativeblade-navigate', path: h }, '*');
    });
`;
