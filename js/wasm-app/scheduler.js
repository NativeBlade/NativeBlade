import { request } from '../runtime/wasm-server.js';

let initialized = false;

export async function init(schedules) {
    if (initialized || !schedules || !schedules.length) return;
    initialized = true;

    try {
        const { invoke } = await import('@tauri-apps/api/core');
        const { listen } = await import('@tauri-apps/api/event');

        await invoke('register_schedules', { schedules });

        await listen('nativeblade-schedule', async (event) => {
            const name = event.payload?.name;
            if (!name) return;

            try {
                await request('/__nb/schedule/' + encodeURIComponent(name));
            } catch {}
        });
    } catch {}
}
