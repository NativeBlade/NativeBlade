<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Mcp\Tool;

class ArchitectureRecipe implements Tool
{
    private const RECIPES = [
        'component-controller' => [
            'summary' => 'How to structure a Livewire component as a thin controller (no business logic).',
            'body' => <<<'TXT'
The Livewire component is a controller, not a view model. It does four things and nothing else:

1. Receives input (form fields, click handlers, deep link)
2. Calls a service
3. Updates state via a typed wrapper
4. Returns a NativeResponse with native actions

Anti-pattern: Eloquent queries, manual validation, hashing, business rules inside the component.

```php
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

        return NativeBlade::notification(fn ($n) => $n->title('Welcome'))
            ->navigate('/', replace: true)
            ->toResponse();
    }
}
```

The service holds the rule. The state wrapper hides the key. The component just routes.
TXT,
        ],

        'form-validation' => [
            'summary' => 'How to validate input in a Livewire component (Form Objects, not Laravel FormRequest).',
            'body' => <<<'TXT'
Use Livewire Form Objects (extends Livewire\Form). Laravel FormRequest is built for HTTP requests and does not fit Livewire's model.

Place forms in `app/Livewire/Forms/{Domain}Form.php`.

```php
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

In the component:

```php
class Login extends Component
{
    public LoginForm $form;

    public function login(AuthService $auth)
    {
        $this->form->validate();
        // $this->form->email and $this->form->password are now validated
    }
}
```

Validation errors flow into the standard $errors bag. Show in Blade via @error('form.email').
TXT,
        ],

        'global-state' => [
            'summary' => 'How to store cross-component state via NativeBlade::setState + typed wrappers.',
            'body' => <<<'TXT'
Cross-component state in NativeBlade lives in NativeBlade::setState (SQLite-backed). It survives navigates and app restarts.

Never call NativeBlade::setState with a string literal. Always wrap it in a class in `app/Native/State/{Domain}State.php`.

```php
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

Scopes:
- `NB::setState($key, $value)` defaults to 'persistent' (survives app restart)
- `NB::setState($key, $value, 'session')` clears when app closes
- For single-render values, use `#[Flash]` on a public prop instead

For NativeBlade's own request lifecycle, the state IS the runtime database. Use it.
TXT,
        ],

        'push-handler' => [
            'summary' => 'How to handle push notifications via a class with handle() method.',
            'body' => <<<'TXT'
Push handlers live in `app/Native/Push/{Domain}PushHandler.php`. Each handler is a class with a `handle()` method that receives a PushPayload and returns ?NativeResponse.

Not an inline closure in the provider, and not __invoke (IDE jump-to-definition gets confused).

```php
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

For multiple push types, one router handler that delegates to siblings via match() on payload->data['type'].
TXT,
        ],

        'deep-link' => [
            'summary' => 'How to route deep links into native actions.',
            'body' => <<<'TXT'
Deep link handlers live in `app/Native/DeepLinks/{Name}DeepLink.php`. The handler takes a parsed URL and returns a NativeResponse (typically a navigate).

```php
namespace App\Native\DeepLinks;

use NativeBlade\Facades\NativeBlade;
use NativeBlade\NativeResponse;

class LessonDeepLink
{
    public function handle(string $url): ?NativeResponse
    {
        $parts = parse_url($url);
        if (! preg_match('#/lesson/(\d+)#', $parts['path'] ?? '', $m)) {
            return null;
        }

        return NativeBlade::navigate("/lesson/{$m[1]}")->toResponse();
    }
}
```

Wire it up via `tauri-plugin-deep-link` listener in the JS layer, dispatching to the PHP handler. Treat each handler as one path pattern.
TXT,
        ],

        'biometric-flow' => [
            'summary' => 'How to gate an action behind biometric auth (Touch ID / Face ID / fingerprint).',
            'body' => <<<'TXT'
Biometric is mobile-only. The flow has two halves: the component triggers the prompt, the listener handles the result.

The service builds the NativeResponse. The component just dispatches.

```php
namespace App\Services\Auth;

use NativeBlade\Facades\NativeBlade;
use NativeBlade\NativeResponse;
use NativeBlade\Plugins\Biometric;

class BiometricFlow
{
    public function prompt(string $reason, string $id): NativeResponse
    {
        return NativeBlade::biometric(function (Biometric $b) use ($reason, $id) {
            $b->id($id)->reason($reason)->allowDeviceCredential();
        });
    }
}
```

```php
class Checkout extends Component
{
    public function confirm(BiometricFlow $bio)
    {
        return $bio->prompt('Confirm purchase', 'checkout')->toResponse();
    }

    #[On('nb:biometric')]
    public function onBiometric($success, $error = null, $id = null, CheckoutService $checkout)
    {
        if ($id !== 'checkout') return;

        if (!$success) {
            $this->error = $error ?: 'Authentication failed';
            return NativeBlade::impact('heavy')->toResponse();
        }

        $checkout->finalize(AuthState::user()['id']);
        return NativeBlade::navigate('/orders', replace: true)->toResponse();
    }
}
```

Use `id()` to disambiguate when one component issues multiple biometric prompts (checkout vs unlock vs edit-email).
TXT,
        ],

        'multiple-http-pool' => [
            'summary' => 'How to fetch from multiple HTTP endpoints concurrently.',
            'body' => <<<'TXT'
For pages that need multiple HTTP requests, sequential calls waste time. Use NativeBlade::pool() which wraps Laravel's Http::pool() builder, running requests in parallel through the WASM HTTP bridge.

This is for HTTP only. State reads are cheap (local SQLite) and do not benefit from pooling.

```php
namespace App\Services\Dashboard;

use NativeBlade\Facades\NativeBlade;

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

Component calls the service; the service uses pool. Stay disciplined: anti-pattern is putting pool() directly in the component.
TXT,
        ],

        'repository-vs-eloquent' => [
            'summary' => 'When to use a Repository class vs Eloquent direct.',
            'body' => <<<'TXT'
Eloquent IS already a repository (Active Record + Query Builder). Wrapping a local SQLite model in a Repository class is overengineering.

Use a Repository class ONLY when reading from a REMOTE MySQL or Postgres database (opened via NativeBlade's database driver). The repository hides the source so the service can swap remote-vs-local replicas without knowing.

```php
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

For local models (User, Lesson in SQLite), call Eloquent directly from the service. No repository class.

```php
class LessonService
{
    public function trail(int $userId): Collection
    {
        return Lesson::forUser($userId)->ordered()->get();
    }
}
```
TXT,
        ],

        'http-client' => [
            'summary' => 'How to wrap an external API (Stripe, Sentry, etc.) in a client class.',
            'body' => <<<'TXT'
Any call to a third-party API goes through a class in `app/Http/Clients/`. This standardizes base URL, auth, retries, error handling.

Service depends on the client, not on raw Http::get().

```php
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

Bind in the service provider:

```php
$this->app->singleton(PaymentClient::class, fn () => new PaymentClient(
    config('services.payments.url'),
    config('services.payments.token'),
));
```

This rule does not apply to your own backend (NativeBlade apps usually do not have one — the Laravel side runs in WASM on the device). It applies to third-party REST APIs.
TXT,
        ],

        'file-organization' => [
            'summary' => 'The canonical folder layout for a NativeBlade app.',
            'body' => <<<'TXT'
```
app/
├── Livewire/                    Components = Controllers, by domain
│   ├── Forms/                   Livewire Form Objects (validation)
│   ├── Auth/
│   └── Lessons/
│
├── Services/                    Business logic, PHP-pure, UI-agnostic
│   ├── Auth/
│   └── Lessons/
│
├── Repositories/                ONLY for remote MySQL/Postgres
│
├── Models/                      Eloquent (SQLite local), called from services
│
├── Native/                      Coordinates native APIs
│   ├── Push/                    Push handlers (handle() method)
│   ├── DeepLinks/               Deep link routing
│   └── State/                   Typed wrappers over NB::setState
│
├── Http/Clients/                External API wrappers
│
└── Providers/
    └── AppServiceProvider.php   Push handlers + deep links registered here
```

Folders by DOMAIN inside Livewire and Services (Auth/, Lessons/, Rewards/), not by TYPE (Controllers/, Services/, Repositories/ — except the top-level kind buckets).

This makes "what does this feature touch" a single folder browse.
TXT,
        ],

        'i18n' => [
            'summary' => 'How to support multiple languages — shell strings, Blade strings, and keeping JS and PHP in sync.',
            'body' => <<<'TXT'
NativeBlade i18n has two layers and one bridge.

## Two APIs, one mental model

| Side | Function | File source | Placeholder |
|---|---|---|---|
| PHP / Blade / Livewire | `__('key.path', ['name' => $x])` / `@lang(...)` | `lang/{locale}/{file}.php` | `:name` |
| JS (shell, custom JS components) | `t('key.path', { name: x })` | `lang/{locale}.json` | `:name` |

Both use the same `:placeholder` substitution syntax, so the translation strings look identical on either side.

## Layer 1: shell strings (JS, via `t()`)

The native splash and boot screens render before PHP boots, so they cannot use Laravel translations. They use the JS-side `t()` exported from `js/runtime/i18n.js`, which loads flat-key JSON from `lang/{locale}.json` at boot.

```js
import { t } from '../runtime/i18n.js';

statusEl.textContent = t('boot.loading');
toast.textContent = t('errors.network', { code: 503 });
```

NativeBlade ships `lang/en.json` and `lang/pt_BR.json` with the boot/splash keys. Add a new locale by dropping `lang/{locale}.json` with the same keys:

```json
{
    "boot.loading": "Carregando...",
    "boot.ready": "Pronto!",
    "splash.loading": "Iniciando..."
}
```

You can extend the same files with your own JS-side keys when you have custom shell components, but most app strings should live on the PHP side (Layer 2), not here.

JS detects the locale via, in order:
1. `./nativeblade-locale.json` (optional file with `{"locale": "pt_BR"}`)
2. `navigator.language` (reflects OS language in Tauri WebView)
3. `'en'` fallback

## Layer 2: app strings (PHP / Blade / Livewire)

Standard Laravel. Place files under `lang/{locale}/{file}.php` and call `__()` or `@lang()`:

```php
// lang/en/messages.php
return [
    'welcome' => 'Welcome, :name',
    'lessons' => 'Lessons',
];

// lang/pt_BR/messages.php
return [
    'welcome' => 'Bem-vindo, :name',
    'lessons' => 'Lições',
];
```

```blade
<h1>{{ __('messages.welcome', ['name' => $user['name']]) }}</h1>
<a href="/lessons">{{ __('messages.lessons') }}</a>
```

```php
class Login extends Component
{
    public function login()
    {
        // ...
        $this->error = __('auth.failed');
    }
}
```

`@lang('auth.failed')` and `trans_choice('messages.lessons_count', $count)` also work.

## The bridge: LocaleState

Without coordination, JS reads `navigator.language` and PHP reads `config('app.locale')` from `.env`. They desync. The fix is a State wrapper plus a tiny middleware so both sides agree.

```php
// app/Native/State/LocaleState.php
namespace App\Native\State;

use NativeBlade\Facades\NativeBlade;

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
namespace App\Http\Middleware;

use App\Native\State\LocaleState;
use Closure;

class ApplyLocale
{
    public function handle($request, Closure $next)
    {
        app()->setLocale(LocaleState::current());
        return $next($request);
    }
}
```

Register it as a global middleware in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [\App\Http\Middleware\ApplyLocale::class]);
})
```

## Switching language at runtime

A settings screen calls the State wrapper. Since the State change persists, the next request reads the new locale and Blade renders the right strings.

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

    public function render()
    {
        return view('livewire.settings', ['available' => LocaleState::supported()]);
    }
}
```

```blade
<select wire:model.change="locale" wire:click="changeLanguage">
    @foreach($available as $lang)
        <option value="{{ $lang }}">{{ $lang }}</option>
    @endforeach
</select>
```

## Sync to the JS shell (optional)

If you want the boot/splash to also follow the user's choice (not just the OS language), expose the locale via the bundle so the next cold start picks it up. Either:
- Write `public/nativeblade-locale.json` with `{"locale": "pt_BR"}` after `LocaleState::set()` (i18n.js reads this file at boot), or
- Inject `<meta name="nb-locale" content="pt_BR">` in the shell HTML and read it from i18n.js

Most apps live with "shell uses OS language, app uses user choice" because the splash screen flashes for <1s and the user rarely cares.

## Anti-patterns

- Hardcoded user-facing strings in Blade or PHP. Every visible string goes through `__()` or `@lang()`.
- Calling `app()->setLocale($x)` directly from a component. Always via `LocaleState::set()` so the value persists.
- String literal locale codes (`'pt_BR'`) outside the LocaleState wrapper. Define `SUPPORTED` once.
TXT,
        ],

        'enums-and-constants' => [
            'summary' => 'How to model closed sets and tunables — never use string literals or magic numbers.',
            'body' => <<<'TXT'
Every closed set of values is an enum. Every numeric tunable is a class constant. String literals and magic numbers in business code are bugs in disguise: typos compile, refactors miss callers, IDE autocomplete fails, the agent hallucinates.

## Enums for closed sets

Anything with a fixed list of possible values: status, type, role, level, kind, mode.

```php
namespace App\Enums;

enum LessonStatus: string
{
    case Locked    = 'locked';
    case Available = 'available';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Locked     => 'Locked',
            self::Available  => 'Available',
            self::InProgress => 'In progress',
            self::Completed  => 'Completed',
        };
    }

    public function isPlayable(): bool
    {
        return $this === self::Available || $this === self::InProgress;
    }
}
```

Usage anywhere:

```php
if ($lesson->status === LessonStatus::Locked) { ... }
$lesson->update(['status' => LessonStatus::Completed]);
```

Eloquent casts make this idiomatic:

```php
class Lesson extends Model
{
    protected $casts = [
        'status' => LessonStatus::class,
    ];
}
```

Now `$lesson->status` is always a `LessonStatus` instance. Comparison, match, autocomplete — all type-safe.

## Where enums live

`app/Enums/` at the root, with subfolders by domain when there are many:

```
app/
└── Enums/
    ├── Auth/
    │   └── UserRole.php
    ├── Lessons/
    │   ├── LessonStatus.php
    │   └── DifficultyLevel.php
    └── Push/
        └── PushType.php
```

## Push payload type routing — enum first

```php
namespace App\Enums\Push;

enum PushType: string
{
    case NewLesson    = 'new_lesson';
    case Achievement  = 'achievement';
    case StreakAlert  = 'streak_alert';
}
```

```php
public function handle(PushPayload $payload): ?NativeResponse
{
    $type = PushType::tryFrom($payload->data['type'] ?? '');

    return match ($type) {
        PushType::NewLesson   => app(LessonPushHandler::class)->handle($payload),
        PushType::Achievement => app(AchievementPushHandler::class)->handle($payload),
        PushType::StreakAlert => app(StreakPushHandler::class)->handle($payload),
        null                  => null,
    };
}
```

The server can keep sending strings — `tryFrom()` returns `null` for unknown types instead of throwing, so unknown future types degrade gracefully.

## Constants for tunables

Any number or string that controls behavior is a class constant. Each constant has an obvious name that documents why it exists.

```php
class AuthService
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const TOKEN_TTL_DAYS = 30;

    public function attempt(string $email, string $password): array
    {
        if ($this->failedAttempts($email) >= self::MAX_LOGIN_ATTEMPTS) {
            return ['ok' => false, 'message' => "Try again in " . self::LOCKOUT_MINUTES . " minutes"];
        }
        // ...
    }
}
```

```php
class PaymentClient
{
    private const TIMEOUT_SECONDS = 10;
    private const RETRIES = 3;
    private const RETRY_DELAY_MS = 200;

    public function charge(int $cents, string $currency): array
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->retry(self::RETRIES, self::RETRY_DELAY_MS)
            ->post('/charges', ['amount' => $cents, 'currency' => $currency])
            ->json();
    }
}
```

The grep-ability and refactor safety justifies the seven extra characters of `self::`.

## State wrappers ARE this pattern

Notice how `app/Native/State/AuthState.php` already follows the rule:

```php
class AuthState
{
    private const KEY = 'auth.user';  // ← constant, not a literal at the call site
}
```

Every state wrapper has its key as a private constant. That's why we never call `NativeBlade::setState('auth.user', ...)` from the outside.

## What stays a literal

- Tiny tags that exist in one place only: `->id('login')`, `->channel('lessons')`. If a string appears in exactly one call and refactoring it means touching only that line, it's fine.
- HTML class names, route paths, error messages that the user reads. Those live in Blade or `lang/` files.

## Anti-patterns

```php
// ❌ Bad
if ($lesson->status === 'completed') { ... }                          // magic string
$user->role === 'admin' ? $this->showAdmin() : $this->showUser();     // typo-prone
Http::timeout(10)->retry(3, 200)->post($url);                         // magic numbers
$cache->put('user_' . $id, $value, 3600);                             // what's 3600?
```

```php
// ✅ Good
if ($lesson->status === LessonStatus::Completed) { ... }
$user->role === UserRole::Admin ? $this->showAdmin() : $this->showUser();
Http::timeout(self::TIMEOUT_SECONDS)->retry(self::RETRIES, self::RETRY_DELAY_MS)->post($url);
$cache->put(self::userKey($id), $value, self::CACHE_TTL_SECONDS);
```

The right test: if a junior reads only the call site, would they understand what the value means? If no, it's wrong.
TXT,
        ],

        'debugging' => [
            'summary' => 'How to debug code running inside PHP-WASM (no dd(), no Laravel log file).',
            'body' => <<<'TXT'
Standard Laravel debugging breaks in NativeBlade:
- `dd()` and `dump()` halt PHP execution mid-request, but the request runs inside WASM with no terminal output. The user sees a broken page; you see nothing.
- `Log::info(...)` writes to `storage/logs/laravel.log`, which lives inside the WASM filesystem. Nobody is tailing that file.
- `error_log(...)` writes to PHP-WASM stderr but you have to know where it surfaces.

The supported way is **`NativeBlade::log()`** — it pipes structured entries from PHP-WASM into the browser DevTools console of the shell, where you actually have eyes.

```php
NativeBlade::log('User logged in', ['id' => $user->id], 'info');
NativeBlade::log('Slow query', ['ms' => $duration], 'warn');
NativeBlade::log('Payment failed', ['order' => $orderId, 'error' => $e->getMessage()], 'error');
NativeBlade::log('Trail snapshot', $trail->toArray(), 'debug');
```

In DevTools you see colored output:
```
[NB:info]  User logged in {id: 42}
[NB:warn]  Slow query {ms: 1247}
[NB:error] Payment failed {order: 1001, error: "..."}
[NB:debug] Trail snapshot {xp: 320, streak: 4, completed: [...]}
```

Levels map to `console.log`/`warn`/`error`/`debug`. Filter by level in DevTools as usual.

**Where to log:**
- In services, log domain events (`AuthService::attempt → 'login attempt'`)
- In push handlers, log every incoming payload while developing
- In components, only log unexpected branches (a service returning false, validation failing in an odd way) — not routine flow

**Production discipline:** remove (or wrap behind `app()->environment('local')`) the `debug` and `info` calls before shipping. `warn`/`error` can stay — they help when triaging user-reported issues.

Anti-pattern: `dd()` in a Livewire component. The render breaks, you see a frozen iframe, no info.
TXT,
        ],

        'anti-patterns' => [
            'summary' => 'The 7 forbidden patterns that signal the architecture is breaking down.',
            'body' => <<<'TXT'
Each of these is a flag the architecture is being violated. Refactor away from them.

1. **Logic in mount()** — Mount should hydrate state and dispatch a service call. Conditions, loops, and computations belong in a service.

2. **Eloquent query inside a Livewire component** — Always go through a Service. The component shouldn't even import Models.

3. **String literal as state key** — `NativeBlade::setState('auth.user', ...)` everywhere is a refactor nightmare. Always go through a `*State` wrapper class in `app/Native/State/`.

4. **Multiple sequential getState() calls** — Read once into a local variable, or expose a single accessor in the State wrapper that returns the full slice.

5. **Manual validation in the component** — Use a Livewire Form Object (`app/Livewire/Forms/{Name}Form.php` extending `Livewire\Form`).

6. **Closure push handler in AppServiceProvider** — Extract to `app/Native/Push/{Domain}PushHandler.php` with a public `handle(PushPayload $payload)` method.

7. **Component calling Http:: directly** — Wrap the external API in `app/Http/Clients/`. The service depends on the client.

8. **Magic string / magic number in business code** — Closed sets become enums in `app/Enums/`. Tunables (timeouts, retries, limits, TTLs) become private class constants. Only one-off tags (`->id('login')`) stay as literals.

The MCP `architecture_recipe` tool returns the correct pattern for each of these when asked.
TXT,
        ],
    ];

    public function name(): string
    {
        return 'architecture_recipe';
    }

    public function description(): string
    {
        return 'Returns the NativeBlade-canonical pattern for a specific use case (component-controller, form-validation, global-state, push-handler, deep-link, biometric-flow, multiple-http-pool, repository-vs-eloquent, http-client, file-organization, enums-and-constants, i18n, debugging, anti-patterns). Use this when generating code instead of guessing — it returns rules + example for each pattern. Call with no arguments to list every recipe.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'use_case' => [
                    'type' => 'string',
                    'description' => 'Recipe name. Omit to list every available recipe with summary.',
                    'enum' => array_keys(self::RECIPES),
                ],
            ],
        ];
    }

    public function run(array $args): string
    {
        $name = $args['use_case'] ?? null;

        if ($name === null || $name === '') {
            $list = [];
            foreach (self::RECIPES as $key => $recipe) {
                $list[] = ['name' => $key, 'summary' => $recipe['summary']];
            }
            return json_encode([
                'available' => $list,
                'usage' => 'Call architecture_recipe with use_case="<name>" to get the full recipe.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (!isset(self::RECIPES[$name])) {
            throw new \InvalidArgumentException(
                "Unknown recipe '$name'. Available: " . implode(', ', array_keys(self::RECIPES))
            );
        }

        $recipe = self::RECIPES[$name];
        return "# {$name}\n\n{$recipe['summary']}\n\n{$recipe['body']}";
    }
}
