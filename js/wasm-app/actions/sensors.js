// Sensors action — sensors (entries of {op, sensor, intervalMs?, id?})
//
// Mobile only: SensorManager (Android) / CoreMotion (iOS) via the
// nativeblade-sensors plugin. Ops: available/read answer on nb:sensor;
// watch streams on nb:sensor-changed (see sensors-boot.js) until stop.
// On desktop/web every op reports available: false so handler code runs
// unchanged.
//
// Uses: ctx.isMobile, ctx.invokeTauri, ctx.post

const COMMANDS = {
    available: 'is_available',
    read: 'read_sensor',
    watch: 'watch_sensor',
    stop: 'stop_sensor',
};

export async function sensors(payload, ctx) {
    const entries = Array.isArray(payload.entries) ? payload.entries : [];
    for (const entry of entries) {
        const command = COMMANDS[entry.op];
        const base = { sensor: entry.sensor ?? null, id: entry.id ?? null };
        if (!ctx.isMobile || !ctx.invokeTauri || !command) {
            if (entry.op !== 'stop') {
                ctx.post('nativeblade-sensor', { ...base, available: false, error: 'unsupported' });
            }
            continue;
        }
        try {
            const res = await ctx.invokeTauri(`plugin:nativeblade-sensors|${command}`, {
                sensor: entry.sensor,
                id: entry.id ?? null,
                ...(entry.op === 'watch' ? { intervalMs: entry.intervalMs ?? 500 } : {}),
            });
            // available/read answer with a payload; watch answers only when
            // the sensor is missing; stop answers nothing.
            if (entry.op === 'available' || entry.op === 'read') {
                ctx.post('nativeblade-sensor', { error: null, ...base, ...res });
            } else if (entry.op === 'watch' && res && res.available === false) {
                ctx.post('nativeblade-sensor', { ...base, available: false, error: null });
            }
        } catch (e) {
            if (entry.op !== 'stop') {
                ctx.post('nativeblade-sensor', { ...base, available: false, error: String(e) });
            }
        }
    }
}
