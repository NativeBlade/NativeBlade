<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\IosConfig;
use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

final class DeepLinksCommandTest extends TestCase
{
    use WithTempBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
        $this->resetAppConfigs();
    }

    protected function tearDown(): void
    {
        $this->resetAppConfigs();
        $this->tearDownTempBasePath();
        parent::tearDown();
    }

    private function resetAppConfigs(): void
    {
        $ref = new ReflectionClass(ShellConfig::class);
        $p = $ref->getProperty('appConfigs');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    private function configure(): void
    {
        $c = new ShellConfig();
        $c->deepLinks(['myapp.com', 'www.myapp.com']);
        $c->android(fn (AndroidConfig $a) => $a->identifier('com.myapp.app'));
        $c->ios(fn (IosConfig $i) => $i->identifier('com.myapp.ios'));
    }

    #[Test]
    public function generates_both_well_known_files_from_config_and_flags(): void
    {
        $this->configure();

        $this->artisan('nativeblade:deeplinks', [
            '--team' => 'ABCDE12345',
            '--fingerprint' => 'AA:BB:CC:DD',
        ])->assertSuccessful();

        $base = base_path('public/.well-known');

        $assetlinks = file_get_contents($base . '/assetlinks.json');
        self::assertStringContainsString('"package_name": "com.myapp.app"', $assetlinks);
        self::assertStringContainsString('AA:BB:CC:DD', $assetlinks);
        self::assertStringContainsString('delegate_permission/common.handle_all_urls', $assetlinks);

        $aasa = file_get_contents($base . '/apple-app-site-association');
        self::assertStringContainsString('ABCDE12345.com.myapp.ios', $aasa);
        self::assertStringContainsString('applinks', $aasa);

        // The Apple file is served without an extension.
        self::assertFileDoesNotExist($base . '/apple-app-site-association.json');
    }

    #[Test]
    public function scaffolds_placeholders_when_flags_are_omitted(): void
    {
        $this->configure();

        $this->artisan('nativeblade:deeplinks')->assertSuccessful();

        $base = base_path('public/.well-known');
        self::assertStringContainsString(
            'REPLACE_WITH_YOUR_SHA256_FINGERPRINT',
            file_get_contents($base . '/assetlinks.json')
        );
        self::assertStringContainsString(
            'YOUR_TEAM_ID',
            file_get_contents($base . '/apple-app-site-association')
        );
    }

    #[Test]
    public function fails_when_no_domains_are_configured(): void
    {
        $this->artisan('nativeblade:deeplinks')->assertFailed();
    }
}
