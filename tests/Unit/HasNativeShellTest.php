<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use LogicException;
use NativeBlade\Attributes\NativeProp;
use NativeBlade\Concerns\HasNativeShell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HasNativeShell wires a Livewire component to a shell module through the
 * shell_module_* action envelope. These tests exercise the trait against a
 * fake component (getId/dispatch stubs) — no Livewire runtime needed.
 */
final class HasNativeShellTest extends TestCase
{
    private function component(): FakeVideoComponent
    {
        return new FakeVideoComponent();
    }

    #[Test]
    public function mount_queues_the_mount_envelope_with_props_and_shell_specs(): void
    {
        $c = $this->component();
        $c->mountHasNativeShell();
        $c->renderedHasNativeShell();

        [$event, $params] = $c->dispatched[0];
        self::assertSame('__nativeblade', $event);

        $actions = $params['actions'];
        self::assertSame('shell_module_mount', $actions[0]['action']);
        self::assertSame([
            'shell' => 'video-player',
            'id' => 'lw-1',
            'owner' => FakeVideoComponent::class,
            'props' => ['url' => 'https://x/video.mp4', 'playing' => false],
            'shellProps' => [['name' => 'position', 'throttle' => 500]],
            'persist' => false,
        ], $actions[0]['data']);

        self::assertSame('lw-1', $c->shellId);
    }

    #[Test]
    public function rendered_flushes_mount_then_update_then_commands_in_order(): void
    {
        $c = $this->component();
        $c->mountHasNativeShell();
        $c->shell('seek', 30);
        $c->renderedHasNativeShell();

        $actions = $c->dispatched[0][1]['actions'];
        self::assertSame(
            ['shell_module_mount', 'shell_module_update', 'shell_module_command'],
            array_column($actions, 'action')
        );
        self::assertSame('seek', $actions[2]['data']['command']);
        self::assertSame([30], $actions[2]['data']['args']);
    }

    #[Test]
    public function update_envelope_carries_only_php_owned_props(): void
    {
        $c = $this->component();
        $c->playing = true;
        $c->renderedHasNativeShell();

        $update = $c->dispatched[0][1]['actions'][0];
        self::assertSame('shell_module_update', $update['action']);
        self::assertSame(
            ['url' => 'https://x/video.mp4', 'playing' => true],
            $update['data']['props']
        );
        self::assertArrayNotHasKey('position', $update['data']['props']);
    }

    #[Test]
    public function hydrate_injects_only_shell_owned_props_from_the_ride_along_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nbshell');
        file_put_contents($file, json_encode([
            'lw-1' => ['position' => 42, 'url' => 'https://evil/replaced.mp4'],
        ]));

        $c = $this->component();
        $c->propsFile = $file;
        $c->hydrateHasNativeShell();

        self::assertSame(42, $c->position, 'shell-owned prop is injected');
        self::assertSame('https://x/video.mp4', $c->url, 'PHP-owned prop must NOT be writable from the shell');

        unlink($file);
    }

    #[Test]
    public function hydrate_is_a_noop_without_a_snapshot_or_for_other_instances(): void
    {
        $c = $this->component();
        $c->propsFile = '/definitely/not/a/file.json';
        $c->hydrateHasNativeShell();
        self::assertSame(0, $c->position);

        $file = tempnam(sys_get_temp_dir(), 'nbshell');
        file_put_contents($file, json_encode(['other-id' => ['position' => 99]]));
        $c->propsFile = $file;
        $c->hydrateHasNativeShell();
        self::assertSame(0, $c->position, 'another instance\'s values must not leak in');
        unlink($file);
    }

    #[Test]
    public function throttled_push_applies_only_to_the_addressed_shell_owned_prop(): void
    {
        $c = $this->component();

        $c->syncNativeShellProp('lw-1', 'position', 7);
        self::assertSame(7, $c->position);

        $c->syncNativeShellProp('someone-else', 'position', 99);
        self::assertSame(7, $c->position, 'wrong instance id is ignored');

        $c->syncNativeShellProp('lw-1', 'url', 'https://evil/replaced.mp4');
        self::assertSame('https://x/video.mp4', $c->url, 'PHP-owned prop is not writable via push');
    }

    #[Test]
    public function shell_mount_requeues_a_mount_envelope_after_destroy(): void
    {
        $c = $this->component();
        $c->shellDestroy();
        $c->shellMount();
        $c->renderedHasNativeShell();

        $actions = array_column($c->dispatched[0][1]['actions'], 'action');
        self::assertSame(
            ['shell_module_destroy', 'shell_module_mount', 'shell_module_update'],
            $actions,
            'issue order is preserved (destroy before mount) with props right after the mount'
        );
    }

    #[Test]
    public function a_type_mismatched_shell_value_is_ignored_instead_of_fatalling(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nbshell');
        file_put_contents($file, json_encode([
            'lw-1' => ['position' => 'not-a-number'],
        ]));

        $c = $this->component();
        $c->propsFile = $file;
        $c->hydrateHasNativeShell();
        self::assertSame(0, $c->position, 'hydrate keeps the previous value on TypeError');

        $c->syncNativeShellProp('lw-1', 'position', ['array' => 'into int']);
        self::assertSame(0, $c->position, 'throttled push keeps the previous value on TypeError');

        unlink($file);
    }

    #[Test]
    public function a_lone_destroy_flushes_without_an_update_for_the_dead_instance(): void
    {
        $c = $this->component();
        $c->shellDestroy();
        $c->renderedHasNativeShell();

        $actions = array_column($c->dispatched[0][1]['actions'], 'action');
        self::assertSame(['shell_module_destroy'], $actions);
    }

    #[Test]
    public function missing_shell_declaration_throws(): void
    {
        $c = new FakeShellLessComponent();

        $this->expectException(LogicException::class);
        $c->mountHasNativeShell();
    }
}

final class FakeVideoComponent
{
    use HasNativeShell;

    protected string $shell = 'video-player';

    #[NativeProp]
    public string $url = 'https://x/video.mp4';

    #[NativeProp]
    public bool $playing = false;

    #[NativeProp(from: NativeProp::SHELL, throttle: 500)]
    public int $position = 0;

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    public array $dispatched = [];

    public string $propsFile = '';

    public function getId(): string
    {
        return 'lw-1';
    }

    public function dispatch(string $event, mixed ...$params): void
    {
        $this->dispatched[] = [$event, $params];
    }

    protected function nativeShellPropsFile(): string
    {
        return $this->propsFile;
    }
}

final class FakeShellLessComponent
{
    use HasNativeShell;

    public function getId(): string
    {
        return 'lw-2';
    }

    public function dispatch(string $event, mixed ...$params): void
    {
    }
}
