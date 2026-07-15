<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ComponentCommand extends Command
{
    protected $signature = 'nativeblade:component {name? : Component name (kebab-case)}';
    protected $description = 'Create a new NativeBlade component';

    public function handle(): int
    {
        $name = $this->argument('name') ?? $this->ask('Component name (kebab-case)');
        $name = Str::kebab($name);
        $class = Str::studly($name);

        $type = $this->choice('Where does this component render?', [
            'shell' => 'Shell (outside WebView — header, nav, toast, dialog)',
            'embedded' => 'Embedded (inside WebView — modal, card, form)',
            'module' => 'Shell module (stateful, bound to a Livewire component via HasNativeShell)',
        ], 'shell');

        $this->newLine();
        $this->line("  <fg=magenta;options=bold>NativeBlade Component</>");
        $this->line("  Name:     <info>{$name}</info>");
        $this->line("  Class:    <info>{$class}</info>");
        $this->line("  Type:     <info>{$type}</info>");
        $this->newLine();

        match ($type) {
            'shell' => $this->createShellComponent($name, $class),
            'embedded' => $this->createEmbeddedComponent($name, $class),
            'module' => $this->createModuleComponent($name),
        };

        $this->newLine();
        $this->info("  Component created!");
        $this->newLine();

        if ($type === 'module') {
            $this->line("  <fg=yellow>Usage in your Livewire component (see NATIVE-SHELL.md):</>");
            $this->line("  <fg=gray>use HasNativeShell;</>");
            $this->line("  <fg=gray>protected string \$shell = '{$name}';</>");
            $this->newLine();
            $this->line("  <fg=yellow>Files:</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.js</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.css</>");
        } elseif ($type === 'shell') {
            $this->line("  <fg=yellow>Usage in Blade:</>");
            $this->line("  <fg=gray><x-nativeblade-{$name} message=\"Hello\" /></>");
            $this->newLine();
            $this->line("  <fg=yellow>Files:</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.js</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.css</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$class}.php</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.blade.php</>");
        } else {
            $this->line("  <fg=yellow>Usage in Blade:</>");
            $this->line("  <fg=gray><x-nativeblade-{$name}>Content</x-nativeblade-{$name}></>");
            $this->newLine();
            $this->line("  <fg=yellow>Files:</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$class}.php</>");
            $this->line("  <fg=gray>nativeblade-components/{$name}/{$name}.blade.php</>");
        }

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

    private function createModuleComponent(string $name): void
    {
        $dir = base_path("nativeblade-components/{$name}");
        @mkdir($dir, 0755, true);

        file_put_contents("{$dir}/{$name}.js", $this->moduleJs($name));
        file_put_contents("{$dir}/{$name}.css", $this->shellCss($name));

        $this->task("Created {$name}.js");
        $this->task("Created {$name}.css");
    }

    private function createEmbeddedComponent(string $name, string $class): void
    {
        $dir = base_path("nativeblade-components/{$name}");
        @mkdir($dir, 0755, true);

        file_put_contents("{$dir}/{$class}.php", $this->embeddedPhp($name, $class));
        file_put_contents("{$dir}/{$name}.blade.php", $this->embeddedBlade($name));

        $this->task("Created {$class}.php");
        $this->task("Created {$name}.blade.php");
    }

    private function shellJs(string $name): string
    {
        return <<<JS
import './{$name}.css';

let el = null;

export function render(config) {
    if (!config) {
        if (el) el.style.display = 'none';
        return;
    }

    if (!el) {
        el = document.createElement('div');
        el.id = 'nb-{$name}';
        document.body.appendChild(el);
    }

    // TODO: render your component
    el.style.display = 'block';
}

export function hide() {
    if (el) el.style.display = 'none';
}
JS;
    }

    private function moduleJs(string $name): string
    {
        return <<<JS
import './{$name}.css';

// Native shell module (see NATIVE-SHELL.md). Bound to a Livewire component:
//   use HasNativeShell;  protected string \$shell = '{$name}';
// Keep state on `this` — each mount gets its own clone of this object.
export default {
    el: null,

    mount(ctx, props) {
        this.el = document.createElement('div');
        this.el.id = 'nb-{$name}';
        ctx.place(this.el, 'top-center'); // optional helper — override/extend with your own styles after
        document.body.appendChild(this.el);
        this.update(props);

        // ctx.set('key', value)  -> write a #[NativeProp(from: 'shell')] prop
        // ctx.emit('event', {})  -> #[On('nb:shell:{$name}:event')] in PHP
    },

    update(props) {
        // PARTIAL patch: only the props that CHANGED since the last flush —
        // absence means "unchanged", never "false". Guard with `'key' in props`.
        // TODO: apply the PHP-owned #[NativeProp] values
    },

    command(name, args) {
        // TODO: handle \$this->shell('...') commands
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

    private function embeddedPhp(string $name, string $class): string
    {
        $namespace = 'App\\NativeBlade\\Components';
        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\View\Component;

class {$class} extends Component
{
    public function __construct(
        public string \$class = '',
    ) {}

    public function render()
    {
        return view('nbc::{$name}');
    }
}
PHP;
    }

    private function embeddedBlade(string $name): string
    {
        return <<<'BLADE'
<div class="bg-gray-900 rounded-lg border border-gray-800 p-4 {{ $class }}">
    {{ $slot }}
</div>
BLADE;
    }

    private function task(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
    }
}
