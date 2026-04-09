<?php

namespace NativeBlade;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class NativeBladeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('nativeblade', function () {
            return new ShellConfig();
        });
    }

    public function boot(): void
    {
        $this->patchWasmRequest();
        $this->registerHttpBridge();
        $this->registerViews();
        $this->registerComponents();
        $this->registerViewComposer();
        $this->discoverCustomComponents();
        $this->discoverPackageComponents();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallCommand::class,
                Commands\AddCommand::class,
                Commands\ConfigCommand::class,
                Commands\DevCommand::class,
                Commands\ComponentCommand::class,
                Commands\IconCommand::class,
            ]);
        }
    }

    private function patchWasmRequest(): void
    {
        if (isset($GLOBALS['__wasm_request_body'])) {
            $body = $GLOBALS['__wasm_request_body'];
            $request = $this->app['request'];

            $contentType = $request->header('Content-Type', '');

            if (str_contains($contentType, 'application/json')) {
                $data = json_decode($body, true) ?: [];
                $request->merge($data);
                $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($data));
            }

            $reflection = new \ReflectionClass($request);
            $contentProp = $reflection->getProperty('content');
            $contentProp->setAccessible(true);
            $contentProp->setValue($request, $body);
        }
    }

    private function registerHttpBridge(): void
    {
        if (!$this->isWasmRuntime()) {
            return;
        }

        \Illuminate\Support\Facades\Http::globalOptions([
            'handler' => \GuzzleHttp\HandlerStack::create(new Http\WasmHttpHandler()),
        ]);
    }

    private function isWasmRuntime(): bool
    {
        return isset($_SERVER['NATIVEBLADE_PLATFORM'])
            && $_SERVER['NATIVEBLADE_PLATFORM'] !== 'web';
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(static::packagePath('resources/views'), 'nativeblade');
    }

    private function registerComponents(): void
    {
        Blade::component('nativeblade-header', Components\NbHeader::class);
        Blade::component('nativeblade-action', Components\NbAction::class);
        Blade::component('nativeblade-bottom-nav', Components\NbBottomNav::class);
        Blade::component('nativeblade-tab', Components\NbTab::class);
        Blade::component('nativeblade-drawer', Components\NbDrawer::class);
        Blade::component('nativeblade-drawer-item', Components\NbDrawerItem::class);
        Blade::component('nativeblade-icon', Components\NbIcon::class);
        Blade::component('nativeblade-image', Components\NbImage::class);
        Blade::component('nativeblade-modal', Components\NbModal::class);
        Blade::component('nativeblade-safe', Components\NbSafe::class);
        Blade::component('nativeblade-skeleton', Components\NbSkeleton::class);
        Blade::component('nativeblade-font', Components\NbFont::class);
    }


    private static array $assetCache = [];

    public static function assetToDataUri(string $file): string
    {
        if (isset(self::$assetCache[$file])) {
            return self::$assetCache[$file];
        }

        $path = public_path($file);
        if (!file_exists($path)) return asset($file);

        $content = file_get_contents($path);

        if (str_starts_with($content, 'data:')) {
            self::$assetCache[$file] = $content;
            return $content;
        }

        $mime = match(strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        $result = 'data:' . $mime . ';base64,' . base64_encode($content);
        self::$assetCache[$file] = $result;
        return $result;
    }

    private function registerViewComposer(): void
    {
        View::composer('components.layouts.*', function ($view) {
            $view->with('shellConfig', app('nativeblade')->get());
        });
    }

    private function discoverCustomComponents(): void
    {
        $dir = base_path('nativeblade-components');
        if (!is_dir($dir)) return;

        $viewPaths = [];

        foreach (scandir($dir) as $folder) {
            if ($folder === '.' || $folder === '..') continue;
            $path = "{$dir}/{$folder}";
            if (!is_dir($path)) continue;

            $viewPaths[] = $path;

            $class = Str::studly($folder);
            $file = "{$path}/{$class}.php";

            if (file_exists($file)) {
                require_once $file;
                $fqcn = "App\\NativeBlade\\Components\\{$class}";
                if (class_exists($fqcn)) {
                    Blade::component("nativeblade-{$folder}", $fqcn);
                }
            }
        }

        if (!empty($viewPaths)) {
            $this->app['view']->addNamespace('nbc', $viewPaths);
        }
    }

    private function discoverPackageComponents(): void
    {
        $installed = base_path('vendor/composer/installed.json');
        if (!file_exists($installed)) return;

        $data = json_decode(file_get_contents($installed), true);
        $packages = $data['packages'] ?? $data;

        foreach ($packages as $package) {
            $extra = $package['extra']['nativeblade'] ?? null;
            if (!$extra) continue;

            $packageName = $package['name'] ?? '';
            $packagePath = base_path('vendor/' . $packageName);

            if (!empty($extra['components'])) {
                foreach ($extra['components'] as $name => $class) {
                    if (class_exists($class)) {
                        Blade::component("nativeblade-{$name}", $class);
                    }
                }
            }

            if (!empty($extra['views'])) {
                $viewPath = $packagePath . '/' . ltrim($extra['views'], '/');
                if (is_dir($viewPath)) {
                    $prefix = Str::slug(str_replace('/', '-', $packageName));
                    $this->app['view']->addNamespace("nb-{$prefix}", $viewPath);
                }
            }

            if (!empty($extra['js'])) {
                $jsPaths = (array) $extra['js'];
                foreach ($jsPaths as $name => $jsFile) {
                    $fullPath = $packagePath . '/' . ltrim($jsFile, '/');
                    if (file_exists($fullPath)) {
                        config(["nativeblade.package_js.{$name}" => $fullPath]);
                    }
                }
            }
        }
    }

    public static function packagePath(string $path = ''): string
    {
        return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : '');
    }
}
