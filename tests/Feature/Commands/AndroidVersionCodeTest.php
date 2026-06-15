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

/**
 * Tauri derives the Android versionCode from the semver version unless
 * bundle.android.versionCode is set (1.4.8 would become 1004008). The
 * generator must write the build number into tauri.conf.json so the store
 * sees the version code the dev declared.
 */
final class AndroidVersionCodeTest extends TestCase
{
    use WithTempBasePath;

    private AndroidConfigGenerator $generator;
    private string $confPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
        $this->resetAppConfigs();

        mkdir(base_path('src-tauri'), 0755, true);
        $this->confPath = base_path('src-tauri/tauri.conf.json');
        $this->writeDefaultTauriConf();

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

    private function writeDefaultTauriConf(): void
    {
        file_put_contents($this->confPath, json_encode([
            'productName' => 'App',
            'version' => '1.4.8',
            'identifier' => 'com.example.app',
            'bundle' => ['active' => true],
        ], JSON_PRETTY_PRINT));
    }

    private function readConf(): array
    {
        return json_decode(file_get_contents($this->confPath), true);
    }

    #[Test]
    public function writes_build_number_as_android_version_code(): void
    {
        $this->generator->generate(['version' => '1.4.8', 'buildNumber' => 12]);

        $conf = $this->readConf();
        self::assertSame(12, $conf['bundle']['android']['versionCode']);
    }

    #[Test]
    public function does_not_derive_version_code_from_the_semver_string(): void
    {
        $this->generator->generate(['version' => '1.4.8', 'buildNumber' => 12]);

        // 1.4.8 derived by Tauri would be 1004008; the build number must win.
        self::assertNotSame(1004008, $this->readConf()['bundle']['android']['versionCode']);
    }

    #[Test]
    public function writes_version_code_even_without_a_scaffolded_gradle_file(): void
    {
        self::assertFileDoesNotExist(base_path('src-tauri/gen/android/app/build.gradle.kts'));

        $this->generator->generate(['version' => '2.0.0', 'buildNumber' => 5]);

        self::assertSame(5, $this->readConf()['bundle']['android']['versionCode']);
    }

    #[Test]
    public function leaves_version_code_untouched_when_build_number_missing(): void
    {
        $this->generator->generate(['version' => '1.4.8']);

        self::assertArrayNotHasKey('android', $this->readConf()['bundle']);
    }

    #[Test]
    public function preserves_unrelated_tauri_conf_keys(): void
    {
        $this->generator->generate(['version' => '1.4.8', 'buildNumber' => 12]);

        $conf = $this->readConf();
        self::assertSame('App', $conf['productName']);
        self::assertSame('com.example.app', $conf['identifier']);
        self::assertTrue($conf['bundle']['active']);
    }
}
