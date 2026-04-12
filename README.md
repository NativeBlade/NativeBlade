<p align="center">
  <img src="banner_nb.png" alt="NativeBlade" width="100%">
</p>

<p align="center">
  <strong>Build desktop & mobile apps with Laravel + Livewire. No Electron. No React Native. Just PHP.</strong>
</p>

<p align="center">
  <a href="https://discord.gg/Vzpach5J2h"><img src="https://img.shields.io/badge/Discord-Join%20Community-5865F2?logo=discord&logoColor=white" alt="Discord"></a>
</p>

<p align="center">
  <a href="CONFIGURATION.md">Configuration</a> &bull;
  <a href="COMPONENTS.md">Components</a> &bull;
  <a href="DIRECTIVES.md">Directives</a> &bull;
  <a href="PLUGINS.md">Plugins</a> &bull;
  <a href="ANIMATIONS.md">Animations</a> &bull;
  <a href="LIFECYCLE.md">Lifecycle</a> &bull;
  <a href="DATABASE.md">Database</a> &bull;
  <a href="FILESYSTEM.md">Filesystem</a> &bull;
  <a href="BUILD.md">Build</a> &bull;
  <a href="SCHEDULER.md">Scheduler</a> &bull;
  <a href="UPDATES.md">Auto-Update</a> &bull;
  <a href="PUBLISH.md">Publish</a>
</p>

---

<p align="center">
  <img src="hello.gif" alt="NativeBlade Demo" width="600">
</p>

---

NativeBlade lets Laravel developers build **desktop** and **mobile** apps using only **PHP and Blade**. Your entire Laravel + Livewire application runs inside a PHP WebAssembly runtime, wrapped in a [Tauri 2](https://v2.tauri.app) shell. No JavaScript frameworks. No API layers. Just the Laravel you already know.

## Features

- **Pure Laravel** — Routes, Livewire components, Blade templates, Eloquent (SQLite)
- **Native Shell** — Top bar, bottom navigation, drawer, modal, tray — all outside the WebView
- **Native APIs** — Dialogs, notifications, camera, geolocation, haptics, biometric, NFC, barcode
- **Desktop** — Windows, macOS, Linux with native menus and system tray
- **Mobile** — Android & iOS with status bar, safe area, orientation control
- **Animations** — 90+ [Animate.css](https://animate.style/) animations + custom NativeBlade animations via `nb-animation` attribute
- **Offline-First** — SQLite persisted to IndexedDB, works without a server
- **Hot Reload** — Vite HMR for instant feedback during development
- **Icons** — 3,024 [Phosphor Icons](https://phosphoricons.com/) (regular + fill) included
- **Custom Fonts** — Offline font loading via base64 embedding
- **Page Transitions** — Fade, slide, zoom, flip, bounce, blur — powered by Animate.css

## Requirements

- PHP 8.3, 8.4, or 8.5 (with GD extension)
- Laravel 11, 12, or 13
- Livewire 3
- Node.js 20+
- Rust — [install here](https://www.rust-lang.org/tools/install)

## Quick Start

```bash
# 1. Create a new Laravel project
composer create-project laravel/laravel my-app
cd my-app

# 2. Install NativeBlade
composer require nativeblade/nativeblade
php artisan nativeblade:install

# 3. Build the frontend
npm run build

# 4. Launch the desktop app
php artisan nativeblade:dev
```

```
✓ src-tauri/          — Tauri project
✓ layouts/            — Blade layouts with shell support
✓ vite.wasm.config.js — Vite config for WASM bundling
✓ AppServiceProvider  — NativeBlade config
✓ Demo app            — Login, Trail, Lesson, Rank, Profile
```

> The first run compiles the Rust binary, which takes a few minutes. Subsequent runs are fast.

### Add Mobile

```bash
php artisan nativeblade:add android
php artisan nativeblade:dev --platform=android

php artisan nativeblade:add ios
php artisan nativeblade:dev --platform=ios
```

## How It Works

```
┌─────────────────────────────────────────────┐
│  Tauri Shell (native window)                │
│  ┌──────────────────────────────┐           │
│  │  Top Bar / Header            │  ← Shell  │
│  ├──────────────────────────────┤           │
│  │                              │           │
│  │   iframe (srcdoc)            │           │
│  │   ┌──────────────────────┐   │           │
│  │   │  Laravel + Livewire  │   │  ← Your  │
│  │   │  via PHP WebAssembly │   │    App    │
│  │   └──────────────────────┘   │           │
│  │                              │           │
│  ├──────────────────────────────┤           │
│  │  Bottom Navigation           │  ← Shell  │
│  └──────────────────────────────┘           │
└─────────────────────────────────────────────┘
```

1. **Boot** — PHP WebAssembly loads your Laravel app (8.3, 8.4, or 8.5)
2. **Migrate** — Pending migrations run automatically
3. **onBoot** — Your startup code runs (license check, data sync, API calls) while splash is visible
4. **Route** — Each navigation runs through Laravel's router inside WASM
5. **Render** — Blade/Livewire HTML is rendered in an iframe
6. **Intercept** — Fetch interceptor routes HTTP requests through WASM
7. **Bridge** — Native actions flow through `postMessage`
8. **Schedule** — Rust timers execute recurring tasks via Laravel Schedule
9. **Persist** — SQLite syncs to IndexedDB automatically

## Database

Migrations run automatically on boot — no `php artisan migrate` needed. Use standard Laravel migrations and Eloquent:

```bash
php artisan make:model Task -m
```

```php
// Migration runs automatically when the app opens
Schema::create('tasks', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->boolean('done')->default(false);
    $table->timestamps();
});
```

```php
// Eloquent works as usual
Task::create(['title' => 'Buy milk']);
$tasks = Task::where('done', false)->get();
$task->update(['done' => true]);
```

## onBoot Hook

Run code before the app becomes visible. Splash stays up until complete:

```php
NativeBladeConfig::onBoot(function () {
    $license = Http::get('https://api.myapp.com/license/check')->json();
    NativeBlade::setState('license', $license);
});
```

HTTP Bridge, Storage, Eloquent — everything works inside `onBoot`. See [LIFECYCLE.md](LIFECYCLE.md).

## Scheduler

Laravel Schedule powered by Rust native timers. Define in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => SyncService::run())->everyFiveMinutes()->name('sync');
Schedule::call(fn () => CacheService::cleanup())->daily()->name('cleanup');
```

All Laravel frequency methods work. Overdue tasks execute on next app open. See [LIFECYCLE.md](LIFECYCLE.md).

## State Management

```php
use NativeBlade\Facades\NativeBlade;

NativeBlade::setState('auth.user', ['name' => 'John']);
$user = NativeBlade::getState('auth.user');
NativeBlade::forget('auth.user');
NativeBlade::flush();
```

## Platform Detection

```php
NativeBlade::platform();   // 'windows', 'macos', 'linux', 'android', 'ios'
NativeBlade::isDesktop();
NativeBlade::isMobile();
NativeBlade::isAndroid();
NativeBlade::isIos();
```

## Navigation

```php
NativeBlade::navigate('/dashboard')->toResponse();
NativeBlade::navigate('/', replace: true)->toResponse();
```

```blade
<button wire:nb-navigate="/users">Users</button>
<button wire:nb-navigate.replace="/">Home</button>
```

## Authentication

```php
// Middleware
$user = NativeBlade::getState('auth.user');
if (!$user) {
    return NativeBlade::navigate('/login')->toResponse();
}

// Login
NativeBlade::setState('auth.user', ['name' => 'Admin', 'email' => $email]);
return NativeBlade::navigate('/', replace: true)->toResponse();

// Logout
NativeBlade::forget('auth.user');
NativeBlade::navigate('/login', replace: true)->toResponse();
```

## HTTP Bridge

Laravel's `Http` facade works transparently — WASM can't make network requests directly, so NativeBlade bridges them through JavaScript:

```php
$response = Http::get('https://api.github.com/users');

// Parallel requests
$responses = NativeBlade::pool(fn ($pool) => [
    $pool->get('https://api.com/users'),
    $pool->get('https://api.com/posts'),
]);
```

## Laravel Compatibility

| Works | Via Bridge | Not Available |
|-------|-----------|---------------|
| Routing, Blade, Livewire | `Http` facade | Queues, Mail (SMTP) |
| Eloquent (SQLite) | External APIs | Redis, Memcached |
| Middleware, Validation | Native Filesystem | WebSockets |
| Collections, Carbon | | MySQL, Postgres |
| Service Container | | Artisan CLI |
| Localization | | File Storage (S3) |
| Task Scheduling (via Rust) | | |
| Migrations (auto on boot) | | |

## Documentation

| Doc | Description |
|-----|-------------|
| [CONFIGURATION.md](CONFIGURATION.md) | Desktop, Android, iOS configs, permissions, privacy manifest, transitions |
| [COMPONENTS.md](COMPONENTS.md) | Shell components, icons, images, skeleton, fonts, safe area, custom components |
| [DIRECTIVES.md](DIRECTIVES.md) | wire:nb-bridge, wire:nb-navigate, nb-feedback, native actions |
| [PLUGINS.md](PLUGINS.md) | Built-in Tauri 2 plugin bridges (dialogs, notifications, clipboard, geolocation, haptics, biometric, barcode, NFC, opener, OS info) |
| [ANIMATIONS.md](ANIMATIONS.md) | nb-animation, Animate.css, custom animations, haptic feedback |
| [DATABASE.md](DATABASE.md) | SQLite local, native MySQL/PostgreSQL/MariaDB via Rust bridge |
| [LIFECYCLE.md](LIFECYCLE.md) | Boot sequence, onBoot hook, clock sync, migrations |
| [SCHEDULER.md](SCHEDULER.md) | Task scheduling with Rust native timers |
| [FILESYSTEM.md](FILESYSTEM.md) | Native filesystem, Storage driver, camera integration |
| [BUILD.md](BUILD.md) | Build command, output, CLI commands, icon generation |
| [UPDATES.md](UPDATES.md) | Auto-update for desktop and mobile |
| [PUBLISH.md](PUBLISH.md) | Publishing to stores |

## How NativeBlade Differs

| | NativeBlade | Electron | React Native | Flutter |
|---|---|---|---|---|
| **Language** | PHP + Blade | JavaScript | JavaScript | Dart |
| **Backend** | Built-in (Laravel) | Separate | Separate | Separate |
| **Binary Size** | ~15 MB | ~150 MB | ~30 MB | ~20 MB |
| **Learning Curve** | None (if you know Laravel) | Medium | High | High |
| **Native UI** | Shell + WebView | WebView only | Native | Custom rendering |
| **Offline** | Yes (WASM + IndexedDB) | Manual | Manual | Manual |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT

---

<p align="center">
  Built with Laravel, Livewire, Tauri, and PHP WebAssembly.<br>
  <a href="https://www.linkedin.com/in/jefferson-silva-66bba7aa/">Jefferson T.S</a>
</p>
