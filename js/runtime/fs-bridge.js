const PENDING_PATH = '/tmp/__nb_fs_pending.json';
const CACHE_DIR = '/tmp/__nb_fs_cache';

let fsApi = null;

async function loadFsApi() {
    if (fsApi) return fsApi;
    try {
        fsApi = await import('@tauri-apps/plugin-fs');
    } catch {
        fsApi = null;
    }
    return fsApi;
}

const BASE_DIR_MAP = {
    'app': 'AppData',
    'cache': 'AppCache',
    'export': 'Document',
    'downloads': 'Download',
    'temp': 'Temp',
};

export async function hasPendingRequest(php, output) {
    return typeof output === 'string' && output.includes('__NB_FS_PENDING__');
}

export async function fulfill(php) {
    const fs = await loadFsApi();
    if (!fs) {
        cleanup(php);
        return false;
    }

    try {
        const pendingList = JSON.parse(php.readFileAsText(PENDING_PATH));
        if (!Array.isArray(pendingList) || pendingList.length === 0) {
            cleanup(php);
            return false;
        }

        try { php.mkdirTree(CACHE_DIR); } catch {}

        for (const pending of pendingList) {
            const baseDir = BASE_DIR_MAP[pending.baseDir] || 'Document';
            const opts = { baseDir: fs.BaseDirectory[baseDir] };
            let result = null;

            try {
                switch (pending.op) {
                    case 'read': {
                        const bytes = await fs.readFile(pending.path, opts);
                        result = arrayBufferToBase64(bytes);
                        break;
                    }
                    case 'write': {
                        await ensureBaseDir(fs, baseDir);
                        const dir = pending.path.split('/').slice(0, -1).join('/');
                        if (dir) {
                            try { await fs.mkdir(dir, { ...opts, recursive: true }); } catch {}
                        }
                        const bytes = base64ToUint8Array(pending.extra);
                        await fs.writeFile(pending.path, bytes, opts);
                        result = true;
                        break;
                    }
                    case 'delete': {
                        await fs.remove(pending.path, opts);
                        result = true;
                        break;
                    }
                    case 'delete_dir': {
                        await fs.remove(pending.path, { ...opts, recursive: true });
                        result = true;
                        break;
                    }
                    case 'exists': {
                        result = await fs.exists(pending.path, opts);
                        break;
                    }
                    case 'dir_exists': {
                        try {
                            const stat = await fs.stat(pending.path, opts);
                            result = stat.isDirectory;
                        } catch {
                            result = false;
                        }
                        break;
                    }
                    case 'mkdir': {
                        await fs.mkdir(pending.path, { ...opts, recursive: true });
                        result = true;
                        break;
                    }
                    case 'stat': {
                        const stat = await fs.stat(pending.path, opts);
                        result = {
                            size: stat.size,
                            lastModified: Math.floor(stat.mtime / 1000),
                        };
                        break;
                    }
                    case 'list': {
                        const deep = pending.extra === '1';
                        const entries = await readDirRecursive(fs, pending.path, opts, deep);
                        result = entries;
                        break;
                    }
                    case 'copy': {
                        const destDir = pending.extra.split('/').slice(0, -1).join('/');
                        if (destDir) {
                            try { await fs.mkdir(destDir, { ...opts, recursive: true }); } catch {}
                        }
                        await fs.copyFile(pending.path, pending.extra, opts);
                        result = true;
                        break;
                    }
                    case 'move': {
                        const moveDestDir = pending.extra.split('/').slice(0, -1).join('/');
                        if (moveDestDir) {
                            try { await fs.mkdir(moveDestDir, { ...opts, recursive: true }); } catch {}
                        }
                        await fs.rename(pending.path, pending.extra, opts);
                        result = true;
                        break;
                    }
                }
            } catch {
                result = null;
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

const ensuredDirs = new Set();

async function ensureBaseDir(fs, baseDir) {
    if (ensuredDirs.has(baseDir)) return;
    try {
        const pathApi = await import('@tauri-apps/api/path');
        let dir;
        if (baseDir === 'AppData') dir = await pathApi.appDataDir();
        else if (baseDir === 'AppCache') dir = await pathApi.appCacheDir();
        else if (baseDir === 'Document') dir = await pathApi.documentDir();
        else if (baseDir === 'Download') dir = await pathApi.downloadDir();
        else if (baseDir === 'Temp') dir = await pathApi.tempDir();
        if (dir) {
            await fs.mkdir(dir, { recursive: true });
            ensuredDirs.add(baseDir);
        }
    } catch {}
}

async function readDirRecursive(fs, path, opts, deep) {
    const entries = await fs.readDir(path, opts);
    const result = [];

    for (const entry of entries) {
        const fullPath = path ? `${path}/${entry.name}` : entry.name;
        const item = {
            path: fullPath,
            isDirectory: entry.isDirectory,
        };

        if (!entry.isDirectory) {
            try {
                const stat = await fs.stat(fullPath, opts);
                item.size = stat.size;
                item.lastModified = Math.floor(stat.mtime / 1000);
            } catch {}
        }

        result.push(item);

        if (deep && entry.isDirectory) {
            const children = await readDirRecursive(fs, fullPath, opts, true);
            result.push(...children);
        }
    }

    return result;
}

function arrayBufferToBase64(bytes) {
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
}

function base64ToUint8Array(b64) {
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
}
