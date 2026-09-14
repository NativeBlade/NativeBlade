<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\IosConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

final class IosSplashTest extends TestCase
{
    use WithTempBasePath;

    private IosConfigGenerator $generator;
    private string $storyboardPath;
    private string $assetsDir;

    // Tauri's stock launch storyboard (fixed ids the injector targets).
    private const TEMPLATE = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<document type="com.apple.InterfaceBuilder3.CocoaTouch.Storyboard.XIB" version="3.0" initialViewController="Y6W-OH-hqX">
    <scenes>
        <scene sceneID="s0d-6b-0kx">
            <objects>
                <viewController id="Y6W-OH-hqX" sceneMemberID="viewController">
                    <view key="view" contentMode="scaleToFill" id="5EZ-qb-Rvc">
                        <rect key="frame" x="0.0" y="0.0" width="414" height="896"/>
                        <autoresizingMask key="autoresizingMask" widthSizable="YES" heightSizable="YES"/>
                        <viewLayoutGuide key="safeArea" id="vDu-zF-Fre"/>
                        <color key="backgroundColor" systemColor="systemBackgroundColor"/>
                    </view>
                </viewController>
            </objects>
        </scene>
    </scenes>
</document>
XML;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        $app = base_path('src-tauri/gen/apple/MyApp');
        mkdir($app . '/Base.lproj', 0755, true);
        file_put_contents($app . '/Info.plist', "<?xml version=\"1.0\"?>\n<plist><dict></dict></plist>");
        $this->storyboardPath = $app . '/Base.lproj/LaunchScreen.storyboard';
        file_put_contents($this->storyboardPath, self::TEMPLATE);

        // The SplashLogo image set is produced by nativeblade:icon; simulate it.
        $this->assetsDir = base_path('src-tauri/gen/apple/Assets.xcassets');
        mkdir($this->assetsDir . '/SplashLogo.imageset', 0755, true);
        file_put_contents($this->assetsDir . '/SplashLogo.imageset/splash-logo.png', 'fake-png-bytes');

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
    public function injects_a_centered_logo_referencing_the_splash_asset(): void
    {
        $this->generator->generate(['splashBackground' => '#101010']);

        $storyboard = file_get_contents($this->storyboardPath);
        self::assertNotFalse(simplexml_load_string($storyboard), 'storyboard must stay well-formed XML');
        self::assertStringContainsString('image="SplashLogo"', $storyboard);
        self::assertStringContainsString('firstAttribute="centerX" secondItem="5EZ-qb-Rvc"', $storyboard);
        self::assertStringContainsString('firstAttribute="centerY" secondItem="5EZ-qb-Rvc"', $storyboard);
        // Background color was rewritten from the system color to an explicit RGB.
        self::assertStringNotContainsString('systemColor="systemBackgroundColor"', $storyboard);
    }

    #[Test]
    public function is_idempotent_across_reruns(): void
    {
        $this->generator->generate([]);
        $this->generator->generate([]);

        $storyboard = file_get_contents($this->storyboardPath);
        self::assertSame(1, substr_count($storyboard, 'image="SplashLogo"'));
    }

    #[Test]
    public function skips_the_logo_when_no_splash_asset_exists(): void
    {
        // Remove the image set (as if nativeblade:icon was never run).
        unlink($this->assetsDir . '/SplashLogo.imageset/splash-logo.png');
        rmdir($this->assetsDir . '/SplashLogo.imageset');

        $this->generator->generate([]);

        $storyboard = file_get_contents($this->storyboardPath);
        self::assertStringNotContainsString('image="SplashLogo"', $storyboard);
    }
}
