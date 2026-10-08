<?php

namespace NativeBlade\Support;

/**
 * Tells whether the active locale has translation files to back it. Laravel
 * silently falls back to the fallback locale when a key is missing, so an app
 * running in 'pt_BR' with no lang/pt_BR shows English and nobody notices until
 * a tester does. In development the shell reports this loudly instead.
 */
final class LocaleCheck
{
    /** Resolved by the framework's own translations even without a lang/ directory. */
    private const BUILT_IN = ['en'];

    public static function hasTranslations(string $locale, string $langPath): bool
    {
        $candidates = array_unique([
            $locale,
            str_replace('-', '_', $locale),
            str_replace('_', '-', $locale),
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (is_dir($langPath . '/' . $candidate) || is_file($langPath . '/' . $candidate . '.json')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A message describing why the locale will not translate, or null when it
     * is fine.
     */
    public static function problem(string $locale, string $fallback, string $langPath): ?string
    {
        if ($locale === '' || in_array($locale, self::BUILT_IN, true)) {
            return null;
        }

        if (self::hasTranslations($locale, $langPath)) {
            return null;
        }

        return sprintf(
            "Locale '%s' is active but lang/ has no '%s' directory or '%s.json' file; strings fall back to '%s'. "
            . 'Add the translations, or change APP_LOCALE / NativeBlade::setLanguage().',
            $locale,
            $locale,
            $locale,
            $fallback,
        );
    }
}
