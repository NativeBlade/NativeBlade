---
title: "Multi-language"
description: "Localization and runtime locale switching."
---

# Multi-Language

NativeBlade resolves the app language on every launch and keeps two translation
layers in sync: the JavaScript shell (splash, loading screen, built-in UI strings)
and Laravel itself (`__()`, `trans()`, `@lang`, validation messages).

By default the **device language wins**. A user with an English phone sees the
English bundle even if you authored it in Portuguese. This is deliberate: forcing
a locale globally is hostile to accessibility (a Portuguese UI read by an English
VoiceOver voice is mush). You can still let the user pick a language explicitly,
and that choice is persisted and respected over the device default.

## The two layers

| Layer | Renders | Reads from |
| --- | --- | --- |
| **Shell (JS)** | Splash screen, loading state, framework UI before PHP boots | `public/lang/{locale}.json` |
| **Laravel (PHP)** | Your Blade, components, validation, anything via `__()` / `trans()` | `lang/{locale}.json` and `lang/{locale}/*.php` |

The shell layer runs before PHP-WASM is ready, so it can only use the flat JSON
files. Laravel sees both the JSON files and the classic PHP array files
(`lang/en/messages.php`).

## How the locale is resolved at boot

At launch, `i18n.js` fetches `nativeblade-locale.json` and picks the first locale
that has a matching `lang/{locale}.json`, in this priority order:

1. **`locale`**, the language the user explicitly chose in-app (persisted). Empty until `setLanguage()` is called.
2. **Device language**, `navigator.language` (e.g. `pt-BR`).
3. **`defaultLocale`**, the bundle fallback, taken from `APP_LOCALE` in your `.env` at build time.
4. **`en`**, hardcoded last resort.

Locale matching is tolerant: a device reporting `pt-BR` matches `pt_BR.json`,
`pt-BR.json`, or `pt.json`, in that order. After resolving, the shell sets
`<html lang="...">` to the BCP-47 form (`pt-BR`) so screen readers use the
correct reading voice.

Laravel follows the same persisted choice: on boot the service provider calls
`app()->setLocale(NativeBlade::currentLanguage())`, so `__()` and friends match
whatever the shell resolved.

## Setting the default at build time

The bundle default comes from `APP_LOCALE` in your project `.env`:

```dotenv
APP_LOCALE=pt_BR
```

When you build, this is written into `public/nativeblade-locale.json` as
`defaultLocale`. It is only a fallback, used when no user choice exists and the
device language has no matching `lang` file.

## Translation files

Drop your translation files in the project `lang/` directory:

```
lang/
  en.json          # shell + Laravel
  pt_BR.json       # shell + Laravel
  es.json          # shell + Laravel
  en/
    messages.php   # Laravel only (php array keys)
    validation.php
```

Only the `*.json` files are copied into the bundle for the shell layer. The PHP
array files (`lang/en/messages.php`) still work for Laravel through `__('messages.welcome')`,
they just are not visible to the pre-boot splash screen.

In Blade, use Laravel translation as usual:

```blade
<h1>{{ __('Welcome back') }}</h1>
<p>{{ __('messages.greeting', ['name' => $user->name]) }}</p>
```

## Changing the language at runtime

Let the user switch languages from inside the app. The choice is persisted to the
local SQLite state, applied to the current request immediately, and mirrored to
`nativeblade-locale.json` so the next splash screen already starts in the chosen
language.

```php
use NativeBlade\Facades\NativeBlade;

// Persist + apply now + carry over to the next launch's splash.
NativeBlade::setLanguage('pt_BR');

// Read the active language (persisted choice, or config('app.locale') default).
$locale = NativeBlade::currentLanguage();
```

A minimal language switcher as a Livewire component:

```php
use Livewire\Component;
use NativeBlade\Facades\NativeBlade;

class LanguageSwitcher extends Component
{
    public string $locale;

    public function mount(): void
    {
        $this->locale = NativeBlade::currentLanguage();
    }

    public function choose(string $locale): void
    {
        NativeBlade::setLanguage($locale);
        $this->locale = $locale;
        NativeBlade::navigate(request()->path())->toResponse();
    }

    public function render()
    {
        return view('livewire.language-switcher');
    }
}
```

```blade
<div class="flex gap-2">
    @foreach (['en' => 'English', 'pt_BR' => 'Português', 'es' => 'Español'] as $code => $label)
        <button
            wire:click="choose('{{ $code }}')"
            @class(['font-bold' => $locale === $code])
        >{{ $label }}</button>
    @endforeach
</div>
```

`setLanguage()` applies the change to the current request, but re-navigating
re-renders the current page so already-rendered strings update. Use
`NativeBlade::navigate(...)` (not Livewire's `wire:navigate`) to repaint.

## The two `<html lang>` attributes

There are two HTML documents, each with its own `lang`, and they behave differently:

| Document | `lang` source | What it covers |
| --- | --- | --- |
| **Shell** (`resources/js/index.html`) | Hardcoded at first (`lang="en"`), then overridden at runtime by `i18n.js` | The Tauri root that hosts the iframes: splash, loading, framework UI |
| **App content** (`app.blade.php`, inside the iframe) | Dynamic: `{{ str_replace('_', '-', app()->getLocale()) }}` | Your actual screens rendered by Laravel |

The hardcoded `lang` in the shell `index.html` is only the initial value. On boot,
`i18n.js` calls `document.documentElement.setAttribute('lang', ...)` with the
resolved locale, so the shell header always ends up correct regardless of what the
file says. You do not need to keep that value in sync by hand.

For accessibility, the one that matters is the **app content** document. Screen
readers read the text inside the iframe using that iframe's `lang`, and it is
already dynamic from `app()->getLocale()` (set to `currentLanguage()` at boot). So
your screens are read with the correct voice without any manual step.

## Accessibility

Because both documents resolve `lang` to the active BCP-47 locale, VoiceOver and
TalkBack read the UI with the matching voice. Keep the device-language-first
default unless you have a strong reason to override it, and when you do offer an
override, always through `setLanguage()` so it persists and the `lang` attribute
stays correct.
