// Desktop windows (WINDOWS.md) — open/close/focus real OS windows via the
// framework's own Rust commands. Desktop only; a no-op on mobile and elsewhere.
// Uses: ctx.isTauri, ctx.invokeTauri, ctx.isMobile

// Pure math: the top-left [x, y] for a named anchor within a work-area rect.
// All values share one unit (logical px here). `area` is {x, y, width, height},
// `winW`/`winH` the window size, `margin` the inset from the anchored edges.
export function anchorToPosition(anchor, area, winW, winH, margin = 0) {
    const left = area.x + margin;
    const right = area.x + area.width - winW - margin;
    const top = area.y + margin;
    const bottom = area.y + area.height - winH - margin;
    const cx = area.x + Math.round((area.width - winW) / 2);
    const cy = area.y + Math.round((area.height - winH) / 2);
    const map = {
        'center': [cx, cy],
        'top-left': [left, top], 'top-center': [cx, top], 'top-right': [right, top],
        'bottom-left': [left, bottom], 'bottom-center': [cx, bottom], 'bottom-right': [right, bottom],
    };
    const p = map[anchor];
    return p ? [Math.round(p[0]), Math.round(p[1])] : null;
}

// A positionAnchor + size in the config is resolved to exact x/y using the real
// monitor (scale-correct, multi-monitor aware) so PHP never measures the screen.
// Falls back to the config unchanged if the anchor can't be resolved.
async function resolveAnchor(config) {
    const strip = (c) => { const { positionAnchor, positionMargin, ...rest } = c; return rest; };
    const anchor = config.positionAnchor;
    if (!anchor || config.width == null || config.height == null) return strip(config);

    try {
        const { currentMonitor } = await import('@tauri-apps/api/window');
        const m = await currentMonitor();
        if (!m) return strip(config);

        const scale = m.scaleFactor || 1;
        // Prefer the work area (excludes the taskbar) when Tauri exposes it.
        const src = (m.workArea && m.workArea.size) ? m.workArea : { position: m.position, size: m.size };
        // currentMonitor is physical px; the window size is logical — work in logical.
        const area = {
            x: src.position.x / scale,
            y: src.position.y / scale,
            width: src.size.width / scale,
            height: src.size.height / scale,
        };
        const p = anchorToPosition(anchor, area, config.width, config.height, config.positionMargin || 0);
        if (p) {
            const c = strip(config);
            c.x = p[0];
            c.y = p[1];
            return c;
        }
    } catch (e) {
        console.warn('[NB open_window] anchor resolve failed:', (e && e.message) || e);
    }
    return strip(config);
}

export async function open_window(payload, ctx) {
    if (!ctx.isTauri || !ctx.invokeTauri || ctx.isMobile) return;
    try {
        const config = await resolveAnchor(payload || {});
        await ctx.invokeTauri('open_window', { config });
    } catch (e) {
        console.warn('[NB] open_window failed:', e);
    }
}

export async function close_window(payload, ctx) {
    if (!ctx.isTauri || !ctx.invokeTauri || ctx.isMobile) return;
    try {
        await ctx.invokeTauri('close_window', { id: payload.id });
    } catch (e) {
        console.warn('[NB] close_window failed:', e);
    }
}

export async function focus_window(payload, ctx) {
    if (!ctx.isTauri || !ctx.invokeTauri || ctx.isMobile) return;
    try {
        await ctx.invokeTauri('focus_window', { id: payload.id });
    } catch (e) {
        console.warn('[NB] focus_window failed:', e);
    }
}
