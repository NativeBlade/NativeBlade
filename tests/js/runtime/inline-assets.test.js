import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { inlineAssets } from '../../../js/runtime/inline-assets.js';

// Minimal php stub: an in-memory /app/public filesystem. readFileAsText throws
// (like the real bridge) when a file is absent, so the inliner's catch leaves
// the tag untouched.
function makePhp(files) {
    const read = (path) => {
        if (Object.prototype.hasOwnProperty.call(files, path)) return files[path];
        throw new Error('ENOENT ' + path);
    };
    return {
        readFileAsText: read,
        readFileAsBuffer: (path) => Buffer.from(read(path), 'binary'),
    };
}

describe('inline-assets/inlineAssets', () => {
    it('inlines a stylesheet referenced with a bare /path', () => {
        const php = makePhp({ '/app/public/css/app.css': 'body{color:red}' });
        const out = inlineAssets('<link rel="stylesheet" href="/css/app.css">', php);
        assert.match(out, /<style>body\{color:red\}<\/style>/);
        assert.doesNotMatch(out, /<link/);
    });

    it('inlines a stylesheet referenced with a host-absolute URL (asset())', () => {
        // Regression: asset('css/app.css') renders as http://localhost/css/app.css.
        // The bare-slash-only regex used to miss it, so in a satellite srcdoc the
        // <link> became a real network request (ERR_CONNECTION_REFUSED).
        const php = makePhp({ '/app/public/css/app.css': 'body{color:red}' });
        const out = inlineAssets('<link rel="stylesheet" href="http://localhost/css/app.css">', php);
        assert.match(out, /<style>body\{color:red\}<\/style>/);
        assert.doesNotMatch(out, /<link/);
    });

    it('preserves subdirectories in a host-absolute stylesheet URL', () => {
        const php = makePhp({ '/app/public/css/theme/dark.css': '.x{}' });
        const out = inlineAssets('<link rel="stylesheet" href="https://tauri.localhost/css/theme/dark.css">', php);
        assert.match(out, /<style>\.x\{\}<\/style>/);
    });

    it('keeps a wire:nb-asset stylesheet as a <link> with a data: URL', () => {
        // Assets inside a Livewire component are re-rendered on every update;
        // wire:nb-asset must survive the inline as a stable <link> tag so the
        // nb-asset directive (wire:ignore.self) can persist it through morphs.
        const css = 'body{color:red}';
        const php = makePhp({ '/app/public/css/ds.css': css });
        const out = inlineAssets('<link wire:nb-asset rel="stylesheet" href="http://localhost/css/ds.css">', php);
        assert.match(out, /^<link/);
        assert.doesNotMatch(out, /<style>/);
        assert.match(out, /wire:nb-asset/);
        const b64 = Buffer.from(css, 'utf8').toString('base64');
        assert.ok(out.includes('data:text/css;base64,' + b64), out);
    });

    it('still inlines an unmarked stylesheet to <style>', () => {
        const php = makePhp({ '/app/public/css/ds.css': 'body{color:red}' });
        const out = inlineAssets('<link rel="stylesheet" href="http://localhost/css/ds.css">', php);
        assert.match(out, /<style>body\{color:red\}<\/style>/);
        assert.doesNotMatch(out, /data:text\/css/);
    });

    it('leaves a <link> without rel="stylesheet" untouched', () => {
        const php = makePhp({ '/app/public/css/app.css': 'body{}' });
        const input = '<link href="/css/app.css">';
        assert.equal(inlineAssets(input, php), input);
    });

    it('inlines a script referenced with a bare /path and with a host', () => {
        const php = makePhp({ '/app/public/js/app.js': 'console.log(1)' });
        const bare = inlineAssets('<script src="/js/app.js"></script>', php);
        assert.match(bare, /<script>console\.log\(1\)<\/script>/);

        const host = inlineAssets('<script src="http://localhost/js/app.js"></script>', php);
        assert.match(host, /<script>console\.log\(1\)<\/script>/);
    });

    it('preserves subdirectories in a host-absolute script URL', () => {
        const php = makePhp({ '/app/public/js/map/main.js': 'init()' });
        const out = inlineAssets('<script src="http://localhost/js/map/main.js"></script>', php);
        assert.match(out, /<script>init\(\)<\/script>/);
    });

    it('inlines a host-absolute image URL, preserving its subdirectory', () => {
        const php = makePhp({ '/app/public/img/logo.png': 'PNGDATA' });
        const out = inlineAssets('<img src="http://localhost/img/logo.png">', php);
        const expected = 'data:image/png;base64,' + Buffer.from('PNGDATA', 'binary').toString('base64');
        assert.ok(out.includes(expected), out);
    });

    it('leaves references to missing files untouched', () => {
        const php = makePhp({});
        const input = '<link rel="stylesheet" href="/css/missing.css">';
        assert.equal(inlineAssets(input, php), input);
    });
});
