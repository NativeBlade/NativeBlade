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

**Sessions are not Laravel sessions** — there is no HTTP session in WASM. NativeBlade's session scope is a marker that the value should be cleared on app close.

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
