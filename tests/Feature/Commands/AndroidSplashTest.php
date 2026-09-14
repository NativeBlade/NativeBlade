<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\AndroidConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

final class AndroidSplashTest extends TestCase
{
    use WithTempBasePath;

    private AndroidConfigGenerator $generator;
    private string $themePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        $main = base_path('src-tauri/gen/android/app/src/main');
        mkdir($main, 0755, true);
        file_put_contents($main . '/AndroidManifest.xml', <<<XML
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android"><application /></manifest>
XML);

        mkdir($main . '/res/values', 0755, true);
        $this->themePath = $main . '/res/values/themes.xml';
        file_put_contents($this->themePath, <<<XML
<?xml version="1.0" encoding="utf-8"?>
<resources xmlns:tools="http://schemas.android.com/tools">
    <style name="Theme.nativeblade" parent="Theme.MaterialComponents.DayNight.NoActionBar">
    </style>
</resources>
XML);

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

    #[Test]
    public function falls_back_to_the_launcher_icon_when_no_splash_logo_generated(): void
    {
        $this->generator->generate([]);

        $theme = file_get_contents($this->themePath);
        self::assertStringContainsString('android:windowSplashScreenAnimatedIcon', $theme);
        self::assertStringContainsString('@mipmap/ic_launcher', $theme);
    }

    #[Test]
    public function uses_the_generated_splash_logo_when_present(): void
    {
        $drawable = base_path('src-tauri/gen/android/app/src/main/res/drawable-xxhdpi');
        mkdir($drawable, 0755, true);
        file_put_contents($drawable . '/splash_icon.png', 'fake-png');

        $this->generator->generate([]);

        $theme = file_get_contents($this->themePath);
        self::assertStringContainsString('@drawable/splash_icon', $theme);
        self::assertStringNotContainsString('@mipmap/ic_launcher', $theme);
    }

    #[Test]
    public function keeps_the_icon_alongside_a_configured_splash_background(): void
    {
        $this->generator->generate(['splashBackground' => '#101010']);

        $theme = file_get_contents($this->themePath);
        self::assertStringContainsString('android:windowSplashScreenBackground', $theme);
        self::assertStringContainsString('#FF101010', $theme);
        self::assertStringContainsString('android:windowSplashScreenAnimatedIcon', $theme);
    }

    #[Test]
    public function reruns_do_not_duplicate_the_icon_item(): void
    {
        $this->generator->generate([]);
        $this->generator->generate([]);

        $theme = file_get_contents($this->themePath);
        self::assertSame(1, substr_count($theme, 'windowSplashScreenAnimatedIcon'));
    }
}
