<?php

namespace NativeBlade\Support;

/**
 * Keeps the app's .gitignore aware of the files NativeBlade generates on every
 * dev run and build, so they never show up as changes.
 */
final class GitIgnore
{
    /** Written by bundle-laravel.js into public/ on every nativeblade:dev and build. */
    public const GENERATED = [
        '/public/laravel-bundle.json',
        '/public/laravel-bundle.json.gz',
        '/public/bundle-meta.json',
        '/public/nativeblade-locale.json',
        '/public/lang',
    ];

    public const MARKER = '# NativeBlade build artifacts';

    /**
     * Append the entries that are missing, under a marker comment. Creates the
     * file when there is none. Returns the entries added.
     *
     * @param  array<int, string>  $entries
     * @return array<int, string>
     */
    public static function ensure(string $path, array $entries = self::GENERATED): array
    {
        $existing = file_exists($path) ? file_get_contents($path) : '';
        $present = array_map('trim', preg_split('/\r?\n/', $existing) ?: []);

        $missing = array_values(array_filter(
            $entries,
            fn (string $entry) => !in_array($entry, $present, true) && !in_array(ltrim($entry, '/'), $present, true),
        ));
        if ($missing === []) {
            return [];
        }

        $block = implode("\n", $missing) . "\n";
        if (!in_array(self::MARKER, $present, true)) {
            $block = self::MARKER . "\n" . $block;
        }

        $content = $existing === '' ? $block : rtrim($existing, "\r\n") . "\n\n" . $block;
        file_put_contents($path, $content);

        return $missing;
    }
}
