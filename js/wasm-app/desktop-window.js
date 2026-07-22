// Resolves named window anchors (DesktopConfig::position('bottom-right')) at
// launch. 'center' and exact x/y are static in tauri.conf; only corner anchors
// reach here, because they need the monitor size. Runs once, desktop only.

let positioned = false;

export async function positionDesktopWindow(anchor) {
    if (positioned || !anchor) return;
    if (typeof window === 'undefined' || !window.__TAURI_INTERNALS__) return;
    positioned = true;
    if (anchor === 'center') return; // Tauri handles this statically

    try {
        const { currentMonitor, getCurrentWindow } = await import('@tauri-apps/api/window');
        const { PhysicalPosition } = await import('@tauri-apps/api/dpi');

        const monitor = await currentMonitor();
        if (!monitor) return;

        const win = getCurrentWindow();
        const size = await win.outerSize();             // physical px
        const { width: sw, height: sh } = monitor.size; // physical px (full screen)
        const { x: mx, y: my } = monitor.position;

        const right = mx + sw - size.width;
        const bottom = my + sh - size.height;
        const cx = mx + Math.round((sw - size.width) / 2);
        const cy = my + Math.round((sh - size.height) / 2);

        const map = {
            'top-left':      [mx, my],
            'top-center':    [cx, my],
            'top-right':     [right, my],
            'bottom-left':   [mx, bottom],
            'bottom-center': [cx, bottom],
            'bottom-right':  [right, bottom],
        };

        const p = map[anchor];
        if (p) await win.setPosition(new PhysicalPosition(Math.round(p[0]), Math.round(p[1])));
    } catch (e) {
        console.warn('[NB] desktop window anchor failed:', e?.message || e);
    }
}
