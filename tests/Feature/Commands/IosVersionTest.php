<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\IosConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tauri reads the iOS version from tauri.conf.json when it generates the
 * Xcode project: the top-level version becomes CFBundleShortVersionString
 * and bundle.iOS.bundleVersion becomes CFBundleVersion. Without the latter
 * Tauri derives the build from the semver string and the declared build
 * number is lost. The plist write only covers an already scaffolded project.
 */
final class IosVersionTest extends TestCase
{
    use WithTempBasePath;

    private IosConfigGenerator $generator;
    private string $confPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        mkdir(base_path('src-tauri'), 0755, true);
        $this->confPath = base_path('src-tauri/tauri.conf.json');
        file_put_contents($this->confPath, json_encode([
            'productName' => 'App',
            'version' => '1.2.3',
            'identifier' => 'com.example.app',
            'bundle' => ['active' => true],
        ], JSON_PRETTY_PRINT));

        $this->generator = new IosConfigGenerator($this->makeDummyCommand());
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

    private function readConf(): array
    {
        return json_decode(file_get_contents($this->confPath), true);
    }

    private function writePlist(): string
    {
        $appDir = base_path('src-tauri/gen/apple/App');
        mkdir($appDir, 0755, true);
        $path = $appDir . '/Info.plist';
        file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key>
    <string>App</string>
    <key>CFBundleShortVersionString</key>
    <string>1.2.3</string>
    <key>CFBundleVersion</key>
    <string>1</string>
</dict>
</plist>
XML);
        return $path;
    }

    #[Test]
    public function writes_version_and_bundle_version_into_tauri_conf_without_a_plist(): void
    {
        self::assertFileDoesNotExist(base_path('src-tauri/gen/apple/App/Info.plist'));

        $this->generator->generate(['version' => '1.2.5', 'buildNumber' => 1000205]);

        $conf = $this->readConf();
        self::assertSame('1.2.5', $conf['version']);
        self::assertSame('1000205', $conf['bundle']['iOS']['bundleVersion']);
    }

    #[Test]
    public function bundle_version_is_a_string_as_tauri_expects(): void
    {
        $this->generator->generate(['version' => '1.2.5', 'buildNumber' => 42]);

        self::assertSame('42', $this->readConf()['bundle']['iOS']['bundleVersion']);
    }

    #[Test]
    public function keeps_writing_the_plist_when_the_project_is_scaffolded(): void
    {
        $plistPath = $this->writePlist();

        $this->generator->generate(['version' => '1.2.5', 'buildNumber' => 1000205]);

        $plist = file_get_contents($plistPath);
        self::assertMatchesRegularExpression('/CFBundleShortVersionString<\/key>\s*<string>1\.2\.5<\/string>/', $plist);
        self::assertMatchesRegularExpression('/CFBundleVersion<\/key>\s*<string>1000205<\/string>/', $plist);
        self::assertSame('1.2.5', $this->readConf()['version']);
    }

    #[Test]
    public function leaves_tauri_conf_untouched_when_build_number_missing(): void
    {
        $this->generator->generate(['version' => '1.2.5']);

        $conf = $this->readConf();
        self::assertSame('1.2.3', $conf['version']);
        // The generator writes other iOS keys (the deployment target); the
        // version ones must stay absent.
        self::assertArrayNotHasKey('bundleVersion', $conf['bundle']['iOS'] ?? []);
    }

    #[Test]
    public function preserves_unrelated_tauri_conf_keys(): void
    {
        $this->generator->generate(['version' => '1.2.5', 'buildNumber' => 3]);

        $conf = $this->readConf();
        self::assertSame('App', $conf['productName']);
        self::assertSame('com.example.app', $conf['identifier']);
        self::assertTrue($conf['bundle']['active']);
    }
}
