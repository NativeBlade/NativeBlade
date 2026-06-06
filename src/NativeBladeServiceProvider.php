<?php

namespace NativeBlade;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;

class NativeBladeServiceProvider extends ServiceProvider
{
    private const ASSET_CACHE_MAX = 128;

    /** @var array<string, string> */
    private static array $assetCache = [];

    public function register(): void
    {
        $this->app->singleton('nativeblade', function () {
            return new ShellConfig();
        });
    }

    public function boot(): void
    {
        $this->registerNativeDatabase();
        $this->registerNativeCache();
        $this->syncClock();
        $this->patchWasmRequest();
        $this->registerHttpBridge();
        $this->registerNativeStorage();
        $this->registerViews();
        $this->registerComponents();
        $this->registerViewComposer();
        $this->discoverCustomComponents();
        $this->discoverPackageComponents();
        $this->registerScheduleRoute();
        $this->registerPushRoutes();

        if (!$this->app->runningInConsole()) {
            $this->app->booted(function () {
                $this->runMigrations();
                app()->setLocale($this->app->make('nativeblade')->currentLanguage());
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallCommand::class,
                Commands\AddCommand::class,
                Commands\ConfigCommand::class,
                Commands\DevCommand::class,
                Commands\ComponentCommand::class,
                Commands\IconCommand::class,
                Commands\BuildCommand::class,
                Commands\BundleCommand::class,
                Commands\DeepLinksCommand::class,
                Commands\SignCommand::class,
                Commands\PhpVersionCommand::class,
                Commands\McpCommand::class,
            ]);
        }
    }

    private function patchWasmRequest(): void
    {
        if (!isset($GLOBALS['__wasm_request_body'])) {
            return;
        }

        $body = $GLOBALS['__wasm_request_body'];
        $request = $this->app['request'];
        $contentType = $request->header('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $data = json_decode($body, true) ?: [];
            $request->merge($data);
            $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($data));
        }

        try {
            $reflection = new \ReflectionClass($request);
            if ($reflection->hasProperty('content')) {
                $contentProp = $reflection->getProperty('content');
                $contentProp->setAccessible(true);
                $contentProp->setValue($request, $body);
            }
        } catch (\Throwable $e) {
            @fwrite(STDERR, '[NativeBlade] patchWasmRequest: ' . $e->getMessage() . "\n");
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
        Blade::component('nativeblade-animate', Components\NbAnimate::class);
        Blade::component('nativeblade-font', Components\NbFont::class);
    }

    public static function assetToDataUri(string $file): string
    {
        if (isset(self::$assetCache[$file])) {
            return self::$assetCache[$file];
        }

        $path = public_path($file);
        if (!file_exists($path)) return asset($file);

        $content = file_get_contents($path);

        if (str_starts_with($content, 'data:')) {
            return self::rememberAsset($file, $content);
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

        return self::rememberAsset($file, 'data:' . $mime . ';base64,' . base64_encode($content));
    }

    private static function rememberAsset(string $file, string $value): string
    {
        if (count(self::$assetCache) >= self::ASSET_CACHE_MAX) {
            array_shift(self::$assetCache);
        }
        self::$assetCache[$file] = $value;
        return $value;
    }

    private function syncClock(): void
    {
        $realTs = $_SERVER['NATIVEBLADE_TIMESTAMP'] ?? null;
        if (!$realTs) {
            return;
        }

        \Illuminate\Support\Facades\Date::setTestNow(
            \Carbon\CarbonImmutable::createFromTimestamp((float) $realTs)
        );
    }

    private function registerScheduleRoute(): void
    {
        if (!$this->isWasmRuntime()) {
            return;
        }

        \Illuminate\Support\Facades\Route::get('/__nb/schedule/{name}', function (string $name) {
            $ran = Schedule\ScheduleRunner::runByName($name);
            return response()->json(['ran' => $ran]);
        });

        \Illuminate\Support\Facades\Route::get('/__nb/boot', function () {
            $callback = ShellConfig::getBootCallback();
            if ($callback) {
                $callback();
            }
            return response()->json(['ok' => true]);
        });
    }

    private function registerPushRoutes(): void
    {
        if (!$this->isWasmRuntime()) {
            return;
        }

        $readJsonBody = fn(): array => $this->readJsonBody();
        $skipCsrf = [\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class];

        \Illuminate\Support\Facades\Route::post('/_nativeblade/push', function () use ($readJsonBody) {
            $payload = Plugins\PushPayload::fromArray($readJsonBody());
            $result = Plugins\PushRegistry::handleReceive($payload);

            if ($result instanceof NativeResponse) {
                return $result->toResponse() ?? response()->json(['ok' => true]);
            }
            return response()->json(['ok' => true]);
        })->withoutMiddleware($skipCsrf);

        \Illuminate\Support\Facades\Route::post('/_nativeblade/push-token', function () use ($readJsonBody) {
            $token = (string) ($readJsonBody()['token'] ?? '');
            if ($token === '') {
                return response()->json(['ok' => false, 'error' => 'invalid token'], 422);
            }

            $result = Plugins\PushRegistry::handleTokenRefresh($token);

            if ($result instanceof NativeResponse) {
                return $result->toResponse() ?? response()->json(['ok' => true]);
            }
            return response()->json(['ok' => true]);
        })->withoutMiddleware($skipCsrf);

        \Illuminate\Support\Facades\Route::post('/_nativeblade/deep-link', function () use ($readJsonBody) {
            $url = (string) ($readJsonBody()['url'] ?? '');
            if ($url === '') {
                return response()->json(['ok' => false, 'error' => 'invalid url'], 422);
            }

            $result = Plugins\DeepLinkRegistry::handle($url);

            if ($result instanceof NativeResponse) {
                return $result->toResponse() ?? response()->json(['ok' => true]);
            }
            return response()->json(['ok' => true]);
        })->withoutMiddleware($skipCsrf);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $body = $GLOBALS['__wasm_request_body'] ?? '';
        if ($body === '') {
            $body = @file_get_contents('php://input') ?: '';
        }
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function registerNativeDatabase(): void
    {
        \Illuminate\Database\Connection::resolverFor('nativeblade-db', function ($connection, $database, $prefix, $config) {
            return new Database\NativeConnection($config);
        });
    }

    /**
     * Auto-wire `Cache::*` to a SQLite-backed store that persists across app
     * restarts on the device. Registers a `nativeblade` cache store backed by
     * Laravel's standard `database` driver against the same `sqlite`
     * connection that `NativeBlade::setState()` writes to, and promotes it to
     * the default driver.
     *
     * Devs who want a different store can still call `Cache::store('xxx')`
     * per use, or override `cache.default` from their own service provider
     * after NativeBlade boots.
     */
    private function registerNativeCache(): void
    {
        config([
            'cache.stores.nativeblade' => [
                'driver' => 'database',
                'connection' => 'sqlite',
                'table' => 'nativeblade_cache',
                'lock_connection' => 'sqlite',
                'lock_table' => 'nativeblade_cache_locks',
                'lock_lottery' => [2, 100],
            ],
        ]);

        config(['cache.default' => 'nativeblade']);

        $this->ensureCacheTables();
    }

    /**
     * Create the cache tables on first boot. Schema matches the columns
     * Laravel's `DatabaseStore` queries (key, value, expiration) so the
     * built-in driver works without modification.
     */
    private function ensureCacheTables(): void
    {
        try {
            $db = \Illuminate\Support\Facades\DB::connection('sqlite');
            $db->statement(
                'CREATE TABLE IF NOT EXISTS nativeblade_cache ('
                . 'key TEXT PRIMARY KEY NOT NULL, '
                . 'value TEXT NOT NULL, '
                . 'expiration INTEGER NOT NULL'
                . ')'
            );
            $db->statement(
                'CREATE TABLE IF NOT EXISTS nativeblade_cache_locks ('
                . 'key TEXT PRIMARY KEY NOT NULL, '
                . 'owner TEXT NOT NULL, '
                . 'expiration INTEGER NOT NULL'
                . ')'
            );
        } catch (\Throwable $e) {
            // sqlite connection may not be configured yet (e.g. early tests).
            // Tables are idempotent — first real call will create them.
        }
    }

    private function registerNativeStorage(): void
    {
        Storage::extend('nativeblade', function ($app, $config) {
            $adapter = new \NativeBlade\Storage\NativeFilesystemAdapter($config['purpose'] ?? 'app');
            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
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

    private function runMigrations(): void
    {
        try {
            $migrator = app('migrator');
            $migrator->usingConnection('sqlite', function () use ($migrator) {
                if (!$migrator->repositoryExists()) {
                    $migrator->getRepository()->createRepository();
                }
                $migrator->run(database_path('migrations'));
            });
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Log::error('[NativeBlade] migration failure', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } catch (\Throwable) {
                @fwrite(STDERR, '[NativeBlade] migration failure: ' . $e->getMessage() . "\n");
            }
            if (config('app.debug')) {
                throw $e;
            }
        }
    }

    public static function packagePath(string $path = ''): string
    {
        return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : '');
    }
}
