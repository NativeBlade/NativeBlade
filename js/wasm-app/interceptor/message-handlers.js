export const handlers = {
    'nativeblade-response': `
        if (_pending[e.data.id]) {
            _pending[e.data.id](e.data.result);
            delete _pending[e.data.id];
        }
    `,

    'nativeblade-alert': `
        alert(e.data.message);
    `,

    'nativeblade-confirm-result': `
        if (window.__nbConfirmResolve) {
            window.__nbConfirmResolve(e.data.confirmed);
            window.__nbConfirmResolve = null;
        }
    `,

    'nativeblade-nb-action': `
        if (e.data.url && typeof __nbAction === 'function') {
            __nbAction(e.data.url);
        }
    `,

    'nativeblade-camera-result': `
        var preview = document.getElementById('photo-preview');
        if (preview) {
            var origKB = Math.round((e.data.originalSize || e.data.size) / 1024);
            var compKB = Math.round(e.data.size / 1024);
            var info = origKB !== compKB
                ? e.data.name + ' (' + origKB + 'KB > ' + compKB + 'KB)'
                : e.data.name + ' (' + compKB + 'KB)';
            preview.innerHTML = '<img src="' + e.data.data + '" style="width:100%;border-radius:8px;margin-bottom:4px">'
                + '<p style="font-size:10px;color:#6b7280">' + info + '</p>';
        }
        window.__nbLastPhoto = e.data;
    `,
};
