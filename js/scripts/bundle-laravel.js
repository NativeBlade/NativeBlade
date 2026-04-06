import { readFileSync, writeFileSync, statSync, readdirSync, mkdirSync, existsSync } from 'fs';
import { join, relative, extname, resolve } from 'path';
import { execSync } from 'child_process';

const ROOT = process.argv[2] || process.cwd();

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
    /platform_check\.php$/,
];

const EXCLUDE_EXTENSIONS = [
    '.md', '.txt', '.yml', '.yaml', '.xml', '.neon', '.dist',
    '.lock', '.editorconfig', '.gitignore', '.gitattributes',
    '.ico',
];

const BINARY_EXTENSIONS = ['.png', '.jpg', '.gif', '.woff', '.woff2', '.ttf'];

const MIME_TYPES = {
    '.png': 'image/png', '.jpg': 'image/jpeg', '.gif': 'image/gif',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
};

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
    if (BINARY_EXTENSIONS.includes(ext) && !rel.startsWith('public/')) return false;

    return true;
}

function collectFiles(dir, files = [], virtualBase = null) {
    try {
        const entries = readdirSync(dir, { withFileTypes: true });
        for (const entry of entries) {
            const realFullPath = join(dir, entry.name);
            const virtualPath = virtualBase ? join(virtualBase, entry.name) : realFullPath;
            const stat = statSync(realFullPath);
            if (stat.isDirectory()) {
                const rel = '/' + relative(ROOT, virtualPath).replace(/\\/g, '/') + '/';
                let skip = false;
                for (const pattern of EXCLUDE_PATTERNS) {
                    if (pattern.test(rel)) { skip = true; break; }
                }
                if (!skip) collectFiles(realFullPath, files, virtualPath);
            } else if (stat.isFile()) {
                if (shouldInclude(virtualPath)) {
                    files.push({ real: realFullPath, virtual: virtualPath });
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
    for (const { real, virtual } of files) {
        const rel = '/' + relative(ROOT, virtual).replace(/\\/g, '/');
        try {
            const stat = statSync(real);
            if (stat.size > 2000000) continue;
            const ext = extname(real).toLowerCase();
            if (BINARY_EXTENSIONS.includes(ext)) {
                const mime = MIME_TYPES[ext] || 'application/octet-stream';
                const b64 = readFileSync(real).toString('base64');
                bundle[rel] = `data:${mime};base64,${b64}`;
            } else {
                bundle[rel] = readFileSync(real, 'utf-8');
            }
            totalSize += (bundle[rel] || '').length;
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
    if (!existsSync(langDst)) mkdirSync(langDst, { recursive: true });
    readdirSync(langSrc).filter(f => f.endsWith('.json')).forEach(f => {
        writeFileSync(join(langDst, f), readFileSync(join(langSrc, f)));
    });
} catch {}

const sizeMB = (Buffer.byteLength(json) / 1024 / 1024).toFixed(2);
console.log(`Files: ${fileCount}`);
console.log(`Bundle size: ${sizeMB} MB`);
console.log(`Output: ${OUTPUT}`);
