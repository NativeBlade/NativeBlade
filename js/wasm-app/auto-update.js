let config = null;

export function init(updateConfig) {
    config = updateConfig;
    if (!config || !config.url) return;

    setTimeout(() => check(), 3000);
}

async function check() {
    if (!config?.url) return;

    try {
        const res = await fetch(config.url);
        if (!res.ok) return;

        const data = await res.json();
        const remoteVersion = data.version;
        if (!remoteVersion || remoteVersion === config.currentVersion) return;

        if (shouldUpdate(config.currentVersion, remoteVersion)) {
            showUpdateModal(data);
        }
    } catch {}
}

function shouldUpdate(current, remote) {
    const c = current.split('.').map(Number);
    const r = remote.split('.').map(Number);
    for (let i = 0; i < 3; i++) {
        if ((r[i] || 0) > (c[i] || 0)) return true;
        if ((r[i] || 0) < (c[i] || 0)) return false;
    }
    return false;
}

function showUpdateModal(data) {
    const storeUrl = config.storeUrl;
    if (!storeUrl) return;

    const overlay = document.createElement('div');
    Object.assign(overlay.style, {
        position: 'fixed',
        inset: '0',
        zIndex: '99999',
        background: 'rgba(0,0,0,0.8)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontFamily: 'system-ui, sans-serif',
    });

    const forceUpdate = data.forceUpdate || false;
    const notes = data.notes || '';

    overlay.innerHTML = `
        <div style="background:#111;border-radius:16px;padding:24px;max-width:320px;width:90%;text-align:center;border:1px solid #2a2a2a">
            <div style="font-size:32px;margin-bottom:12px">🚀</div>
            <h2 style="color:#fff;font-size:20px;font-weight:900;margin:0 0 8px">Update Available</h2>
            <p style="color:#9ca3af;font-size:14px;margin:0 0 4px">Version ${data.version} is available</p>
            ${notes ? `<p style="color:#6b7280;font-size:12px;margin:0 0 16px">${notes}</p>` : '<div style="height:12px"></div>'}
            <a href="${storeUrl}" target="_blank" style="display:block;background:#c0392b;color:#fff;font-weight:700;font-size:16px;padding:14px;border-radius:12px;text-decoration:none;text-transform:uppercase;letter-spacing:1px">
                Update Now
            </a>
            ${!forceUpdate ? `<button onclick="this.closest('div[style]').parentElement.remove()" style="display:block;width:100%;margin-top:12px;padding:10px;background:none;border:1px solid #2a2a2a;border-radius:12px;color:#9ca3af;font-weight:700;font-size:14px;cursor:pointer">
                Later
            </button>` : ''}
        </div>
    `;

    document.body.appendChild(overlay);

    if (forceUpdate) {
        overlay.querySelector('a').addEventListener('click', () => {
            try { import('@tauri-apps/plugin-process').then(m => m.exit(0)); } catch {}
        });
    }
}
