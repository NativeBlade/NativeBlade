<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * nativeblade:php <version>
 *
 * - Validates X.Y format
 * - Writes resources/js/php-loader.js that re-exports the correct
 *   @php-wasm/web-<major>-<minor> package.
 */
final class PhpVersionCommandTest extends TestCase
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
    public function valid_version_writes_the_loader_file_with_correct_package(): void
    {
        $this->artisan('nativeblade:php', ['version' => '8.3'])
            ->assertExitCode(0);

        $loader = resource_path('js/php-loader.js');
        self::assertFileExists($loader);

        $contents = file_get_contents($loader);
        self::assertStringContainsString("@php-wasm/web-8-3", $contents);
        self::assertStringContainsString("getPHPLoaderModule", $contents);
    }

    #[Test]
    public function valid_version_8_4_rewrites_package_name_with_hyphen(): void
    {
        $this->artisan('nativeblade:php', ['version' => '8.4'])
            ->assertExitCode(0);

        self::assertStringContainsString(
            '@php-wasm/web-8-4',
            file_get_contents(resource_path('js/php-loader.js'))
        );
    }

    #[Test]
    public function valid_version_8_5_rewrites_package_name_with_hyphen(): void
    {
        $this->artisan('nativeblade:php', ['version' => '8.5'])
            ->assertExitCode(0);

        self::assertStringContainsString(
            '@php-wasm/web-8-5',
            file_get_contents(resource_path('js/php-loader.js'))
        );
    }

    #[Test]
    public function invalid_version_format_fails_with_error(): void
    {
        $this->artisan('nativeblade:php', ['version' => '8'])
            ->expectsOutputToContain('Invalid version')
            ->assertExitCode(1);

        self::assertFileDoesNotExist(resource_path('js/php-loader.js'));
    }

    #[Test]
    public function version_with_patch_part_is_rejected(): void
    {
        $this->artisan('nativeblade:php', ['version' => '8.3.2'])
            ->expectsOutputToContain('Invalid version')
            ->assertExitCode(1);
    }

    #[Test]
    public function no_argument_defaults_to_current_runtime_version(): void
    {
        $this->artisan('nativeblade:php')
            ->assertExitCode(0);

        $expected = '@php-wasm/web-' . PHP_MAJOR_VERSION . '-' . PHP_MINOR_VERSION;
        self::assertStringContainsString($expected, file_get_contents(resource_path('js/php-loader.js')));
    }
}
