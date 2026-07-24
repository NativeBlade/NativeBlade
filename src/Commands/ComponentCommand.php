<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ComponentCommand extends Command
{
    protected $signature = 'nativeblade:component {name? : Component name (kebab-case)}';
    protected $description = 'Create a new NativeBlade shell component';

    public function handle(): int
    {
        $name = $this->argument('name') ?? $this->ask('Component name (kebab-case)');
        $name = Str::kebab($name);
        $class = Str::studly($name);

        $this->newLine();
        $this->line("  <fg=magenta;options=bold>NativeBlade Shell Component</>");
        $this->line("  Name:     <info>{$name}</info>");
        $this->line("  Class:    <info>{$class}</info>");
        $this->newLine();

        $this->createShellComponent($name, $class);

        $this->newLine();
        $this->info("  Component created!");
        $this->newLine();

        $this->line("  <fg=yellow>Use it either way (same files):</>");
        $this->line("  <fg=gray>Declarative:  <x-nativeblade-{$name} message=\"Hello\" /></>");
        $this->line("  <fg=gray>Bound (state): use HasNativeShell; protected \$shell = '{$name}';</>");
        $this->newLine();
        $this->line("  <fg=yellow>Files:</>");
        $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.js</>");
        $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.css</>");
        $this->line("  <fg=gray>nativeblade-components/{$name}/{$class}.php</>");
        $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.blade.php</>");
        $this->newLine();

        return self::SUCCESS;
    }

    private function createShellComponent(string $name, string $class): void
    {
        $dir = base_path("nativeblade-components/{$name}");
        @mkdir($dir, 0755, true);

        file_put_contents("{$dir}/{$name}.js", $this->shellJs($name));
        file_put_contents("{$dir}/{$name}.css", $this->shellCss($name));
        file_put_contents("{$dir}/{$class}.php", $this->shellPhp($name, $class));
        file_put_contents("{$dir}/{$name}.blade.php", $this->shellBlade($name));

        $this->task("Created {$name}.js");
        $this->task("Created {$name}.css");
        $this->task("Created {$class}.php");
        $this->task("Created {$name}.blade.php");
    }

    private function shellJs(string $name): string
    {
        return <<<JS
import './{$name}.css';

// NativeBlade shell component. It renders in the SHELL (outside the WebView),
// so it sits above the app and survives SPA navigations. There is NO Livewire
// runtime here: `wire:model` / `wire:click` do not work in the shell. You write
// the reactivity yourself in plain JS, and talk to PHP through `ctx`.
//
// One file, summoned two ways:
//
//   1. Declarative (chrome, no host):  <x-nativeblade-{$name} message="Hi" />
//      mount(ctx, props) runs with props from the tag. `ctx.emit` fires a
//      GLOBAL event any #[On] can catch; `ctx.set` has no host to write to.
//
//   2. Bound to a Livewire component (stateful, two-way, persistent):
//        use HasNativeShell;
//        protected string \$shell = '{$name}';
//        #[NativeProp] public string \$message = '';                 // PHP -> shell
//        #[NativeProp(from: NativeProp::SHELL)] public int \$value = 0; // shell -> PHP
//      Now `ctx.set('value', ...)` lands in PHP, `\$this->shell('cmd', ...)`
//      reaches command(), and `\$shellPersist = true` keeps it across screens.
//
// Fill in what you need; delete command()/destroy() if you do not use them.
export default {
    el: null,

    mount(ctx, props) {
        this.el = document.createElement('div');
        this.el.id = 'nb-{$name}';
        document.body.appendChild(this.el);
        this.update(props);

        // ctx.place(this.el, 'bottom-center')  // optional safe-area positioning
        // ctx.emit('picked', { value })        // -> #[On('nb:shell:{$name}:picked')]
        // ctx.set('value', 42)                 // -> #[NativeProp(from: SHELL)] (bound only)
    },

    update(props) {
        // PARTIAL patch: only CHANGED props are present. Guard with `'key' in props`.
        if ('message' in props) this.el.textContent = props.message ?? '';
        this.el.style.display = 'block';
    },

    command(name, args) {
        // Handle \$this->shell('name', ...args) from the bound Livewire component.
    },

    destroy() {
        this.el?.remove();
        this.el = null;
    },
};
JS;
    }

    private function shellCss(string $name): string
    {
        return <<<CSS
#nb-{$name} {
    display: none;
    /* TODO: style your component */
}
CSS;
    }

    private function shellPhp(string $name, string $class): string
    {
        $namespace = 'App\\NativeBlade\\Components';
        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\View\Component;

class {$class} extends Component
{
    public function __construct(
        public string \$message = '',
    ) {}

    public function render()
    {
        return view('nbc::{$name}');
    }
}
PHP;
    }

    private function shellBlade(string $name): string
    {
        return '<div data-nb="' . $name . '" data-message="{{ $message }}" style="display:none">{{ $slot }}</div>' . "\n";
    }

    private function task(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
    }
}
