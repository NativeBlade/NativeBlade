// Database executor for the native bridge: one `db` message is one query
// run by the Rust side (sqlx) through Tauri's invoke. PHP is suspended while
// it runs; a rejected invoke becomes a failed reply, which NativeConnection
// turns into a QueryException.

let _invokeOverride = null;

export function __setInvokeForTests(fn) { _invokeOverride = fn; }
export function __resetForTests() { _invokeOverride = null; }

export async function execute(message) {
    // Browser preview has no Tauri host: the native DB driver isn't available,
    // and importing Tauri's invoke there would throw on window.__TAURI_INTERNALS__.
    if (!_invokeOverride && (typeof window === 'undefined' || !window.__TAURI_INTERNALS__)) {
        throw new Error('The native database is only available inside the app (no Tauri host).');
    }

    const invoke = _invokeOverride ?? (await import('@tauri-apps/api/core')).invoke;

    return invoke('db_query', {
        driver: message.driver,
        connection: message.connection,
        queryType: message.type,
        sql: message.sql,
        bindings: message.bindings,
    });
}
