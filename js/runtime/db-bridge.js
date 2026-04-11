const PENDING_PATH = '/tmp/__nb_db_pending.json';
const CACHE_DIR = '/tmp/__nb_db_cache';

export async function hasPendingRequest(php, output) {
    return typeof output === 'string' && output.includes('__NB_DB_PENDING__');
}

export async function fulfill(php) {
    try {
        const pendingList = JSON.parse(php.readFileAsText(PENDING_PATH));
        if (!Array.isArray(pendingList) || pendingList.length === 0) {
            cleanup(php);
            return false;
        }

        try { php.mkdirTree(CACHE_DIR); } catch {}

        const { invoke } = await import('@tauri-apps/api/core');

        for (const pending of pendingList) {
            let result = null;

            try {
                result = await invoke('db_query', {
                    driver: pending.driver,
                    connection: pending.connection,
                    queryType: pending.type,
                    sql: pending.sql,
                    bindings: pending.bindings,
                });
            } catch (err) {
                result = { error: err.toString() };
            }

            php.writeFile(`${CACHE_DIR}/${pending.key}.json`, JSON.stringify({ result }));
        }

        try { php.unlink(PENDING_PATH); } catch {}
        return true;
    } catch {
        cleanup(php);
        return false;
    }
}

export function done(php) {
    clearCache(php);
}

function cleanup(php) {
    try { php.unlink(PENDING_PATH); } catch {}
    clearCache(php);
}

function clearCache(php) {
    try {
        const files = php.listFiles(CACHE_DIR);
        for (const f of files) {
            if (f !== '.' && f !== '..') {
                try { php.unlink(CACHE_DIR + '/' + f); } catch {}
            }
        }
    } catch {}
}
