// Where a NativeBlade::log() entry (or a PHP error) goes besides the devtools
// console: a log file in the app's log directory, and the dev server's
// terminal while one is reachable. The console is useless on a phone; these
// two are what `nativeblade:logs` and `nativeblade:dev` read.
//
// Leaf module (no runtime imports): the file system and fetch are injected, so
// the sink is unit-tested with fakes. actions/system.js wires the real ones.

export const LOG_FILE = 'nativeblade.log';
export const ROTATED_FILE = 'nativeblade.log.1';
export const ROTATE_AT = 1024 * 1024;

/** One line per entry: `<time> [<source>:<level>] <message> <context json>`. */
export function formatLine(entry) {
    const at = typeof entry.at === 'string' && entry.at ? entry.at : new Date().toISOString();
    const level = typeof entry.level === 'string' && entry.level ? entry.level : 'info';
    const tag = entry.source === 'php' ? `php:${level}` : level;
    const message = String(entry.message ?? '').replace(/\r?\n/g, '\n    ');
    const context = entry.context && typeof entry.context === 'object' && Object.keys(entry.context).length
        ? ' ' + safeJson(entry.context)
        : '';
    return `${at} [${tag}] ${message}${context}\n`;
}

function safeJson(value) {
    try { return JSON.stringify(value); } catch { return '[unserializable]'; }
}

/**
 * @param {object}   opts
 * @param {object}   [opts.fs]           @tauri-apps/plugin-fs (stat, rename, remove, writeTextFile)
 * @param {*}        [opts.baseDir]      fs BaseDirectory the log file lives in
 * @param {Function} [opts.ensureDir]    creates the base directory; called before the first write
 * @param {string}   [opts.devServerUrl] dev server origin; '' when there is none
 * @param {Function} [opts.fetchFn]      fetch used to post entries to the dev server
 * @returns {(entry: object) => Promise<void>} resolves once the file write settled; never rejects
 */
export function createSink({ fs = null, baseDir = null, ensureDir = null, devServerUrl = '', fetchFn = null } = {}) {
    let chain = Promise.resolve();
    let size = null;

    async function append(line) {
        if (ensureDir) {
            await ensureDir();
            ensureDir = null;
        }
        if (size === null) {
            try { size = (await fs.stat(LOG_FILE, { baseDir })).size || 0; } catch { size = 0; }
        }
        if (size > 0 && size + line.length > ROTATE_AT) {
            try {
                await fs.rename(LOG_FILE, ROTATED_FILE, { oldPathBaseDir: baseDir, newPathBaseDir: baseDir });
            } catch {
                try { await fs.remove(LOG_FILE, { baseDir }); } catch {}
            }
            size = 0;
        }
        await fs.writeTextFile(LOG_FILE, line, { baseDir, append: true });
        size += line.length;
    }

    return function sink(entry) {
        if (devServerUrl && fetchFn) {
            try {
                fetchFn(`${devServerUrl}/__nb_log`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(entry),
                    keepalive: true,
                }).catch(() => {});
            } catch {}
        }

        if (!fs || baseDir === null || baseDir === undefined) return chain;

        // Serialized: appends from a burst of logs must not interleave.
        chain = chain.then(() => append(formatLine(entry))).catch(() => {});
        return chain;
    };
}
