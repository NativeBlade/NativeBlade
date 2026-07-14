import { request } from '../runtime/wasm-server.js';

let initialized = false;

export async function init(schedules) {
    if (initialized || !schedules || !schedules.length) return;
    initialized = true;

    try {
        const { invoke } = await import('@tauri-apps/api/core');
        const { listen } = await import('@tauri-apps/api/event');

        // Install the listener BEFORE registering: register_schedules spawns the
        // per-schedule loops immediately and an already-overdue one emits right
        // away, so registering first would let that first event fire into the void.
        await listen('nativeblade-schedule', async (event) => {
            const name = event.payload?.name;
            if (!name) return;

            try {
                await request('/__nb/schedule/' + encodeURIComponent(name));
            } catch {}
        });

        await invoke('register_schedules', { schedules });
    } catch {}
}
