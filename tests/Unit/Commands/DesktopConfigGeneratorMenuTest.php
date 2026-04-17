<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Commands;

use Illuminate\Console\Command;
use NativeBlade\Commands\Config\DesktopConfigGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * DesktopConfigGenerator::buildMenuItems() is a pure recursive tree builder
 * that drives both tauri menu.json and tray.json generation. Testing it here
 * without booting the whole generate() pipeline keeps the semantics pinned.
 */
final class DesktopConfigGeneratorMenuTest extends TestCase
{
    private DesktopConfigGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DesktopConfigGenerator($this->makeDummyCommand());
    }

    /**
     * @param  array<string, mixed>  $menu
     * @return array<int, array<string, mixed>>
     */
    private function build(array $menu): array
    {
        $ref = new ReflectionClass(DesktopConfigGenerator::class);
        $method = $ref->getMethod('buildMenuItems');
        $method->setAccessible(true);
        return $method->invoke($this->generator, $menu);
    }

    private function makeDummyCommand(): Command
    {
        return new class extends Command {
            protected $signature = 'dummy:dummy';
        };
    }

    #[Test]
    public function empty_menu_yields_empty_list(): void
    {
        self::assertSame([], $this->build([]));
    }

    #[Test]
    public function simple_label_to_action_pairs_become_flat_items(): void
    {
        $result = $this->build([
            'New Window' => 'new_window',
            'Quit' => 'quit',
        ]);

        self::assertSame([
            ['label' => 'New Window', 'action' => 'new_window'],
            ['label' => 'Quit', 'action' => 'quit'],
        ], $result);
    }

    #[Test]
    public function triple_dash_value_becomes_a_separator(): void
    {
        $result = $this->build([
            'First' => 'first',
            'sep' => '---',
            'Second' => 'second',
        ]);

        self::assertCount(3, $result);
        self::assertSame(['label' => 'First', 'action' => 'first'], $result[0]);
        self::assertSame(['separator' => true], $result[1]);
        self::assertSame(['label' => 'Second', 'action' => 'second'], $result[2]);
    }

    #[Test]
    public function nested_array_value_becomes_a_submenu_with_items(): void
    {
        $result = $this->build([
            'File' => [
                'Open' => 'open_file',
                'Close' => 'close_file',
            ],
        ]);

        self::assertCount(1, $result);
        self::assertSame('File', $result[0]['label']);
        self::assertArrayHasKey('items', $result[0]);
        self::assertSame([
            ['label' => 'Open', 'action' => 'open_file'],
            ['label' => 'Close', 'action' => 'close_file'],
        ], $result[0]['items']);
    }

    #[Test]
    public function separators_inside_nested_submenus_are_preserved(): void
    {
        $result = $this->build([
            'Edit' => [
                'Undo' => 'undo',
                'sep' => '---',
                'Redo' => 'redo',
            ],
        ]);

        self::assertSame(['separator' => true], $result[0]['items'][1]);
    }

    #[Test]
    public function deeply_nested_menus_recurse_correctly(): void
    {
        $result = $this->build([
            'A' => [
                'B' => [
                    'C' => 'run_c',
                ],
            ],
        ]);

        self::assertSame('A', $result[0]['label']);
        self::assertSame('B', $result[0]['items'][0]['label']);
        self::assertSame(
            [['label' => 'C', 'action' => 'run_c']],
            $result[0]['items'][0]['items']
        );
    }
}
