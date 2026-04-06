let translations = {};

export function t(key, replacements = {}) {
    let text = translations[key] || key;
    for (const [k, v] of Object.entries(replacements)) {
        text = text.replace(':' + k, v);
    }
    return text;
}

export async function loadTranslations() {
    let locale = null;

    try {
        const res = await fetch('./nativeblade-locale.json');
        if (res.ok) locale = (await res.json()).locale;
    } catch {}

    const raw = locale || navigator.language || 'en';
    const underscored = raw.replace('-', '_');
    const candidates = [underscored, raw, raw.split('-')[0], 'en'];

    for (const lang of candidates) {
        try {
            const res = await fetch(`./lang/${lang}.json`);
            if (res.ok) {
                translations = await res.json();
                return;
            }
        } catch {}
    }
}
