<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\IosConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tauri defaults the iOS deployment target to 14.0, which current Xcode
 * rejects ("supported deployment target versions is 15.0 to ..."). Config must
 * raise it in tauri.conf.json and in the already generated Xcode project.
 */
final class IosDeploymentTargetTest extends TestCase
{
    use WithTempBasePath;

    private IosConfigGenerator $generator;
    private string $confPath;
    private string $ymlPath;
    private string $pbxPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        $apple = base_path('src-tauri/gen/apple');
        mkdir($apple . '/nativeblade.xcodeproj', 0755, true);

        $this->confPath = base_path('src-tauri/tauri.conf.json');
        file_put_contents($this->confPath, json_encode(['productName' => 'App', 'bundle' => ['active' => true]]));

        $this->ymlPath = $apple . '/project.yml';
        file_put_contents($this->ymlPath, "name: nativeblade\noptions:\n  deploymentTarget:\n    iOS: 14.0\ntargets:\n  nativeblade_iOS:\n    platform: iOS\n");

        $this->pbxPath = $apple . '/nativeblade.xcodeproj/project.pbxproj';
        file_put_contents($this->pbxPath, "buildSettings = {\n\tIPHONEOS_DEPLOYMENT_TARGET = 14.0;\n};\nbuildSettings = {\n\tIPHONEOS_DEPLOYMENT_TARGET = 14.0;\n};\n");

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

    #[Test]
    public function raises_the_default_14_target_to_15_everywhere(): void
    {
        $this->generator->generate([]);

        $conf = json_decode(file_get_contents($this->confPath), true);
        self::assertSame('15.0', $conf['bundle']['iOS']['minimumSystemVersion']);
        self::assertTrue($conf['bundle']['active'], 'existing bundle keys are kept');

        self::assertStringContainsString('iOS: 15.0', file_get_contents($this->ymlPath));
        self::assertStringContainsString('platform: iOS', file_get_contents($this->ymlPath));

        $pbx = file_get_contents($this->pbxPath);
        self::assertSame(2, substr_count($pbx, 'IPHONEOS_DEPLOYMENT_TARGET = 15.0;'));
        self::assertStringNotContainsString('14.0', $pbx);
    }

    #[Test]
    public function keeps_a_higher_target_set_by_the_app(): void
    {
        file_put_contents($this->confPath, json_encode(['bundle' => ['iOS' => ['minimumSystemVersion' => '16.4']]]));
        file_put_contents($this->pbxPath, "IPHONEOS_DEPLOYMENT_TARGET = 16.4;\n");

        $this->generator->generate([]);

        $conf = json_decode(file_get_contents($this->confPath), true);
        self::assertSame('16.4', $conf['bundle']['iOS']['minimumSystemVersion']);
        self::assertStringContainsString('IPHONEOS_DEPLOYMENT_TARGET = 16.4;', file_get_contents($this->pbxPath));
    }

    #[Test]
    public function applies_the_declared_min_ios_version_to_the_xcode_project(): void
    {
        $this->generator->generate(['minIosVersion' => '16.0']);

        $conf = json_decode(file_get_contents($this->confPath), true);
        self::assertSame('16.0', $conf['bundle']['iOS']['minimumSystemVersion']);
        self::assertStringContainsString('iOS: 16.0', file_get_contents($this->ymlPath));
        self::assertSame(2, substr_count(file_get_contents($this->pbxPath), 'IPHONEOS_DEPLOYMENT_TARGET = 16.0;'));
    }

    #[Test]
    public function declared_version_is_the_source_of_truth_even_when_lower_than_the_project(): void
    {
        file_put_contents($this->pbxPath, "IPHONEOS_DEPLOYMENT_TARGET = 16.4;\n");

        $this->generator->generate(['minIosVersion' => '15.0']);

        self::assertStringContainsString('IPHONEOS_DEPLOYMENT_TARGET = 15.0;', file_get_contents($this->pbxPath));
    }

    #[Test]
    public function a_declared_version_below_the_supported_minimum_is_clamped(): void
    {
        $this->generator->generate(['minIosVersion' => '14.0']);

        $conf = json_decode(file_get_contents($this->confPath), true);
        self::assertSame('15.0', $conf['bundle']['iOS']['minimumSystemVersion']);
        self::assertStringNotContainsString('14.0', file_get_contents($this->pbxPath));
    }

    #[Test]
    public function is_idempotent(): void
    {
        $this->generator->generate([]);
        $pbx = file_get_contents($this->pbxPath);
        $yml = file_get_contents($this->ymlPath);

        $this->generator->generate([]);

        self::assertSame($pbx, file_get_contents($this->pbxPath));
        self::assertSame($yml, file_get_contents($this->ymlPath));
    }
}
