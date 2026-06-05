<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\AndroidConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

final class AndroidMetaDataTest extends TestCase
{
    use WithTempBasePath;

    private AndroidConfigGenerator $generator;
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        $dir = base_path('src-tauri/gen/android/app/src/main');
        mkdir($dir, 0755, true);
        $this->manifestPath = $dir . '/AndroidManifest.xml';
        $this->writeManifest();

        $this->generator = new AndroidConfigGenerator($this->makeDummyCommand());
    }

    protected function tearDown(): void
    {
        $this->tearDownTempBasePath();
        parent::tearDown();
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
    public function writes_meta_data_entries_inside_application(): void
    {
        $this->generator->generate([
            'manifestMetaData' => [
                'com.google.android.gms.ads.APPLICATION_ID' => 'ca-app-pub-xxx~yyy',
                'com.example.flag' => true,
            ],
        ]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringContainsString('nativeblade:meta:start', $manifest);
        self::assertStringContainsString('android:name="com.google.android.gms.ads.APPLICATION_ID"', $manifest);
        self::assertStringContainsString('android:value="ca-app-pub-xxx~yyy"', $manifest);
        // Booleans are written as the string "true"/"false".
        self::assertStringContainsString('android:value="true"', $manifest);
        // The block sits just before the closing application tag.
        self::assertMatchesRegularExpression('/nativeblade:meta:end\s*-->\s*<\/application>/', $manifest);
    }

    #[Test]
    public function reruns_replace_the_block_idempotently(): void
    {
        $payload = ['manifestMetaData' => ['com.example.A' => '1']];

        $this->generator->generate($payload);
        $this->generator->generate($payload);

        $manifest = file_get_contents($this->manifestPath);
        self::assertSame(1, substr_count($manifest, 'nativeblade:meta:start'));
        self::assertSame(1, substr_count($manifest, 'com.example.A'));
    }

    #[Test]
    public function dropping_the_config_removes_the_block(): void
    {
        $this->generator->generate(['manifestMetaData' => ['com.example.A' => '1']]);
        self::assertStringContainsString('com.example.A', file_get_contents($this->manifestPath));

        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringNotContainsString('nativeblade:meta', $manifest);
        self::assertStringNotContainsString('com.example.A', $manifest);
    }
}
