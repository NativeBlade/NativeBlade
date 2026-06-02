let translations = {};
let resolvedLocale = 'en';

export function t(key, replacements = {}) {
    let text = translations[key] || key;
    for (const [k, v] of Object.entries(replacements)) {
        text = text.replace(':' + k, v);
    }
    return text;
}

/**
 * Resolve the runtime locale and load its translation file.
 *
 * Priority (highest to lowest):
 *   1. Device language (navigator.language).
 *   2. Bundle fallback declared in nativeblade-locale.json (defaultLocale).
 *   3. Hardcoded 'en'.
 *
 * The bundle locale is treated as a fallback, NEVER as a forced override.
 * Forcing a locale globally is hostile to accessibility: a Portuguese bundle
 * loaded on an English VoiceOver device reads Portuguese text with an English
 * voice, which is mush. The device language wins.
 *
 * After resolving, document.documentElement.lang is set to the BCP-47 form
 * (en, pt-BR) so screen readers pick the correct reading voice.
 */
export async function loadTranslations() {
    let userLocale = null, fallback = null;

    try {
        const stored = localStorage.getItem('nb:locale');
        if (stored) userLocale = stored;
    } catch {}

    try {
        const res = await fetch('./nativeblade-locale.json');
        if (res.ok) {
            const json = await res.json();
            userLocale = userLocale || json.locale || null;
            fallback = json.defaultLocale || null;
        }
    } catch {}

    const device = navigator.language || 'en';
    const sources = [userLocale, device, fallback, 'en'].filter(Boolean);

    const candidates = [];
    for (const src of sources) {
        const underscored = src.replace('-', '_');
        candidates.push(underscored, src, src.split('-')[0]);
    }

    for (const lang of candidates) {
        try {
            const res = await fetch(`./lang/${lang}.json`);
            if (res.ok) {
                translations = await res.json();
                resolvedLocale = lang;
                applyLangAttribute(lang);
                return;
            }
        } catch {}
    }

    applyLangAttribute('en');
}

export function getResolvedLocale() {
    return resolvedLocale;
}

function applyLangAttribute(locale) {
    if (typeof document === 'undefined' || !document.documentElement) return;
    const bcp47 = locale.replace('_', '-');
    document.documentElement.setAttribute('lang', bcp47);
}
