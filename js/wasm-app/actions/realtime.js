// Realtime action — driver-based pub/sub + streaming over one connection per
// named endpoint. Drivers: `reverb`/`pusher` (via `laravel-echo` + `pusher-js`)
// and raw `ws` (any external/custom WebSocket + AI streaming). Each driver has
// its own branch in ensureConnection + the op handlers.
//
// Config comes from public/nativeblade-config.json (`.realtime`), written by
// `nativeblade:config` from NativeBladeConfig::realtimeConfig(). ONE connection
// multiplexes many channels; subscriptions are ref-counted so shared channels
// open once and close only when the last component leaves.
//
// Socket events are asynchronous (not tied to the dispatching action), so they
// reach the CURRENT app frame via postToApp and are re-dispatched as Livewire
// events by the interceptor:
//   nb:realtime            ($connection, $channel, $event, $payload)  — discrete messages
//   nb:realtime-presence   ($connection, $channel, $event, $members|$user)
//   nb:realtime-stream     ($connection, $streamId, $delta)           — coalesced deltas
//   nb:realtime-stream-end / nb:realtime-stream-error ($connection, $streamId, ...)
//   nb:realtime:{channel}:{event}  — same message, pre-routed for a dedicated #[On]
//   nb:realtime-connected / nb:realtime-reconnected / nb:realtime-disconnected ($connection, ...)
//
// Private/presence auth: the app sets the bearer via NativeBlade::realtimeAuth()
// (→ realtime_auth), read by authToken() and applied to open connections.

import { postToApp } from '../bridge.js';

const STREAM_FLUSH_MS = 60; // coalesce stream deltas to ~16 Hz so PHP appends batches, not tokens

// --- config -------------------------------------------------------------

let configPromise = null;
function loadConfig() {
    configPromise ??= (async () => {
        if (typeof window !== 'undefined' && window.__NB_REALTIME__) return window.__NB_REALTIME__;
        try {
            const r = await fetch('./nativeblade-config.json', { cache: 'no-store' });
            if (r.ok) return (await r.json()).realtime || null;
        } catch {}
        return null;
    })();
    return configPromise;
}

// Private/presence auth: Echo POSTs to `authEndpoint` with the user's bearer
// token. The app supplies it (a global it can set from PHP state, or a future
// realtime_auth action). Public channels don't need it.
function authToken() {
    return (typeof window !== 'undefined' && window.__NB_REALTIME_TOKEN__) || null;
}

// Deliver a message to Livewire: the generic `nb:realtime` plus a pre-routed
// `nb:realtime:{channel}:{event}` (the interceptor maps `nativeblade-*` → `nb:*`
// by a plain prefix replace, so dynamic names pass through). Pick either style
// in your component — the other is simply not listened to.
function emitRealtime(connection, channel, event, payload, id) {
    postToApp('nativeblade-realtime', { connection, channel, event, payload, id });
    if (channel) postToApp(`nativeblade-realtime:${channel}:${event}`, { connection, channel, event, payload, id });
}

// --- connections (lazy, one per named endpoint) -------------------------

// name -> { name, settings, echo, channels: Map<channel,{count,chan,type}>, streams: Map<id,{buf,timer}> }
const connections = new Map();

async function ensureConnection(name) {
    const cfg = await loadConfig();
    if (!cfg) throw new Error('[NB] realtime: no config (call NativeBladeConfig::realtimeConfig)');
    const connName = name || cfg.default;
    if (connections.has(connName)) return connections.get(connName);

    const settings = cfg.connections?.[connName];
    if (!settings) throw new Error(`[NB] realtime: unknown connection '${connName}'`);

    const conn = { name: connName, settings, echo: null, ws: null, channels: new Map(), streams: new Map() };
    if (settings.driver === 'reverb' || settings.driver === 'pusher') {
        await setupEcho(conn);
    } else if (settings.driver === 'ws') {
        setupWs(conn);
    } else {
        throw new Error(`[NB] realtime: driver '${settings.driver}' not implemented yet`);
    }
    connections.set(connName, conn);
    return conn;
}

async function setupEcho(conn) {
    const s = conn.settings;
    const Pusher = (await import('pusher-js')).default;
    const Echo = (await import('laravel-echo')).default;
    if (typeof window !== 'undefined') window.Pusher = Pusher;

    const token = authToken();
    const opts = {
        broadcaster: s.driver, // 'reverb' | 'pusher'
        key: s.key,
        wsHost: s.host,
        wsPort: s.port ?? 443,
        wssPort: s.port ?? 443,
        forceTLS: (s.scheme ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        // Always an OBJECT, never undefined: pusher-js does `'params' in auth`
        // when building its channel authorizer, which throws on undefined.
        authEndpoint: s.authEndpoint || '/broadcasting/auth',
        auth: { headers: token ? { Authorization: `Bearer ${token}` } : {} },
    };
    if (s.cluster) opts.cluster = s.cluster; // pusher-hosted only

    conn.echo = new Echo(opts);

    // Lifecycle → the gap-fill hooks. pusher-js exposes the raw connection; the
    // FIRST 'connected' is a fresh connect, later ones are reconnects (where the
    // app should re-fetch history for the gap it missed).
    const pusher = conn.echo?.connector?.pusher;
    if (pusher?.connection) {
        let wasConnected = false;
        pusher.connection.bind('state_change', ({ current }) => {
            if (current === 'connected') {
                postToApp(wasConnected ? 'nativeblade-realtime-reconnected' : 'nativeblade-realtime-connected',
                    { connection: conn.name });
                wasConnected = true;
            } else if (current === 'disconnected' || current === 'unavailable' || current === 'failed') {
                postToApp('nativeblade-realtime-disconnected', { connection: conn.name, reason: current });
            }
        });
    }
}

// --- ops (subscribe / private / presence / stream / leave) --------------

export async function realtime(payload) {
    const ops = payload?.ops || [];
    for (const op of ops) {
        try {
            const conn = await ensureConnection(op.connection);
            if (op.op === 'leave') { leaveChannel(conn, op.channel); continue; }
            if (op.op === 'stream') { openStream(conn, op); continue; }
            subscribeChannel(conn, op);
        } catch (e) {
            console.error('[NB] realtime op failed', op, e);
        }
    }
}

function subscribeChannel(conn, op) {
    if (conn.ws) return wsSubscribe(conn, op);
    const existing = conn.channels.get(op.channel);
    if (existing) { existing.count++; return; } // ref-count: already open

    const echo = conn.echo;
    const chan = op.type === 'presence' ? echo.join(op.channel)
        : op.type === 'private' ? echo.private(op.channel)
        : echo.channel(op.channel);

    // Every event on the channel → the generic nb:realtime. Echo prefixes
    // broadcastAs() names with a leading '.', which we strip.
    chan.listenToAll((event, data) => {
        emitRealtime(conn.name, op.channel, String(event).replace(/^\./, ''), data, op.id ?? null);
    });

    if (op.type === 'presence') {
        chan.here((members) => postToApp('nativeblade-realtime-presence', { connection: conn.name, channel: op.channel, event: 'here', members }))
            .joining((user) => postToApp('nativeblade-realtime-presence', { connection: conn.name, channel: op.channel, event: 'joining', user }))
            .leaving((user) => postToApp('nativeblade-realtime-presence', { connection: conn.name, channel: op.channel, event: 'leaving', user }));
    }

    conn.channels.set(op.channel, { count: 1, chan, type: op.type });
}

// Accumulating stream: the server broadcasts delta events on the channel; we
// coalesce them (flush a few times a second) so PHP appends batched text instead
// of re-rendering per token. `end`/`error` finalize. Streams shine on the `ws`
// driver (AI endpoints); over Echo they're discrete delta events, same coalescer.
function openStream(conn, op) {
    if (conn.ws) return wsStream(conn, op);
    const streamId = op.id || op.channel;
    if (conn.streams.has(streamId)) return;

    const state = { buf: '', timer: null };
    conn.streams.set(streamId, state);

    const flush = () => {
        state.timer = null;
        if (!state.buf) return;
        postToApp('nativeblade-realtime-stream', { connection: conn.name, streamId, delta: state.buf });
        state.buf = '';
    };

    const chan = op.type === 'private' ? conn.echo.private(op.channel) : conn.echo.channel(op.channel);
    chan.listenToAll((event, data) => {
        const e = String(event).replace(/^\./, '');
        if (e === 'end' || e === 'stream-end') {
            flush();
            conn.streams.delete(streamId);
            postToApp('nativeblade-realtime-stream-end', { connection: conn.name, streamId });
        } else if (e === 'error' || e === 'stream-error') {
            flush();
            conn.streams.delete(streamId);
            postToApp('nativeblade-realtime-stream-error', { connection: conn.name, streamId, error: data?.error ?? 'stream error' });
        } else {
            state.buf += typeof data === 'string' ? data : (data?.delta ?? data?.text ?? '');
            state.timer ??= setTimeout(flush, STREAM_FLUSH_MS);
        }
    });

    conn.channels.set(op.channel, { count: 1, chan, type: 'stream' });
}

function leaveChannel(conn, channel) {
    if (conn.ws) { conn.wsChannels?.delete(channel); return; }
    const entry = conn.channels.get(channel);
    if (!entry) return;
    if (--entry.count > 0) return; // another component still holds it
    conn.channels.delete(channel);
    const stream = conn.streams.get(channel) || conn.streams.get(entry?.id);
    if (stream?.timer) clearTimeout(stream.timer);
    try { conn.echo.leave(channel); } catch {}
}

// --- send / whisper / leave (runtime actions) ---------------------------

// Ephemeral client event (typing, cursor) on a private/presence channel.
export async function realtime_whisper(payload) {
    try {
        const conn = await ensureConnection(payload.connection);
        if (conn.ws) return wsSend(conn, payload); // ws has no whisper — send a frame
        const entry = conn.channels.get(payload.channel);
        const chan = entry?.chan || conn.echo.private(payload.channel);
        chan.whisper(payload.event, payload.payload || {});
    } catch (e) {
        console.error('[NB] realtime whisper failed', e);
    }
}

// Publish. On the `ws` driver this is a real send over the socket. On Reverb/
// Pusher a socket-level send is only a whisper (private/presence, ephemeral) —
// for a persisted send, POST to your backend, which broadcasts.
export async function realtime_send(payload) {
    try {
        const conn = await ensureConnection(payload.connection);
        if (conn.ws) return wsSend(conn, payload);
        return realtime_whisper(payload);
    } catch (e) {
        console.error('[NB] realtime send failed', e);
    }
}

export async function realtime_leave(payload) {
    try {
        const conn = await ensureConnection(payload.connection);
        leaveChannel(conn, payload.channel);
    } catch {}
}

// Set (or clear, with null) the bearer token used to authorize private/presence
// subscriptions — Echo POSTs it to the connection's authEndpoint. Call it after
// login and before subscribing to a private/presence channel. Already-open
// connections are updated in place, so a subscribe made after login still auths
// even if the connection was created earlier by a public subscribe.
export async function realtime_auth(payload) {
    const token = payload?.token ?? null;
    if (typeof window !== 'undefined') window.__NB_REALTIME_TOKEN__ = token;
    for (const conn of connections.values()) {
        const pusher = conn.echo?.connector?.pusher;
        if (!pusher?.config) continue;
        const auth = token ? { headers: { Authorization: `Bearer ${token}` } } : {};
        if (pusher.config.channelAuthorization) Object.assign(pusher.config.channelAuthorization, auth);
        pusher.config.auth = auth;
    }
}

// --- ws driver (raw WebSocket: external/custom feeds, AI streaming) ------
// Bring-your-own-protocol: the framework opens/reconnects the socket, routes
// frames to Livewire, coalesces streams, and queues sends before it is open —
// but the frame SHAPES are your server's. Incoming JSON with `channel`/`event`
// routes on those; otherwise a frame is delivered as nb:realtime ($event =
// 'message', $payload = parsed JSON or the raw string).

function setupWs(conn) {
    conn.wsChannels = new Set();
    conn.wsStreams = new Map(); // streamId -> { buf, timer } (one accumulating session per ws connection)
    conn.outbox = [];           // sends queued before the socket is open / while reconnecting
    conn.everConnected = false;
    conn.closing = false;
    conn.backoff = 500;
    openWs(conn);
}

function openWs(conn) {
    let ws;
    try { ws = new WebSocket(conn.settings.url); }
    catch (e) { console.error('[NB] realtime ws open failed', e); scheduleReconnect(conn); return; }
    conn.ws = ws;

    ws.onopen = () => {
        conn.backoff = 500;
        postToApp(conn.everConnected ? 'nativeblade-realtime-reconnected' : 'nativeblade-realtime-connected',
            { connection: conn.name });
        conn.everConnected = true;
        for (const frame of conn.outbox.splice(0)) { try { ws.send(frame); } catch {} }
    };
    ws.onmessage = (ev) => routeWsFrame(conn, ev.data);
    ws.onclose = () => {
        // A mid-stream drop is an interruption to retry, NOT a gap to backfill.
        for (const streamId of [...conn.wsStreams.keys()]) errorWsStream(conn, streamId, { error: 'disconnected' });
        postToApp('nativeblade-realtime-disconnected', { connection: conn.name, reason: 'closed' });
        if (!conn.closing) scheduleReconnect(conn);
    };
    ws.onerror = () => { try { ws.close(); } catch {} };
}

function scheduleReconnect(conn) {
    conn.backoff = Math.min((conn.backoff || 500) * 2, 15000);
    setTimeout(() => { if (!conn.closing) openWs(conn); }, conn.backoff);
}

function wsSend(conn, payload) {
    const frame = typeof payload.payload === 'string'
        ? payload.payload
        : JSON.stringify(payload.event ? { event: payload.event, ...(payload.payload || {}) } : (payload.payload || {}));
    if (conn.ws && conn.ws.readyState === 1) {
        try { conn.ws.send(frame); } catch (e) { console.error('[NB] realtime ws send', e); }
    } else {
        conn.outbox.push(frame); // flushed on (re)connect
    }
}

function wsSubscribe(conn, op) {
    conn.wsChannels.add(op.channel);
    // If your server needs an explicit subscribe frame, this sends one; servers
    // that stream everything by default simply ignore it.
    if (op.channel) wsSend(conn, { event: 'subscribe', payload: { channel: op.channel } });
}

function wsStream(conn, op) {
    const streamId = op.id || op.channel;
    if (!conn.wsStreams.has(streamId)) conn.wsStreams.set(streamId, { buf: '', timer: null });
}

function routeWsFrame(conn, raw) {
    let data = raw;
    if (typeof raw === 'string') { try { data = JSON.parse(raw); } catch {} }

    // Stream mode: one accumulating session per ws connection (the AI case).
    if (conn.wsStreams.size) {
        const [streamId, st] = conn.wsStreams.entries().next().value;
        if (isStreamEnd(data)) { flushWsStream(conn, streamId); endWsStream(conn, streamId); return; }
        if (isStreamError(data)) { flushWsStream(conn, streamId); errorWsStream(conn, streamId, data); return; }
        st.buf += extractDelta(data);
        st.timer ??= setTimeout(() => flushWsStream(conn, streamId), STREAM_FLUSH_MS);
        return;
    }

    // Message mode.
    const isObj = data && typeof data === 'object';
    emitRealtime(conn.name, (isObj && data.channel) || '', (isObj && data.event) || 'message', data, null);
}

function flushWsStream(conn, streamId) {
    const st = conn.wsStreams.get(streamId);
    if (!st) return;
    st.timer = null;
    if (!st.buf) return;
    postToApp('nativeblade-realtime-stream', { connection: conn.name, streamId, delta: st.buf });
    st.buf = '';
}

function endWsStream(conn, streamId) {
    const st = conn.wsStreams.get(streamId);
    if (st?.timer) clearTimeout(st.timer);
    conn.wsStreams.delete(streamId);
    postToApp('nativeblade-realtime-stream-end', { connection: conn.name, streamId });
}

function errorWsStream(conn, streamId, data) {
    const st = conn.wsStreams.get(streamId);
    if (!st) return;
    if (st.timer) clearTimeout(st.timer);
    conn.wsStreams.delete(streamId);
    postToApp('nativeblade-realtime-stream-error', { connection: conn.name, streamId, error: data?.error ?? 'stream error' });
}

// Common defaults for AI/token WS frames (a future config hook can override).
function isStreamEnd(d) {
    if (d === '[DONE]') return true;
    return !!(d && typeof d === 'object' && (d.done === true || d.type === 'done' || d.finish || d.finished));
}
function isStreamError(d) {
    return !!(d && typeof d === 'object' && (d.error || d.type === 'error'));
}
function extractDelta(d) {
    if (typeof d === 'string') return d;
    if (!d || typeof d !== 'object') return '';
    return d.delta ?? d.text ?? d.content ?? d.token ?? '';
}
