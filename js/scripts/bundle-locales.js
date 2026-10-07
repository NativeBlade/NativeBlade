// Which locales the bundle keeps for Carbon (vendor/nesbot/carbon/src/Carbon/Lang)
// and Symfony (vendor/symfony/*/Resources/translations). The other ~800 files
// are dropped to keep the bundle small.
//
// Standalone module (no runtime imports) so bundle-laravel.js's locale rules can
// be unit-tested without running the bundler.

import { readFileSync, readdirSync } from 'fs';
import { join } from 'path';

/**
 * Locales the app can run in: `en`, APP_LOCALE and APP_FALLBACK_LOCALE from
 * .env, and every locale the app translates in lang/ (directories such as
 * lang/pt_BR, and lang/pt_BR.json or .php files). An app that switches locale at
 * runtime has nothing in .env for the extra languages, but it does have them in
 * lang/, and Carbon's day and month names must ship for each of them or
 * translatedFormat() silently falls back to English.
 *
 * @returns {string[]}
 */
export function resolveActiveLocales(root) {
    const locales = new Set(['en']);

    try {
        const env = readFileSync(join(root, '.env'), 'utf-8');
        for (const key of ['APP_LOCALE', 'APP_FALLBACK_LOCALE']) {
            const match = env.match(new RegExp(`^${key}=(\\S+)`, 'm'));
            if (match) locales.add(normalize(match[1].replace(/['"]/g, '')));
        }
    } catch {}

    try {
        for (const entry of readdirSync(join(root, 'lang'), { withFileTypes: true })) {
            if (entry.name.startsWith('.')) continue;
            // lang/vendor/<package>/<locale> holds published package translations,
            // not an app locale.
            if (entry.isDirectory() && entry.name === 'vendor') continue;

            const name = entry.isDirectory()
                ? entry.name
                : (entry.name.match(/^(.+)\.(json|php)$/) || [])[1];
            if (name) locales.add(normalize(name));
        }
    } catch {}

    return [...locales];
}

// Laravel apps sometimes name locales with a hyphen (pt-BR); Carbon and Symfony
// files use an underscore.
function normalize(locale) {
    return locale.replace(/-/g, '_');
}

/**
 * Whether a vendor locale file should stay out of the bundle. A file matches an
 * active locale when it is the same locale, a regional variant of it (es.php
 * active keeps es_MX.php) or the base locale a regional variant depends on
 * (pt_BR active keeps pt.php, which pt_BR.php requires).
 *
 * @param {string}   rel         project-relative path with forward slashes
 * @param {string[]} localeList  from resolveActiveLocales()
 */
export function isLocaleFileToSkip(rel, localeList) {
    const carbon = rel.match(/vendor\/nesbot\/carbon\/src\/Carbon\/Lang\/([^/]+?)\.php$/);
    if (carbon) return !matchesAny(carbon[1], localeList);

    const symfony = rel.match(/vendor\/symfony\/[^/]+\/Resources\/translations\/[^/]+\.([^.]+)\.(xlf|yaml|yml|php)$/);
    if (symfony) return !matchesAny(symfony[1], localeList);

    return false;
}

function matchesAny(locale, localeList) {
    return localeList.some((l) => locale === l || locale.startsWith(l + '_') || l.startsWith(locale + '_'));
}
