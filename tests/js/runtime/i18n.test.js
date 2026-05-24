import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

async function loadModuleFresh() {
    const mod = await import(`../../../js/runtime/i18n.js?cachebust=${Date.now()}-${Math.random()}`);
    return mod;
}

function installFetchMock(mapping) {
    globalThis.fetch = async (url) => {
        if (url in mapping) {
            const value = mapping[url];
            if (value === null) return { ok: false, status: 404, json: async () => ({}) };
            return { ok: true, status: 200, json: async () => value };
        }
        return { ok: false, status: 404, json: async () => ({}) };
    };
}

function installDocumentMock() {
    const langStore = { value: '' };
    globalThis.document = {
        documentElement: {
            setAttribute(name, value) {
                if (name === 'lang') langStore.value = value;
            },
            getAttribute(name) {
                if (name === 'lang') return langStore.value;
                return null;
            },
        },
    };
    return langStore;
}

function setNavigatorLanguage(lang) {
    globalThis.navigator = { language: lang };
}

describe('runtime/i18n', () => {
    beforeEach(() => {
        delete globalThis.document;
        delete globalThis.navigator;
        delete globalThis.fetch;
    });

    it('sets documentElement.lang in BCP-47 form when device language matches a translation file', async () => {
        const langStore = installDocumentMock();
        setNavigatorLanguage('pt-BR');
        installFetchMock({
            './nativeblade-locale.json': null,
            './lang/pt_BR.json': { 'splash.loading': 'Iniciando...' },
        });

        const { loadTranslations, t, getResolvedLocale } = await loadModuleFresh();
        await loadTranslations();

        assert.equal(langStore.value, 'pt-BR');
        assert.equal(t('splash.loading'), 'Iniciando...');
        assert.equal(getResolvedLocale(), 'pt_BR');
    });

    it('prefers device language over the bundle defaultLocale', async () => {
        const langStore = installDocumentMock();
        setNavigatorLanguage('en-US');
        installFetchMock({
            './nativeblade-locale.json': { defaultLocale: 'pt_BR' },
            './lang/en.json': { 'splash.loading': 'Loading...' },
            './lang/pt_BR.json': { 'splash.loading': 'Iniciando...' },
        });

        const { loadTranslations, t } = await loadModuleFresh();
        await loadTranslations();

        assert.equal(langStore.value, 'en');
        assert.equal(t('splash.loading'), 'Loading...');
    });

    it('falls back to bundle defaultLocale when the device language has no translation file', async () => {
        const langStore = installDocumentMock();
        setNavigatorLanguage('fr-FR');
        installFetchMock({
            './nativeblade-locale.json': { defaultLocale: 'pt_BR' },
            './lang/pt_BR.json': { 'splash.loading': 'Iniciando...' },
        });

        const { loadTranslations, t } = await loadModuleFresh();
        await loadTranslations();

        assert.equal(langStore.value, 'pt-BR');
        assert.equal(t('splash.loading'), 'Iniciando...');
    });

    it('honors legacy locale key on nativeblade-locale.json for backward compatibility', async () => {
        const langStore = installDocumentMock();
        setNavigatorLanguage('fr-FR');
        installFetchMock({
            './nativeblade-locale.json': { locale: 'pt_BR' },
            './lang/pt_BR.json': { 'splash.loading': 'Iniciando...' },
        });

        const { loadTranslations } = await loadModuleFresh();
        await loadTranslations();

        assert.equal(langStore.value, 'pt-BR');
    });

    it('sets lang to en when no translation file matches anywhere', async () => {
        const langStore = installDocumentMock();
        setNavigatorLanguage('xx-YY');
        installFetchMock({
            './nativeblade-locale.json': null,
        });

        const { loadTranslations } = await loadModuleFresh();
        await loadTranslations();

        assert.equal(langStore.value, 'en');
    });

    it('returns the key untouched when no translation exists', async () => {
        installDocumentMock();
        setNavigatorLanguage('en');
        installFetchMock({});

        const { loadTranslations, t } = await loadModuleFresh();
        await loadTranslations();

        assert.equal(t('missing.key'), 'missing.key');
    });
});
