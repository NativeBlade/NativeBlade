// Tauri action — generic invoke
// Lets PHP call any Tauri plugin command without a custom JS handler.
// Used by NativeResponse::tauriInvoke().

export async function tauri_invoke(payload, ctx) {
    if (!window.__TAURI_INTERNALS__) {
        console.warn('[NB] tauri_invoke: not running in Tauri');
        return;
    }

    let invoke;
    try {
        ({ invoke } = await import('@tauri-apps/api/core'));
    } catch (e) {
        console.warn('[NB] tauri_invoke: @tauri-apps/api/core import failed:', e);
        return;
    }

    const command = payload.command;
    if (!command) return;

    try {
        const result = await invoke(command, payload.args || {});
        if (payload.emit) {
            ctx.post('nativeblade-' + payload.emit, { result });
        }
    } catch (e) {
        console.warn('[NB] tauri_invoke failed:', command, e);
        if (payload.emit) {
            ctx.post('nativeblade-' + payload.emit, {
                error: e?.message || String(e),
            });
        }
    }
}
