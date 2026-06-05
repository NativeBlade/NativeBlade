<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\IosConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

final class IosInfoPlistTest extends TestCase
{
    use WithTempBasePath;

    private IosConfigGenerator $generator;
    private string $plistPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        $appDir = base_path('src-tauri/gen/apple/App');
        mkdir($appDir, 0755, true);
        $this->plistPath = $appDir . '/Info.plist';
        $this->writePlist();

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

    private function writePlist(): void
    {
        $plist = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key>
    <string>App</string>
</dict>
</plist>
XML;
        file_put_contents($this->plistPath, $plist);
    }

    #[Test]
    public function writes_scalar_and_bool_info_plist_entries(): void
    {
        $this->generator->generate([
            'infoPlist' => [
                'ITSAppUsesNonExemptEncryption' => false,
                'CustomNumber' => 7,
            ],
        ]);

        $plist = file_get_contents($this->plistPath);
        self::assertStringContainsString('nativeblade:config:start', $plist);
        self::assertMatchesRegularExpression('/<key>ITSAppUsesNonExemptEncryption<\/key>\s*<false\/>/', $plist);
        self::assertMatchesRegularExpression('/<key>CustomNumber<\/key>\s*<integer>7<\/integer>/', $plist);
    }

    #[Test]
    public function serializes_lists_as_plist_array(): void
    {
        $this->generator->generate([
            'infoPlist' => [
                'LSApplicationQueriesSchemes' => ['whatsapp', 'tel'],
            ],
        ]);

        $plist = file_get_contents($this->plistPath);
        self::assertStringContainsString('<key>LSApplicationQueriesSchemes</key>', $plist);
        self::assertStringContainsString('<array>', $plist);
        self::assertStringContainsString('<string>whatsapp</string>', $plist);
        self::assertStringContainsString('<string>tel</string>', $plist);
    }

    #[Test]
    public function serializes_associative_arrays_as_plist_dict(): void
    {
        $this->generator->generate([
            'infoPlist' => [
                'CustomDict' => ['Inner' => 'value'],
            ],
        ]);

        $plist = file_get_contents($this->plistPath);
        self::assertStringContainsString('<dict>', $plist);
        self::assertStringContainsString('<key>Inner</key>', $plist);
        self::assertStringContainsString('<string>value</string>', $plist);
    }

    #[Test]
    public function ignores_keys_that_nativeblade_manages(): void
    {
        $this->generator->generate([
            'orientation' => 'portrait',
            'infoPlist' => [
                'UISupportedInterfaceOrientations' => ['bogus'],
            ],
        ]);

        $plist = file_get_contents($this->plistPath);
        // Only the orientation-generated key survives, not the infoPlist override.
        self::assertSame(1, substr_count($plist, 'UISupportedInterfaceOrientations'));
        self::assertStringNotContainsString('bogus', $plist);
    }

    #[Test]
    public function reruns_replace_the_block_idempotently(): void
    {
        $payload = ['infoPlist' => ['ITSAppUsesNonExemptEncryption' => false]];

        $this->generator->generate($payload);
        $this->generator->generate($payload);

        $plist = file_get_contents($this->plistPath);
        self::assertSame(1, substr_count($plist, 'nativeblade:config:start'));
        self::assertSame(1, substr_count($plist, 'ITSAppUsesNonExemptEncryption'));
    }
}
