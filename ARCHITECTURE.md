# Architecture

NativeBlade has an opinionated take on how a Laravel codebase should be organized when it runs inside the WebView shell. The TL;DR: **the Livewire component is your controller**. It receives input, calls a service, updates state, and returns a `NativeResponse`. Everything else lives elsewhere.

This document is the canonical reference. The MCP server's `architecture_recipe` tool fragments it into focused snippets so AI coding agents follow the rules without loading the whole thing.

## The mental model

A NativeBlade app is a long-running PHP-WASM process that simulates HTTP requests internally as the user navigates. There is no opcache between navigates, but there IS persistent state via `NativeBlade::setState`. This shapes three core principles:

1. **Cold-start budget is short.** Every navigate re-bootstraps the Laravel container. Heavy work in `mount()` translates directly into a slow screen render. Push heavy work into services called from event handlers, not from mount.
2. **State is the runtime database.** Cross-component data lives in `NativeBlade::setState` (backed by SQLite), not in PHP sessions (which don't really exist here) or in static properties (which reset every navigate).
3. **Native actions are domain operations.** Biometric, push, scan, navigate, notification — these belong inside the service that owns the domain, not in a generic "MobileService" pool of utilities.

## The Component = Controller rule

A Livewire component does exactly four things:

1. Receives input (form fields, click handlers, deep link routing)
2. Calls a service
3. Updates state (via a typed wrapper, see below)
4. Returns a `NativeResponse` with the native actions to run

That's it. **No Eloquent queries. No business calculations. No data formatting.** If your component method has more than ~10 lines of meaningful code, it's wearing too many hats.

```php
// ✅ Good: thin controller
class Login extends Component
{
    public LoginForm $form;

    #[Flash]
    public string $error = '';

    public function login(AuthService $auth)
    {
        $this->form->validate();

        $result = $auth->attempt($this->form->email, $this->form->password);

        if (!$result['ok']) {
            $this->error = $result['message'];
            return NativeBlade::impact('heavy')->toResponse();
        }

        AuthState::set($result['user']);

        return NativeBlade::notification(fn ($n) => $n->title('Welcome back'))
            ->navigate('/', replace: true)
            ->toResponse();
    }
}
```

```php
// ❌ Bad: fat controller (do not do this)
class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public string $error = '';

    public function login()
    {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->error = 'Invalid email';
            return;
        }

        $user = User::where('email', $this->email)->first();
        if (!$user || !Hash::check($this->password, $user->password)) {
            $this->error = 'Invalid credentials';
            return;
        }

        NativeBlade::setState('auth.user', [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
        ]);
        NativeBlade::setState('auth.logged_at', now()->timestamp);

        return NativeBlade::navigate('/', replace: true)->toResponse();
    }
}
```

The first version is testable, swappable, readable. The second is a tangled blob.

## One component per screen — decompose by responsibility

A screen is a component. A feature is a component. Do NOT build one giant component that holds every screen and swaps them with a `$screen`/`$tab`/`@if` ladder — that's a monolith with a fake router inside it, and it re-renders and re-hydrates the whole app on every tap.

- **Each route/screen is its own Livewire component** in `app/Livewire/{Domain}/`, wired in `routes/web.php`. Moving between screens is `->navigate('/path')`, never toggling a property.
- **Break a busy screen into children by responsibility:**
  - Has its own state + actions (an editable row, a cart line, a comment box) → a nested Livewire component.
  - Purely presentational (a badge, an avatar, a stat card) → a Blade component (`<x-…>`) or partial, no class.
- **One component = one job.** If a component has fields and methods for three unrelated things (the profile form AND the feed AND the cart), split it into three.

```php
// ❌ Bad: one component is the entire app
class Home extends Component {
    public string $screen = 'feed';                 // fake router
    public array $feed = []; public array $profile = []; public array $cart = [];
    public function show($s) { $this->screen = $s; }
    public function addToCart(...) {...} public function saveProfile(...) {...}  // everything
    // render() → one blade full of @if($screen === 'feed') … @elseif($screen === 'cart') …
}
```
```php
// ✅ Good: a component per screen, real routes
// routes/web.php
Route::get('/', Feed::class);
Route::get('/cart', Cart::class);
Route::get('/profile', Profile::class);

// app/Livewire/Shop/Cart.php — only the cart's state + actions
class Cart extends Component { /* … */ }
```

Rule of thumb: if you're writing `@if($screen === …)` or `match($tab)` in a Blade view to pick which whole screen to show, stop — those are separate components behind separate routes. Native tab bars are configured with `NativeBladeConfig::bottomNav([...])` pointing at those routes, not a property toggle.

## Folder structure

```
app/
├── Livewire/                    Components = Controllers, by domain
│   ├── Forms/                   Livewire Form Objects (validation)
│   │   ├── LoginForm.php
│   │   └── ProfileForm.php
│   ├── Auth/
│   │   ├── Login.php
│   │   └── Register.php
│   └── Lessons/
│       ├── Trail.php
│       └── Detail.php
│
├── Services/                    Business logic, PHP-pure, UI-agnostic
│   ├── Auth/
│   │   ├── AuthService.php
│   │   └── BiometricFlow.php
│   └── Lessons/
│       ├── LessonService.php
│       └── ProgressService.php
│
├── Repositories/                ONLY when reading from remote MySQL/Postgres
│   └── PaymentsRepository.php
│
├── Models/                      Eloquent (SQLite local) — call directly from services
│   └── User.php
│
├── Enums/                       Closed sets (status, type, role). No string literals in business code.
│   ├── Auth/UserRole.php
│   ├── Lessons/LessonStatus.php
│   └── Push/PushType.php
│
├── Native/                      Coordinates native APIs
│   ├── Push/                    Push handlers, registered in AppServiceProvider
│   │   └── LessonPushHandler.php
│   ├── DeepLinks/               Deep link routing
│   │   └── LessonDeepLink.php
│   └── State/                   Typed wrappers over NativeBlade::setState
│       ├── AuthState.php
│       └── TrailState.php
│
├── Http/
│   └── Clients/                 Wrappers for EXTERNAL APIs (Stripe, etc.)
│       └── PaymentClient.php
│
└── Providers/
    └── AppServiceProvider.php   Push handlers + deep links registered here
```

## State hierarchy

NativeBlade has four state scopes. Pick the right one for the lifetime you want.

| Scope | Tool | Lifetime | Use for |
|---|---|---|---|
| Single render | `#[Flash]` prop | Until next render | Error toasts, success messages, validation flashes |
| Component lifetime | Public prop | Until navigate away | Form fields, UI toggles |
| Session | `NB::setState($key, $value, 'session')` | Until app closes | Filters, current view state, last seen ids |
| Persistent | `NB::setState($key, $value)` (default) | Until uninstall / logout | `auth.user`, `trail.xp`, preferences |
| TTL'd cache | `Cache::put($key, $value, $ttl)` | Until expiration or eviction | Memoized expensive computations, throttle counters, idempotency keys |

**Sessions are not Laravel sessions** — there is no HTTP session in WASM. NativeBlade's session scope is a marker that the value should be cleared on app close.

### `Cache::*` persists across restarts automatically

`Cache::put` / `Cache::get` / `Cache::remember` / `Cache::lock` / `Cache::forget` work out of the box and survive cold starts. NativeBlade auto-wires Laravel's standard `database` cache driver against the same `sqlite` connection that powers `setState`, using two tables (`nativeblade_cache`, `nativeblade_cache_locks`) created on boot.

```php
use Illuminate\Support\Facades\Cache;

// Memoize an expensive computation for one hour
$weather = Cache::remember('weather.lisbon', 3600, function () {
    return Http::get('https://api.weather.com/lisbon')->json();
});

// One-shot expiration
Cache::put('idempotency:' . $orderId, true, 300);
if (Cache::has('idempotency:' . $orderId)) {
    return;
}
```

**When to choose what:**

- `NB::setState` — use for **identity and configuration**: who is logged in, what locale the user picked, what onboarding step they finished. No TTL semantics, queryable by scope, intended to be the source of truth across the whole app.
- `Cache::*` — use for **derived data** that's safe to recompute: API responses, expensive queries, throttle/rate-limit counters, idempotency markers, anything that has a "this is stale, fetch again" lifecycle.

Both are persistent, both live in the same SQLite file, but the contract is different: state is "definitive", cache is "best effort and disposable".

Override the default driver from your own service provider if you need something else (`config(['cache.default' => 'array']);` in a test, etc.) — NativeBlade only sets it during its own `boot()`.

### State wrappers are mandatory

Never call `NativeBlade::setState('auth.user', ...)` with a string literal. Wrap it:

```php
// app/Native/State/AuthState.php
namespace App\Native\State;

use NativeBlade\Facades\NativeBlade;

class AuthState
{
    private const KEY = 'auth.user';

    public static function set(array $user): void
    {
        NativeBlade::setState(self::KEY, $user);
    }

    public static function user(): ?array
    {
        return NativeBlade::getState(self::KEY);
    }

    public static function clear(): void
    {
        NativeBlade::forget(self::KEY);
    }

    public static function isAuthenticated(): bool
    {
        return self::user() !== null;
    }
}
```

Benefits:
- One file refactors the shape of the stored data
- IDE autocomplete on `AuthState::user()` instead of memorizing keys
- Easy to find every caller via "find usages"
- Encapsulates fan-out (`logout()` clearing multiple keys, etc.)

## Form validation: use Livewire Form Objects

Laravel `FormRequest` is built for HTTP requests. Livewire components don't fit that model. Use **Livewire Form Objects** instead — they are the official equivalent.

```php
// app/Livewire/Forms/LoginForm.php
namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:6')]
    public string $password = '';
}
```

```php
class Login extends Component
{
    public LoginForm $form;

    public function login(AuthService $auth)
    {
        $this->form->validate();
        // $this->form->email and $this->form->password are now safe to read
    }
}
```

Validation errors flow into the standard `$errors` bag, available in Blade via `@error('form.email')`.

## Push handlers as classes

The push handler class lives in `app/Native/Push/{Domain}PushHandler.php` with a `handle()` method that receives a `PushPayload` and returns a `NativeResponse` (or null when no action is needed).

```php
// app/Native/Push/LessonPushHandler.php
namespace App\Native\Push;

use NativeBlade\Facades\NativeBlade;
use NativeBlade\NativeResponse;
use NativeBlade\Plugins\PushPayload;
use App\Services\Lessons\ProgressService;

class LessonPushHandler
{
    public function __construct(private ProgressService $progress) {}

    public function handle(PushPayload $payload): ?NativeResponse
    {
        if (($payload->data['type'] ?? null) !== 'new_lesson') {
            return null;
        }

        $lessonId = $payload->data['lesson_id'];
        $this->progress->markPending($lessonId);

        return NativeBlade::navigate("/lesson/{$lessonId}")->toResponse();
    }
}
```

Registered in `AppServiceProvider::boot()`:

```php
NativeBladeConfig::android(fn (AndroidConfig $c) => $c->notification(
    fn ($push) => $push->onReceive(fn ($payload) => app(LessonPushHandler::class)->handle($payload))
));
```

For apps with multiple push types, the handler can route internally:

```php
public function handle(PushPayload $payload): ?NativeResponse
{
    return match ($payload->data['type'] ?? null) {
        'new_lesson'  => app(LessonPushHandler::class)->handle($payload),
        'achievement' => app(AchievementPushHandler::class)->handle($payload),
        default       => null,
    };
}
```

## The `app/Native/*` handler convention

Push handlers are one instance of a house pattern: **anything the native side
delivers to PHP outside a user interaction arrives in a dedicated class under
`app/Native/`, resolved through the container, with a single `handle()`
method** — never a closure in `AppServiceProvider`, never logic inline in a
Livewire component that happens to be open.

| Folder | Receives | Signature |
|---|---|---|
| `app/Native/Push/` | push notifications | `handle(PushPayload $p): ?NativeResponse` |
| `app/Native/DeepLinks/` | deep / universal links | `handle(string $url): ?NativeResponse` |
| `app/Native/Tasks/` | background task results ([TASKS.md](TASKS.md)) | `handle(TaskResult $r): void` |
| `app/Native/State/` | (not events — typed `setState`/`getState` wrappers) | — |

The reasoning is the same in every row: these payloads arrive with no screen
context (the app may have just cold-started from a push tap, or be draining
results fetched hours ago with the app closed), so the receiver must be
screen-independent, testable in isolation, and container-resolved so it can
take services in the constructor.

For background tasks specifically, the handler is the **push-style option** —
the primary consumption is pull (`NativeBlade::getTask($name)` from whatever
screen needs the data). Reach for a `app/Native/Tasks/` handler only when a
result must be *processed* on arrival (write state, update the local DB)
rather than *displayed*:

```php
// app/Native/Tasks/PricesFetched.php
namespace App\Native\Tasks;

use NativeBlade\Facades\NativeBlade;
use NativeBlade\Tasks\TaskResult;

class PricesFetched
{
    public function handle(TaskResult $result): void
    {
        // Runs on app open, once per queued result, oldest first.
        NativeBlade::setState('prices', $result->json());
    }
}
```

## Concurrent HTTP: `NativeBlade::pool()`

For pages that need to fetch from multiple HTTP endpoints, sequential calls are wasteful. `NativeBlade::pool()` wraps Laravel's `Http::pool()` builder to run requests concurrently inside the WASM HTTP bridge.

```php
class Dashboard extends Component
{
    public array $widgets = [];

    public function mount(DashboardService $dashboard)
    {
        $this->widgets = $dashboard->loadAll();
    }
}

// app/Services/Dashboard/DashboardService.php
class DashboardService
{
    public function loadAll(): array
    {
        [$user, $stats, $feed] = NativeBlade::pool(fn ($pool) => [
            $pool->get('https://api.example.com/me'),
            $pool->get('https://api.example.com/stats'),
            $pool->get('https://api.example.com/feed'),
        ]);

        return [
            'user'  => $user->json(),
            'stats' => $stats->json(),
            'feed'  => $feed->json(),
        ];
    }
}
```

Use this **only** for HTTP. State reads are cheap (SQLite local) and don't benefit from pooling.

## Repositories: only for external databases

Eloquent is already a sufficient abstraction for local SQLite. Wrapping it in a repository for a model that only ever talks to local SQLite is overengineering.

**Use a repository class** when the data lives in a remote MySQL or Postgres database (the connection you opened with NativeBlade's database driver). The repository hides whether you read from the local replica or the remote, and centralizes sync logic for offline-first patterns.

```php
// app/Repositories/PaymentsRepository.php
namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class PaymentsRepository
{
    public function recentForUser(int $userId, int $limit = 20): array
    {
        return DB::connection('mysql_remote')
            ->table('payments')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }
}
```

For local models (`User`, `Lesson` in SQLite), keep using Eloquent directly from the service.

## HTTP clients: external APIs only

Anything that calls a third-party API (Stripe, Pagar.me, Sentry, an analytics backend) goes through a client class in `app/Http/Clients/`. Standardizes base URL, auth, retry, error handling. The service depends on the client, not on `Http::get(...)` raw.

```php
// app/Http/Clients/PaymentClient.php
namespace App\Http\Clients;

use Illuminate\Support\Facades\Http;

class PaymentClient
{
    public function __construct(private string $baseUrl, private string $token) {}

    public function charge(int $cents, string $currency): array
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->retry(3, 200)
            ->post('/charges', ['amount' => $cents, 'currency' => $currency])
            ->throw()
            ->json();
    }
}
```

## Enums and constants

Every closed set of values is an enum. Every numeric tunable is a class constant. String literals and magic numbers in business code are bugs in disguise: typos compile, refactors miss callers, IDE autocomplete fails, AI agents hallucinate.

### Enums for closed sets

```php
// app/Enums/LessonStatus.php
namespace App\Enums;

enum LessonStatus: string
{
    case Locked     = 'locked';
    case Available  = 'available';
    case InProgress = 'in_progress';
    case Completed  = 'completed';

    public function isPlayable(): bool
    {
        return $this === self::Available || $this === self::InProgress;
    }
}
```

Cast the column on the model so the value is always typed:

```php
class Lesson extends Model
{
    protected $casts = ['status' => LessonStatus::class];
}
```

Now `$lesson->status === LessonStatus::Completed` everywhere. Typos won't compile.

### Enums live in `app/Enums/`

By domain when there are many:

```
app/Enums/
├── Auth/UserRole.php
├── Lessons/{LessonStatus,DifficultyLevel}.php
└── Push/PushType.php
```

### Push routing prefers enums + `tryFrom()`

```php
enum PushType: string
{
    case NewLesson   = 'new_lesson';
    case Achievement = 'achievement';
}

public function handle(PushPayload $payload): ?NativeResponse
{
    return match (PushType::tryFrom($payload->data['type'] ?? '')) {
        PushType::NewLesson   => app(LessonPushHandler::class)->handle($payload),
        PushType::Achievement => app(AchievementPushHandler::class)->handle($payload),
        null                  => null,  // unknown future type degrades gracefully
    };
}
```

### Constants for tunables

Timeouts, retry counts, TTLs, limits — anything numeric or short string that controls behavior. Name documents the why.

```php
class AuthService
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const TOKEN_TTL_DAYS = 30;
}

class PaymentClient
{
    private const TIMEOUT_SECONDS = 10;
    private const RETRIES = 3;
    private const RETRY_DELAY_MS = 200;
}
```

State wrappers already follow this — `private const KEY = 'auth.user'` is exactly this pattern. Extend the discipline everywhere business logic lives.

### When a literal is fine

- One-off tags in fluent builders: `->id('login')`, `->channel('lessons')`. If the string appears in exactly one place and refactoring it means editing only that line, leaving it inline is fine.
- HTML class names, route paths, user-facing copy. Those live in Blade and `lang/` files.

### The test

If a junior reads only the call site, would they understand what the value means?

```php
if ($lesson->status === 'completed') { ... }                  // no
Http::timeout(10)->retry(3, 200)->post($url);                 // no
$cache->put('user_' . $id, $value, 3600);                     // no

if ($lesson->status === LessonStatus::Completed) { ... }      // yes
Http::timeout(self::TIMEOUT_SECONDS)
    ->retry(self::RETRIES, self::RETRY_DELAY_MS)
    ->post($url);                                             // yes
$cache->put(self::userKey($id), $value, self::CACHE_TTL_SECONDS); // yes
```

## Internationalization

NativeBlade i18n has two APIs that mirror each other and one State wrapper to keep them in sync.

| Side | Function | File source | Placeholder |
|---|---|---|---|
| PHP / Blade / Livewire | `__('key.path', ['name' => $x])` or `@lang(...)` | `lang/{locale}/{file}.php` | `:name` |
| JS (shell, custom JS components) | `t('key.path', { name: x })` | `lang/{locale}.json` | `:name` |

Same `:placeholder` syntax on both sides so strings look identical.

### Layer 1: shell strings (JS, via `t()`)

The splash and boot screens render before PHP boots, so they use `t()` from `js/runtime/i18n.js`. NativeBlade ships `lang/en.json` and `lang/pt_BR.json` covering boot/splash keys. Add a new locale by dropping `lang/{locale}.json` with the same keys.

```js
import { t } from '../runtime/i18n.js';
statusEl.textContent = t('boot.loading');
```

JS detects the locale via, in order: `./nativeblade-locale.json` → `navigator.language` (OS language in Tauri) → `'en'`.

### Layer 2: app strings (PHP, via `__()`)

Standard Laravel translation files at `lang/{locale}/{file}.php`. Use in Blade and Livewire:

```blade
<h1>{{ __('messages.welcome', ['name' => $user['name']]) }}</h1>
<a href="/lessons">{{ __('messages.lessons') }}</a>
```

```php
$this->error = __('auth.failed');
```

### The bridge: `LocaleState`

Without coordination, JS uses `navigator.language` and PHP uses `config('app.locale')` from `.env`. They drift apart. Fix with a State wrapper plus middleware so both halves agree.

```php
// app/Native/State/LocaleState.php
class LocaleState
{
    private const KEY = 'locale.current';
    private const SUPPORTED = ['en', 'pt_BR'];
    private const DEFAULT = 'en';

    public static function set(string $locale): void
    {
        if (! in_array($locale, self::SUPPORTED, true)) return;
        NativeBlade::setState(self::KEY, $locale);
        app()->setLocale($locale);
    }

    public static function current(): string
    {
        return NativeBlade::getState(self::KEY, self::DEFAULT);
    }

    public static function supported(): array
    {
        return self::SUPPORTED;
    }
}
```

```php
// app/Http/Middleware/ApplyLocale.php
class ApplyLocale
{
    public function handle($request, Closure $next)
    {
        app()->setLocale(LocaleState::current());
        return $next($request);
    }
}
```

Register as global web middleware in `bootstrap/app.php`. Every request reads the persisted locale before Blade renders anything.

### Switching language at runtime

```php
class Settings extends Component
{
    public string $locale = '';

    public function mount()
    {
        $this->locale = LocaleState::current();
    }

    public function changeLanguage()
    {
        LocaleState::set($this->locale);
        return NativeBlade::navigate('/settings', force: true)->toResponse();
    }
}
```

`LocaleState::set` writes to `NB::setState` (persistent), so the choice survives app restart. Next request reads from the wrapper.

### When the JS shell should follow the user choice

If you need the splash to track the user's pick instead of the OS, write `public/nativeblade-locale.json` with the current locale after every `LocaleState::set` — `i18n.js` reads that file at boot. Most apps skip this since the splash is gone in under a second.

### Anti-patterns

- Hardcoded user-facing strings in Blade, Livewire, or shell JS. Every visible string flows through `__()` (PHP) or `t()` (JS).
- `app()->setLocale($x)` called directly from a component. Always via `LocaleState::set()` so the value persists.
- String-literal locale codes outside `LocaleState`. Keep the supported list in one place.

## Frontend assets & animations

The Blade layout at `resources/views/components/layouts/app.blade.php` is the app's HTML shell — the single place to load client-side assets.

### External JS/CSS: vendor the file, reference it by path

Put third-party libraries and your own front-end scripts in `public/`, and load them with a plain tag in `app.blade.php`:

```blade
{{-- resources/views/components/layouts/app.blade.php --}}
<script src="/js/tsparticles.bundle.min.js"></script>
<script src="/js/pet-renderer.js"></script>
```

- Vendor libraries live in `public/js/` (or `public/css/`) as their own file — download the minified build, commit it, reference it. NEVER paste a library's source inline in a Blade view.
- Your own front-end code (canvas renderers, chart glue, particle setups) is its own file in `public/js/`, not a `<script>` blob dropped in the middle of a component.
- Files under `public/` are served from the root: `public/js/pet-renderer.js` → `/js/pet-renderer.js`.
- Load order matters — put the library tag before the script that uses it.

Why: inlining libraries and ad-hoc scripts bloats every render, defeats browser caching, can't be reused across screens, and turns the view into an unreadable wall. One tag per asset keeps the shell clean and the files cacheable.

### The loading splash is `resources/js/index.html`

The screen shown while php-wasm boots is `resources/js/index.html` — the very first thing the user sees. Customize it (logo, colors, name) to match the app; don't ship it looking generic.

Two categories of markup:

- **Required — do NOT remove or rename** (`app.js` reads these by id):
  - `#splash` — the overlay, hidden once the app has booted.
  - `#app` — the iframe the Laravel app renders into.
  - the two `<script>` tags at the bottom.
- **Remove these — a spinner + status line reads like a loading web page, not a native app:**
  - `<div class="spinner"></div>` — a purely-CSS boot spinner, nothing in JS touches it.
  - `<div class="status" id="status"></div>` — the boot progress line. `app.js` reads it as `getElementById('status') || { textContent:'', style:{} }`, so removing it is null-safe and never breaks boot.

Delete both by default: a native-feeling splash shows only your branding while it boots, then hands off to the app. A minimal splash is just your branding inside `#splash` plus the `#app` iframe and the scripts.

### Tailwind compiles at build time — rebuild after changing classes

The layout loads CSS with `@vite(['resources/css/app.css'])`, which resolves to the compiled `public/build/` bundle. Tailwind scans your Blade/PHP and generates utilities **only when `npm run build` runs**. The `nativeblade:dev` watcher re-bundles PHP/Blade but does NOT recompile Tailwind, and it explicitly ignores `public/build/`.

So whenever you add or remove Tailwind utility classes, or edit `resources/css/app.css`:

1. Stop `nativeblade:dev`.
2. `npm run build`
3. Start `nativeblade:dev` again (it re-bundles the fresh CSS at boot).

Skip this and the new classes have no compiled CSS — they render as silent no-ops (an element that just doesn't get styled). This is the #1 "why isn't my style applying?" trap.

### Animations: CSS transitions, never JavaScript

Animate every show/hide — modals, sheets, toasts, drawers, any enter/leave — with CSS transitions driven by a single class, never by JavaScript.

The flicker-proof pattern:
- Keep the element **always mounted** in the DOM (do NOT `@if`/`wire:if` it in and out) and hidden by default.
- Livewire toggles one class (e.g. `is-open`) on a boolean property; nothing else changes.
- CSS transitions `opacity` + a `transform` (slide/scale) between the closed and `.is-open` states, plus a backdrop fade.

```blade
<div class="nb-modal @class(['is-open' => $showModal])">
    <div class="panel">…</div>
</div>
```
```css
.nb-modal { opacity: 0; pointer-events: none; transition: opacity .25s ease; }
.nb-modal > .panel { transform: translateY(16px); transition: transform .25s ease; }
.nb-modal.is-open { opacity: 1; pointer-events: auto; }
.nb-modal.is-open > .panel { transform: none; }
```

Why: Livewire flips that class exactly once, so the browser runs a single, uninterruptible CSS transition. Mounting/unmounting the node or driving the animation from JS makes it flicker (a visible flash) on open/close and never feels native.

## Debugging

PHP-WASM breaks the usual Laravel debugging tools:

- `dd()` and `dump()` halt execution but produce no output — you see a frozen iframe.
- `Log::info()` writes to `storage/logs/laravel.log` inside the WASM filesystem, where nobody is watching.
- `error_log()` works but goes to stderr without context.

Use **`NativeBlade::log()`** — it pipes structured entries through the request handler to the browser DevTools console of the shell, where you actually look.

```php
NativeBlade::log('User logged in', ['id' => $user->id], 'info');
NativeBlade::log('Slow query', ['ms' => $duration], 'warn');
NativeBlade::log('Payment failed', ['order' => $orderId, 'err' => $e->getMessage()], 'error');
NativeBlade::log('Trail snapshot', $trail->toArray(), 'debug');
```

In DevTools you get colored output keyed by level:

```
[NB:info]  User logged in    {id: 42}
[NB:warn]  Slow query        {ms: 1247}
[NB:error] Payment failed    {order: 1001, err: "..."}
[NB:debug] Trail snapshot    {xp: 320, streak: 4, completed: [...]}
```

Filter by level in DevTools as usual. The four levels map to `console.log` / `warn` / `error` / `debug`.

**Where to log:**
- **Services** — log domain events you'd want to see while debugging a feature
- **Push handlers** — log every incoming payload while developing the handler, then remove
- **Components** — only the unexpected branch (validation failed for a weird reason, service returned an unusual shape)

**Production discipline:** strip `debug` and most `info` calls before shipping, or wrap behind `app()->environment('local')`. `warn` and `error` can stay — they help triage user-reported bugs after launch.

Anti-pattern: `dd()` in a Livewire component. The render breaks silently.

## Anti-patterns

These are bugs in disguise. The MCP architecture tool will flag any of these.

1. **Logic in `mount()`.** Mount should hydrate state and dispatch a service call. If you have `if`/`match`/loops doing work in mount, move it to a service.
2. **Eloquent query inside a component.** Always go through a service.
3. **String literal for a state key.** Always go through a `*State` wrapper in `app/Native/State/`.
4. **`getState()` called multiple times in sequence.** Read once into a local variable, or expose a single accessor in the wrapper that returns the full slice.
5. **Manual validation in the component.** Use a Livewire Form Object.
6. **Closure push handler in `AppServiceProvider`.** Extract to `app/Native/Push/{Domain}PushHandler.php` with a `handle()` method.
7. **Component calling `Http::get(...)` directly.** Wrap the external API in `app/Http/Clients/`.

8. **Magic string or magic number in business code.** Closed sets become enums in `app/Enums/`. Tunables (timeouts, retries, limits, TTLs) become private class constants. Only one-off tags (`->id('login')`) stay inline.

9. **A JS library (or a wall of front-end code) pasted inline in a Blade view.** Vendor it to `public/js/` and load it with `<script src="/js/…">` in `app.blade.php`.

10. **Animating a show/hide from JavaScript, or `@if`/`wire:if`-mounting an overlay to animate it.** Keep it mounted, toggle one class, animate in CSS.

11. **One mega-component holding every screen behind a `$screen`/`$tab` toggle.** Each screen is its own component behind its own route; decompose busy screens into child components by responsibility.

## Worked example: a complete feature

A "redeem reward" feature, end to end.

**Form:**

```php
// app/Livewire/Forms/RedeemRewardForm.php
class RedeemRewardForm extends Form
{
    #[Validate('required|integer|exists:rewards,id')]
    public ?int $reward_id = null;

    #[Validate('required|string|min:6|max:6')]
    public string $confirmation_code = '';
}
```

**State wrapper:**

```php
// app/Native/State/TrailState.php
class TrailState
{
    public static function xp(): int
    {
        return NativeBlade::getState('trail.xp', 0);
    }

    public static function deductXp(int $amount): void
    {
        NativeBlade::setState('trail.xp', max(0, self::xp() - $amount));
    }
}
```

**Service:**

```php
// app/Services/Rewards/RewardService.php
class RewardService
{
    public function __construct(private PaymentClient $payments) {}

    public function redeem(int $rewardId, string $code, int $userId): array
    {
        $reward = Reward::findOrFail($rewardId);

        if (TrailState::xp() < $reward->cost) {
            return ['ok' => false, 'message' => 'Not enough XP'];
        }

        $charge = $this->payments->charge($reward->price_cents, 'BRL');
        if (! $charge['ok']) {
            return ['ok' => false, 'message' => 'Payment failed'];
        }

        TrailState::deductXp($reward->cost);

        return ['ok' => true, 'charge_id' => $charge['id']];
    }
}
```

**Component (controller):**

```php
class RedeemReward extends Component
{
    public RedeemRewardForm $form;

    #[Flash]
    public string $error = '';

    public function redeem(RewardService $rewards)
    {
        $this->form->validate();

        $result = $rewards->redeem(
            $this->form->reward_id,
            $this->form->confirmation_code,
            AuthState::user()['id'],
        );

        if (! $result['ok']) {
            $this->error = $result['message'];
            return NativeBlade::impact('heavy')->toResponse();
        }

        return NativeBlade::notification(fn ($n) => $n->title('Reward redeemed'))
            ->navigate('/rewards', replace: true)
            ->toResponse();
    }
}
```

That's the full stack. Component has eight lines of meaningful code. Service has the rule. State wrapper hides the key. Client hides the external HTTP.

## What this architecture costs

- More files for tiny apps. A 3-screen prototype doesn't need this structure.
- More indirection. Going from button click to actual DB write takes more jumps in the IDE.

## What it buys

- Tests stay fast because services don't need Livewire.
- Refactors stay local because every responsibility has one address.
- AI agents stop hallucinating because the rules are mechanical: where does X live, what calls Y.
- A second developer can read your `app/Services/` and understand the domain in 10 minutes.

The tradeoff is appropriate for apps that grow past prototype.
