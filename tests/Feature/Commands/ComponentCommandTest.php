<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * nativeblade:component — scaffolds a new component into
 * base_path/nativeblade-components/{name}/ based on answers to interactive
 * questions. Each test uses a clean tempdir for base_path.
 */
final class ComponentCommandTest extends TestCase
{
    use WithTempBasePath;

    private const TYPE_CHOICES = [
        'shell' => 'Shell (outside WebView — header, nav, toast, dialog)',
        'embedded' => 'Embedded (inside WebView — modal, card, form)',
        'module' => 'Shell module (stateful, bound to a Livewire component via HasNativeShell)',
    ];

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
    public function shell_component_scaffolds_four_files(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'toast-banner'])
            ->expectsChoice(
                'Where does this component render?',
                'shell',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        $dir = base_path('nativeblade-components/toast-banner');
        self::assertFileExists($dir . '/toast-banner.js');
        self::assertFileExists($dir . '/toast-banner.css');
        self::assertFileExists($dir . '/ToastBanner.php');
        self::assertFileExists($dir . '/toast-banner.blade.php');
    }

    #[Test]
    public function shell_component_php_class_has_correct_namespace_and_class(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'status-badge'])
            ->expectsChoice(
                'Where does this component render?',
                'shell',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        $php = file_get_contents(base_path('nativeblade-components/status-badge/StatusBadge.php'));

        self::assertStringContainsString('namespace App\\NativeBlade\\Components;', $php);
        self::assertStringContainsString('class StatusBadge extends Component', $php);
        self::assertStringContainsString("view('nbc::status-badge')", $php);
    }

    #[Test]
    public function embedded_component_scaffolds_two_files_only(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'info-card'])
            ->expectsChoice(
                'Where does this component render?',
                'embedded',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        $dir = base_path('nativeblade-components/info-card');
        self::assertFileExists($dir . '/InfoCard.php');
        self::assertFileExists($dir . '/info-card.blade.php');
        self::assertFileDoesNotExist($dir . '/info-card.js');
        self::assertFileDoesNotExist($dir . '/info-card.css');
    }

    #[Test]
    public function name_is_normalized_to_kebab_case(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'FancyButton'])
            ->expectsChoice(
                'Where does this component render?',
                'shell',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        self::assertDirectoryExists(base_path('nativeblade-components/fancy-button'));
        self::assertFileExists(base_path('nativeblade-components/fancy-button/FancyButton.php'));
    }

    #[Test]
    public function shell_js_file_references_its_css_sibling(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'loader'])
            ->expectsChoice(
                'Where does this component render?',
                'shell',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        $js = file_get_contents(base_path('nativeblade-components/loader/loader.js'));
        self::assertStringContainsString("import './loader.css';", $js);
        self::assertStringContainsString('id = \'nb-loader\'', $js);
    }

    #[Test]
    public function module_component_scaffolds_js_and_css_only(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'mini-player'])
            ->expectsChoice(
                'Where does this component render?',
                'module',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        $dir = base_path('nativeblade-components/mini-player');
        self::assertFileExists($dir . '/mini-player.js');
        self::assertFileExists($dir . '/mini-player.css');
        self::assertFileDoesNotExist($dir . '/MiniPlayer.php');
        self::assertFileDoesNotExist($dir . '/mini-player.blade.php');
    }

    #[Test]
    public function module_js_stub_has_the_default_export_contract(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'mini-player'])
            ->expectsChoice(
                'Where does this component render?',
                'module',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        $js = file_get_contents(base_path('nativeblade-components/mini-player/mini-player.js'));

        self::assertStringContainsString("import './mini-player.css';", $js);
        self::assertStringContainsString('export default {', $js);
        foreach (['mount(ctx, props)', 'update(props)', 'command(name, args)', 'destroy()'] as $hook) {
            self::assertStringContainsString($hook, $js);
        }
        self::assertStringContainsString('ctx.place(this.el', $js);
    }

    #[Test]
    public function blade_stub_renders_slot_and_message_data_attrs(): void
    {
        $this->artisan('nativeblade:component', ['name' => 'banner'])
            ->expectsChoice(
                'Where does this component render?',
                'shell',
                self::TYPE_CHOICES
            )
            ->assertExitCode(0);

        $blade = file_get_contents(base_path('nativeblade-components/banner/banner.blade.php'));
        self::assertStringContainsString('data-nb="banner"', $blade);
        self::assertStringContainsString('data-message="{{ $message }}"', $blade);
        self::assertStringContainsString('{{ $slot }}', $blade);
    }
}
