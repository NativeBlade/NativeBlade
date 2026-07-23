---
title: "Components"
description: "Shell components, utility components, and custom components."
---

# Components

## Shell Components

Shell components render **outside** the WebView, they never flicker during page transitions.

### Header

```blade
<x-nativeblade-header title="Home" />
<x-nativeblade-header title="Settings" :back="true" />
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
    <x-nativeblade-drawer-item icon="gear" label="Settings" href="/settings" />
</x-nativeblade-drawer>
```

### Modal

Renders at z-index 9999 above all shell components. Pre-rendered, shown via bridge:

```blade
<x-nativeblade-modal>
    <div style="padding:24px">
        <h3 style="font-size:18px;font-weight:900;color:#fff">Confirm?</h3>
        <button data-nav="/next-page" data-replace>Yes</button>
        <button data-dismiss>Cancel</button>
    </div>
</x-nativeblade-modal>

<button wire:nb-bridge="showModal">Open Modal</button>
```

### Available Shell Components

| Component | Description |
|-----------|-------------|
| `<x-nativeblade-header>` | Top bar with title, back button, and action slots |
| `<x-nativeblade-action>` | Header action button (icon + optional badge) |
| `<x-nativeblade-bottom-nav>` | Bottom tab navigation bar |
| `<x-nativeblade-tab>` | Tab item (icon + label + href) |
| `<x-nativeblade-drawer>` | Side drawer / hamburger menu |
| `<x-nativeblade-drawer-item>` | Drawer navigation item |
| `<x-nativeblade-modal>` | Shell modal (above all shell components) |

## Utility Components

### Animate

Enter/exit animations with Livewire compatibility. See [Animations](/core/animations/) for full docs.

```blade
<x-nativeblade-animate in="shakeX" out="fadeOutUp" dismiss="3s">
    Error message
</x-nativeblade-animate>

<x-nativeblade-animate in="fadeInUp" :once="true">
    Static content (won't re-animate on morph)
</x-nativeblade-animate>
```

### Icons

1,512 [Phosphor Icons](https://phosphoricons.com/) (regular + fill) included.

```blade
<x-nativeblade-icon name="house" />
<x-nativeblade-icon name="heart-fill" size="32" class="text-red-400" />
```

### Images

Converts to base64 data URI. Works inside Livewire re-renders.

```blade
<x-nativeblade-image asset="logo.png" alt="Logo" class="w-20 h-20" />
```

### Skeleton

Loading placeholder with shimmer animation:

```blade
<x-nativeblade-skeleton class="h-4 w-3/4" />
<x-nativeblade-skeleton class="w-12 h-12 rounded-full" />
<x-nativeblade-skeleton class="h-20 w-full rounded-xl" />
```

### Custom Fonts

Offline font loading via base64 embedding:

```blade
{{-- 1. Place files in public/fonts/inter/ (Inter-400.woff2, etc.) --}}
{{-- 2. Load in layout --}}
<x-nativeblade-font name="Inter" weights="400,500,700,900" />
```

Register in Tailwind (`resources/css/app.css`):

```css
@theme {
    --font-sans: 'Inter', sans-serif;
    --font-display: 'Playfair', serif;
}
```

### Safe Area

For pages without shell header/bottom-nav:

```blade
{{-- Wrapping content --}}
<x-nativeblade-safe>
    <div>Content with safe padding</div>
</x-nativeblade-safe>

{{-- Fixed elements use CSS variables --}}
<header class="fixed top-0" style="padding-top:max(var(--nb-safe-top), 12px)">
```

You rarely need to handle safe areas yourself: the shell already pads its body
so your app renders inside the safe region (above the notch, above the home
indicator). A sticky header at `top: 0` or a sticky bottom nav at `bottom: 0`
sits at the safe edge automatically. The `--nb-safe-*` variables exist as a
minimum-padding helper (`max(var(--nb-safe-top), 12px)`) for the rare fixed
element that needs it.

### Software keyboard

When the on-screen keyboard opens, the shell adds a `nb-keyboard-visible`
class to the app `<body>` and sets a `--nb-keyboard-height` variable. Use it
to get out of the keyboard's way, for example hiding a fixed bottom nav so it
does not sit on top of the keyboard:

```css
body.nb-keyboard-visible .bottom-nav {
    transform: translateY(100%);
}
```

```blade
{{-- Or pad a fixed footer up by the keyboard height --}}
<div class="fixed bottom-0" style="padding-bottom:var(--nb-keyboard-height)">
```

## Custom Components

### Shell (renders outside WebView)

```bash
php artisan nativeblade:component fab-button
# Select: shell
```

Creates: `nativeblade-components/fab-button/` with `.js`, `.css`, `.php`, `.blade.php`

### Embedded (renders inside WebView)

```bash
php artisan nativeblade:component stat-card
# Select: embedded
```

Creates: `nativeblade-components/stat-card/` with `.php`, `.blade.php`

### Publishing as Package

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

Auto-synced into `nativeblade-components/` at the start of both `nativeblade:dev`
and `nativeblade:build` (recursive, packages may ship subfolders). This also
covers native shell modules ([Native Shell Modules](/core/native-shell/)): a package can
distribute a module and `protected string $shell = '<name>'` finds it. See
[nativeblade-toast](https://github.com/NativeBlade/nativeblade-toast) as example.
