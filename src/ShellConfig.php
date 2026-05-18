<?php

namespace NativeBlade;

use Closure;
use Illuminate\Support\Facades\DB;
use NativeBlade\Config\DesktopConfig;
use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\IosConfig;

/**
 * Root container for NativeBlade's runtime configuration and services.
 *
 * Resolved via the `'nativeblade'` container binding and exposed through
 * the `NativeBlade` facade. Holds shell config (bottom nav, top bar),
 * per-platform build configs (Desktop/Android/iOS), boot callback,
 * page transition, native action factory, persistent key/value state,
 * and the log sink.
 *
 * Unknown methods are delegated to a fresh `NativeResponse` via
 * `__call`, so every native action (alert, notification, camera, etc.)
 * is usable directly off the facade.
 *
 * @see \NativeBlade\Facades\NativeBlade
 * @see \NativeBlade\NativeResponse
 */
class ShellConfig
{
    /** @var array<string, mixed> Shell-level config (bottomNav, topBar, etc.) */
    private array $config = [];

    /** @var array<string, array<string, mixed>> Per-platform build configs keyed by 'desktop'|'android'|'ios'. */
    private static array $appConfigs = [];

    /** Default page transition applied to every `navigate` action. */
    private static string $transition = 'none';

    /** @var callable|null Callback executed once when the app boots (before first render). */
    private static $onBootCallback = null;

    // ------------------------------------------------------------------
    // Shell config
    // ------------------------------------------------------------------

    /**
     * Configure the native bottom navigation bar rendered outside the WebView.
     *
     * @param  array<int, array<string, mixed>>  $items  List of `{label, icon, path}` entries.
     */
    public function bottomNav(array $items): static
    {
        $this->config['bottomNav'] = $items;
        return $this;
    }

    /**
     * Configure the native top bar rendered outside the WebView.
     *
     * @param  array<string, mixed>  $options  Supports `title`, `backgroundColor`, `actions`, etc.
     */
    public function topBar(array $options): static
    {
        $this->config['topBar'] = $options;
        return $this;
    }

    /**
     * Serialize the full shell config for the JS bridge to consume at boot.
     *
     * Includes the active transition, the current platform's app config
     * (version, update URL, store URL), and any schedules registered via
     * Laravel's `Schedule::call()`.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $config = $this->config;
        if (static::$transition !== 'none') {
            $config['transition'] = static::$transition;
        }

        $platform = $this->platform();
        $platformKey = match (true) {
            in_array($platform, ['android']) => 'android',
            in_array($platform, ['ios']) => 'ios',
            default => 'desktop',
        };
        $platformConfig = static::$appConfigs[$platformKey] ?? [];

        if (isset($platformConfig['updateUrl']) && isset($platformConfig['version'])) {
            $config['update'] = [
                'url' => $platformConfig['updateUrl'],
                'currentVersion' => $platformConfig['version'],
                'storeUrl' => $platformConfig['storeUrl'] ?? null,
            ];
        }

        $schedules = Schedule\ScheduleRunner::extractSchedules();
        if (!empty($schedules)) {
            $config['schedules'] = $schedules;
        }

        return $config;
    }

    // ------------------------------------------------------------------
    // Platform configs
    // ------------------------------------------------------------------

    /**
     * Register the desktop (Windows/macOS/Linux) build config.
     *
     * @param  callable(DesktopConfig): void  $callback  Receives a fluent `DesktopConfig` builder.
     */
    public function desktop(callable $callback): void
    {
        $config = new DesktopConfig();
        $callback($config);
        static::$appConfigs['desktop'] = $config->toArray();
    }

    /**
     * Register the Android build config.
     *
     * @param  callable(AndroidConfig): void  $callback  Receives a fluent `AndroidConfig` builder.
     */
    public function android(callable $callback): void
    {
        $config = new AndroidConfig();
        $callback($config);
        static::$appConfigs['android'] = $config->toArray();
    }

    /**
     * Register the iOS build config.
     *
     * @param  callable(IosConfig): void  $callback  Receives a fluent `IosConfig` builder.
     */
    public function ios(callable $callback): void
    {
        $config = new IosConfig();
        $callback($config);
        static::$appConfigs['ios'] = $config->toArray();
    }

    /**
     * Declare which Tauri plugins to bundle. If not called, all plugins are
     * included by default (matches the legacy behavior). When declared, only
     * the listed plugins ship in the binary, capabilities, AndroidManifest,
     * Info.plist, and package.json — keeping the app footprint minimal and
     * avoiding store reviews flagging unused permissions.
     *
     * Always-on plugins (`dialog`, `os`, `process`, `store`, `fs`, `opener`)
     * are added automatically because NativeBlade core depends on them.
     *
     * ```php
     * NativeBladeConfig::plugins([
     *     Plugin::MEDIA,
     *     Plugin::PUSH,
     *     Plugin::HAPTICS,
     * ]);
     * ```
     *
     * @param  \NativeBlade\Config\Plugin[]  $plugins
     */
    public function plugins(array $plugins): void
    {
        static::$appConfigs['plugins'] = array_map(
            fn(\NativeBlade\Config\Plugin $p) => $p->value,
            $plugins
        );
    }

    /**
     * @return \NativeBlade\Config\Plugin[]|null  null when the dev hasn't called plugins()
     */
    public static function getDeclaredPlugins(): ?array
    {
        if (!isset(static::$appConfigs['plugins'])) return null;

        return array_filter(array_map(
            fn(string $v) => \NativeBlade\Config\Plugin::tryFrom($v),
            static::$appConfigs['plugins']
        ));
    }

    /**
     * Return every registered platform config, keyed by platform.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAppConfigs(): array
    {
        return static::$appConfigs;
    }

    /**
     * Read `version` + `buildNumber` from a specific platform's config.
     *
     * @param  string  $platform  One of `'desktop'`, `'android'`, `'ios'`.
     * @return array{version: string, buildNumber: int|string}
     *
     * @throws \RuntimeException When the platform has no version registered.
     */
    public static function getVersion(string $platform): array
    {
        $config = static::$appConfigs[$platform] ?? [];

        if (!isset($config['version']) || !isset($config['buildNumber'])) {
            throw new \RuntimeException("Version not configured for '{$platform}'. Add ->version('1.0.0', 1) in your AppServiceProvider.");
        }

        return [
            'version' => $config['version'],
            'buildNumber' => $config['buildNumber'],
        ];
    }

    // ------------------------------------------------------------------
    // Bundle push (OTA Laravel updates without store re-submit)
    // ------------------------------------------------------------------

    /**
     * Enable over-the-air updates for the Laravel bundle. The app checks
     * the URL on boot and, if a newer bundle version is available, downloads
     * and applies it. Only the Laravel/Livewire/Blade code updates — the
     * native shell stays the same.
     *
     * The endpoint must return JSON with this shape:
     * ```json
     * {
     *   "bundle": {
     *     "version": "1.0.5",
     *     "url": "https://cdn.myapp.com/bundles/laravel-bundle-1.0.5.json.gz",
     *     "minShellVersion": "1.0.0"
     *   }
     * }
     * ```
     *
     * `minShellVersion` guards against bundles that need a feature only
     * present in newer shell versions (e.g. a plugin you added). When the
     * shell is older than `minShellVersion`, the update is skipped — the
     * user has to update the shell first via the normal Tauri/store flow.
     *
     * @param  string  $url        URL returning the version JSON.
     * @param  bool    $autoApply  When true, downloads in background and
     *                             swaps the bundle on the next boot.
     */
    public function bundlePush(string $url, bool $autoApply = true): static
    {
        static::$appConfigs['bundlePush'] = [
            'url' => $url,
            'autoApply' => $autoApply,
        ];
        return $this;
    }

    // ------------------------------------------------------------------
    // Boot & transitions
    // ------------------------------------------------------------------

    /**
     * Register a callback that runs exactly once when the app boots, before
     * the first page is rendered. Useful for seeding state, running migrations,
     * priming caches.
     *
     * @param  callable  $callback
     */
    public function onBoot(callable $callback): static
    {
        static::$onBootCallback = $callback;
        return $this;
    }

    /** @return callable|null The boot callback registered via `onBoot()`, or null. */
    public static function getBootCallback(): ?callable
    {
        return static::$onBootCallback;
    }

    private const VALID_TRANSITIONS = ['none', 'slide', 'fade'];

    /**
     * Set the default page transition used when navigating between routes.
     *
     * @param  string  $type  One of `'none'`, `'slide'`, `'fade'`.
     * @throws \InvalidArgumentException If `$type` is not one of the supported transitions.
     */
    public function transition(string $type = 'fade'): static
    {
        if (!in_array($type, self::VALID_TRANSITIONS, true)) {
            throw new \InvalidArgumentException(
                "Invalid transition '{$type}'. Use one of: " . implode(', ', self::VALID_TRANSITIONS) . '.'
            );
        }
        static::$transition = $type;
        return $this;
    }

    /** @return string The currently active default transition. */
    public static function getTransition(): string
    {
        return static::$transition;
    }

    // ------------------------------------------------------------------
    // Platform detection
    // ------------------------------------------------------------------

    /**
     * Return the current platform identifier.
     *
     * @return string One of `'windows'`, `'macos'`, `'linux'`, `'android'`,
     *                `'ios'`, or `'web'` when running outside the Tauri shell.
     */
    public function platform(): string
    {
        if (isset($_SERVER['NATIVEBLADE_PLATFORM'])) {
            return $_SERVER['NATIVEBLADE_PLATFORM'];
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua) {
            if (preg_match('/Android/i', $ua)) return 'android';
            if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'ios';
            if (preg_match('/Macintosh|Mac OS X/i', $ua)) return 'macos';
            if (preg_match('/Windows/i', $ua)) return 'windows';
            if (preg_match('/Linux/i', $ua)) return 'linux';
        }

        return 'web';
    }

    /** True on Windows, macOS or Linux. */
    public function isDesktop(): bool
    {
        return in_array($this->platform(), ['windows', 'macos', 'linux']);
    }

    /** True on Android. */
    public function isAndroid(): bool
    {
        return $this->platform() === 'android';
    }

    /** True on iOS. */
    public function isIos(): bool
    {
        return $this->platform() === 'ios';
    }

    /** True on Android or iOS. */
    public function isMobile(): bool
    {
        return in_array($this->platform(), ['android', 'ios']);
    }

    /** True on Windows. */
    public function isWindows(): bool
    {
        return $this->platform() === 'windows';
    }

    /** True on macOS. */
    public function isMacos(): bool
    {
        return $this->platform() === 'macos';
    }

    /** True on Linux. */
    public function isLinux(): bool
    {
        return $this->platform() === 'linux';
    }

    /** True when running outside the Tauri shell (browser preview). */
    public function isWeb(): bool
    {
        return $this->platform() === 'web';
    }

    // ------------------------------------------------------------------
    // Native actions
    // ------------------------------------------------------------------

    /**
     * Start a fresh chain of native actions.
     *
     * Most actions (alert, confirm, notification, camera, etc.) are available
     * as shortcuts directly on the facade via `__call`, so you only need
     * `response()` when you want an empty chain to start with (e.g.
     * `NativeBlade::response()->exit()`).
     */
    public function response(): NativeResponse
    {
        return new NativeResponse();
    }

    /**
     * Write a log entry to PHP's stderr stream, which the JS runtime
     * captures and routes to the Tauri webview console.
     *
     * Works from any PHP context (Livewire actions, schedule jobs, boot
     * callbacks, routes, mount()). Logs survive PHP fatal errors because
     * stderr is preserved even when the process dies mid-execution.
     *
     * ```
     * NativeBlade::log('Exporting stats', ['user' => auth()->id()]);
     * NativeBlade::log('Retrying', ['attempt' => 3], 'warn');
     * NativeBlade::log('Payment failed', ['error' => $e->getMessage()], 'error');
     * NativeBlade::log('Query ran', ['ms' => 12], 'debug');
     * ```
     *
     * @param  string  $message  Human-readable message.
     * @param  array<string, mixed>  $context  Extra data shown as an expandable
     *                                         object in the browser devtools.
     * @param  string  $level  One of `'info'`, `'warn'`, `'error'`, `'debug'`.
     *                         Controls which `console.*` method is called
     *                         on the JS side and the color of the `[NB]` prefix.
     */
    public function log(string $message, array $context = [], string $level = 'info'): void
    {
        $entry = json_encode([
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents('php://stderr', "__NB_LOG__{$entry}__NB_LOG_END__\n");
    }

    /**
     * Delegate unknown method calls to a fresh NativeResponse so that every
     * action available on NativeResponse is also usable as a shortcut on
     * the NativeBlade facade (e.g. `NativeBlade::alert()`, `NativeBlade::vibrate()`).
     *
     * @param  array<int, mixed>  $args
     */
    public function __call(string $method, array $args): mixed
    {
        $response = new NativeResponse();
        if (method_exists($response, $method)) {
            return $response->{$method}(...$args);
        }
        throw new \BadMethodCallException("Method {$method} does not exist on NativeBlade.");
    }

    // ------------------------------------------------------------------
    // Persistent state
    // ------------------------------------------------------------------

    /**
     * Store a JSON-encoded value in the local SQLite state table.
     *
     * State persists between requests and app restarts on the same device.
     * Scopes let you group keys so you can bulk-clear via `flush($scope)`.
     *
     * @param  string  $key    Unique identifier (dot-notation by convention, e.g. `'auth.user'`).
     * @param  mixed   $value  Any JSON-serializable value.
     * @param  string  $scope  Namespace for bulk operations. Defaults to `'persistent'`.
     */
    public function setState(string $key, mixed $value, string $scope = 'persistent'): void
    {
        $this->ensureTable();
        DB::connection('sqlite')->statement(
            'INSERT OR REPLACE INTO nativeblade_state (key, value, scope) VALUES (?, ?, ?)',
            [$key, json_encode($value), $scope]
        );
    }

    /**
     * Read a value from the state table, decoding it from JSON.
     *
     * @param  string  $key      The key set previously via `setState()`.
     * @param  mixed   $default  Returned when the key is missing or the JSON is invalid.
     */
    public function getState(string $key, mixed $default = null): mixed
    {
        $this->ensureTable();
        $row = DB::connection('sqlite')->selectOne('SELECT value FROM nativeblade_state WHERE key = ?', [$key]);
        if (!$row) return $default;
        return json_decode($row->value, true) ?? $default;
    }

    /**
     * Return every key/value pair currently in the state table.
     *
     * @param  string|null  $scope  If provided, filters by scope.
     * @return array<string, mixed>
     */
    public function state(?string $scope = null): array
    {
        $this->ensureTable();
        $query = $scope
            ? DB::connection('sqlite')->select('SELECT key, value FROM nativeblade_state WHERE scope = ?', [$scope])
            : DB::connection('sqlite')->select('SELECT key, value FROM nativeblade_state');
        $state = [];
        foreach ($query as $row) {
            $state[$row->key] = json_decode($row->value, true);
        }
        return $state;
    }

    /** Remove a single key from the state table. */
    public function forget(string $key): void
    {
        $this->ensureTable();
        DB::connection('sqlite')->delete('DELETE FROM nativeblade_state WHERE key = ?', [$key]);
    }

    /**
     * Remove every key in a scope, or the whole table when no scope is given.
     *
     * @param  string|null  $scope  Scope to wipe, or null to delete everything.
     */
    public function flush(?string $scope = null): void
    {
        $this->ensureTable();
        if ($scope) {
            DB::connection('sqlite')->delete('DELETE FROM nativeblade_state WHERE scope = ?', [$scope]);
        } else {
            DB::connection('sqlite')->delete('DELETE FROM nativeblade_state');
        }
    }

    // ------------------------------------------------------------------
    // HTTP pool
    // ------------------------------------------------------------------

    /**
     * Execute a group of `Http::` requests in parallel via Tauri's native
     * HTTP stack, bypassing the single-request bridge pattern.
     *
     * @param  callable  $callback  Receives Laravel's `Http::pool()` builder.
     * @return array<int, mixed>    Responses in the same order as the pool calls.
     */
    public function pool(callable $callback): array
    {
        Http\WasmHttpHandler::enablePool();
        $results = \Illuminate\Support\Facades\Http::pool($callback);
        Http\WasmHttpHandler::flushPool();
        return $results;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Create the `nativeblade_state` table on first access. */
    private function ensureTable(): void
    {
        DB::connection('sqlite')->statement('CREATE TABLE IF NOT EXISTS nativeblade_state (key TEXT PRIMARY KEY, value TEXT, scope TEXT DEFAULT \'persistent\')');
    }
}
