// Background tasks action — get_task
//
// Pull the latest parked result of a background task from the Rust courier's
// store. Pure Rust command (no Kotlin/Swift passthrough), available on every
// platform where Plugin::TASK_MANAGER is declared. The answer is forwarded
// as nb:task.
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
