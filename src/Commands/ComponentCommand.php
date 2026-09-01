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

// NativeBlade shell component. It renders in the SHELL (outside the WebView), so
// it sits above the app and survives SPA navigations. There is NO Livewire here:
// `wire:model` / `wire:click` do not work in the shell, it is plain HTML + CSS.
// You write the JS, and `nb` is your one handle to everything:
//
//   nb.element             your root <div id="nb-{$name}"> (auto-created + removed)
//   nb.animate(sel?).enter/leave('slideInUp')   animate.css in/out (root or a child)
//   nb.php.watch(p, fn)    react to a PHP-owned #[NativeProp]
//   nb.php.listen(n, fn)   react to \$this->shell('n', ...args)
//   nb.php.set(k, v)       write a #[NativeProp(from: SHELL)]  (bound only)
//   nb.php.emit(e, data)   -> #[On('nb:shell:{$name}:e')] on any component
//   nb.php.props           the last props PHP pushed (read-only)
//   nb.context.place(el, 'bottom-center')   optional safe-area positioning
//   nb.onCleanup(fn)       teardown for anything you created yourself
//
// Summoned two ways, same file:
//   Declarative:   <x-nativeblade-{$name} message="Hi" />
//   Bound (state): use HasNativeShell; protected string \$shell = '{$name}';
//
// The function body IS the mount: state is plain closure variables, cleanup is
// automatic. Split by feature into helper functions before it grows into one
// long body.
export default (nb) => {
    nb.php.watch('message', (message) => {
        nb.element.textContent = message ?? '';
    });

    // nb.php.listen('do', (args) => { ... });          // <- \$this->shell('do', ...)
    // nb.element.onclick = () => nb.php.emit('tapped');  // -> #[On('nb:shell:{$name}:tapped')]
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
