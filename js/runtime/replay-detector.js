// Spots non-deterministic PHP between bridge calls.
//
// A logical request runs PHP several times: once per bridge call (HTTP, DB,
// FS), each run replaying the previous calls from the cache. That only works
// when the sequence of calls is identical on every run. PHP numbers each call
// (`index` in the pending file) and keys it by its content, so if the same
// index comes back with a different key, something before it changed between
// runs: random values, the clock, state mutated before the bridge call. The
// developer sees exactly which call moved and what it was before.
//
// Leaf module (no runtime imports); request-handler.js owns one instance per
// php-wasm runtime and resets it at each logical request boundary.

export function createReplayDetector() {
    let seen = new Map();

    return {
        /**
         * Record the calls PHP asked for in this run.
         *
         * @param {string} type     'http' | 'db' | 'fs'
         * @param {object[]} entries pending list as PHP wrote it
         * @returns {Array<{type: string, index: number, was: string, now: string}>} divergences found
         */
        track(type, entries) {
            const findings = [];
            if (!Array.isArray(entries)) return findings;

            for (const entry of entries) {
                if (!entry || typeof entry.index !== 'number' || typeof entry.key !== 'string') continue;
                const id = `${type}:${entry.index}`;
                const summary = describeCall(type, entry);
                const previous = seen.get(id);
                if (previous && previous.key !== entry.key) {
                    findings.push({ type, index: entry.index, was: previous.summary, now: summary });
                }
                seen.set(id, { key: entry.key, summary });
            }

            return findings;
        },

        reset() {
            seen = new Map();
        },
    };
}

/** Read a bridge's pending file; [] when missing or unreadable. */
export function readPending(php, pendingPath) {
    try {
        const list = JSON.parse(php.readFileAsText(pendingPath));
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

export function describeCall(type, entry) {
    switch (type) {
        case 'http': return `${entry.method || 'GET'} ${entry.url || ''}`.trim();
        case 'db': return `${entry.type || 'query'} ${truncate(entry.sql || '')}`.trim();
        case 'fs': return `${entry.op || 'op'} ${entry.baseDir ? entry.baseDir + ':' : ''}${entry.path || ''}`.trim();
        default: return JSON.stringify(entry);
    }
}

const LABELS = { http: 'HTTP call', db: 'query', fs: 'filesystem operation' };

export function formatDivergence(finding) {
    const label = LABELS[finding.type] || `${finding.type} call`;
    return `Replay diverged: ${label} #${finding.index + 1} changed between runs of the same request. `
        + `Was "${finding.was}", now "${finding.now}". `
        + 'PHP is re-run after each bridge call and must make the same calls in the same order; '
        + 'something before this call is not deterministic (random values, the clock, state changed before the call).';
}

function truncate(text, max = 120) {
    return text.length > max ? text.slice(0, max - 3) + '...' : text;
}
