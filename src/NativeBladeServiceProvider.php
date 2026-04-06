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
        $this->registerViews();
        $this->registerComponents();
        $this->registerViewComposer();
        $this->discoverCustomComponents();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallCommand::class,
                Commands\ConfigCommand::class,
                Commands\DevCommand::class,
                Commands\ComponentCommand::class,
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
    }

    private function registerViewComposer(): void
    {
        View::composer('components.layouts.app', function ($view) {
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

    public static function packagePath(string $path = ''): string
    {
        return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : '');
    }
}
