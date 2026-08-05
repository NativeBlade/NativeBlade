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
 * Plugin::GLOBAL_SHORTCUT is the first desktop-only optional plugin. The
 * generator must:
 *   - gate its lib.rs init behind cfg(not(mobile)) so it never compiles on
 *     Android/iOS (where the crate does not exist),
 *   - add its Cargo feature,
 *   - land its permissions in capabilities/desktop.json, never default.json.
 *
 * Fixtures are LF-normalized so the marker regexes match regardless of the
 * checkout's line endings.
 */
final class GlobalShortcutDesktopOnlyTest extends TestCase
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

    /** Write with LF endings so the generator's marker regexes always match. */
    private function writeFixture(string $path, string $content): void
    {
        $full = base_path($path);
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, str_replace(["\r\n", "\r"], "\n", $content));
    }

    private function scaffold(): void
    {
        $this->writeFixture('src-tauri/Cargo.toml', <<<TOML
        [dependencies]
        tauri = { version = "2" }

        [target.'cfg(not(any(target_os = "android", target_os = "ios")))'.dependencies]
        tauri-plugin-global-shortcut = { version = "2", optional = true }

        # nativeblade:plugins:start
        [features]
        default = ["custom-protocol"]
        custom-protocol = ["tauri/custom-protocol"]
        # nativeblade:plugins:end
        TOML);

        $this->writeFixture('src-tauri/src/lib.rs', <<<RUST
        pub fn run() {
            let builder = tauri::Builder::default();
            // nativeblade:plugins:start
            // nativeblade:plugins:end
            let _ = builder;
        }
        RUST);

        $this->writeFixture('src-tauri/capabilities/default.json', '{"permissions": []}');
        $this->writeFixture('src-tauri/capabilities/mobile.json', '{"permissions": []}');
        $this->writeFixture('src-tauri/capabilities/desktop.json', '{"permissions": []}');
        $this->writeFixture('src-tauri/gen/android/app/src/main/AndroidManifest.xml', <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <manifest xmlns:android="http://schemas.android.com/apk/res/android">
            <application>
                <activity android:name=".MainActivity" />
            </application>
        </manifest>
        XML);
    }

    #[Test]
    public function gates_lib_rs_init_behind_a_non_mobile_cfg(): void
    {
        $this->generator->generate([Plugin::GLOBAL_SHORTCUT]);

        $lib = file_get_contents(base_path('src-tauri/src/lib.rs'));
        self::assertStringContainsString(
            '#[cfg(all(not(any(target_os = "android", target_os = "ios")), feature = "global_shortcut"))]',
            $lib
        );
        self::assertStringContainsString(
            '.plugin(tauri_plugin_global_shortcut::Builder::new().build())',
            $lib
        );
    }

    #[Test]
    public function adds_the_feature_to_cargo_toml(): void
    {
        $this->generator->generate([Plugin::GLOBAL_SHORTCUT]);

        $cargo = file_get_contents(base_path('src-tauri/Cargo.toml'));
        self::assertStringContainsString('global_shortcut = ["dep:tauri-plugin-global-shortcut"]', $cargo);
    }

    #[Test]
    public function permissions_land_in_desktop_json_not_default(): void
    {
        $this->generator->generate([Plugin::GLOBAL_SHORTCUT]);

        $desktop = file_get_contents(base_path('src-tauri/capabilities/desktop.json'));
        self::assertStringContainsString('global-shortcut:allow-register', $desktop);

        $default = file_get_contents(base_path('src-tauri/capabilities/default.json'));
        self::assertStringNotContainsString('global-shortcut', $default);
    }

    /**
     * NativeBlade ships window-control actions (minimize/maximize/hide/show/
     * focus) that call core window mutators, which `core:default` does not
     * grant. The generator must add them to desktop.json by default so the
     * actions work without the developer hand-editing capabilities.
     */
    #[Test]
    public function grants_window_controls_in_desktop_json_by_default(): void
    {
        // No plugins: the window controls are baseline, not plugin-driven.
        $this->generator->generate([]);

        $desktop = file_get_contents(base_path('src-tauri/capabilities/desktop.json'));
        self::assertStringContainsString('core:window:allow-set-focus', $desktop);
        self::assertStringContainsString('core:window:allow-show', $desktop);
        self::assertStringContainsString('core:window:allow-unminimize', $desktop);

        // Desktop-only: they must not leak into the cross-platform default.
        $default = file_get_contents(base_path('src-tauri/capabilities/default.json'));
        self::assertStringNotContainsString('core:window:allow-set-focus', $default);
    }

    /**
     * Regression: regeneration used to strip any scoped core permission a
     * developer added to desktop.json (its `core` prefix was not allowed),
     * silently breaking window focus. Scoped-object core permissions must now
     * survive `nativeblade:config`, alongside the framework's own baseline.
     */
    #[Test]
    public function preserves_developer_core_permissions_on_regeneration(): void
    {
        $this->writeFixture('src-tauri/capabilities/desktop.json', <<<JSON
        {
            "permissions": [
                { "identifier": "core:webview:allow-set-webview-focus" }
            ]
        }
        JSON);

        $this->generator->generate([]);

        $desktop = file_get_contents(base_path('src-tauri/capabilities/desktop.json'));
        self::assertStringContainsString('core:webview:allow-set-webview-focus', $desktop);
        // And the framework baseline is still present alongside it.
        self::assertStringContainsString('core:window:allow-set-focus', $desktop);
    }
}
