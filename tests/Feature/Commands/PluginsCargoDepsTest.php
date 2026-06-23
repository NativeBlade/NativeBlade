<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\PluginsConfigGenerator;
use NativeBlade\Config\Plugin;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The path-based nativeblade-* plugin crates are seeded into Cargo.toml from
 * the stub at scaffold time. When a plugin is added to the framework later,
 * its [features] entry is regenerated but its dependency line is missing,
 * breaking Cargo. The generator must add the missing dep lines.
 */
final class PluginsCargoDepsTest extends TestCase
{
    use WithTempBasePath;

    private PluginsConfigGenerator $generator;
    private string $cargoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();

        $dir = base_path('src-tauri');
        mkdir($dir, 0755, true);
        $this->cargoPath = $dir . '/Cargo.toml';
        $this->writeCargo();

        $this->generator = new PluginsConfigGenerator($this->makeDummyCommand());
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

    private function writeCargo(): void
    {
        $cargo = <<<TOML
[dependencies]
nativeblade-tauri = { path = "../vendor/nativeblade/nativeblade/rust" }

[target.'cfg(any(target_os = "android", target_os = "ios"))'.dependencies]
tauri-plugin-nativeblade-push = { path = "../vendor/nativeblade/nativeblade/rust/plugins/push", optional = true }
tauri-plugin-nativeblade-media = { path = "../vendor/nativeblade/nativeblade/rust/plugins/media", optional = true }

# nativeblade:plugins:start
[features]
default = ["custom-protocol"]
custom-protocol = ["tauri/custom-protocol"]
# nativeblade:plugins:end
TOML;
        file_put_contents($this->cargoPath, $cargo);
    }

    #[Test]
    public function adds_missing_nativeblade_dep_lines_deriving_the_base_path(): void
    {
        $this->generator->generate([
            Plugin::PUSH,
            Plugin::MEDIA,
            Plugin::IN_APP_REVIEW,
            Plugin::SECURE_STORAGE,
            Plugin::ADMOB,
        ]);

        $cargo = file_get_contents($this->cargoPath);

        self::assertStringContainsString(
            'tauri-plugin-nativeblade-review = { path = "../vendor/nativeblade/nativeblade/rust/plugins/review", optional = true }',
            $cargo
        );
        self::assertStringContainsString(
            'tauri-plugin-nativeblade-secure-storage = { path = "../vendor/nativeblade/nativeblade/rust/plugins/secure-storage", optional = true }',
            $cargo
        );
        self::assertStringContainsString(
            'tauri-plugin-nativeblade-admob = { path = "../vendor/nativeblade/nativeblade/rust/plugins/admob", optional = true }',
            $cargo
        );

        // The feature block now references deps that actually exist.
        self::assertStringContainsString('in_app_review = ["dep:tauri-plugin-nativeblade-review"]', $cargo);
        self::assertStringContainsString('secure_storage = ["dep:tauri-plugin-nativeblade-secure-storage"]', $cargo);
        self::assertStringContainsString('admob = ["dep:tauri-plugin-nativeblade-admob"]', $cargo);
    }

    #[Test]
    public function does_not_duplicate_existing_dep_lines(): void
    {
        $this->generator->generate([
            Plugin::PUSH,
            Plugin::MEDIA,
            Plugin::IN_APP_REVIEW,
        ]);

        $cargo = file_get_contents($this->cargoPath);

        self::assertSame(1, substr_count($cargo, 'tauri-plugin-nativeblade-push ='));
        self::assertSame(1, substr_count($cargo, 'tauri-plugin-nativeblade-media ='));
        self::assertSame(1, substr_count($cargo, 'tauri-plugin-nativeblade-review ='));
    }

    #[Test]
    public function rerunning_does_not_re_add_the_dep(): void
    {
        $plugins = [Plugin::PUSH, Plugin::MEDIA, Plugin::IN_APP_REVIEW];

        $this->generator->generate($plugins);
        $this->generator->generate($plugins);

        $cargo = file_get_contents($this->cargoPath);
        self::assertSame(1, substr_count($cargo, 'tauri-plugin-nativeblade-review ='));
    }
}
