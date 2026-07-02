// Background tasks boot — hands the task manifest (written into
// nativeblade-config.json by nativeblade:config) to the Rust courier, which
// persists it and re-enqueues the OS schedules (WorkManager UPDATE policy),
// so config changes propagate on the next app open. Then drains queued
// results of handler-mode tasks into their PHP handler classes; pull-mode
// consumption (NativeBlade::getTask → nb:task) needs nothing here.

import { request } from '../runtime/wasm-server.js';

export async function init() {
    if (!window.__TAURI_INTERNALS__) return;

    let tasks = [];
    try {
        const r = await fetch('./nativeblade-config.json', { cache: 'no-store' });
        if (!r.ok) return;
        tasks = (await r.json())?.backgroundTasks || [];
    } catch {
        return;
    }
    if (!tasks.length) return;

    let invoke;
    try {
        ({ invoke } = await import('@tauri-apps/api/core'));
        await invoke('plugin:nativeblade-tasks|register_tasks', { tasks });
    } catch (e) {
        // Plugin::TASK_MANAGER not declared — configured tasks can't run.
        console.warn('[NB Tasks] register failed:', e);
        return;
    }

    // Handler-mode tasks: deliver queued results (runs that happened with the
    // app closed) to their PHP handler classes, oldest first.
    const withHandlers = tasks.filter((t) => t.handler).map((t) => t.name);
    if (!withHandlers.length) return;
    try {
        const results = await invoke('plugin:nativeblade-tasks|drain_results', {
            names: withHandlers,
        });
        for (const r of results || []) {
            await request('/_nativeblade/task-result', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(r),
            });
        }
    } catch (e) {
        console.warn('[NB Tasks] drain failed:', e);
    }
}
