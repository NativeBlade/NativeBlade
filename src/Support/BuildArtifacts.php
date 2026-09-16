<?php

namespace NativeBlade\Support;

/**
 * Picks the artifact a build produced out of a Tauri/Gradle/Xcode output tree.
 *
 * Output trees keep artifacts from earlier builds (a debug APK next to a
 * release one, installers from previous versions) and Gradle keeps working
 * copies under intermediates/. Copying every match to the same destination left
 * whichever the directory walk visited last, e.g. an old unsigned release APK
 * in place of the debug APK that was just built.
 *
 * Age is the only reliable signal: Gradle skips rewriting an up-to-date APK, so
 * the right artifact is not necessarily modified during the current build.
 */
class BuildArtifacts
{
    /**
     * For each extension, the newest matching file under $dir, ignoring
     * intermediates/.
     *
     * @param  string[]  $extensions
     * @return array<string, string>  file path keyed by extension
     */
    public static function latest(string $dir, array $extensions): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $newest = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $ext = $file->getExtension();
            if (!in_array($ext, $extensions, true)) continue;

            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/intermediates/')) continue;

            $mtime = $file->getMTime();
            if (!isset($newest[$ext]) || $mtime > $newest[$ext]['mtime']) {
                $newest[$ext] = ['path' => $file->getPathname(), 'mtime' => $mtime];
            }
        }

        return array_map(fn (array $match) => $match['path'], $newest);
    }
}
