// Filesystem executor for the native bridge: one `fs` message is one
// operation on the device through Tauri's fs plugin. PHP is suspended while
// it runs. A failure throws, which the bridge reports as a failed reply;
// NativeFilesystemAdapter maps that to the Flysystem exception for reads and
// writes and to "absent" for the rest.

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

export function __setFsApiForTests(fs) { fsApi = fs; }
export function __resetForTests() {
    fsApi = null;
    ensuredDirs.clear();
}

const BASE_DIR_MAP = {
    'app': 'AppData',
    'cache': 'AppCache',
    'export': 'Document',
    'downloads': 'Download',
    'temp': 'Temp',
};

export async function execute(message) {
    const fs = await loadFsApi();
    if (!fs) throw new Error('The native filesystem is only available inside the app (no fs plugin).');

    const baseDir = BASE_DIR_MAP[message.baseDir] || 'Document';
    const opts = { baseDir: fs.BaseDirectory[baseDir] };
    const path = message.path;
    const extra = message.extra;

    switch (message.op) {
        case 'read': {
            const bytes = await fs.readFile(path, opts);
            return arrayBufferToBase64(bytes);
        }
        case 'write': {
            await ensureBaseDir(fs, baseDir);
            const dir = path.split('/').slice(0, -1).join('/');
            if (dir) {
                try { await fs.mkdir(dir, { ...opts, recursive: true }); } catch {}
            }
            await fs.writeFile(path, base64ToUint8Array(extra), opts);
            return true;
        }
        case 'delete':
            await fs.remove(path, opts);
            return true;
        case 'delete_dir':
            await fs.remove(path, { ...opts, recursive: true });
            return true;
        case 'exists':
            return fs.exists(path, opts);
        case 'dir_exists': {
            try {
                const stat = await fs.stat(path, opts);
                return stat.isDirectory;
            } catch {
                return false;
            }
        }
        case 'mkdir':
            await fs.mkdir(path, { ...opts, recursive: true });
            return true;
        case 'stat': {
            const stat = await fs.stat(path, opts);
            return { size: stat.size, lastModified: Math.floor(stat.mtime / 1000) };
        }
        case 'list':
            return readDirRecursive(fs, path, opts, extra === '1');
        case 'copy': {
            const destDir = extra.split('/').slice(0, -1).join('/');
            if (destDir) {
                try { await fs.mkdir(destDir, { ...opts, recursive: true }); } catch {}
            }
            await fs.copyFile(path, extra, opts);
            return true;
        }
        case 'move': {
            const destDir = extra.split('/').slice(0, -1).join('/');
            if (destDir) {
                try { await fs.mkdir(destDir, { ...opts, recursive: true }); } catch {}
            }
            await fs.rename(path, extra, opts);
            return true;
        }
        default:
            throw new Error(`Unknown filesystem operation '${message.op}'.`);
    }
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
        const item = { path: fullPath, isDirectory: entry.isDirectory };

        if (!entry.isDirectory) {
            try {
                const stat = await fs.stat(fullPath, opts);
                item.size = stat.size;
                item.lastModified = Math.floor(stat.mtime / 1000);
            } catch {}
        }

        result.push(item);

        if (deep && entry.isDirectory) {
            result.push(...await readDirRecursive(fs, fullPath, opts, true));
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
