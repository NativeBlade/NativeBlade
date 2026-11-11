<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * nativeblade:doctor scans the app for PHP that php-wasm cannot run or
 * pause. Exit code 1 only for problems (code that ends the instance on the
 * Asyncify build); warnings alone keep exit code 0 so a CI step can decide.
 */
final class DoctorCommandTest extends TestCase
{
    use WithTempBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
        mkdir(base_path('app/Services'), 0755, true);
        mkdir(base_path('resources/views'), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tearDownTempBasePath();
        parent::tearDown();
    }

    #[Test]
    public function reports_a_clean_app_and_succeeds(): void
    {
        file_put_contents(base_path('app/Services/Clean.php'), "<?php\nclass Clean { public function run() { return array_map(fn (\$x) => \$x, []); } }");

        $this->artisan('nativeblade:doctor')
            ->expectsOutputToContain('No incompatible code found in 1 PHP files.')
            ->assertExitCode(0);
    }

    #[Test]
    public function fails_on_a_call_php_wasm_cannot_pause(): void
    {
        file_put_contents(base_path('app/Services/Sync.php'), "<?php\nclass Sync { public function run() {\n  sleep(2);\n} }");

        // One expectation per output line: each one consumes the line it matches.
        $this->artisan('nativeblade:doctor')
            ->expectsOutputToContain('app/Services/Sync.php:3  sleep() pauses PHP inside php-wasm')
            ->expectsOutputToContain('1 problem, 0 warnings in 1 file.')
            ->assertExitCode(1);
    }

    #[Test]
    public function warnings_alone_keep_the_exit_code_at_zero(): void
    {
        file_put_contents(base_path('app/Services/Sort.php'), "<?php\nusort(\$a, fn (\$x, \$y) => Http::get(\$x) <=> 0);");

        $this->artisan('nativeblade:doctor')
            ->expectsOutputToContain('Http::get() inside the callback of usort()')
            ->expectsOutputToContain('0 problems, 1 warning in 1 file.')
            ->assertExitCode(0);
    }

    #[Test]
    public function compiles_blade_views(): void
    {
        file_put_contents(base_path('resources/views/home.blade.php'), "<div>{{ usleep(10) }}</div>");

        $this->artisan('nativeblade:doctor')
            ->expectsOutputToContain('resources/views/home.blade.php:1')
            ->assertExitCode(1);
    }

    #[Test]
    public function prints_json_with_relative_paths(): void
    {
        file_put_contents(base_path('app/Services/Sync.php'), "<?php\nfsockopen('a', 80);");

        $exit = \Illuminate\Support\Facades\Artisan::call('nativeblade:doctor', ['--json' => true]);
        $json = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        self::assertSame(1, $exit);
        self::assertSame(1, $json['files']);
        self::assertSame(1, $json['errors']);
        self::assertSame(0, $json['warnings']);
        self::assertSame('app/Services/Sync.php', $json['findings'][0]['file']);
        self::assertSame(2, $json['findings'][0]['line']);
        self::assertSame('suspension', $json['findings'][0]['rule']);
    }

    #[Test]
    public function scans_third_party_vendor_packages_by_default_and_skips_the_framework_families(): void
    {
        mkdir(base_path('vendor/acme/lib/src'), 0755, true);
        mkdir(base_path('vendor/laravel/framework/src'), 0755, true);
        file_put_contents(base_path('vendor/acme/lib/src/Client.php'), "<?php\nusleep(5);");
        file_put_contents(base_path('vendor/laravel/framework/src/Lock.php'), "<?php\nusleep(5);");

        $this->artisan('nativeblade:doctor')
            ->expectsOutputToContain('and 1 vendor package: acme/lib')
            ->expectsOutputToContain('vendor/acme/lib/src/Client.php:2')
            ->expectsOutputToContain('1 problem, 0 warnings in 1 file.')
            ->assertExitCode(1);

        $this->artisan('nativeblade:doctor', ['--vendor' => true])
            ->expectsOutputToContain('2 problems, 0 warnings in 2 files.')
            ->assertExitCode(1);

        $this->artisan('nativeblade:doctor', ['--path' => ['app']])
            ->expectsOutputToContain('No incompatible code found in 0 PHP files.')
            ->assertExitCode(0);
    }

    #[Test]
    public function skips_what_the_framework_requires_and_dev_packages_using_composer_installed_json(): void
    {
        foreach (['acme/lib', 'acme/transitive', 'acme/devtool', 'laravel/framework'] as $name) {
            mkdir(base_path("vendor/{$name}/src"), 0755, true);
            file_put_contents(base_path("vendor/{$name}/src/A.php"), "<?php\nsleep(1);");
        }
        mkdir(base_path('vendor/composer'), 0755, true);
        file_put_contents(base_path('vendor/composer/installed.json'), json_encode([
            'packages' => [
                ['name' => 'laravel/framework', 'require' => ['acme/transitive' => '^1.0'], 'install-path' => '../laravel/framework'],
                ['name' => 'acme/transitive', 'require' => [], 'install-path' => '../acme/transitive'],
                ['name' => 'acme/lib', 'require' => ['php' => '^8.2'], 'install-path' => '../acme/lib'],
                ['name' => 'acme/devtool', 'require' => [], 'install-path' => '../acme/devtool'],
            ],
            'dev-package-names' => ['acme/devtool'],
        ]));

        $this->artisan('nativeblade:doctor')
            ->expectsOutputToContain('and 1 vendor package: acme/lib')
            ->expectsOutputToContain('1 problem, 0 warnings in 1 file.')
            ->assertExitCode(1);

        $this->artisan('nativeblade:doctor', ['--vendor' => true])
            ->expectsOutputToContain('4 problems, 0 warnings in 4 files.')
            ->assertExitCode(1);
    }

    #[Test]
    public function scans_only_the_requested_paths(): void
    {
        file_put_contents(base_path('app/Services/Sync.php'), "<?php\nsleep(1);");
        mkdir(base_path('lib'), 0755, true);
        file_put_contents(base_path('lib/Other.php'), "<?php\nreturn 1;");

        $this->artisan('nativeblade:doctor', ['--path' => ['lib', 'missing']])
            ->expectsOutputToContain('Path not found: missing')
            ->expectsOutputToContain('No incompatible code found in 1 PHP files.')
            ->assertExitCode(0);
    }
}
