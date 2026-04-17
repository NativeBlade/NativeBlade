<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * nativeblade:add <platform>
 *
 * Happy path invokes `npx tauri <platform> init` and patches native config
 * files — out of scope for unit tests. We cover only the validation branches
 * that short-circuit before any process is spawned.
 */
final class AddCommandTest extends TestCase
{
    use WithTempBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempBasePath();
        parent::tearDown();
    }

    #[Test]
    public function unsupported_platform_fails(): void
    {
        $this->artisan('nativeblade:add', ['platform' => 'desktop'])
            ->expectsOutputToContain('Unsupported platform: desktop')
            ->assertExitCode(1);
    }

    #[Test]
    public function platform_argument_is_case_insensitive(): void
    {
        // 'ANDROID' should match 'android' after strtolower(); the command
        // then fails because src-tauri/ doesn't exist in our tempdir.
        $this->artisan('nativeblade:add', ['platform' => 'ANDROID'])
            ->expectsOutputToContain('src-tauri/ not found')
            ->assertExitCode(1);
    }

    #[Test]
    public function missing_src_tauri_fails_with_helpful_hint(): void
    {
        $this->artisan('nativeblade:add', ['platform' => 'android'])
            ->expectsOutputToContain('src-tauri/ not found. Run php artisan nativeblade:install first.')
            ->assertExitCode(1);
    }

    #[Test]
    public function empty_platform_argument_fails(): void
    {
        $this->artisan('nativeblade:add', ['platform' => ''])
            ->expectsOutputToContain('Unsupported platform')
            ->assertExitCode(1);
    }
}
