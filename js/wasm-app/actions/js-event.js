// NativeBlade::jsEvent('name', [...]) — PHP talking to the app's own page JS.
// Rides the deliver:'js' path: the interceptor re-emits it as a DOM CustomEvent
// `nb:js:{name}` on window (never a Livewire/PHP dispatch), so page scripts
// listen with window.addEventListener('nb:js:name', e => e.detail).

export function js_event(payload, ctx) {
    const event = payload?.event;
    if (!event) return;

    const data = { ...(payload.payload || {}) };
    delete data.type; // reserved: it is the postMessage envelope discriminator

    ctx.post(`nativeblade-js:${event}`, { ...data, __nbDeliver: 'js' });
}
