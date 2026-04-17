// Portable Node test runner.
//
// Background: `node --test` in Node 20 does not expand globs - passing
// "tests/js/**/*.test.js" reaches Node as a literal string and fails.
// Node 22 handles globs, but our CI matrix includes both versions.
// fs.globSync is also Node-22-only, so we walk the tree manually.
//
// Works identically on Linux, macOS, and Windows regardless of shell.

import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

const ROOT = 'tests/js';
const SUFFIX = '.test.js';

function walk(dir, out = []) {
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        const stat = statSync(full);
        if (stat.isDirectory()) {
            walk(full, out);
        } else if (entry.endsWith(SUFFIX)) {
            out.push(full);
        }
    }
    return out;
}

const files = walk(ROOT);

if (files.length === 0) {
    console.error('No ' + SUFFIX + ' files found under ' + ROOT);
    process.exit(1);
}

const extraArgs = process.argv.slice(2);

const result = spawnSync(
    process.execPath,
    ['--test', ...extraArgs, ...files],
    { stdio: 'inherit' },
);

process.exit(result.status ?? 1);
