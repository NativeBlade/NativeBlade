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
retries, and the response helpers.

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

## Enable the plugin

`HTTP` is opt-in. Declare it in your `AppServiceProvider`:

```php
NativeBladeConfig::plugins([
    Plugin::HTTP,
]);
```
