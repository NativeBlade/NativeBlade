import { readFileSync, writeFileSync, statSync, readdirSync } from 'fs';
import { join, relative, extname } from 'path';
import { execSync } from 'child_process';

const ROOT = process.argv[2] || 'C:/xampp/htdocs/phpstay';

try {
    execSync('composer install --no-dev --optimize-autoloader --quiet', { cwd: ROOT, stdio: 'inherit' });
} catch {}
const OUTPUT = join(ROOT, 'public', 'laravel-bundle.json');

const INCLUDE_DIRS = [
    'app',
    'bootstrap',
    'config',
    'database/migrations',
    'lang',
    'resources/views',
    'public',
    'routes',
    'nativeblade-components',
    'vendor',
];

const INCLUDE_FILES = [
    '.env',
    'artisan',
    'composer.json',
];

const EXCLUDE_PATTERNS = [
    /\/tests?\//i,
    /\/test\//i,
    /\/Tests?\//i,
    /\/docs?\//i,
    /\/examples?\//i,
    /\/fixtures?\//i,
    /\/stubs?\//i,
    /\/phpunit/i,
    /\/phpstan/i,
    /\/psalm/i,
    /\/pint/i,
    /\/rector/i,
    /\/CHANGELOG/i,
    /\/UPGRADE/i,
    /\/CONTRIBUTING/i,
    /\/\.github\//,
    /\/\.git\//,
    /\/node_modules\//,
    /\/storage\/framework\/views\//,
    /\/storage\/logs\//,
];

const EXCLUDE_EXTENSIONS = [
    '.md', '.txt', '.yml', '.yaml', '.xml', '.neon', '.dist',
    '.lock', '.editorconfig', '.gitignore', '.gitattributes',
    '.png', '.jpg', '.gif', '.svg', '.ico',
];

const ALWAYS_INCLUDE = [
    /composer\.json$/,
    /autoload.*\.php$/,
];

function shouldInclude(filePath) {
    const rel = relative(ROOT, filePath).replace(/\\/g, '/');

    for (const pattern of ALWAYS_INCLUDE) {
        if (pattern.test(rel)) return true;
    }

    for (const pattern of EXCLUDE_PATTERNS) {
        if (pattern.test('/' + rel + '/')) return false;
    }

    const ext = extname(filePath).toLowerCase();
    if (EXCLUDE_EXTENSIONS.includes(ext)) return false;

    return true;
}

function collectFiles(dir, files = []) {
    try {
        const entries = readdirSync(dir, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = join(dir, entry.name);
            if (entry.isDirectory()) {
                const rel = '/' + relative(ROOT, fullPath).replace(/\\/g, '/') + '/';
                let skip = false;
                for (const pattern of EXCLUDE_PATTERNS) {
                    if (pattern.test(rel)) { skip = true; break; }
                }
                if (!skip) collectFiles(fullPath, files);
            } else if (entry.isFile()) {
                if (shouldInclude(fullPath)) {
                    files.push(fullPath);
                }
            }
        }
    } catch {}
    return files;
}

console.log('Bundling Laravel files...');
const bundle = {};
let totalSize = 0;
let fileCount = 0;

for (const file of INCLUDE_FILES) {
    const fullPath = join(ROOT, file);
    try {
        const content = readFileSync(fullPath, 'utf-8');
        bundle['/' + file] = content;
        totalSize += content.length;
        fileCount++;
    } catch {}
}

for (const dir of INCLUDE_DIRS) {
    const fullDir = join(ROOT, dir);
    const files = collectFiles(fullDir);
    for (const file of files) {
        const rel = '/' + relative(ROOT, file).replace(/\\/g, '/');
        try {
            const stat = statSync(file);
            if (stat.size > 2000000) continue;
            const content = readFileSync(file, 'utf-8');
            bundle[rel] = content;
            totalSize += content.length;
            fileCount++;
        } catch {}
    }
}

const json = JSON.stringify(bundle);
writeFileSync(OUTPUT, json);

try {
    const envContent = readFileSync(join(ROOT, '.env'), 'utf-8');
    const localeMatch = envContent.match(/APP_LOCALE=(\S+)/);
    const locale = localeMatch ? localeMatch[1] : 'en';
    writeFileSync(join(ROOT, 'public', 'nativeblade-locale.json'), JSON.stringify({ locale }));
    const langSrc = join(ROOT, 'lang');
    const langDst = join(ROOT, 'public', 'lang');
    try { readdirSync(langDst); } catch { require('fs').mkdirSync(langDst, { recursive: true }); }
    readdirSync(langSrc).filter(f => f.endsWith('.json')).forEach(f => {
        writeFileSync(join(langDst, f), readFileSync(join(langSrc, f)));
    });
} catch {}

const sizeMB = (Buffer.byteLength(json) / 1024 / 1024).toFixed(2);
console.log(`Files: ${fileCount}`);
console.log(`Bundle size: ${sizeMB} MB`);
console.log(`Output: ${OUTPUT}`);
