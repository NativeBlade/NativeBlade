import { getInstance } from '../runtime/php-runtime.js';

const DB_NAME = 'nativeblade';
const STORE_NAME = 'database';
const DB_PATH = '/app/database/database.sqlite';
let idb = null;
let debounceTimer = null;

function openIDB() {
    return new Promise((resolve, reject) => {
        if (idb) return resolve(idb);
        const req = indexedDB.open(DB_NAME, 3);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME);
            }
        };
        req.onsuccess = () => { idb = req.result; resolve(idb); };
        req.onerror = () => reject(req.error);
    });
}

function idbGet(key) {
    return openIDB().then(db => new Promise(resolve => {
        const req = db.transaction(STORE_NAME, 'readonly').objectStore(STORE_NAME).get(key);
        req.onsuccess = () => resolve(req.result ?? null);
        req.onerror = () => resolve(null);
    }));
}

function idbPut(key, value) {
    return openIDB().then(db => new Promise(resolve => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(value, key);
        tx.oncomplete = () => resolve();
        tx.onerror = () => resolve();
    }));
}

export async function init() {
    await openIDB();
}

export async function restoreToWasm() {
    const php = getInstance();
    if (!php) return;
    const bytes = await idbGet('sqlite');
    if (bytes && bytes.byteLength > 0) {
        php.writeFile(DB_PATH, new Uint8Array(bytes));
    }
}

export async function persist() {
    const php = getInstance();
    if (!php) return;
    try {
        const bytes = php.readFileAsBuffer(DB_PATH);
        if (bytes && bytes.byteLength > 0) await idbPut('sqlite', bytes);
    } catch {}
}

export function schedulePersist(delay = 2000) {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(persist, delay);
}

export function startAutoSync(intervalMs = 30000) {
    setInterval(persist, intervalMs);
    window.addEventListener('beforeunload', () => {
        const php = getInstance();
        if (!php) return;
        try {
            const bytes = php.readFileAsBuffer(DB_PATH);
            if (bytes && bytes.byteLength > 0) {
                idb.transaction(STORE_NAME, 'readwrite').objectStore(STORE_NAME).put(bytes, 'sqlite');
            }
        } catch {}
    });
}
