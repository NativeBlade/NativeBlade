<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Support;

use NativeBlade\Support\BuildArtifacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BuildArtifacts picks which file `nativeblade:build` copies to build/<platform>.
 * Output trees keep artifacts from earlier builds, so the choice must depend on
 * age, not on the order the directory walk happens to visit files.
 */
final class BuildArtifactsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nb-artifacts-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function artifact(string $rel, int $mtime): string
    {
        $path = $this->root . '/' . $rel;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $rel);
        touch($path, $mtime);

        return $path;
    }

    #[Test]
    public function picks_the_newest_file_per_extension_regardless_of_directory_order(): void
    {
        // The reported case: a debug preview build next to an older release build.
        // "release" sorts after "debug", so the old walk copied the release APK last.
        $debug = $this->artifact('app/build/outputs/apk/universal/debug/app-universal-debug.apk', 2_000);
        $this->artifact('app/build/outputs/apk/universal/release/app-universal-release-unsigned.apk', 1_000);

        $result = BuildArtifacts::latest($this->root, ['apk']);

        self::assertSame(['apk'], array_keys($result));
        self::assertSame(realpath($debug), realpath($result['apk']));
    }

    #[Test]
    public function returns_one_artifact_for_each_extension(): void
    {
        $apk = $this->artifact('outputs/apk/app.apk', 2_000);
        $aab = $this->artifact('outputs/bundle/app.aab', 2_000);

        $result = BuildArtifacts::latest($this->root, ['apk', 'aab']);

        self::assertSame(realpath($apk), realpath($result['apk']));
        self::assertSame(realpath($aab), realpath($result['aab']));
    }

    #[Test]
    public function ignores_gradle_intermediates_even_when_they_are_newer(): void
    {
        $real = $this->artifact('app/build/outputs/apk/universal/debug/app-universal-debug.apk', 2_000);
        $this->artifact('app/build/intermediates/apk/universal/debug/app-universal-debug.apk', 3_000);

        $result = BuildArtifacts::latest($this->root, ['apk']);

        self::assertSame(realpath($real), realpath($result['apk']));
    }

    #[Test]
    public function ignores_other_extensions_and_missing_directories(): void
    {
        $this->artifact('outputs/mapping.txt', 2_000);

        self::assertSame([], BuildArtifacts::latest($this->root, ['apk']));
        self::assertSame([], BuildArtifacts::latest($this->root . '/missing', ['apk']));
    }
}
