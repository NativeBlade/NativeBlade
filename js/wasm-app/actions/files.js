// File actions — file_picker, file_save, copy_file, move_file
// Uses: ctx.dialogApi, ctx.resolveFileDest, ctx.post

export function file_picker(payload, ctx) {
    if (!ctx.dialogApi?.open) return;
    const opts = {};
    if (payload.title) opts.title = payload.title;
    if (payload.defaultPath) opts.defaultPath = payload.defaultPath;
    if (payload.multiple) opts.multiple = true;
    if (payload.directory) opts.directory = true;
    if (payload.filters) opts.filters = payload.filters;

    ctx.dialogApi.open(opts).then(result => {
        const paths = result == null ? [] : Array.isArray(result) ? result : [result];
        ctx.post('nativeblade-file-result', { paths, id: payload.id || null });
    }).catch(() => {
        ctx.post('nativeblade-file-result', { paths: [], id: payload.id || null });
    });
}

export function file_save(payload, ctx) {
    if (!ctx.dialogApi?.save) return;
    const opts = {};
    if (payload.title) opts.title = payload.title;
    if (payload.defaultPath) opts.defaultPath = payload.defaultPath;
    if (payload.defaultName) opts.defaultPath = payload.defaultName;
    if (payload.filters) opts.filters = payload.filters;

    ctx.dialogApi.save(opts).then(path => {
        ctx.post('nativeblade-file-save-result', { path: path || null, id: payload.id || null });
    }).catch(() => {
        ctx.post('nativeblade-file-save-result', { path: null, id: payload.id || null });
    });
}

export function copy_file(payload, ctx) {
    return fileOp('copy', payload, ctx);
}

export function move_file(payload, ctx) {
    return fileOp('move', payload, ctx);
}

async function fileOp(op, payload, ctx) {
    try {
        const [{ invoke: inv }, pathApi] = await Promise.all([
            import('@tauri-apps/api/core'),
            import('@tauri-apps/api/path'),
        ]);
        const dest = await ctx.resolveFileDest(pathApi, payload.to, payload.purpose);
        const cmd = op === 'copy' ? 'nb_copy_file' : 'nb_move_file';
        await inv(cmd, { from: payload.from, to: dest });
        ctx.post('nativeblade-file-op-result', {
            success: true,
            operation: op,
            from: payload.from,
            to: dest,
        });
    } catch (e) {
        console.warn(`[NB] ${op}File failed:`, e);
        ctx.post('nativeblade-file-op-result', {
            success: false,
            operation: op,
            error: e?.message || `${op} failed`,
        });
    }
}
