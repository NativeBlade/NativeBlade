import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window ??= {};

import { checkForUpdate } from '../../../js/runtime/bundle-push.js';

const URL = 'https://releases.test/version.json';

function setup({ channel, manifest = {}, stored = {}, shellVersion } = {}) {
    globalThis.window.__NB_BUNDLE_PUSH__ = channel ? { url: URL, channel } : { url: URL };
    globalThis.window.__NB_SHELL_VERSION__ = shellVersion;
    globalThis.window.__NB_SHELL_BUNDLE_VERSION__ = undefined;
    globalThis.localStorage = {
        getItem: (key) => (key in stored ? stored[key] : null),
        setItem: () => {},
    };
    globalThis.fetch = async () => ({ json: async () => manifest });
}

function reset() {
    delete globalThis.window.__NB_BUNDLE_PUSH__;
    delete globalThis.window.__NB_SHELL_VERSION__;
    delete globalThis.window.__NB_SHELL_BUNDLE_VERSION__;
    delete globalThis.localStorage;
    delete globalThis.fetch;
}

describe('bundle-push.js/checkForUpdate channels', () => {
    beforeEach(reset);
    afterEach(reset);

    it('default (no channel) reads the top-level bundle entry', async () => {
        setup({
            manifest: {
                bundle: { version: '1.0.1', url: 'stable.gz' },
                channels: { beta: { version: '9.9.9', url: 'beta.gz' } },
            },
        });

        const r = await checkForUpdate();
        assert.equal(r.available, true);
        assert.equal(r.nextVersion, '1.0.1');
        assert.equal(r.url, 'stable.gz');
        assert.equal(r.channel, 'stable');
    });

    it('a beta build reads channels.beta instead of bundle', async () => {
        setup({
            channel: 'beta',
            manifest: {
                bundle: { version: '1.0.1', url: 'stable.gz' },
                channels: { beta: { version: '1.1.0-beta.2', url: 'beta.gz' } },
            },
        });

        const r = await checkForUpdate();
        assert.equal(r.available, true);
        assert.equal(r.nextVersion, '1.1.0-beta.2');
        assert.equal(r.url, 'beta.gz');
        assert.equal(r.channel, 'beta');
    });

    it('a beta build with no channels entry stays put instead of dropping to stable', async () => {
        setup({
            channel: 'beta',
            manifest: { bundle: { version: '1.0.1', url: 'stable.gz' } },
        });

        const r = await checkForUpdate();
        assert.equal(r.available, false);
        assert.equal(r.reason, 'up-to-date');
    });

    it('backward compat: no channel config + manifest with only bundle works', async () => {
        setup({ manifest: { bundle: { version: '2.0.0', url: 'x.gz' } } });

        const r = await checkForUpdate();
        assert.equal(r.available, true);
        assert.equal(r.nextVersion, '2.0.0');
        assert.equal(r.channel, 'stable');
    });
});

describe('bundle-push.js/checkForUpdate version comparison', () => {
    beforeEach(reset);
    afterEach(reset);

    it('treats an identical version string as up-to-date', async () => {
        setup({
            manifest: { bundle: { version: '1.0.1', url: 'x.gz' } },
            stored: { 'nb:bundleVersion': '1.0.1' },
        });

        const r = await checkForUpdate();
        assert.equal(r.available, false);
        assert.equal(r.reason, 'up-to-date');
    });

    it('downloads whenever the string differs, even a lower one (string equality, not ahead/behind)', async () => {
        setup({
            // installed is "higher" than the manifest, yet it still applies
            manifest: { bundle: { version: '1.0.0', url: 'x.gz' } },
            stored: { 'nb:bundleVersion': '1.0.5' },
        });

        const r = await checkForUpdate();
        assert.equal(r.available, true);
        assert.equal(r.nextVersion, '1.0.0');
    });

    it('tracks the installed version per channel (stable key does not satisfy beta)', async () => {
        // The stable key holds the same string, but beta has its own key, which
        // is absent here, so the beta build still sees an update.
        setup({
            channel: 'beta',
            manifest: { channels: { beta: { version: '1.1.0-beta.2', url: 'beta.gz' } } },
            stored: { 'nb:bundleVersion': '1.1.0-beta.2' },
        });

        const r = await checkForUpdate();
        assert.equal(r.available, true);
        assert.equal(r.nextVersion, '1.1.0-beta.2');
    });

    it('uses the per-channel key when it is present', async () => {
        setup({
            channel: 'beta',
            manifest: { channels: { beta: { version: '1.1.0-beta.2', url: 'beta.gz' } } },
            stored: { 'nb:bundleVersion:beta': '1.1.0-beta.2' },
        });

        const r = await checkForUpdate();
        assert.equal(r.available, false);
        assert.equal(r.reason, 'up-to-date');
    });
});

describe('bundle-push.js/checkForUpdate guards', () => {
    beforeEach(reset);
    afterEach(reset);

    it('reports not-configured when no url is set', async () => {
        globalThis.window.__NB_BUNDLE_PUSH__ = {}; // truthy but no url
        const r = await checkForUpdate();
        assert.equal(r.available, false);
        assert.equal(r.reason, 'not-configured');
    });

    it('reports invalid-manifest when the selected entry has no version/url', async () => {
        setup({ manifest: {} });
        const r = await checkForUpdate();
        assert.equal(r.available, false);
        assert.equal(r.reason, 'invalid-manifest');
    });

    it('reports shell-too-old when minShellVersion exceeds the running shell', async () => {
        setup({
            manifest: { bundle: { version: '1.0.1', url: 'x.gz', minShellVersion: '2.0.0' } },
            shellVersion: '1.0.0',
        });

        const r = await checkForUpdate();
        assert.equal(r.available, false);
        assert.equal(r.reason, 'shell-too-old');
        assert.equal(r.requiredShell, '2.0.0');
    });
});
