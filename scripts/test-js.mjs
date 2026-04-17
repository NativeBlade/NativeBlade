// Portable Node test runner.
//
// Background: `node --test` in Node 20 does not expand globs — passing
// "tests/js/**/*.test.js" reaches Node as a literal string and fails.
// Node 22 handles globs, but our CI matrix includes both versions.
//
// This script uses fs.globSync (available in Node 20.12+ and 22+) to
// expand the pattern ourselves, then spawns `node --test <files...>`.
// Works identically on Linux, macOS, and Windows regardless of shell.

import { globSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const pattern = 'tests/js/**/*.test.js';
const files = globSync(pattern);

if (files.length === 0) {
    console.error(`No test files matched ${pattern}`);
    process.exit(1);
}

// Allow extra flags from CLI: `node scripts/test-js.mjs --watch`
const extraArgs = process.argv.slice(2);

const result = spawnSync(
    process.execPath,
    ['--test', ...extraArgs, ...files],
    { stdio: 'inherit' },
);

process.exit(result.status ?? 1);
