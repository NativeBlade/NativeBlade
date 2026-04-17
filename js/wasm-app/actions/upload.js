// Upload action
// Uses: ctx.uploadApi, ctx.post

export function upload(payload, ctx) {
    if (!ctx.uploadApi || !payload.path || !payload.url) return;
    const headers = payload.headers || {};

    ctx.uploadApi.upload(payload.url, payload.path, (progress) => {
        ctx.post('nativeblade-upload-progress', {
            id: payload.id || null,
            progress: progress.progress,
            total: progress.total,
        });
    }, headers).then(() => {
        ctx.post('nativeblade-upload-complete', {
            id: payload.id || null,
            success: true,
        });
    }).catch(e => {
        ctx.post('nativeblade-upload-complete', {
            id: payload.id || null,
            success: false,
            error: e?.message || 'Upload failed',
        });
    });
}
