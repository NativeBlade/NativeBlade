<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * nativeblade:build <platform>
 *
 * The happy path runs tauri/npm subprocesses, so only the validation branches
 * are covered here — the cases that fail before any shell-out.
 */
final class BuildCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        // reset ShellConfig's static appConfigs so tests don't leak version state
        $ref = new ReflectionClass(ShellConfig::class);
        $p = $ref->getProperty('appConfigs');
        $p->setAccessible(true);
        $p->setValue(null, []);

        parent::tearDown();
    }

    #[Test]
    public function invalid_platform_fails_before_any_build_work(): void
    {
        $this->artisan('nativeblade:build', ['platform' => 'web'])
            ->expectsOutputToContain('Invalid platform: web')
            ->assertExitCode(1);
    }

    #[Test]
    public function empty_platform_fails(): void
    {
        $this->artisan('nativeblade:build', ['platform' => ''])
            ->expectsOutputToContain('Invalid platform')
            ->assertExitCode(1);
    }

    #[Test]
    public function desktop_without_registered_version_fails_with_ship_config_error(): void
    {
        // No desktop config registered → ShellConfig::getVersion throws
        // → BuildCommand catches and exits with FAILURE.
        $this->artisan('nativeblade:build', ['platform' => 'desktop'])
            ->expectsOutputToContain("Version not configured for 'desktop'")
            ->assertExitCode(1);
    }

    #[Test]
    public function android_without_registered_version_fails_with_ship_config_error(): void
    {
        $this->artisan('nativeblade:build', ['platform' => 'android'])
            ->expectsOutputToContain("Version not configured for 'android'")
            ->assertExitCode(1);
    }
}
