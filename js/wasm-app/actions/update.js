import { checkForUpdate, downloadUpdate } from '../../runtime/bundle-push.js';

export async function checkUpdate(_payload, ctx) {
    try {
        const result = await checkForUpdate();
        ctx.post('nativeblade-update-check', result);
    } catch (e) {
        ctx.post('nativeblade-update-check', {
            available: false,
            reason: 'unexpected-error',
            error: e?.message || String(e),
        });
    }
}

export async function forceUpdate(_payload, ctx) {
    try {
        const result = await downloadUpdate();
        ctx.post('nativeblade-update-applied', result);
    } catch (e) {
        ctx.post('nativeblade-update-applied', {
            applied: false,
            reason: 'unexpected-error',
            error: e?.message || String(e),
        });
    }
}
