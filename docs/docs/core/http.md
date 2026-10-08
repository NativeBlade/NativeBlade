---
title: "HTTP"
description: "Make HTTP requests with Laravel's Http facade, run natively and free of CORS."
---

# HTTP

Laravel's `Http` facade works as usual. Requests run on the native side, not in
the WebView, so there is no CORS restriction and nothing to proxy.

```php
use Illuminate\Support\Facades\Http;

$response = Http::get('https://api.example.com/users/1');
$user = $response->json();

Http::withToken($token)->post('https://api.example.com/orders', [
    'item' => 'coffee',
]);
```

Everything you know from Laravel applies: headers, tokens, JSON, timeouts,
retries, and the response helpers. Inside the app the native bridge is
installed as the Guzzle handler of every request, so Laravel's own pipeline
still runs: `beforeSending()` callbacks, `Http::fake()` in tests, request
middleware and macros behave exactly as on a server. Only the network hop is
different.

## Parallel requests

A screen that needs several requests should not run them one after another. Use
`NativeBlade::pool()`, which wraps Laravel's `Http::pool()` and runs the calls in
parallel through the native HTTP stack.

```php
use NativeBlade\Facades\NativeBlade;

[$user, $stats, $feed] = NativeBlade::pool(fn ($pool) => [
    $pool->get('https://api.example.com/user'),
    $pool->get('https://api.example.com/stats'),
    $pool->get('https://api.example.com/feed'),
]);

$user->json();
```

Responses come back in the same order as the calls.

## How requests run

There is no blocking socket in the WebView, so the shell performs the fetch.
php-wasm runs with JSPI (or Asyncify where the WebView lacks it), which lets
PHP pause inside a call and resume with the result:

```
PHP: Http::get('https://api.example.com/user')
→ WasmHttpHandler sends method, URL, headers and body to the shell and PHP pauses
→ JS performs the real fetch natively
→ PHP resumes with the response and the Http facade returns it
```

The request runs once, top to bottom, exactly as it would on a server. Loops
with a request per item, writes between calls, locks, random values and
timestamps all behave as they do in plain Laravel. The only cost of a call is
its own network time; there is no limit on how many a request makes, beyond
the user's patience.

For several independent calls, `NativeBlade::pool()` sends them together and
the shell runs them in parallel; the responses come back in order.

### Calls the runtime cannot pause in

The Asyncify build can only pause PHP on instrumented paths. A native call
(an HTTP request, a query on the native database, a native filesystem
operation) made from inside the callback of one of these kills the PHP
instance there:

`usort`, `uasort`, `uksort`, `array_walk`, `array_walk_recursive`, the
`array_udiff` and `array_uintersect` family, `json_encode` (a
`JsonSerializable`), `count()` (a `Countable`), `preg_replace_callback`, an
output buffer handler, a user error handler, an autoloader, and
`ReflectionFunction::invoke`.

NativeBlade checks the PHP stack before pausing and throws
`NativeCallNotAllowed` with the construct named, on every platform and in
PHPUnit, so the problem shows on the first run anywhere instead of only on
iOS. Collect the data first, then sort, encode or walk the result. A lazy
loaded relation inside `jsonSerialize()` on a model from the native database
is the typical case: load it before encoding. `array_map`, `array_filter`,
`array_reduce`, `call_user_func`, generators, magic methods and `foreach`
over an `IteratorAggregate` are fine.

## Enable the plugin

`HTTP` is opt-in. Declare it in your `AppServiceProvider`:

```php
NativeBladeConfig::plugins([
    Plugin::HTTP,
]);
```
