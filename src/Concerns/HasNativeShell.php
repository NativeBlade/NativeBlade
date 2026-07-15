<?php

namespace NativeBlade\Concerns;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use NativeBlade\Attributes\NativeProp;
use ReflectionClass;
use ReflectionProperty;

/**
 * Binds a Livewire component to a native shell module — a JS module living in
 * the webview SHELL (the parent window, outside the app iframe), loaded from
 * `public/js/shell/{name}.js`. Because the shell survives SPA navigations, a
 * shell module can keep playing video/audio across screens (mini-player) when
 * `$shellPersist = true`.
 *
 * Declare the module and mark synced props:
 *
 * ```
 * use HasNativeShell;
 *
 * protected string $shell = 'video-player';
 * protected bool $shellPersist = false;          // true: survives navigation
 *
 * #[NativeProp] public string $url = '';          // PHP -> shell on render
 * #[NativeProp(from: NativeProp::SHELL)]
 * public int $position = 0;                       // shell -> PHP at hydrate
 *
 * public function seekTo(int $s) { $this->shell('seek', $s); }
 *
 * #[On('nb:shell:video-player:ended')]            // module's ctx.emit('ended')
 * public function onEnded(): void { $this->playing = false; }
 * ```
 *
 * With multiple instances of the same component on screen, scope a listener
 * to one instance via the `$shellId` public prop Livewire interpolates:
 * `#[On('nb:shell:video-player:{shellId}:ended')]` — every event is emitted
 * on both the generic and the id-scoped name (same double-emit pattern as
 * realtime's per-channel routing).
 *
 * Data flow (all riding pipes that already exist — no new request types):
 *  - PHP -> shell: mount/update/command envelopes dispatched as `__nativeblade`
 *    actions with the response (`shell_module_*` handlers in the JS bridge).
 *  - shell -> PHP values: written to `/tmp/__nb_shell_props.json` before every
 *    PHP request by the wasm host; injected into `from: SHELL` props at
 *    hydrate. Zero extra requests.
 *  - shell -> PHP events: `ctx.emit()` -> interceptor -> Livewire `#[On]`
 *    (human-paced by design; high-frequency data belongs in shell-owned props
 *    or a `deliver: 'js'` realtime connection, never in events).
 *
 * @see \NativeBlade\Attributes\NativeProp
 */
trait HasNativeShell
{
    /**
     * The component id, mirrored as a public prop so event listeners can be
     * scoped per instance: `#[On('nb:shell:player:{shellId}:ended')]`.
     */
    #[Locked]
    public string $shellId = '';

    /** @var array<int, array{action: string, data: array<string, mixed>}> */
    private array $nativeShellQueue = [];

    public function mountHasNativeShell(): void
    {
        $this->shellId = $this->getId();

        $this->nativeShellQueue[] = ['action' => 'shell_module_mount', 'data' => [
            'shell' => $this->nativeShellName(),
            'id' => $this->getId(),
            'props' => $this->nativePropValues(),
            'shellProps' => $this->nativeShellOwnedSpecs(),
            'persist' => property_exists($this, 'shellPersist') && (bool) $this->shellPersist,
        ]];
    }

    /**
     * Inject shell-owned prop values snapshotted by the wasm host right before
     * this request, so `from: SHELL` props are current when the action runs.
     */
    public function hydrateHasNativeShell(): void
    {
        $file = $this->nativeShellPropsFile();
        if (!is_file($file)) {
            return;
        }

        $all = json_decode((string) @file_get_contents($file), true);
        $mine = is_array($all) ? ($all[$this->getId()] ?? null) : null;
        if (!is_array($mine)) {
            return;
        }

        foreach ($this->nativeProps() as $name => $attr) {
            if ($attr->from === NativeProp::SHELL && array_key_exists($name, $mine)) {
                $this->{$name} = $mine[$name];
            }
        }
    }

    /**
     * Flush the envelope with the response: mount first (when this request
     * mounted), then the current PHP-owned props, then queued commands in the
     * order they were issued — so a command always sees the props that
     * preceded it.
     */
    public function renderedHasNativeShell(mixed ...$args): void
    {
        $mounts = [];
        $rest = [];
        foreach ($this->nativeShellQueue as $item) {
            if ($item['action'] === 'shell_module_mount') {
                $mounts[] = $item;
            } else {
                $rest[] = $item;
            }
        }
        $this->nativeShellQueue = [];

        $update = ['action' => 'shell_module_update', 'data' => [
            'shell' => $this->nativeShellName(),
            'id' => $this->getId(),
            'props' => $this->nativePropValues(),
        ]];

        $this->dispatch('__nativeblade', actions: [...$mounts, $update, ...$rest]);
    }

    /**
     * Send an explicit command to the shell module (`module.command(name, args)`).
     * This is how PHP moves a value AGAINST a prop's direction — e.g. seeking a
     * shell-owned position: `$this->shell('seek', 30)`.
     */
    public function shell(string $command, mixed ...$args): void
    {
        $this->nativeShellQueue[] = ['action' => 'shell_module_command', 'data' => [
            'shell' => $this->nativeShellName(),
            'id' => $this->getId(),
            'command' => $command,
            'args' => array_values($args),
        ]];
    }

    /**
     * Tear the module down explicitly. Non-persistent modules are destroyed
     * automatically on navigation; call this to end a persistent one (e.g.
     * "close mini-player").
     */
    public function shellDestroy(): void
    {
        $this->nativeShellQueue[] = ['action' => 'shell_module_destroy', 'data' => [
            'shell' => $this->nativeShellName(),
            'id' => $this->getId(),
        ]];
    }

    /**
     * Receiver for throttled active pushes (`NativeProp(from: SHELL, throttle: N)`).
     * Every instance hears the event; only the addressed one applies it, and
     * only to a declared shell-owned prop — PHP-owned props are not writable
     * from the shell.
     */
    #[On('nb:shell-prop')]
    public function syncNativeShellProp(string $id, string $key, mixed $value): void
    {
        if ($id !== $this->getId()) {
            return;
        }

        $attr = $this->nativeProps()[$key] ?? null;
        if (!$attr || $attr->from !== NativeProp::SHELL) {
            return;
        }

        $this->{$key} = $value;
    }

    // ------------------------------------------------------------------

    protected function nativeShellName(): string
    {
        if (!property_exists($this, 'shell') || !is_string($this->shell) || $this->shell === '') {
            throw new \LogicException(
                static::class." uses HasNativeShell but does not define `protected string \$shell = '<module-name>';`"
            );
        }

        return $this->shell;
    }

    /** Where the wasm host snapshots shell-owned prop values before each request. */
    protected function nativeShellPropsFile(): string
    {
        return '/tmp/__nb_shell_props.json';
    }

    /** @return array<string, NativeProp> public property name -> attribute */
    private function nativeProps(): array
    {
        $props = [];
        foreach ((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attrs = $property->getAttributes(NativeProp::class);
            if ($attrs !== []) {
                $props[$property->getName()] = $attrs[0]->newInstance();
            }
        }

        return $props;
    }

    /** @return array<string, mixed> current values of the PHP-owned props */
    private function nativePropValues(): array
    {
        $values = [];
        foreach ($this->nativeProps() as $name => $attr) {
            if ($attr->from === NativeProp::PHP) {
                $values[$name] = $this->{$name};
            }
        }

        return $values;
    }

    /** @return array<int, array{name: string, throttle: int|null}> */
    private function nativeShellOwnedSpecs(): array
    {
        $specs = [];
        foreach ($this->nativeProps() as $name => $attr) {
            if ($attr->from === NativeProp::SHELL) {
                $specs[] = ['name' => $name, 'throttle' => $attr->throttle];
            }
        }

        return $specs;
    }
}
