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

<p align="center">
  <img src="hello.gif" alt="NativeBlade Demo" width="600">
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

## Assets

NativeBlade runs inside an iframe — standard `asset()` URLs break during Livewire updates. Use the `<x-nativeblade-image>` component instead:

```blade
<x-nativeblade-image asset="logo.png" alt="Logo" class="w-20 h-20 rounded-2xl" />
```

The component converts the image to a base64 data URI, caches it in memory, and adds `wire:ignore.self` automatically so Livewire never breaks the image on re-renders.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `asset` | — | File name in `public/` (required) |
| `alt` | `''` | Alt text |
| `class` | `''` | CSS classes |

Supported formats: PNG, JPG, GIF, SVG, WebP, ICO.

## Livewire Directives

NativeBlade extends Livewire with custom directives prefixed with `nb-`. No `onclick` or `__nbBridge` needed — everything is declarative in Blade.

### `wire:nb-bridge`

Triggers a native bridge action on click:

```blade
<button wire:nb-bridge="alert" wire:nb-payload='{"message":"Hello!","title":"Alert"}'>
    Alert
</button>

<button wire:nb-bridge="toast" wire:nb-payload='{"message":"Saved!","type":"success"}'>
    Toast
</button>

<button wire:nb-bridge="notification" wire:nb-payload='{"body":"New message","sound":"default"}'>
    Push
</button>

<button wire:nb-bridge="vibrate" wire:nb-payload='{"duration":100}'>
    Vibrate
</button>

<button wire:nb-bridge="scan">
    Scan QR
</button>

<button wire:nb-bridge="clipboard_write" wire:nb-payload='{"text":"Copied!"}'>
    Copy
</button>

<button wire:nb-bridge="open_url" wire:nb-payload='{"url":"https://github.com"}'>
    Open
</button>
```

### `wire:nb-navigate`

Navigates using NativeBlade's internal history stack:

```blade
<button wire:nb-navigate="/users">Users</button>
<button wire:nb-navigate="/settings">Settings</button>
```

Use the `.replace` modifier to replace the current entry in the history stack instead of adding a new one. This prevents the user from swiping back to the previous page (useful after login, logout, or onboarding flows):

```blade
{{-- After login — user can't swipe back to login screen --}}
<button wire:nb-navigate.replace="/">Home</button>
```

From PHP:

```php
// replace: true prevents back navigation to the current page
NativeBlade::navigate('/', replace: true)->toResponse();
```

From shell JS:

```javascript
window.__nb.navigateReplace('/');
```

> **Note:** Use `wire:nb-navigate` instead of standard `wire:navigate` — Livewire's built-in navigation doesn't work in the WASM context.

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
| `<x-nativeblade-modal>` | Shell modal (renders above all shell components) |
| `<x-nativeblade-safe>` | Safe area wrapper (device notch, home indicator) |
| `<x-nativeblade-skeleton>` | Skeleton loading placeholder with shimmer |
| `<x-nativeblade-font>` | Custom font loader (offline, base64 embedded) |

### Modal

Shell modal renders at z-index 9999, above all other shell components. Pre-rendered on navigation, shown/hidden via bridge:

```blade
{{-- Define modal content (always in the template, hidden by default) --}}
<x-nativeblade-modal>
    <div style="padding:24px">
        <h3 style="font-size:18px;font-weight:900;color:#fff">Confirm?</h3>
        <p style="color:#9ca3af;font-size:14px">Are you sure?</p>
        <button data-nav="/next-page" data-replace style="...">Yes</button>
        <button data-dismiss style="...">Cancel</button>
    </div>
</x-nativeblade-modal>

{{-- Trigger it --}}
<button wire:nb-bridge="showModal">Open Modal</button>
```

Inside the modal HTML: `data-dismiss` hides the modal, `data-nav="/path"` navigates (add `data-replace` for history replace).

### Page Transitions

Configure globally in `AppServiceProvider`:

```php
NativeBlade::transition('fade');  // fade between pages
NativeBlade::transition('slide'); // slide + fade between pages
NativeBlade::transition('none');  // no transition (default)
```

Or per-navigation:

```php
NativeBlade::navigate('/lesson/1')->transition('slide')->toResponse();
NativeBlade::navigate('/')->transition('fade')->toResponse();
```

### Safe Area

For pages **without** shell header/bottom-nav (embedded components), use `<x-nativeblade-safe>` or CSS variables to handle device notch and home indicator.

**Wrapping content (normal flow):**

```blade
<x-nativeblade-safe>
    <div>Your content here — gets padding for notch/home indicator</div>
</x-nativeblade-safe>

{{-- Only top --}}
<x-nativeblade-safe :bottom="false">
    <header>...</header>
</x-nativeblade-safe>
```

**Fixed/absolute elements (CSS variables):**

Layouts include `--nb-safe-top` and `--nb-safe-bottom` CSS variables mapped to device safe area insets. Use them on fixed/sticky elements:

```blade
<header class="fixed top-0" style="padding-top:max(var(--nb-safe-top), 12px)">
    ...
</header>

<div class="sticky bottom-0" style="padding-bottom:max(var(--nb-safe-bottom), 16px)">
    ...
</div>
```

The component uses `NativeBlade::isIos()`, `isAndroid()`, `isDesktop()` to apply platform-appropriate values.

---

## Animations

NativeBlade includes [Animate.css](https://animate.style/) (90+ animations) plus custom NativeBlade animations. Use them declaratively with HTML attributes — no CSS keyframes needed.

### Usage

```blade
{{-- Basic --}}
<div nb-animation="fadeInUp">Hello</div>

{{-- With delay --}}
<div nb-animation="bounceIn" nb-animation-delay="200ms">Bounce!</div>

{{-- With speed --}}
<div nb-animation="zoomIn" nb-animation-speed="fast">Fast zoom</div>

{{-- Infinite --}}
<div nb-animation="pulse" nb-animation-repeat="infinite">Loading...</div>

{{-- Custom repeat count --}}
<div nb-animation="shakeX" nb-animation-repeat="3">Shake 3 times</div>
```

### Attributes

| Attribute | Values | Description |
|-----------|--------|-------------|
| `nb-animation` | Any animation name | The animation to apply |
| `nb-animation-delay` | `100ms`, `0.5s`, etc. | Delay before animation starts |
| `nb-animation-speed` | `slower`, `slow`, `fast`, `faster` | Animation speed |
| `nb-animation-repeat` | `1`, `2`, `3`, `infinite` | Repeat count |

### Available Animations

All [Animate.css](https://animate.style/) animations work out of the box: `fadeIn`, `fadeInUp`, `fadeInDown`, `fadeInLeft`, `fadeInRight`, `bounceIn`, `zoomIn`, `slideInUp`, `slideInRight`, `flipInX`, `jackInTheBox`, `shakeX`, `tada`, `pulse`, `heartBeat`, and [many more](https://animate.style/).

**NativeBlade custom animations:**

| Name | Description |
|------|-------------|
| `pulseGlow` | Pulsating glow effect |
| `shimmer` | Shine effect for progress bars |
| `confetti` | Falling particle effect |
| `xpFill` | Progress bar fill |
| `springPop` | Bouncy scale with rotation |
| `float` | Gentle floating up and down |
| `glow` | Pulsating box-shadow glow |
| `scaleTap` | Quick press feedback |
| `shakeSubtle` | Gentle horizontal shake |
| `slideFadeInRight` | Slide + fade combined (right) |
| `slideFadeInLeft` | Slide + fade combined (left) |
| `slideFadeInUp` | Slide + fade combined (up) |
| `slideFadeInDown` | Slide + fade combined (down) |
| `popIn` / `popOut` | Scale from/to 0 with overshoot |
| `celebrate` | Quick scale pulse for success |
| `wiggle` | Playful rotation wiggle |
| `revealUp` / `revealDown` | Clip-path reveal |
| `blurIn` / `blurOut` | Blur to sharp |

### Skeleton

Loading placeholder with shimmer animation. Define size and shape via `class`:

```blade
{{-- Text line --}}
<x-nativeblade-skeleton class="h-4 w-3/4" />

{{-- Avatar --}}
<x-nativeblade-skeleton class="w-12 h-12 rounded-full" />

{{-- Card --}}
<x-nativeblade-skeleton class="h-20 w-full rounded-xl" />

{{-- Group of lines --}}
<div class="space-y-3">
    <x-nativeblade-skeleton class="h-4 w-full" />
    <x-nativeblade-skeleton class="h-4 w-5/6" />
    <x-nativeblade-skeleton class="h-4 w-2/3" />
</div>
```

### Custom Fonts

Load custom fonts offline. Fonts are embedded as base64 data URIs — no server or internet required.

**1. Add font files:**

```
public/fonts/inter/
├── Inter-400.woff2
├── Inter-500.woff2
├── Inter-700.woff2
└── Inter-900.woff2
```

File naming: `FontName-weight.woff2` (also supports `.woff` and `.ttf`).

**2. Load in your layout:**

```blade
{{-- resources/views/components/layouts/app.blade.php --}}
<head>
    <x-nativeblade-font name="Inter" src="fonts/inter" weights="400,500,700,900" />
    @vite(['resources/css/app.css'])
</head>
<body style="font-family: 'Inter', sans-serif">
```

The component reads each font file, converts to base64, and outputs `@font-face` declarations inline. Results are cached in memory per request.

---

## Native Actions

Use `wire:nb-bridge` directives in Blade (see [Livewire Directives](#livewire-directives)) or `NativeResponse` from PHP:

```php
use NativeBlade\Facades\NativeBlade;

// Works in both Controllers and Livewire components
NativeBlade::alert('Export complete!')->title('Success')->toResponse();
NativeBlade::notification('Task completed')->toResponse();
NativeBlade::navigate('/dashboard')->toResponse();
NativeBlade::navigate('/', replace: true)->toResponse(); // no back navigation
```

### Available Actions

| Action | Blade directive | PHP method |
|--------|----------------|------------|
| Alert dialog | `wire:nb-bridge="alert"` | `NativeBlade::alert($msg)` |
| Notification | `wire:nb-bridge="notification"` | `NativeBlade::notification($body)` |
| Confirm dialog | `wire:nb-bridge="confirm"` | — |
| Navigate | `wire:nb-navigate="/path"` | `NativeBlade::navigate($path)` |
| Navigate (replace) | `wire:nb-navigate.replace="/path"` | `NativeBlade::navigate($path, replace: true)` |
| Clipboard copy | `wire:nb-bridge="clipboard_write"` | — |
| Clipboard paste | `wire:nb-bridge="clipboard_read"` | — |
| Geolocation | `wire:nb-bridge="geolocation"` | — |
| Vibrate | `wire:nb-bridge="vibrate"` | — |
| Impact feedback | `wire:nb-bridge="impact"` | — |
| Biometric auth | `wire:nb-bridge="biometric"` | — |
| QR/Barcode scan | `wire:nb-bridge="scan"` | — |
| NFC read | `wire:nb-bridge="nfc_read"` | — |
| Open URL | `wire:nb-bridge="open_url"` | — |
| OS info | `wire:nb-bridge="os_info"` | — |
| Camera | `wire:nb-bridge="camera"` | — |
| Exit app | `wire:nb-bridge="exit"` | `NativeBlade::exit()` |

### Receiving results in Livewire

Bridge actions that return data dispatch Livewire events automatically:

```php
use Livewire\Attributes\On;

#[On('nb:scan')]
public function onScan($result) {
    $this->qrCode = $result['content'] ?? '';
}

#[On('nb:geolocation')]
public function onLocation($position) {
    $this->lat = $position['coords']['latitude'] ?? null;
}

#[On('nb:clipboard')]
public function onClipboard($text) {
    $this->pastedText = $text ?? '';
}

#[On('nb:biometric')]
public function onBiometric($success) {
    $this->authenticated = $success ?? false;
}

#[On('nb:os-info')]
public function onOsInfo($info) {
    $this->osInfo = $info ?? [];
}

#[On('nb:confirm-result')]
public function onConfirm($confirmed) {
    $this->confirmed = $confirmed ?? false;
}
```

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

### Publishing a Component Package

You can publish your own NativeBlade components as Composer packages. The community can install them with `composer require` and they work automatically.

Your package just needs a `composer.json` with the `nativeblade` extra pointing to the component folder:

```json
{
    "name": "your-vendor/your-component",
    "extra": {
        "nativeblade": {
            "components": {
                "your-component": "your-component"
            }
        }
    }
}
```

The component folder follows the same structure as `php artisan nativeblade:component`:

```
your-component/
├── YourComponent.php        ← PHP class (namespace App\NativeBlade\Components)
├── your-component.blade.php ← Blade template
├── your-component.js        ← Shell JS (if shell component)
└── your-component.css       ← Styles (if shell component)
```

When the user runs `php artisan nativeblade:dev`, the component is automatically synced to `nativeblade-components/` and available as `<x-nativeblade-your-component />`.

See [nativeblade/nativeblade-toast](https://github.com/NativeBlade/nativeblade-toast) as an example of a published shell component.

---

## Navigation

NativeBlade manages its own navigation stack — the browser history is not used. This works consistently across desktop and mobile (including Android swipe back).

### From PHP (Livewire / Controllers)

```php
use NativeBlade\Facades\NativeBlade;

NativeBlade::navigate('/dashboard')->toResponse();
```

> `$this->redirect()` does not work in WASM context. Always use `NativeBlade::navigate()`.

### From Blade Templates (Embedded)

```blade
{{-- Link — intercepted automatically --}}
<a href="/users">Users</a>

{{-- Button via bridge --}}
<button onclick="__nbBridge('navigate', { path: '/users' })">Users</button>
```

### From Shell Components (JavaScript)

```javascript
// Via import
import { nb } from '@nativeblade/wasm-app/nb.js';

nb.navigate('/users');
nb.goBack();
nb.canGoBack();       // true/false
nb.getCurrentPath();  // '/users'
nb.icon('house');     // returns SVG string
```

```javascript
// Or via global (no import needed)
window.__nb.navigate('/users');
window.__nb.goBack();
```

### Back Navigation

Back navigation uses an internal history stack, not the browser:

- **Header back button** — `<x-nativeblade-header :back="true" />` pops the stack automatically
- **Android swipe back** — intercepted and routed through the stack
- **Programmatic** — `nb.goBack()` from shell JS, or `__nbBridge('navigate', { path: '/' })` from Blade

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
| `php artisan nativeblade:icon` | Generate all platform icons from a 1024x1024 PNG |
| `php artisan nativeblade:component {name}` | Create a new custom component |

### Icon Generation

Place a 1024x1024 PNG at `src-tauri/icons/logo.png` and run:

```bash
php artisan nativeblade:icon
```

This generates all icons in PHP (GD extension required):

- **Desktop** — 32, 128, 256, 512, icon.ico, icon.icns
- **Android** — adaptive icons with safe zone padding, round icons, monochrome notification icons, background color XML
- **iOS** — all required sizes with opaque background, Contents.json

Options:

```bash
# Custom source icon
php artisan nativeblade:icon resources/icons/my-logo.png

# Custom background color for adaptive icon
php artisan nativeblade:icon --bg=#1a1a2e
```

Icons are generated automatically during `nativeblade:install` and `nativeblade:add android/ios`. Run `nativeblade:icon` manually to regenerate after changing your logo.

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

Laravel's `Http` facade works transparently through a bridge. Since PHP WASM can't make network requests directly, NativeBlade intercepts `Http` calls and routes them through JavaScript:

```php
$response = Http::get('https://api.github.com/users');
$users = $response->json();

$response = Http::post('https://api.example.com/orders', [
    'product_id' => 1,
]);
```

**How it works under the hood:**

1. PHP calls `Http::get()` → no network available → writes the request to a temp file → exits
2. JavaScript picks up the pending request → makes a real `fetch()` → caches the response
3. PHP re-executes → finds the cached response → returns it to your code

This is fully transparent — your code uses standard Laravel `Http` without any changes.

**Sequential vs Parallel:**

Each individual `Http` call triggers one re-execution cycle. For N sequential requests, there are N+1 PHP executions:

```php
// Sequential — 3 requests = 4 PHP executions
$users = Http::get('https://api.com/users');
$posts = Http::get('https://api.com/posts');
$stats = Http::get('https://api.com/stats');
```

For better performance, use `NativeBlade::pool()` to run all requests in parallel with a single re-execution:

```php
// Parallel — 3 requests = 2 PHP executions (always)
$responses = NativeBlade::pool(fn ($pool) => [
    $pool->get('https://api.com/users'),
    $pool->get('https://api.com/posts'),
    $pool->get('https://api.com/stats'),
]);

$users = $responses[0]->json();
$posts = $responses[1]->json();
$stats = $responses[2]->json();
```

With `pool()`, all requests are collected in the first execution, JavaScript fetches them all simultaneously via `Promise.all()`, and the second execution has every response cached.

**Best practice for pages with external data:**

Use `wire:init` to keep navigation instant while data loads in the background:

```php
class Dashboard extends Component
{
    public array $users = [];
    public bool $loading = true;

    // Don't fetch in mount() — it blocks navigation
    public function loadData()
    {
        $responses = NativeBlade::pool(fn ($pool) => [
            $pool->get('https://api.com/users'),
            $pool->get('https://api.com/stats'),
        ]);

        $this->users = $responses[0]->json();
        $this->loading = false;
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
```

```blade
{{-- Page renders immediately with skeleton, data loads async --}}
<div wire:init="loadData">
    @if($loading)
        {{-- skeleton --}}
    @else
        {{-- real content --}}
    @endif
</div>
```

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
  Built with Laravel, Livewire, Tauri, and PHP WebAssembly.<br>
  <a href="https://www.linkedin.com/in/jefferson-silva-66bba7aa/">Jefferson T.S</a>
</p>
