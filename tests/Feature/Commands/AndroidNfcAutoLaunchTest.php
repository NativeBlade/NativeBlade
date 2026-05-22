<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\AndroidConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

final class AndroidNfcAutoLaunchTest extends TestCase
{
    use WithTempBasePath;

    private AndroidConfigGenerator $generator;
    private string $manifestPath;
    private string $techFilterPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        $manifestDir = base_path('src-tauri/gen/android/app/src/main');
        mkdir($manifestDir, 0755, true);
        $this->manifestPath = $manifestDir . '/AndroidManifest.xml';
        $this->techFilterPath = $manifestDir . '/res/xml/nfc_tech_filter.xml';

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

    private function writeManifest(string $activityBody = ''): void
    {
        $body = $activityBody !== '' ? $activityBody : <<<XML
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
XML;
        $manifest = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application>
        <activity android:name=".MainActivity">
{$body}
        </activity>
    </application>
</manifest>
XML;
        file_put_contents($this->manifestPath, $manifest);
    }

    #[Test]
    public function without_declaration_strips_legacy_nfc_block_and_removes_tech_filter(): void
    {
        $legacy = <<<XML
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
            <!-- NFC PLUGIN. AUTO-GENERATED. DO NOT REMOVE. -->
            <intent-filter>
                <action android:name="android.nfc.action.TAG_DISCOVERED" />
                <category android:name="android.intent.category.DEFAULT" />
            </intent-filter>
            <meta-data
                android:name="android.nfc.action.TECH_DISCOVERED"
                android:resource="@xml/nfc_tech_filter" />
            <!-- NFC PLUGIN. AUTO-GENERATED. DO NOT REMOVE. -->
XML;
        $this->writeManifest($legacy);
        mkdir(dirname($this->techFilterPath), 0755, true);
        file_put_contents($this->techFilterPath, '<stale/>');

        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringNotContainsString('TAG_DISCOVERED', $manifest);
        self::assertStringNotContainsString('TECH_DISCOVERED', $manifest);
        self::assertStringNotContainsString('NFC PLUGIN', $manifest);
        self::assertFileDoesNotExist($this->techFilterPath);
    }

    #[Test]
    public function nfc_auto_launch_with_any_tag_emits_tag_discovered_filter(): void
    {
        $this->writeManifest();

        $this->generator->generate([
            'nfcAutoLaunch' => ['anyTag' => true, 'techs' => []],
        ]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringContainsString('nativeblade:nfc:start', $manifest);
        self::assertStringContainsString('android.nfc.action.TAG_DISCOVERED', $manifest);
        self::assertStringNotContainsString('TECH_DISCOVERED', $manifest);
        self::assertFileDoesNotExist($this->techFilterPath);
    }

    #[Test]
    public function nfc_auto_launch_with_techs_writes_tech_filter_and_meta_data(): void
    {
        $this->writeManifest();

        $this->generator->generate([
            'nfcAutoLaunch' => ['anyTag' => false, 'techs' => ['IsoDep', 'MifareClassic']],
        ]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringContainsString('TECH_DISCOVERED', $manifest);
        self::assertStringContainsString('@xml/nfc_tech_filter', $manifest);
        self::assertStringNotContainsString('TAG_DISCOVERED', $manifest);

        self::assertFileExists($this->techFilterPath);
        $techXml = file_get_contents($this->techFilterPath);
        self::assertStringContainsString('android.nfc.tech.IsoDep', $techXml);
        self::assertStringContainsString('android.nfc.tech.MifareClassic', $techXml);
        self::assertStringNotContainsString('NfcA', $techXml);
    }

    #[Test]
    public function rerunning_generator_replaces_previous_nfc_block_idempotently(): void
    {
        $this->writeManifest();

        $this->generator->generate([
            'nfcAutoLaunch' => ['anyTag' => true, 'techs' => ['IsoDep']],
        ]);

        $firstRun = file_get_contents($this->manifestPath);
        self::assertSame(1, substr_count($firstRun, 'nativeblade:nfc:start'));
        self::assertSame(1, substr_count($firstRun, 'TAG_DISCOVERED'));

        $this->generator->generate([
            'nfcAutoLaunch' => ['anyTag' => true, 'techs' => ['IsoDep']],
        ]);

        $secondRun = file_get_contents($this->manifestPath);
        self::assertSame(1, substr_count($secondRun, 'nativeblade:nfc:start'));
        self::assertSame(1, substr_count($secondRun, 'TAG_DISCOVERED'));
    }

    #[Test]
    public function dropping_declaration_after_a_previous_inject_removes_the_block(): void
    {
        $this->writeManifest();

        $this->generator->generate([
            'nfcAutoLaunch' => ['anyTag' => true, 'techs' => ['IsoDep']],
        ]);
        self::assertStringContainsString('TAG_DISCOVERED', file_get_contents($this->manifestPath));

        $this->generator->generate([]);

        $manifest = file_get_contents($this->manifestPath);
        self::assertStringNotContainsString('TAG_DISCOVERED', $manifest);
        self::assertStringNotContainsString('TECH_DISCOVERED', $manifest);
        self::assertStringNotContainsString('nativeblade:nfc', $manifest);
        self::assertFileDoesNotExist($this->techFilterPath);
    }
}
