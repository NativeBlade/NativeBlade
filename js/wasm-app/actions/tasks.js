// Background tasks actions — get_task, enqueue_task, get_task_queue
//
// Pure Rust commands (no Kotlin/Swift passthrough), available on every
// platform where Plugin::TASK_MANAGER is declared. get_task pulls the latest
// parked result (forwarded as nb:task); enqueue_task parks runtime payloads
// in a queue task's outbox — parking only, delivery is on the queue's clock
// (acked as nb:task-queued); get_task_queue peeks at pending entries without
// consuming them (forwarded as nb:task-queue).
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

export async function get_task_queue(payload, ctx) {
    const name = payload.name || null;
    if (!ctx.invokeTauri || !name) {
        ctx.post('nativeblade-task-queue', { name, entries: [], count: 0, error: 'unsupported' });
        return;
    }
    try {
        const entries = await ctx.invokeTauri('plugin:nativeblade-tasks|get_queue', { name });
        ctx.post('nativeblade-task-queue', {
            name,
            entries: entries ?? [],
            count: (entries ?? []).length,
            error: null,
        });
    } catch (e) {
        ctx.post('nativeblade-task-queue', { name, entries: [], count: 0, error: String(e) });
    }
}

export async function clear_task_queue(payload, ctx) {
    const name = payload.name || null;
    if (!ctx.invokeTauri || !name) {
        ctx.post('nativeblade-task-queue-cleared', { name, removed: 0, error: 'unsupported' });
        return;
    }
    try {
        const removed = await ctx.invokeTauri('plugin:nativeblade-tasks|clear_queue', {
            name,
            id: payload.id ?? null,
        });
        ctx.post('nativeblade-task-queue-cleared', { name, removed: removed ?? 0, error: null });
    } catch (e) {
        ctx.post('nativeblade-task-queue-cleared', { name, removed: 0, error: String(e) });
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
                id: entry.id ?? null,
            });
            ctx.post('nativeblade-task-queued', { name: entry.name, ok: true, error: null });
        } catch (e) {
            ctx.post('nativeblade-task-queued', { name: entry.name ?? null, ok: false, error: String(e) });
        }
    }
}
