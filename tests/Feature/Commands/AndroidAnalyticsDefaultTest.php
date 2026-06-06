<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\AndroidConfigGenerator;
use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Console\Output\NullOutput;

final class AndroidAnalyticsDefaultTest extends TestCase
{
    use WithTempBasePath;

    private AndroidConfigGenerator $generator;
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
        $this->resetAppConfigs();

        $dir = base_path('src-tauri/gen/android/app/src/main');
        mkdir($dir, 0755, true);
        $this->manifestPath = $dir . '/AndroidManifest.xml';
        $this->writeManifest();

        $this->generator = new AndroidConfigGenerator($this->makeDummyCommand());
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

    private function makeDummyCommand(): Command
    {
        $cmd = new class extends Command {
            protected $signature = 'dummy:dummy';
        };
        $cmd->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new NullOutput()
        ));
        return $cmd;
    }

    private function writeManifest(): void
    {
        $manifest = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application>
        <activity android:name=".MainActivity" />
    </application>
</manifest>
XML;
        file_put_contents($this->manifestPath, $manifest);
    }

    #[Test]
    public function writes_collection_disabled_meta_data_for_consent_first(): void
    {
        (new ShellConfig())->analytics(collectionEnabledByDefault: false);

        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringContainsString('nativeblade:analytics:start', $manifest);
        self::assertStringContainsString(
            'android:name="firebase_analytics_collection_enabled" android:value="false"',
            $manifest
        );
    }

    #[Test]
    public function defaults_collection_enabled_when_not_consent_first(): void
    {
        (new ShellConfig())->analytics(autoScreenTracking: true);

        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringContainsString('android:value="true"', $manifest);
    }

    #[Test]
    public function removes_the_block_when_analytics_not_configured(): void
    {
        (new ShellConfig())->analytics(collectionEnabledByDefault: false);
        $this->generator->generate([]);
        self::assertStringContainsString('nativeblade:analytics', file_get_contents($this->manifestPath));

        $this->resetAppConfigs();
        $this->generator->generate([]);

        self::assertStringNotContainsString('nativeblade:analytics', file_get_contents($this->manifestPath));
    }
}
