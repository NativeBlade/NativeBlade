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

## How requests run

Like the database, HTTP uses an exit-and-replay bridge. There is no blocking
socket in the WebView, so each request is fulfilled by re-running the PHP request
from the top:

```
PHP: Http::get('https://api.example.com/user')
→ WasmHttpHandler serializes method + URL + body, writes a temp file, exits
→ JS performs the real fetch natively
→ Caches the response, PHP re-executes from the top
→ WasmHttpHandler finds the cached response and returns it to the Http facade
```

Each request triggers one re-execution of PHP. For an action with N requests
there are N+1 PHP executions, the same shape as the
[database bridge](/core/database/).

### Keep the call sequence deterministic

A cached response is matched to its request by call **order**: the Nth `Http`
call in the run gets the Nth cached response. Every re-execution must therefore
issue the same requests in the same sequence. Standard code is deterministic and
works fine, but any logic that changes the sequence between re-executions, or a
side effect that alters it, breaks the match and can loop forever.

The safe pattern is to do all the network calls first, then the side effects
(database writes, file deletes, status flags) after the responses are in.

```php
// AVOID. Side effects between calls change the next re-execution.
Draft::where('pending', true)->each(function ($draft) {
    Http::post($url, $draft->payload);    // call index shifts as drafts are marked sent
    $draft->update(['pending' => false]); // fewer drafts next run, indexes slide, cache miss
    Storage::delete($draft->attachment);  // file gone on replay, empty upload
});
```

```php
// OK. Every re-execution issues the same calls in the same order.
$drafts = Draft::where('pending', true)->get();

$responses = $drafts->map(fn ($draft) => Http::post($url, $draft->payload));

// Side effects run only after every response is cached, so the replays above
// stayed identical on each run.
foreach ($drafts as $i => $draft) {
    if ($responses[$i]->successful()) {
        $draft->update(['pending' => false]);
        Storage::delete($draft->attachment);
    }
}
```

Two traps in particular:

- **Do not write a lock or "in progress" flag before the first request.** The
  re-execution reads its own flag and bails out mid-run. You do not need one:
  php-wasm serves one request at a time, and the replay is the same request, not
  a second one. If you must stamp an attempt, write it at the end, in a `finally`.
- **Do not delete files or records while sending.** Removing them mid-loop means
  the next re-execution cannot find them and sends empty. Discard them in a
  separate pass, after the response is saved.

For several independent calls, `NativeBlade::pool()` resolves them in a single
re-execution and sidesteps the ordering question entirely.

### The request budget

Because each call costs one re-execution, a single request cannot make an
unlimited number of them. The bridge caps a request at about ten sequential
`Http` calls. Past that it is abandoned with no response: the screen stays on its
old HTML, and a warning is logged to the console.

This rarely affects a normal screen, but it bites long, multi-step work such as a
full sync that pages through several endpoints. Two ways to stay well under the
cap:

- **Batch independent calls with `NativeBlade::pool()`.** A pool resolves all of
  its requests in a single re-execution, so ten parallel calls cost one, not ten.
- **Slice sequential, paginated work into separate requests.** Do one page (one
  network call) per request and start the next step when the previous one
  returns. A progress bar per step is a natural fit, and the cost per request
  stays flat whether the dataset has three pages or three hundred.

## Enable the plugin

`HTTP` is opt-in. Declare it in your `AppServiceProvider`:

```php
NativeBladeConfig::plugins([
    Plugin::HTTP,
]);
```
