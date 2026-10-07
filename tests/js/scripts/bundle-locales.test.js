import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { resolveActiveLocales, isLocaleFileToSkip } from '../../../js/scripts/bundle-locales.js';

const carbon = (locale) => `vendor/nesbot/carbon/src/Carbon/Lang/${locale}.php`;
const symfony = (locale) => `vendor/symfony/validator/Resources/translations/validators.${locale}.xlf`;

describe('bundle-locales', () => {
    let root;

    beforeEach(() => {
        root = mkdtempSync(join(tmpdir(), 'nb-locales-'));
    });

    afterEach(() => {
        rmSync(root, { recursive: true, force: true });
    });

    describe('resolveActiveLocales', () => {
        it('always keeps en, even with no .env and no lang/', () => {
            assert.deepEqual(resolveActiveLocales(root), ['en']);
        });

        it('reads APP_LOCALE and APP_FALLBACK_LOCALE from .env, quotes included', () => {
            writeFileSync(join(root, '.env'), 'APP_NAME=x\nAPP_LOCALE="pt_BR"\nAPP_FALLBACK_LOCALE=es\n');

            assert.deepEqual(resolveActiveLocales(root).sort(), ['en', 'es', 'pt_BR']);
        });

        // The reported case: a scaffolded app translates the UI in lang/ and
        // switches locale at runtime, with nothing in .env. Carbon's pt_BR and
        // es files were dropped and translatedFormat() fell back to English.
        it('adds every locale the app translates in lang/', () => {
            mkdirSync(join(root, 'lang', 'es'), { recursive: true });
            writeFileSync(join(root, 'lang', 'es', 'validation.php'), '<?php return [];');
            writeFileSync(join(root, 'lang', 'en.json'), '{}');
            writeFileSync(join(root, 'lang', 'pt_BR.json'), '{}');
            writeFileSync(join(root, 'lang', 'fr.php'), '<?php return [];');

            assert.deepEqual(resolveActiveLocales(root).sort(), ['en', 'es', 'fr', 'pt_BR']);
        });

        it('ignores dotfiles and published package translations under lang/vendor', () => {
            mkdirSync(join(root, 'lang', 'vendor', 'some-package', 'de'), { recursive: true });
            writeFileSync(join(root, 'lang', '.gitkeep'), '');
            writeFileSync(join(root, 'lang', 'README.md'), 'notes');

            assert.deepEqual(resolveActiveLocales(root), ['en']);
        });

        it('normalizes hyphenated locale names to the underscore form Carbon uses', () => {
            mkdirSync(join(root, 'lang', 'pt-BR'), { recursive: true });

            assert.deepEqual(resolveActiveLocales(root).sort(), ['en', 'pt_BR']);
        });
    });

    describe('isLocaleFileToSkip', () => {
        it('keeps an active locale, its base locale and its regional variants', () => {
            const locales = ['en', 'pt_BR', 'es'];

            assert.equal(isLocaleFileToSkip(carbon('pt_BR'), locales), false);
            assert.equal(isLocaleFileToSkip(carbon('pt'), locales), false, 'pt_BR.php requires pt.php');
            assert.equal(isLocaleFileToSkip(carbon('es_MX'), locales), false, 'a variant of an active base locale');
            assert.equal(isLocaleFileToSkip(carbon('en_GB'), locales), false);
        });

        it('drops locales the app does not use', () => {
            const locales = ['en', 'pt_BR'];

            assert.equal(isLocaleFileToSkip(carbon('de'), locales), true);
            assert.equal(isLocaleFileToSkip(carbon('pt_PT'), locales), true, 'a sibling variant is not needed');
            assert.equal(isLocaleFileToSkip(symfony('fr'), locales), true);
        });

        it('applies the same rule to Symfony translation files', () => {
            const locales = ['en', 'es'];

            assert.equal(isLocaleFileToSkip(symfony('es'), locales), false);
            assert.equal(isLocaleFileToSkip('vendor/symfony/validator/Resources/translations/validators.es_419.yml', locales), false);
            assert.equal(isLocaleFileToSkip(symfony('it'), locales), true);
        });

        it('never skips files outside the Carbon and Symfony locale folders', () => {
            assert.equal(isLocaleFileToSkip('vendor/nesbot/carbon/src/Carbon/Carbon.php', ['en']), false);
            assert.equal(isLocaleFileToSkip('lang/de.json', ['en']), false);
        });
    });
});
