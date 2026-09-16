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

    private function makeBufferedCommand(\Symfony\Component\Console\Output\BufferedOutput $buffer): Command
    {
        $cmd = new class extends Command {
            protected $signature = 'dummy:dummy';
        };
        $cmd->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            $buffer
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

        // The feature block now references deps that actually exist.
        self::assertStringContainsString('in_app_review = ["dep:tauri-plugin-nativeblade-review"]', $cargo);
        self::assertStringContainsString('secure_storage = ["dep:tauri-plugin-nativeblade-secure-storage"]', $cargo);
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

    #[Test]
    public function adds_the_always_on_system_crate_as_a_direct_non_optional_dep(): void
    {
        // tauri-build only discovers a plugin's permission files from the app's
        // own dependency list, so the always-on system crate must be a direct,
        // non-optional dep even though the framework already pulls it in.
        $this->generator->generate([Plugin::PUSH, Plugin::MEDIA, Plugin::SYSTEM]);

        $cargo = file_get_contents($this->cargoPath);

        self::assertStringContainsString(
            'tauri-plugin-nativeblade-system = { path = "../vendor/nativeblade/nativeblade/rust/plugins/system" }',
            $cargo
        );
        // Never optional — that would hide it from the ACL build.
        self::assertStringNotContainsString('plugins/system", optional', $cargo);

        // Lands in [dependencies] (compiles on every target), not the mobile
        // target section, since the crate carries a desktop stub.
        $depsPos = strpos($cargo, '[dependencies]');
        $mobilePos = strpos($cargo, "[target.'cfg(any(target_os");
        $systemPos = strpos($cargo, 'tauri-plugin-nativeblade-system =');
        self::assertNotFalse($systemPos);
        self::assertGreaterThan($depsPos, $systemPos);
        self::assertLessThan($mobilePos, $systemPos);
    }

    #[Test]
    public function does_not_duplicate_the_always_on_system_crate(): void
    {
        $plugins = [Plugin::PUSH, Plugin::SYSTEM];

        $this->generator->generate($plugins);
        $this->generator->generate($plugins);

        $cargo = file_get_contents($this->cargoPath);
        self::assertSame(1, substr_count($cargo, 'tauri-plugin-nativeblade-system ='));
    }

    #[Test]
    public function refreshes_the_lock_when_it_is_stale_even_if_the_toml_is_unchanged(): void
    {
        // Simulate a project whose Cargo.toml already carries the system dep (an
        // earlier update added it) but whose committed lock never recorded it.
        file_put_contents($this->cargoPath, <<<TOML
        [dependencies]
        nativeblade-tauri = { path = "../vendor/nativeblade/nativeblade/rust" }
        tauri-plugin-nativeblade-system = { path = "../vendor/nativeblade/nativeblade/rust/plugins/system" }

        [target.'cfg(any(target_os = "android", target_os = "ios"))'.dependencies]
        tauri-plugin-nativeblade-push = { path = "../vendor/nativeblade/nativeblade/rust/plugins/push", optional = true }

        # nativeblade:plugins:start
        [features]
        default = ["custom-protocol"]
        custom-protocol = ["tauri/custom-protocol"]
        # nativeblade:plugins:end
        TOML);

        // Lock exists but has no system package entry -> stale.
        file_put_contents(
            base_path('src-tauri/Cargo.lock'),
            "[[package]]\nname = \"tauri-plugin-nativeblade-push\"\nversion = \"0.1.0\"\n"
        );

        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        (new PluginsConfigGenerator($this->makeBufferedCommand($buffer)))
            ->generate([Plugin::PUSH, Plugin::SYSTEM]);

        // The toml dep line is untouched, yet a refresh must have been attempted
        // (it either refreshed the lock or emitted the fallback hint).
        $out = $buffer->fetch();
        self::assertMatchesRegularExpression('/Cargo\.lock refreshed|generate-lockfile/', $out);
    }

    #[Test]
    public function does_not_refresh_when_the_lock_is_already_in_sync(): void
    {
        file_put_contents($this->cargoPath, <<<TOML
        [dependencies]
        nativeblade-tauri = { path = "../vendor/nativeblade/nativeblade/rust" }
        tauri-plugin-nativeblade-system = { path = "../vendor/nativeblade/nativeblade/rust/plugins/system" }

        # nativeblade:plugins:start
        [features]
        default = ["custom-protocol"]
        custom-protocol = ["tauri/custom-protocol"]
        # nativeblade:plugins:end
        TOML);

        // Lock already records the system crate -> in sync, no refresh needed.
        file_put_contents(
            base_path('src-tauri/Cargo.lock'),
            "[[package]]\nname = \"tauri-plugin-nativeblade-system\"\nversion = \"0.1.0\"\n"
        );

        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        (new PluginsConfigGenerator($this->makeBufferedCommand($buffer)))
            ->generate([Plugin::SYSTEM]);

        $out = $buffer->fetch();
        self::assertDoesNotMatchRegularExpression('/Cargo\.lock refreshed|generate-lockfile/', $out);
    }
}
