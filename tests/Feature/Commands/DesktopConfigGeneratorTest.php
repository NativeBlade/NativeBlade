<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\DesktopConfigGenerator;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

/**
 * DesktopConfigGenerator::generate() mutates a real tauri.conf.json and
 * writes sibling menu.json / tray.json files under src-tauri/. These tests
 * use a hermetic tempdir via the WithTempBasePath trait so nothing touches
 * the real project.
 */
final class DesktopConfigGeneratorTest extends TestCase
{
    use WithTempBasePath;

    private DesktopConfigGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTempBasePath();
        mkdir(base_path('src-tauri/icons'), 0755, true);
        $this->writeDefaultTauriConf();
        $this->generator = new DesktopConfigGenerator($this->makeDummyCommand());
    }

    protected function tearDown(): void
    {
        $this->tearDownTempBasePath();
        parent::tearDown();
    }

    private function writeDefaultTauriConf(): void
    {
        $default = [
            'productName' => 'OldName',
            'version' => '0.0.0',
            'identifier' => 'com.old.app',
            'app' => [
                'windows' => [
                    [
                        'title' => 'OldTitle',
                        'width' => 800,
                        'height' => 600,
                    ],
                ],
            ],
            'bundle' => ['icon' => []],
        ];
        file_put_contents(
            base_path('src-tauri/tauri.conf.json'),
            json_encode($default, JSON_PRETTY_PRINT)
        );
    }

    private function makeDummyCommand(): Command
    {
        $cmd = new class extends Command {
            protected $signature = 'dummy:dummy';
        };
        // Command::line() proxies to an OutputInterface; without one plugged in
        // writeln() is called on null. NullOutput silently discards everything.
        $cmd->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new NullOutput()
        ));
        return $cmd;
    }

    #[Test]
    public function generate_updates_title_version_and_identifier(): void
    {
        $this->generator->generate([
            'title' => 'MyCoolApp',
            'version' => '2.0.0',
            'identifier' => 'com.mycool.app',
        ]);

        $conf = json_decode(file_get_contents(base_path('src-tauri/tauri.conf.json')), true);

        self::assertSame('MyCoolApp', $conf['productName']);
        self::assertSame('MyCoolApp', $conf['app']['windows'][0]['title']);
        self::assertSame('2.0.0', $conf['version']);
        self::assertSame('com.mycool.app', $conf['identifier']);
    }

    #[Test]
    public function generate_updates_window_dimensions(): void
    {
        $this->generator->generate([
            'width' => 1200,
            'height' => 800,
            'minWidth' => 640,
            'minHeight' => 480,
            'resizable' => false,
            'center' => true,
        ]);

        $conf = json_decode(file_get_contents(base_path('src-tauri/tauri.conf.json')), true);
        $win = $conf['app']['windows'][0];

        self::assertSame(1200, $win['width']);
        self::assertSame(800, $win['height']);
        self::assertSame(640, $win['minWidth']);
        self::assertSame(480, $win['minHeight']);
        self::assertFalse($win['resizable']);
        self::assertTrue($win['center']);
    }

    #[Test]
    public function generate_does_nothing_when_tauri_conf_missing(): void
    {
        unlink(base_path('src-tauri/tauri.conf.json'));

        // Should not throw — the generator guards on file_exists.
        $this->generator->generate(['title' => 'X']);

        self::assertFileDoesNotExist(base_path('src-tauri/tauri.conf.json'));
    }

    #[Test]
    public function generate_writes_menu_json_when_menu_provided(): void
    {
        $this->generator->generate([
            'menu' => [
                'File' => [
                    'Quit' => 'quit',
                ],
            ],
        ]);

        $menu = json_decode(file_get_contents(base_path('src-tauri/menu.json')), true);

        self::assertIsArray($menu);
        self::assertSame('File', $menu[0]['label']);
        self::assertSame([['label' => 'Quit', 'action' => 'quit']], $menu[0]['items']);
    }

    #[Test]
    public function generate_skips_menu_json_when_menu_not_provided(): void
    {
        $this->generator->generate(['title' => 'X']);

        self::assertFileDoesNotExist(base_path('src-tauri/menu.json'));
    }

    #[Test]
    public function generate_always_writes_tray_json_even_without_tray_config(): void
    {
        $this->generator->generate(['title' => 'X']);

        $tray = json_decode(file_get_contents(base_path('src-tauri/tray.json')), true);
        self::assertFalse($tray['enabled']);
        self::assertFalse($tray['customIcon']);
        self::assertSame('NativeBlade', $tray['tooltip']);
        self::assertSame([], $tray['menu']);
    }

    #[Test]
    public function generate_writes_tray_json_with_menu_when_tray_configured(): void
    {
        $this->generator->generate([
            'tray' => [
                'tooltip' => 'MyApp running',
                'menu' => [
                    'Show' => 'show_window',
                    'Quit' => 'quit',
                ],
            ],
            'hideOnClose' => true,
        ]);

        $tray = json_decode(file_get_contents(base_path('src-tauri/tray.json')), true);

        self::assertTrue($tray['enabled']);
        self::assertTrue($tray['hideOnClose']);
        self::assertSame('MyApp running', $tray['tooltip']);
        self::assertCount(2, $tray['menu']);
        self::assertSame(['label' => 'Show', 'action' => 'show_window'], $tray['menu'][0]);
    }

    #[Test]
    public function generate_adds_updater_plugin_when_update_url_provided(): void
    {
        $this->generator->generate([
            'updateUrl' => 'https://updates.example.com/feed',
            'updatePubkey' => 'PUBKEY',
        ]);

        $conf = json_decode(file_get_contents(base_path('src-tauri/tauri.conf.json')), true);

        self::assertArrayHasKey('plugins', $conf);
        self::assertSame(
            ['https://updates.example.com/feed'],
            $conf['plugins']['updater']['endpoints']
        );
        self::assertSame('PUBKEY', $conf['plugins']['updater']['pubkey']);
        self::assertTrue($conf['plugins']['updater']['dialog']);
    }

    #[Test]
    public function generate_leaves_unrelated_keys_untouched(): void
    {
        $this->generator->generate(['title' => 'Renamed']);

        $conf = json_decode(file_get_contents(base_path('src-tauri/tauri.conf.json')), true);

        // identifier was not in the new config — original must remain
        self::assertSame('com.old.app', $conf['identifier']);
        self::assertSame('0.0.0', $conf['version']);
    }
}
