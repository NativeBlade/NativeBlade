<?php

namespace NativeBlade;

use Illuminate\Support\Facades\DB;

class ShellConfig
{
    private array $config = [];
    private static array $appConfigs = [];

    public function bottomNav(array $items): static
    {
        $this->config['bottomNav'] = $items;
        return $this;
    }

    public function topBar(array $options): static
    {
        $this->config['topBar'] = $options;
        return $this;
    }

    public function get(): array
    {
        return $this->config;
    }

    public function desktop(callable $callback): void
    {
        $config = new AppConfig();
        $callback($config);
        static::$appConfigs['desktop'] = $config->toArray();
    }

    public function mobile(callable $callback): void
    {
        $config = new AppConfig();
        $callback($config);
        static::$appConfigs['mobile'] = $config->toArray();
    }

    public function android(callable $callback): void
    {
        $config = new AppConfig();
        $callback($config);
        static::$appConfigs['android'] = $config->toArray();
    }

    public function ios(callable $callback): void
    {
        $config = new AppConfig();
        $callback($config);
        static::$appConfigs['ios'] = $config->toArray();
    }

    public static function getAppConfigs(): array
    {
        return static::$appConfigs;
    }

    public function platform(): string
    {
        return $_SERVER['NATIVEBLADE_PLATFORM'] ?? 'web';
    }

    public function isDesktop(): bool
    {
        return in_array($this->platform(), ['windows', 'macos', 'linux']);
    }

    public function isAndroid(): bool
    {
        return $this->platform() === 'android';
    }

    public function isIos(): bool
    {
        return $this->platform() === 'ios';
    }

    public function isMobile(): bool
    {
        return in_array($this->platform(), ['android', 'ios']);
    }

    public function isWindows(): bool
    {
        return $this->platform() === 'windows';
    }

    public function isMacos(): bool
    {
        return $this->platform() === 'macos';
    }

    public function isLinux(): bool
    {
        return $this->platform() === 'linux';
    }

    public function isWeb(): bool
    {
        return $this->platform() === 'web';
    }

    public function alert(string $message): NativeResponse
    {
        return (new NativeResponse())->alert($message);
    }

    public function notification(string $body): NativeResponse
    {
        return (new NativeResponse())->notification($body);
    }

    public function navigate(string $path): NativeResponse
    {
        return (new NativeResponse())->navigate($path);
    }

    public function response(): NativeResponse
    {
        return new NativeResponse();
    }

    public function setState(string $key, mixed $value, string $scope = 'persistent'): void
    {
        $this->ensureTable();
        DB::statement(
            'INSERT OR REPLACE INTO nativeblade_state (key, value, scope) VALUES (?, ?, ?)',
            [$key, json_encode($value), $scope]
        );
    }

    public function getState(string $key, mixed $default = null): mixed
    {
        $this->ensureTable();
        $row = DB::selectOne('SELECT value FROM nativeblade_state WHERE key = ?', [$key]);
        if (!$row) return $default;
        return json_decode($row->value, true) ?? $default;
    }

    public function state(?string $scope = null): array
    {
        $this->ensureTable();
        $query = $scope
            ? DB::select('SELECT key, value FROM nativeblade_state WHERE scope = ?', [$scope])
            : DB::select('SELECT key, value FROM nativeblade_state');
        $state = [];
        foreach ($query as $row) {
            $state[$row->key] = json_decode($row->value, true);
        }
        return $state;
    }

    public function forget(string $key): void
    {
        $this->ensureTable();
        DB::delete('DELETE FROM nativeblade_state WHERE key = ?', [$key]);
    }

    public function flush(?string $scope = null): void
    {
        $this->ensureTable();
        if ($scope) {
            DB::delete('DELETE FROM nativeblade_state WHERE scope = ?', [$scope]);
        } else {
            DB::delete('DELETE FROM nativeblade_state');
        }
    }

    private function ensureTable(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS nativeblade_state (key TEXT PRIMARY KEY, value TEXT, scope TEXT DEFAULT \'persistent\')');
    }
}
