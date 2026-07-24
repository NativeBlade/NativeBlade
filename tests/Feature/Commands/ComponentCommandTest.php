<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * nativeblade:component — scaffolds a single shell component into
 * base_path/nativeblade-components/{name}/. There is one component type: a
 * shell component with the full module contract, usable declaratively
 * (<x-nativeblade-{name}>) or bound to a Livewire component via HasNativeShell.
 * Each test uses a clean tempdir for base_path.
 */
final class ComponentCommandTest extends TestCase
{
    use WithTempBasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
    }

    protected function tearDown(): void
    {
        $this->tearDownTempBasePath();
        parent::tearDown();
    }

    #[Test]
    public function it_scaffolds_four_files_with_no_prompt(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'toast-banner'])
            ->assertExitCode(0);

        $dir = base_path('nativeblade-components/toast-banner');
        self::assertFileExists($dir . '/toast-banner.js');
        self::assertFileExists($dir . '/toast-banner.css');
        self::assertFileExists($dir . '/ToastBanner.php');
        self::assertFileExists($dir . '/toast-banner.blade.php');
    }

    #[Test]
    public function php_class_has_correct_namespace_and_class(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'status-badge'])
            ->assertExitCode(0);

        $php = file_get_contents(base_path('nativeblade-components/status-badge/StatusBadge.php'));

        self::assertStringContainsString('namespace App\\NativeBlade\\Components;', $php);
        self::assertStringContainsString('class StatusBadge extends Component', $php);
        self::assertStringContainsString("view('nbc::status-badge')", $php);
    }

    #[Test]
    public function name_is_normalized_to_kebab_case(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'FancyButton'])
            ->assertExitCode(0);

        self::assertDirectoryExists(base_path('nativeblade-components/fancy-button'));
        self::assertFileExists(base_path('nativeblade-components/fancy-button/FancyButton.php'));
    }

    #[Test]
    public function js_file_references_its_css_sibling_and_shell_id(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'loader'])
            ->assertExitCode(0);

        $js = file_get_contents(base_path('nativeblade-components/loader/loader.js'));
        self::assertStringContainsString("import './loader.css';", $js);
        self::assertStringContainsString('nb-loader', $js);
    }

    #[Test]
    public function js_stub_uses_the_nb_setup_form(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'mini-player'])
            ->assertExitCode(0);

        $js = file_get_contents(base_path('nativeblade-components/mini-player/mini-player.js'));

        self::assertStringContainsString("import './mini-player.css';", $js);
        self::assertStringContainsString('export default (nb) => {', $js);
        foreach (['nb.element', 'nb.php.watch', 'nb.php.emit'] as $api) {
            self::assertStringContainsString($api, $js);
        }
    }

    #[Test]
    public function js_stub_documents_both_summon_styles(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'side-panel'])
            ->assertExitCode(0);

        $js = file_get_contents(base_path('nativeblade-components/side-panel/side-panel.js'));

        self::assertStringContainsString('<x-nativeblade-side-panel', $js);
        self::assertStringContainsString('HasNativeShell', $js);
        self::assertStringContainsString("protected string \$shell = 'side-panel';", $js);
    }

    #[Test]
    public function blade_stub_renders_slot_and_message_data_attrs(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'banner'])
            ->assertExitCode(0);

        $blade = file_get_contents(base_path('nativeblade-components/banner/banner.blade.php'));
        self::assertStringContainsString('data-nb="banner"', $blade);
        self::assertStringContainsString('data-message="{{ $message }}"', $blade);
        self::assertStringContainsString('{{ $slot }}', $blade);
    }
}
