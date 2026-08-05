// Runtime global shortcuts (desktop). Register / unregister system-wide hotkeys
// on demand from PHP (NativeBlade::registerShortcut / unregisterShortcut /
// unregisterAllShortcuts), firing nb:shortcut on press just like the declarative
// globalShortcuts([...]) set. No-op on mobile / when Plugin::GLOBAL_SHORTCUT
// isn't declared (the import throws). postToApp follows frame swaps, so a press
// after navigation still reaches the current page.

import { postToApp } from '../bridge.js';

async function plugin(ctx) {
    if (!ctx.isTauri || ctx.isMobile) return null;
    try {
        return await import('@tauri-apps/plugin-global-shortcut');
    } catch (e) {
        console.warn('[NB shortcuts] global-shortcut plugin unavailable:', e);
        return null;
    }
}

export async function register_shortcut(payload, ctx) {
    const gs = await plugin(ctx);
    if (!gs) return;
    const { accelerator, name } = payload || {};
    if (!accelerator || !name) return;
    try {
        if (await gs.isRegistered(accelerator)) await gs.unregister(accelerator);
        await gs.register(accelerator, (event) => {
            if (event && event.state && event.state !== 'Pressed') return;
            postToApp('nativeblade-shortcut', { name, accelerator });
        });
    } catch (e) {
        console.warn(`[NB shortcuts] register "${accelerator}" failed:`, e);
    }
}

export async function unregister_shortcut(payload, ctx) {
    const gs = await plugin(ctx);
    if (!gs) return;
    const { accelerator } = payload || {};
    if (!accelerator) return;
    try {
        await gs.unregister(accelerator);
    } catch (e) {
        console.warn(`[NB shortcuts] unregister "${accelerator}" failed:`, e);
    }
}

export async function unregister_all_shortcuts(_payload, ctx) {
    const gs = await plugin(ctx);
    if (!gs) return;
    try {
        await gs.unregisterAll();
    } catch (e) {
        console.warn('[NB shortcuts] unregisterAll failed:', e);
    }
}
