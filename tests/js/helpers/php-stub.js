// In-memory stand-in for the php-wasm file API that the bridges talk to.
// Production `php` exposes readFileAsText/writeFile/unlink/mkdirTree/listFiles;
// this stub mirrors those with an object-backed file table so tests can
// pre-seed pending JSON and assert on cache writes.

export function makePhp(initialFiles = {}) {
    const files = { ...initialFiles };
    const state = {
        unlinkCalls: [],
        writeCalls: [],
        mkdirCalls: [],
        listCalls: [],
    };

    return {
        // Direct access for assertions
        files,
        state,

        readFileAsText(path) {
            if (!(path in files)) {
                const err = new Error(`ENOENT: ${path}`);
                err.code = 'ENOENT';
                throw err;
            }
            return files[path];
        },

        writeFile(path, content) {
            state.writeCalls.push({ path, content });
            files[path] = content;
        },

        unlink(path) {
            state.unlinkCalls.push(path);
            if (!(path in files)) {
                const err = new Error(`ENOENT: ${path}`);
                err.code = 'ENOENT';
                throw err;
            }
            delete files[path];
        },

        mkdirTree(path) {
            state.mkdirCalls.push(path);
        },

        listFiles(dir) {
            state.listCalls.push(dir);
            const prefix = dir.endsWith('/') ? dir : dir + '/';
            const seen = new Set();
            for (const p of Object.keys(files)) {
                if (p.startsWith(prefix)) {
                    const rest = p.substring(prefix.length);
                    const head = rest.split('/')[0];
                    if (head) seen.add(head);
                }
            }
            return Array.from(seen);
        },
    };
}
