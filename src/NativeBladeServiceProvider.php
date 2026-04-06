<?php

namespace NativeBlade;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\ServiceProvider;

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
        $this->disableCsrf();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallCommand::class,
                Commands\ConfigCommand::class,
                Commands\DevCommand::class,
                Commands\ComponentCommand::class,
            ]);
        }
    }

    private function disableCsrf(): void
    {
        $this->app->resolving(VerifyCsrfToken::class, function ($middleware) {
            $middleware->except(['*']);
        });
    }

    public static function packagePath(string $path = ''): string
    {
        return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : '');
    }
}
