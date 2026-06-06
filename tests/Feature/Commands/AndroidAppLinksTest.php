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

final class AndroidAppLinksTest extends TestCase
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
        <activity android:name=".MainActivity">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>
</manifest>
XML;
        file_put_contents($this->manifestPath, $manifest);
    }

    #[Test]
    public function writes_an_autoverify_intent_filter_with_a_data_per_domain(): void
    {
        (new ShellConfig())->deepLinks(['myapp.com', 'www.myapp.com']);

        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringContainsString('nativeblade:applinks:start', $manifest);
        self::assertStringContainsString('android:autoVerify="true"', $manifest);
        self::assertStringContainsString('android:scheme="https" android:host="myapp.com"', $manifest);
        self::assertStringContainsString('android:scheme="https" android:host="www.myapp.com"', $manifest);
        // The block sits inside the activity, just before its closing tag.
        self::assertMatchesRegularExpression('/nativeblade:applinks:end\s*-->\s*<\/activity>/', $manifest);
    }

    #[Test]
    public function rerunning_is_idempotent(): void
    {
        (new ShellConfig())->deepLinks(['myapp.com']);

        $this->generator->generate([]);
        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertSame(1, substr_count($manifest, 'nativeblade:applinks:start'));
        self::assertSame(1, substr_count($manifest, 'android:host="myapp.com"'));
    }

    #[Test]
    public function dropping_the_config_removes_the_filter(): void
    {
        (new ShellConfig())->deepLinks(['myapp.com']);
        $this->generator->generate([]);
        self::assertStringContainsString('myapp.com', file_get_contents($this->manifestPath));

        $this->resetAppConfigs();
        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringNotContainsString('nativeblade:applinks', $manifest);
        self::assertStringNotContainsString('myapp.com', $manifest);
    }
}
