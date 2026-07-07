// System actions — exit, log

export function exit() {
    // The outer promise must be caught too: in a browser preview m.exit(0)
    // rejects (no Tauri host) and would surface as an uncaught rejection.
    // Quitting has no browser equivalent, so a rejection is a fine no-op.
    return import('@tauri-apps/plugin-process').then(m => m.exit(0)).catch(() => {});
}

async function getMainWindow() {
    try {
        const mod = await import('@tauri-apps/api/window');
        return mod.getCurrentWindow ? mod.getCurrentWindow() : null;
    } catch {
        return null;
    }
}

export async function minimize() {
    const win = await getMainWindow();
    if (!win) return;
    try { await win.minimize(); } catch (e) { console.warn('[NB] minimize failed:', e); }
}

export async function maximize() {
    const win = await getMainWindow();
    if (!win) return;
    try { await win.maximize(); } catch (e) { console.warn('[NB] maximize failed:', e); }
}

export async function unmaximize() {
    const win = await getMainWindow();
    if (!win) return;
    try { await win.unmaximize(); } catch (e) { console.warn('[NB] unmaximize failed:', e); }
}

export async function toggle_maximize() {
    const win = await getMainWindow();
    if (!win) return;
    try { await win.toggleMaximize(); } catch (e) { console.warn('[NB] toggleMaximize failed:', e); }
}

export async function hide() {
    const win = await getMainWindow();
    if (!win) return;
    try { await win.hide(); } catch (e) { console.warn('[NB] hide failed:', e); }
}

export async function show() {
    const win = await getMainWindow();
    if (!win) return;
    try { await win.show(); } catch (e) { console.warn('[NB] show failed:', e); }
}

export function log(payload) {
    const level = payload.level || 'info';
    const message = payload.message || '';
    const context = payload.context || {};
    const fn = { info: 'log', warn: 'warn', error: 'error', debug: 'debug' }[level] || 'log';
    const color = { info: '#3498db', warn: '#f39c12', error: '#e74c3c', debug: '#9b59b6' }[level] || '#3498db';
    const style = `color:${color};font-weight:bold`;
    const prefix = `%c[NB:${level}]`;
    if (context && Object.keys(context).length > 0) {
        console[fn](prefix, style, message, context);
    } else {
        console[fn](prefix, style, message);
    }
}
