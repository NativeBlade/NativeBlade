<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\PluginsConfigGenerator;
use NativeBlade\Config\CustomPlugin;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

/**
 * customPlugins() wires a third-party Tauri plugin into the same files a
 * built-in plugin touches: Cargo.toml (dep + feature), lib.rs (init),
 * capabilities, AndroidManifest. A feature name that collides with a built-in
 * is rejected.
 */
final class CustomPluginTest extends TestCase
{
    use WithTempBasePath;

    private PluginsConfigGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
        $this->scaffold();
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

    private function scaffold(): void
    {
        mkdir(base_path('src-tauri/src'), 0755, true);
        mkdir(base_path('src-tauri/capabilities'), 0755, true);
        mkdir(base_path('src-tauri/gen/android/app/src/main'), 0755, true);

        file_put_contents(base_path('src-tauri/Cargo.toml'), <<<TOML
        [dependencies]
        tauri = { version = "2" }

        [target.'cfg(any(target_os = "android", target_os = "ios"))'.dependencies]
        tauri-plugin-nfc = { version = "2", optional = true }

        # nativeblade:plugins:start
        [features]
        default = ["custom-protocol"]
        custom-protocol = ["tauri/custom-protocol"]
        # nativeblade:plugins:end
        TOML);

        file_put_contents(base_path('src-tauri/src/lib.rs'), <<<RUST
        pub fn run() {
            let builder = tauri::Builder::default();
            // nativeblade:plugins:start
            // nativeblade:plugins:end
            let _ = builder;
        }
        RUST);

        file_put_contents(base_path('src-tauri/capabilities/default.json'), '{"permissions": []}');

        file_put_contents(base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml'), <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <manifest xmlns:android="http://schemas.android.com/apk/res/android">
            <application>
                <activity android:name=".MainActivity" />
            </application>
        </manifest>
        XML);
    }

    private function fingerprint(): CustomPlugin
    {
        return CustomPlugin::init(
            feature: 'fingerprint',
            feature_crate: 'tauri-plugin-fingerprint',
            rust_init: 'tauri_plugin_fingerprint::init()',
            version: '0.1',
            capabilities: ['fingerprint:default'],
            android_permissions: ['USE_BIOMETRIC'],
        );
    }

    #[Test]
    public function wires_the_crate_dependency_and_feature_into_cargo_toml(): void
    {
        $this->generator->generate([], [], [], [$this->fingerprint()]);

        $cargo = file_get_contents(base_path('src-tauri/Cargo.toml'));
        self::assertStringContainsString(
            'tauri-plugin-fingerprint = { version = "0.1", optional = true }',
            $cargo
        );
        self::assertStringContainsString('fingerprint = ["dep:tauri-plugin-fingerprint"]', $cargo);
    }

    #[Test]
    public function wires_the_init_call_into_lib_rs(): void
    {
        $this->generator->generate([], [], [], [$this->fingerprint()]);

        $lib = file_get_contents(base_path('src-tauri/src/lib.rs'));
        self::assertStringContainsString('feature = "fingerprint"', $lib);
        self::assertStringContainsString('tauri_plugin_fingerprint::init()', $lib);
    }

    #[Test]
    public function grants_capabilities_and_android_permissions(): void
    {
        $this->generator->generate([], [], [], [$this->fingerprint()]);

        self::assertStringContainsString(
            'fingerprint:default',
            file_get_contents(base_path('src-tauri/capabilities/default.json'))
        );
        self::assertStringContainsString(
            'android.permission.USE_BIOMETRIC',
            file_get_contents(base_path('src-tauri/gen/android/app/src/main/AndroidManifest.xml'))
        );
    }

    #[Test]
    public function mobile_only_dependency_lands_in_the_mobile_target_section(): void
    {
        $plugin = CustomPlugin::init(
            feature: 'my_nfc',
            feature_crate: 'tauri-plugin-my-nfc',
            rust_init: 'tauri_plugin_my_nfc::init()',
            path: '../plugins/my-nfc',
            mobile_only: true,
        );

        $this->generator->generate([], [], [], [$plugin]);

        $cargo = file_get_contents(base_path('src-tauri/Cargo.toml'));
        $mobileSection = substr($cargo, strpos($cargo, "[target.'cfg(any(target_os"));
        self::assertStringContainsString(
            'tauri-plugin-my-nfc = { path = "../plugins/my-nfc", optional = true }',
            $mobileSection
        );
    }

    #[Test]
    public function rejects_a_feature_name_that_collides_with_a_builtin(): void
    {
        $clash = CustomPlugin::init(
            feature: 'haptics', // built-in
            feature_crate: 'my-haptics',
            rust_init: 'my_haptics::init()',
            version: '1.0',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->generator->generate([], [], [], [$clash]);
    }

    #[Test]
    public function rejects_a_custom_plugin_without_version_or_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomPlugin::init(
            feature: 'broken',
            feature_crate: 'tauri-plugin-broken',
            rust_init: 'tauri_plugin_broken::init()',
        );
    }
}
