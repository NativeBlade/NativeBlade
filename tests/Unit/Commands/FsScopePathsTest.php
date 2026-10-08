<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Commands;

use NativeBlade\Commands\Config\PluginsConfigGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The log file lives in $APPLOG, which apps created before it was added to the
 * capabilities stub never allowed. nativeblade:config tops up the existing
 * fs:scope object so those apps can write it too.
 */
final class FsScopePathsTest extends TestCase
{
    private const PATHS = ['$APPLOG', '$APPLOG/**'];

    #[Test]
    public function adds_missing_paths_to_an_existing_fs_scope(): void
    {
        $perms = [
            ['identifier' => 'shell:allow-execute', 'allow' => [['name' => 'sh', 'cmd' => 'sh', 'args' => true]]],
            ['identifier' => 'fs:scope', 'allow' => [['path' => '$APPDATA'], ['path' => '$APPDATA/**']]],
        ];

        $result = PluginsConfigGenerator::ensureFsScopePaths($perms, self::PATHS);

        self::assertSame($perms[0], $result[0], 'other scoped permissions are untouched');
        self::assertSame(
            [['path' => '$APPDATA'], ['path' => '$APPDATA/**'], ['path' => '$APPLOG'], ['path' => '$APPLOG/**']],
            $result[1]['allow']
        );
    }

    #[Test]
    public function is_idempotent_and_accepts_string_entries(): void
    {
        $perms = [['identifier' => 'fs:scope', 'allow' => ['$APPLOG', ['path' => '$APPLOG/**']]]];

        $once = PluginsConfigGenerator::ensureFsScopePaths($perms, self::PATHS);
        $twice = PluginsConfigGenerator::ensureFsScopePaths($once, self::PATHS);

        self::assertSame($perms, $once);
        self::assertSame($once, $twice);
    }

    #[Test]
    public function does_nothing_without_an_fs_scope_object(): void
    {
        $perms = [['identifier' => 'shell:allow-open']];

        self::assertSame($perms, PluginsConfigGenerator::ensureFsScopePaths($perms, self::PATHS));
        self::assertSame([], PluginsConfigGenerator::ensureFsScopePaths([], self::PATHS));
    }

    #[Test]
    public function the_stub_already_allows_the_log_directory(): void
    {
        $stub = json_decode(file_get_contents(__DIR__ . '/../../../stubs/capabilities/default.json'), true);
        $scope = null;
        foreach ($stub['permissions'] as $perm) {
            if (is_array($perm) && ($perm['identifier'] ?? null) === 'fs:scope') {
                $scope = $perm;
            }
        }

        self::assertNotNull($scope);
        $paths = array_column($scope['allow'], 'path');
        foreach (self::PATHS as $path) {
            self::assertContains($path, $paths);
        }
    }
}
