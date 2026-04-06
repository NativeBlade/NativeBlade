<p align="center">
  <img src="banner_nb.png" alt="NativeBlade" width="100%">
</p>

<p align="center">
  <strong>Build desktop & mobile apps with Laravel + Livewire. No Electron. No React Native. Just PHP.</strong>
</p>

<p align="center">
  <a href="#installation">Installation</a> &bull;
  <a href="#how-it-works">How It Works</a> &bull;
  <a href="#configuration">Configuration</a> &bull;
  <a href="#native-actions">Native Actions</a> &bull;
  <a href="#custom-components">Custom Components</a> &bull;
  <a href="#api-reference">API Reference</a>
</p>

---

NativeBlade lets Laravel developers build **desktop** and **mobile** apps using only **PHP and Blade**. Your entire Laravel + Livewire application runs inside a PHP WebAssembly runtime, wrapped in a [Tauri 2](https://v2.tauri.app) shell. No JavaScript frameworks. No API layers. Just the Laravel you already know.

## Features

- **Pure Laravel** — Routes, Livewire components, Blade templates, Eloquent (SQLite)
- **Native Shell** — Top bar, bottom navigation, drawer, tray icon — all outside the WebView
- **Native APIs** — Dialogs, notifications, camera, file system via PHP
- **Desktop** — Windows, macOS, Linux with native menus and system tray
- **Mobile** — Android & iOS with status bar, safe area, orientation control
- **Offline-First** — SQLite persisted to IndexedDB, works without a server
- **Hot Reload** — Vite HMR for instant feedback during development
- **Tiny Footprint** — Your app code stays in your project; the framework lives in `vendor/`

## Requirements

- PHP 8.3.x
- Laravel 11, 12, or 13
- Livewire 3
- Node.js 20+
- Rust — [install here](https://www.rust-lang.org/tools/install)

## Quick Start

From zero to a running desktop app in 4 steps:

```bash
# 1. Create a new Laravel project
composer create-project laravel/laravel my-app
cd my-app

# 2. Install NativeBlade
composer require nativeblade/nativeblade
php artisan nativeblade:install

# 3. Build the frontend assets
npm run build

# 4. Launch the desktop app
php artisan nativeblade:dev
```

The `nativeblade:install` command will ask for your **app name** and **identifier**, then scaffold everything automatically:

```
✓ src-tauri/          — Tauri project (Cargo.toml, main.rs, tauri.conf.json)
✓ layouts/            — app.blade.php with shell support
✓ vite.wasm.config.js — Vite config for WASM bundling
✓ AppServiceProvider  — NativeBlade config block
✓ Demo app            — Login + Home pages ready to go
```

> **Note:** The first run compiles the Rust/Tauri binary, which may take a few minutes. Subsequent runs are fast.

---

## How It Works

```
┌─────────────────────────────────────────────┐
│  Tauri Shell (native window)                │
│  ┌──────────────────────────────┐           │
│  │  Top Bar / Header            │  ← Shell  │
│  ├──────────────────────────────┤           │
│  │                              │           │
│  │   iframe (blob URL)          │           │
│  │   ┌──────────────────────┐   │           │
│  │   │  Laravel + Livewire  │   │  ← Your  │
│  │   │  rendered via        │   │    App    │
│  │   │  PHP WebAssembly     │   │           │
│  │   └──────────────────────┘   │           │
│  │                              │           │
│  ├──────────────────────────────┤           │
│  │  Bottom Navigation           │  ← Shell  │
│  └──────────────────────────────┘           │
└─────────────────────────────────────────────┘
```

1. **Boot** — PHP 8.3 WebAssembly loads your Laravel app (bundled as JSON)
2. **Route** — Each navigation request runs through Laravel's router inside WASM
3. **Render** — Blade/Livewire HTML is rendered and displayed in an iframe
4. **Intercept** — A fetch interceptor routes all HTTP requests through the WASM runtime
5. **Bridge** — Native actions (alerts, navigation, notifications) flow through `postMessage`
6. **Persist** — SQLite database syncs to IndexedDB automatically

### Request Flow

```
User clicks button
  → wire:click="save"
    → Livewire POST /livewire/update
      → Fetch interceptor catches it
        → Routes to PHP WASM runtime
          → Laravel processes the request
            → Response flows back to Livewire
              → DOM updates reactively
```

### NativeResponse Flow

```
Controller or Livewire method
  → NativeBlade::navigate('/dashboard')->toResponse()
    → Detects context automatically:
      ├─ Controller: returns JSON { nativeblade: true, actions: [...] }
      │   → Fetch interceptor detects it
      │     → Forwards to parent shell via postMessage
      │       → Shell executes the action
      │
      └─ Livewire: dispatches browser event
          → Interceptor listener catches it
            → Forwards to parent shell via postMessage
              → Shell executes the action
```

---

## Configuration

All configuration is done in PHP via your `AppServiceProvider`:

```php
use NativeBlade\Facades\NativeBlade;

public function boot(): void
{
    NativeBlade::desktop(function ($config) {
        $config->title('My App')
            ->identifier('com.myapp.desktop')
            ->icon('resources/icons/logo.png')
            ->size(1200, 800)
            ->minSize(800, 600)
            ->resizable()
            ->singleInstance()
            ->tray(
                icon: 'resources/icons/logo.png',
                tooltip: 'My App',
                menu: [
                    'Show'     => 'show',
                    'Settings' => '/settings',
                    '---',
                    'Quit'     => 'exit',
                ]
            )
            ->hideOnClose()
            ->menu([
                'File' => [
                    'Home'     => '/',
                    'Settings' => '/settings',
                    'Export'   => [
                        'PDF' => '/api/export/pdf',
                        'CSV' => '/api/export/csv',
                    ],
                    '---',
                    'Quit' => 'exit',
                ],
                'Help' => [
                    'About' => '/api/about',
                    'Docs'  => '/docs',
                ],
            ]);
    });

    NativeBlade::mobile(function ($config) {
        $config->orientation('portrait')
            ->statusBar(style: 'dark', color: '#0a0a0a')
            ->splash(bg: '#0a0a0a');
    });

    NativeBlade::android(function ($config) {
        $config->navigationBar(color: '#0a0a0a')
            ->backButton(true);
    });
}
```

After changing config, regenerate:

```bash
php artisan nativeblade:config
```

### Menu & Tray Actions

Menu items follow a simple convention:

| Value | Behavior |
|-------|----------|
| `/path` | Navigates to a route (renders Livewire page) |
| `/api/action` | Calls a controller route (NativeResponse is intercepted) |
| `exit` | Quits the application |
| `show` | Shows the window (tray only) |
| `---` | Separator |
| `[...]` | Submenu (nested array) |

### Desktop Options

| Method | Description |
|--------|-------------|
| `title(string)` | Window title and product name |
| `identifier(string)` | App identifier (com.example.app) |
| `icon(string)` | Path to app icon (PNG, generates all sizes) |
| `size(w, h)` | Default window size |
| `minSize(w, h)` | Minimum window size |
| `resizable(bool)` | Allow window resizing |
| `fullscreen(bool)` | Start in fullscreen |
| `singleInstance(bool)` | Prevent multiple instances |
| `hideOnClose(bool)` | Hide to tray instead of closing |
| `tray(icon, tooltip, menu)` | System tray configuration |
| `menu(array)` | Native menu bar |

### Mobile Options

| Method | Description |
|--------|-------------|
| `orientation(string)` | `portrait`, `landscape`, or `auto` |
| `statusBar(style, color)` | Status bar appearance |
| `navigationBar(color)` | Android navigation bar color |
| `safeArea(bool)` | Respect safe area insets |
| `splash(bg)` | Splash screen background color |
| `backButton(bool)` | Android back button support |
| `swipeBack(bool)` | iOS swipe back gesture |

---

## Icons

NativeBlade includes all 1,512 [Phosphor Icons](https://phosphoricons.com/) (regular style). Browse all available icons at [phosphoricons.com](https://phosphoricons.com/).

### In Shell Components

Use the icon name directly via the `icon` attribute:

```blade
<x-nativeblade-tab icon="house" label="Home" href="/" />
<x-nativeblade-action icon="bell" action="/api/notifications" badge="3" />
<x-nativeblade-drawer-item icon="gear" label="Settings" href="/settings" />
```

### In Blade Templates (Embedded)

Use the `<x-nativeblade-icon>` component anywhere in your Blade views:

```blade
<x-nativeblade-icon name="house" />
<x-nativeblade-icon name="gear" size="32" />
<x-nativeblade-icon name="bell" size="20" class="text-red-400" />

{{-- Inside a button --}}
<button class="flex items-center gap-2">
    <x-nativeblade-icon name="sign-out" size="18" />
    Logout
</button>
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `name` | — | Icon name from Phosphor (required) |
| `size` | `24` | Width and height in pixels |
| `class` | — | CSS classes |

---

## Shell Components

Shell components render **outside** the WebView — they never flicker during page transitions. Use them directly in your Blade templates:

### Header

```blade
{{-- Simple header --}}
<x-nativeblade-header title="Home" />

{{-- Header with back button --}}
<x-nativeblade-header title="Settings" :back="true" />

{{-- Header with action buttons --}}
<x-nativeblade-header title="Demo">
    <x-nativeblade-action icon="magnifying-glass" action="/api/search" />
    <x-nativeblade-action icon="bell" action="/api/notifications" badge="3" />
</x-nativeblade-header>
```

### Bottom Navigation

```blade
<x-nativeblade-bottom-nav>
    <x-nativeblade-tab icon="house" label="Home" href="/" />
    <x-nativeblade-tab icon="lightning" label="Demo" href="/demo" />
    <x-nativeblade-tab icon="gear" label="Settings" href="/settings" />
</x-nativeblade-bottom-nav>
```

### Drawer

```blade
<x-nativeblade-drawer title="My App">
    <x-nativeblade-drawer-item icon="house" label="Home" href="/" />
    <x-nativeblade-drawer-item icon="lightning" label="Demo" href="/demo" />
    <x-nativeblade-drawer-item icon="gear" label="Settings" href="/settings" />
</x-nativeblade-drawer>
```

### Full Page Example

```blade
{{-- resources/views/livewire/home.blade.php --}}
<div>
<x-nativeblade-header title="My App" />
<x-nativeblade-drawer title="My App">
    <x-nativeblade-drawer-item icon="house" label="Home" href="/" />
    <x-nativeblade-drawer-item icon="lightning" label="Demo" href="/demo" />
    <x-nativeblade-drawer-item icon="gear" label="Settings" href="/settings" />
</x-nativeblade-drawer>
<x-nativeblade-bottom-nav>
    <x-nativeblade-tab icon="house" label="Home" href="/" />
    <x-nativeblade-tab icon="lightning" label="Demo" href="/demo" />
    <x-nativeblade-tab icon="gear" label="Settings" href="/settings" />
</x-nativeblade-bottom-nav>

<div class="max-w-2xl mx-auto">
    <h1>Welcome!</h1>
    <p>Your content here.</p>
</div>
</div>
```

The Livewire component is clean — no shell configuration in PHP:

```php
<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Home extends Component
{
    public function render()
    {
        return view('livewire.home');
    }
}
```

### Available Shell Components

| Component | Description |
|-----------|-------------|
| `<x-nativeblade-header>` | Top bar with title, back button, and action slots |
| `<x-nativeblade-action>` | Header action button (icon + optional badge) |
| `<x-nativeblade-bottom-nav>` | Bottom tab navigation bar |
| `<x-nativeblade-tab>` | Tab item (icon + label + href) |
| `<x-nativeblade-drawer>` | Side drawer / hamburger menu |
| `<x-nativeblade-drawer-item>` | Drawer navigation item (icon + label + href) |

---

## Native Actions

NativeBlade provides two ways to trigger native functionality from Blade templates:

### `__nbBridge(action, payload)` — Direct Native Action

Executes immediately on the client side:

```blade
{{-- Alert dialog --}}
<button onclick="__nbBridge('alert', { message: 'Hello!', title: 'Info' })">
    Show Alert
</button>

{{-- Native notification --}}
<button onclick="__nbBridge('notification', { title: 'Done', body: 'Task completed' })">
    Notify
</button>

{{-- Navigate --}}
<button onclick="__nbBridge('navigate', { path: '/settings' })">
    Go to Settings
</button>

{{-- Camera --}}
<button onclick="__nbBridge('camera')">Take Photo</button>
<button onclick="__nbBridge('gallery')">Pick from Gallery</button>

{{-- Exit app --}}
<button onclick="__nbBridge('exit')">Quit</button>
```

### `__nbAction(url, method?, body?)` — Backend-Driven Action

Calls a Laravel route that returns a `NativeResponse`:

```blade
<button onclick="__nbAction('/api/export')">Export Data</button>
<button onclick="__nbAction('/api/logout', 'POST')">Logout</button>
```

```php
// routes/web.php
Route::post('/api/export', function () {
    // ... do work ...
    return NativeBlade::alert('Export complete!')
        ->title('Success')
        ->toResponse();
});
```

### Available Actions

| Action | Payload | Description |
|--------|---------|-------------|
| `alert` | `{message, title?, kind?}` | Native alert dialog |
| `notification` | `{title, body}` | System notification |
| `confirm` | `{message, title?}` | Confirm dialog (returns result) |
| `navigate` | `{path}` | Navigate to route |
| `camera` | `{quality?, maxWidth?}` | Open camera |
| `gallery` | — | Open image picker |
| `exit` | — | Close application |

---

## NativeResponse

`NativeResponse` is a fluent API for triggering native actions from PHP. It works transparently in both **controllers** and **Livewire components**:

```php
use NativeBlade\Facades\NativeBlade;

// In a Controller — returns JSON intercepted by the fetch override
return NativeBlade::navigate('/dashboard')->toResponse();

// In a Livewire component — automatically dispatches via Livewire events
NativeBlade::navigate('/dashboard')->toResponse();

// Same API everywhere. NativeBlade detects the context automatically.
```

### Chaining Actions

```php
return NativeBlade::alert('Data exported successfully!')
    ->title('Export')
    ->navigate('/downloads')
    ->toResponse();
```

### Available Methods

| Method | Description |
|--------|-------------|
| `alert(message)` | Show native alert |
| `title(string)` | Set title for last action |
| `confirm(label)` | Set confirm button label |
| `cancel(label)` | Set cancel button label |
| `notification(body)` | System notification |
| `navigate(path)` | Navigate to route |
| `exit()` | Close application |
| `toResponse()` | Execute (auto-detects Controller vs Livewire) |

---

## State Management

NativeBlade provides persistent state backed by SQLite, synced to IndexedDB:

```php
use NativeBlade\Facades\NativeBlade;

// Set state
NativeBlade::setState('auth.user', ['name' => 'John', 'email' => 'john@example.com']);
NativeBlade::setState('preferences.theme', 'dark');

// Get state
$user = NativeBlade::getState('auth.user');
$theme = NativeBlade::getState('preferences.theme', 'light'); // with default

// Get all state
$all = NativeBlade::state();
$persistent = NativeBlade::state('persistent'); // by scope

// Remove state
NativeBlade::forget('auth.user');
NativeBlade::flush(); // clear all
NativeBlade::flush('session'); // clear by scope
```

State persists across app restarts — it's stored in SQLite inside WASM, automatically synced to IndexedDB every 30 seconds and on page unload.

---

## Platform Detection

Write platform-specific logic in your Blade templates or PHP:

```php
use NativeBlade\Facades\NativeBlade;

if (NativeBlade::isDesktop()) {
    // Desktop-only logic
}

if (NativeBlade::isMobile()) {
    // Mobile-only logic
}

NativeBlade::platform();   // 'windows', 'macos', 'linux', 'android', 'ios'
NativeBlade::isWindows();
NativeBlade::isMacos();
NativeBlade::isLinux();
NativeBlade::isAndroid();
NativeBlade::isIos();
```

---

## Custom Components

NativeBlade supports two types of custom components:

### Shell Components

Render **outside** the WebView (in the native shell). Perfect for floating buttons, toasts, modals, or custom overlays. Shell components never flicker during page transitions because they live in the parent window, not inside the iframe.

```bash
php artisan nativeblade:component fab-button
# Select: shell
```

This creates:

```
nativeblade-components/
└── fab-button/
    ├── fab-button.js        ← Render logic (DOM manipulation)
    ├── fab-button.css       ← Styles
    ├── FabButton.php        ← Laravel component class
    └── fab-button.blade.php ← Blade template (data-nb attributes)
```

**How it works:**

1. Your Blade template outputs a hidden `<div data-nb="fab-button">` with data attributes
2. The framework extracts these from the HTML response
3. Your JS `render()` function receives the data and renders in the parent shell
4. When the component is absent from a page, `render(null)` is called so you can hide it

**Step 1 — Blade template** passes data to the shell via `data-*` attributes:

```blade
{{-- fab-button.blade.php --}}
<div data-nb="fab-button" data-icon="{{ $icon }}" data-action="{{ $action }}" style="display:none"></div>
```

**Step 2 — PHP class** defines the component props:

```php
// FabButton.php
namespace App\NativeBlade\Components;

use Illuminate\View\Component;

class FabButton extends Component
{
    public function __construct(
        public string $icon = 'plus',
        public string $action = '',
    ) {}

    public function render()
    {
        return view('nbc::fab-button');
    }
}
```

**Step 3 — JavaScript** renders the component outside the WebView:

```javascript
// fab-button.js
import './fab-button.css';
import { svg } from '@nativeblade/wasm-app/components/icons.js';

let el = null;

export function render(config) {
    if (!config) {
        if (el) el.style.display = 'none';
        return;
    }

    if (!el) {
        el = document.createElement('button');
        el.id = 'nb-fab-button';
        document.body.appendChild(el);

        el.addEventListener('click', () => {
            const action = el.dataset.action;
            if (action) {
                // Navigate or trigger native action
                window.postMessage({
                    type: 'nativeblade-navigate',
                    path: action,
                }, '*');
            }
        });
    }

    el.innerHTML = svg(config.icon || 'plus');
    el.dataset.action = config.action || '';
    el.style.display = 'flex';
}
```

**Step 4 — CSS** styles the floating button:

```css
/* fab-button.css */
#nb-fab-button {
    display: none;
    position: fixed;
    bottom: 80px;
    right: 20px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #a855f7;
    border: none;
    color: white;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    cursor: pointer;
    z-index: 100;
}
#nb-fab-button svg { width: 24px; height: 24px; }
```

**Use in any Blade view:**

```blade
<x-nativeblade-fab-button icon="plus" action="/create" />
```

The FAB will render outside the WebView. Pages that don't include the component will automatically hide it.

> **Icons in shell JS:** Import from `@nativeblade/wasm-app/components/icons.js` to use any of the 1,512 Phosphor icons via `svg('icon-name')`.

### Embedded Components

Render **inside** the WebView. Standard Laravel Blade components with a NativeBlade namespace.

```bash
php artisan nativeblade:component stat-card
# Select: embedded
```

```
nativeblade-components/
└── stat-card/
    ├── StatCard.php
    └── stat-card.blade.php
```

**Use in Blade:**

```blade
<x-nativeblade-stat-card class="mt-4">
    <h3>Revenue</h3>
    <p>$12,345</p>
</x-nativeblade-stat-card>
```

---

## Livewire Integration

NativeBlade works seamlessly with Livewire. Use `wire:model`, `wire:click`, `wire:poll`, and all Livewire features as usual:

```php
<?php

namespace App\Livewire;

use Livewire\Component;
use NativeBlade\Facades\NativeBlade;

class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public string $error = '';

    public function login()
    {
        if ($this->email === 'admin@example.com' && $this->password === 'secret') {
            NativeBlade::setState('auth.user', [
                'name' => 'Admin',
                'email' => $this->email,
            ]);

            // Works from Livewire — same API as controllers
            return NativeBlade::navigate('/')->toResponse();
        }

        $this->error = 'Invalid credentials';
    }

    public function render()
    {
        return view('livewire.login');
    }
}
```

```blade
<div>
    @if($error)
        <div class="text-red-400">{{ $error }}</div>
    @endif

    <input type="email" wire:model="email" placeholder="Email">
    <input type="password" wire:model="password" placeholder="Password">
    <button wire:click="login">Sign In</button>
</div>
```

### Navigation

Use `NativeBlade::navigate()` for navigation — standard `$this->redirect()` does not work in WASM context since there is no real HTTP server to redirect to:

```php
public function save()
{
    // ... save logic ...
    NativeBlade::navigate('/dashboard')->toResponse();
}
```

### wire:poll

Use `wire:poll` for real-time updates like session timers:

```blade
<div wire:poll.5s="checkSession">
    Session expires in {{ $remaining }}s
</div>
```

---

## Authentication Example

Since NativeBlade runs entirely client-side in WASM, authentication uses state management instead of traditional sessions:

### Middleware

```php
// app/Http/Middleware/AuthMiddleware.php
namespace App\Http\Middleware;

use Closure;
use NativeBlade\Facades\NativeBlade;

class AuthMiddleware
{
    public function handle($request, Closure $next)
    {
        $user = NativeBlade::getState('auth.user');

        if (!$user) {
            return NativeBlade::navigate('/login')->toResponse();
        }

        // Optional: session timeout
        $loggedAt = NativeBlade::getState('auth.logged_at');
        $timeout = 3600; // 1 hour

        if ($loggedAt && (now()->timestamp - $loggedAt) > $timeout) {
            NativeBlade::forget('auth.user');
            NativeBlade::forget('auth.logged_at');
            return NativeBlade::navigate('/login')->toResponse();
        }

        NativeBlade::setState('auth.logged_at', now()->timestamp);
        return $next($request);
    }
}
```

### Routes

```php
// routes/web.php
Route::get('/login', Login::class);

Route::middleware('auth.nativeblade')->group(function () {
    Route::get('/', Home::class);
    Route::get('/settings', Settings::class);
});
```

### Logout

```php
public function logout()
{
    NativeBlade::forget('auth.user');
    NativeBlade::forget('auth.logged_at');
    NativeBlade::navigate('/login')->toResponse();
}
```

---

## Project Structure

After installation, your project looks like this:

```
my-app/
├── app/
│   ├── Providers/AppServiceProvider.php    ← NativeBlade config
│   ├── Livewire/                           ← Your components
│   ├── Http/Controllers/                   ← Your controllers
│   └── Http/Middleware/                    ← Your middleware
├── resources/
│   ├── views/
│   │   ├── components/layouts/
│   │   │   ├── app.blade.php              ← Main layout
│   │   └── livewire/                      ← Your Livewire views
│   └── css/app.css
├── routes/web.php
├── nativeblade-components/                 ← Custom components
├── src-tauri/
│   ├── Cargo.toml                          ← depends on nativeblade-tauri
│   ├── tauri.conf.json                     ← generated
│   ├── menu.json                           ← generated
│   ├── tray.json                           ← generated
│   └── src/
│       └── main.rs                         ← fn main() { nativeblade::run(); }
├── vite.wasm.config.js
├── composer.json
└── package.json
```

Everything in `vendor/nativeblade/nativeblade/` — you never touch framework code.

---

## CLI Commands

| Command | Description |
|---------|-------------|
| `php artisan nativeblade:install` | Interactive setup — scaffolds Tauri project, layouts, config |
| `php artisan nativeblade:add android` | Add Android platform scaffold |
| `php artisan nativeblade:add ios` | Add iOS platform scaffold (macOS only) |
| `php artisan nativeblade:dev` | Start desktop development server with hot reload |
| `php artisan nativeblade:dev --platform=android` | Run on Android device |
| `php artisan nativeblade:dev --platform=ios` | Run on iOS simulator |
| `php artisan nativeblade:config` | Regenerate Tauri configs from PHP |
| `php artisan nativeblade:component {name}` | Create a new custom component |

---

## Development Workflow

```bash
# Start desktop dev with hot reload
php artisan nativeblade:dev

# Start mobile dev (Android)
php artisan nativeblade:dev --platform=android

# Regenerate configs after changing AppServiceProvider
php artisan nativeblade:config

# Create a custom shell component
php artisan nativeblade:component my-widget
```

Changes to Blade templates and PHP files are reflected instantly via hot reload — no manual rebuild needed.

---

## Laravel Compatibility

NativeBlade runs Laravel inside PHP WebAssembly — there is no real server. This means some Laravel features work perfectly, some work through a bridge, and some are not available.

### Works out of the box

| Feature | Notes |
|---------|-------|
| Routing | Full Laravel router |
| Blade / Livewire | Core of the framework |
| Eloquent (SQLite) | Persisted to IndexedDB |
| Middleware | Standard middleware pipeline |
| Validation | All validation rules |
| Collections | All collection methods |
| Service Container / DI | Full dependency injection |
| Localization | Lang files, `__()` helper |
| Events (synchronous) | Event dispatch and listeners |
| Carbon / Helpers | All date and string helpers |
| Auth (via state) | Using `NativeBlade::setState()` instead of sessions |

### Works via HTTP Bridge

Laravel's `Http` facade works transparently through a bridge that routes requests through the native Tauri shell:

```php
// This just works — the bridge handles it automatically
$response = Http::get('https://api.github.com/users');
$users = $response->json();

$response = Http::post('https://api.example.com/orders', [
    'product_id' => 1,
]);
```

Each `Http` call triggers a re-execution cycle (PHP signals the request, JS fulfills it, PHP re-runs with the cached response). For N external requests, there are N+1 PHP executions. This is transparent to your code but worth knowing for performance.

### Does not work (not planned)

| Feature | Why |
|---------|-----|
| Queues / Jobs | No background worker process in WASM |
| Mail (SMTP) | No direct network I/O from PHP WASM |
| Cache (Redis / Memcached) | No external cache services |
| Sessions (file / database driver) | Use `NativeBlade::setState()` instead |
| Broadcasting / WebSockets | No server to broadcast from |
| Scheduling (cron) | No cron in WASM |
| Storage (S3, FTP) | No filesystem drivers beyond local WASM FS |
| Database (MySQL / Postgres) | Only SQLite is available |
| Artisan commands | No CLI in WASM runtime |
| Notifications (mail/database) | Use `NativeBlade::notification()` for native OS notifications |
| `file_get_contents()` for URLs | Use `Http` facade instead (bridged) |

---

## How NativeBlade Differs

| | NativeBlade | Electron | React Native | Flutter |
|---|---|---|---|---|
| **Language** | PHP + Blade | JavaScript | JavaScript | Dart |
| **Backend** | Built-in (Laravel) | Separate | Separate | Separate |
| **Binary Size** | ~15 MB | ~150 MB | ~30 MB | ~20 MB |
| **Learning Curve** | None (if you know Laravel) | Medium | High | High |
| **Native UI** | Shell + WebView | WebView only | Native | Custom rendering |
| **Offline** | Yes (WASM + IndexedDB) | Manual | Manual | Manual |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to contribute to NativeBlade.

---

## License

MIT

---

<p align="center">
  Built with Laravel, Livewire, Tauri, and PHP WebAssembly.
</p>
