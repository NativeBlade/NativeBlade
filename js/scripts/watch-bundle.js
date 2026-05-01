#!/usr/bin/env node
// Watches Laravel source dirs for changes during `nativeblade:dev` and
// re-runs bundle-laravel.js so the on-disk bundle stays fresh. This means
// cold restarts of the app see the latest code, not just hot-reload sessions.

import { spawn } from 'node:child_process';
import { watch } from 'node:fs';
import { existsSync, statSync, readdirSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(process.argv[2] || process.cwd());
const bundleScript = join(__dirname, 'bundle-laravel.js');

const WATCH_DIRS = [
    'app',
    'resources/views',
    'resources/lang',
    'routes',
    'config',
    'database/migrations',
    'database/seeders',
    'lang',
    'nativeblade-components',
];

const IGNORE_PATTERNS = [
    /\/\.git\//,
    /\/node_modules\//,
    /\/vendor\//,
    /\/storage\/(logs|framework)\//,
    /\/public\/laravel-bundle/,
    /\/public\/build\//,
    /\.swp$/i,
    /~$/,
];

const DEBOUNCE_MS = 400;

let pending = false;
let running = false;
let queued = false;

function shouldIgnore(path) {
    return IGNORE_PATTERNS.some((re) => re.test(path));
}

function rebundle() {
    if (running) {
        queued = true;
        return;
    }
    running = true;

    const start = Date.now();
    process.stdout.write('  [watch] rebuilding bundle... ');

    const child = spawn('node', [bundleScript, projectRoot], {
        stdio: ['ignore', 'ignore', 'inherit'],
        cwd: projectRoot,
    });

    child.on('exit', (code) => {
        running = false;
        const elapsed = Date.now() - start;
        if (code === 0) {
            process.stdout.write(`done in ${elapsed}ms\n`);
        } else {
            process.stdout.write(`failed (${code})\n`);
        }
        if (queued) {
            queued = false;
            setTimeout(rebundle, 50);
        }
    });
}

function scheduleRebuild() {
    if (pending) return;
    pending = true;
    setTimeout(() => {
        pending = false;
        rebundle();
    }, DEBOUNCE_MS);
}

function watchTree(root) {
    if (!existsSync(root)) return;
    try {
        watch(root, { recursive: true }, (event, filename) => {
            if (!filename) return;
            const full = join(root, filename);
            if (shouldIgnore(full)) return;
            scheduleRebuild();
        });
    } catch (e) {
        // Some platforms (older Linux) don't support recursive watch.
        // Fall back to walking the tree and watching each subdir.
        walkAndWatch(root);
    }
}

function walkAndWatch(root) {
    if (!existsSync(root)) return;
    try {
        watch(root, (event, filename) => {
            if (!filename) return;
            const full = join(root, filename);
            if (shouldIgnore(full)) return;
            scheduleRebuild();
        });
        for (const entry of readdirSync(root, { withFileTypes: true })) {
            if (entry.isDirectory()) {
                walkAndWatch(join(root, entry.name));
            }
        }
    } catch {}
}

console.log('  [watch] watching:');
for (const dir of WATCH_DIRS) {
    const full = join(projectRoot, dir);
    if (existsSync(full)) {
        console.log(`            ${dir}/`);
        watchTree(full);
    }
}

console.log('  [watch] ready — bundle will rebuild on file changes\n');

process.on('SIGINT', () => process.exit(0));
process.on('SIGTERM', () => process.exit(0));
