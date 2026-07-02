// Background tasks actions — get_task, enqueue_task
//
// Pure Rust commands (no Kotlin/Swift passthrough), available on every
// platform where Plugin::TASK_MANAGER is declared. get_task pulls the latest
// parked result (forwarded as nb:task); enqueue_task parks a runtime payload
// in a queue task's outbox for send-when-possible (acked as nb:task-queued).
//
// Uses: ctx.invokeTauri, ctx.post

export async function get_task(payload, ctx) {
    const name = payload.name || null;
    if (!ctx.invokeTauri || !name) {
        ctx.post('nativeblade-task', { name, found: false, payload: null, error: 'unsupported' });
        return;
    }
    try {
        const res = await ctx.invokeTauri('plugin:nativeblade-tasks|get_task', { name });
        ctx.post('nativeblade-task', {
            name,
            found: !!res.found,
            payload: res.payload ?? null,
            ranAt: res.ranAt ?? null,
            status: res.status ?? null,
            error: res.error ?? null,
        });
    } catch (e) {
        ctx.post('nativeblade-task', { name, found: false, payload: null, error: String(e) });
    }
}

export async function enqueue_task(payload, ctx) {
    const entries = Array.isArray(payload.entries) ? payload.entries : [];
    if (!ctx.invokeTauri) {
        for (const e of entries) {
            ctx.post('nativeblade-task-queued', { name: e.name ?? null, ok: false, error: 'unsupported' });
        }
        return;
    }
    // Sequential on purpose: dispatch order === outbox order.
    for (const entry of entries) {
        try {
            await ctx.invokeTauri('plugin:nativeblade-tasks|enqueue_task', {
                name: entry.name,
                payload: entry.payload ?? {},
            });
            ctx.post('nativeblade-task-queued', { name: entry.name, ok: true, error: null });
        } catch (e) {
            ctx.post('nativeblade-task-queued', { name: entry.name ?? null, ok: false, error: String(e) });
        }
    }
}
