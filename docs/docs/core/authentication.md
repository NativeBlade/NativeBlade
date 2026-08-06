---
title: "Authentication"
description: "Guard routes with middleware and keep auth state client-side."
---

# Authentication

Authentication in a NativeBlade app works like any Laravel app, with two rules
that come from php-wasm running on the device:

1. A guard redirects by returning `NativeBlade::navigate(...)->toResponse()`, never
   Laravel's `redirect()`. The navigate action is carried in the response and run
   by the shell, so the guard works on a full-page load and on a Livewire update
   alike.
2. Auth state lives in the client-side state store, not a server session. php-wasm
   serves each request fresh, so `auth()` and the session do not persist across
   requests. Write the user on login with `NativeBlade::setState('auth.user', ...)`
   and read it back in your guards.

## Route guards (middleware)

The guard (`app/Http/Middleware/NativeBladeAuth.php`):

```php
use Closure;
use NativeBlade\Facades\NativeBlade;

class NativeBladeAuth
{
    public function handle($request, Closure $next)
    {
        if (! NativeBlade::getState('auth.user')) {
            return NativeBlade::navigate('/login')->toResponse();
        }
        return $next($request);
    }
}
```

The mirror guard sends a logged-in user away from guest-only screens like login:

```php
class NativeBladeGuest
{
    public function handle($request, Closure $next)
    {
        if (NativeBlade::getState('auth.user')) {
            return NativeBlade::navigate('/home')->toResponse();
        }
        return $next($request);
    }
}
```

Alias them in `bootstrap/app.php` (the `nb.` prefix avoids clashing with Laravel's
built-in `auth` / `guest` aliases). CSRF is disabled here too, since there is no
server session to protect:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['*']);
    $middleware->alias([
        'nb.auth'  => \App\Http\Middleware\NativeBladeAuth::class,
        'nb.guest' => \App\Http\Middleware\NativeBladeGuest::class,
    ]);
})
```

Apply them to route groups in `routes/web.php`:

```php
Route::middleware('nb.guest')->group(function () {
    Route::get('/login', Login::class);
    Route::get('/register', Register::class);
});

Route::middleware('nb.auth')->group(function () {
    Route::get('/home', Home::class);
    Route::get('/settings', Settings::class);
});
```

## Login and logout

Set the state on login and clear it on logout, navigating with `replace` so the
user cannot go back into the wrong side of the guard:

```php
// login
NativeBlade::setState('auth.user', $user->only('id', 'name'));
return NativeBlade::navigate('/home', replace: true)->toResponse();

// logout
NativeBlade::forget('auth.user');
return NativeBlade::navigate('/login', replace: true)->toResponse();
```

The `auth.user` state persists across app restarts (see [State Management](/core/state/)),
so a returning user stays signed in until you `forget` it.

::: callout warning "Do not use redirect() or the session"
A guard must return `NativeBlade::navigate(...)->toResponse()`. Laravel's
`return redirect('/login')` sends a 302 whose `Location` header the WebView never
follows, so the screen goes blank. And `auth()->check()` / `Auth::user()` read a
server session that php-wasm does not keep between requests: keep auth in
`NativeBlade::getState('auth.user')`.
:::

## Verifying credentials

The guard only checks presence of `auth.user`. Verifying the password on login is
ordinary Laravel — `Hash::check()` against a user in the on-device database (see
[Database](/core/database/)), or a call to your backend over
[HTTP](/core/http/) whose response you store in `auth.user`. NativeBlade does not
impose a driver; it only carries the navigate and holds the state.
