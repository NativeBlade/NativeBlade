// System actions — exit, log

export function exit() {
    try {
        import('@tauri-apps/plugin-process').then(m => m.exit(0));
    } catch {}
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
