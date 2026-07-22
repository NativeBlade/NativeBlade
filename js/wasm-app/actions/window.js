// Desktop windows (WINDOWS.md spike) — open/close/focus real OS windows via the
// framework's own Rust commands. Desktop only; a no-op elsewhere.
// Uses: ctx.isTauri, ctx.invokeTauri

export async function open_window(payload, ctx) {
    if (!ctx.isTauri || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('open_window', { config: payload });
    } catch (e) {
        console.warn('[NB] open_window failed:', e);
    }
}

export async function close_window(payload, ctx) {
    if (!ctx.isTauri || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('close_window', { id: payload.id });
    } catch (e) {
        console.warn('[NB] close_window failed:', e);
    }
}

export async function focus_window(payload, ctx) {
    if (!ctx.isTauri || !ctx.invokeTauri) return;
    try {
        await ctx.invokeTauri('focus_window', { id: payload.id });
    } catch (e) {
        console.warn('[NB] focus_window failed:', e);
    }
}
