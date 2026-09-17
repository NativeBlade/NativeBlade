# Observability: tracing across PHP, JS, Rust and native

## Goal

Give a NativeBlade app one trace per unit of work that crosses every layer it
touches, and a bridge where the developer plugs the observability tool they
already use (an OpenTelemetry collector, Sentry, Datadog, Grafana, or their own
endpoint) and receives those traces.

Two paths must be covered:

- Mobile: PHP (php-wasm) -> JS shell -> Rust plugin -> Kotlin / Swift -> back
- Desktop: PHP (php-wasm) -> JS shell -> Rust command -> back

The result is a span tree such as:

```
navigate /orders                               (JS shell, 812 ms)
  php request POST /livewire/update            (php-wasm, 610 ms)
    Orders::confirm                            (PHP, Livewire call)
    http GET https://api.example.com/orders/42 (bridge: PHP -> JS fetch, 180 ms)
    db select ... orders                       (bridge: PHP -> JS -> Rust db_query, 9 ms)
  action share                                 (JS, 1.4 s)
    plugin:nativeblade-sharing|share           (Rust -> Kotlin SharePlugin.share)
  render                                       (JS, 60 ms)
```

## What exists today

The framework has no tracing. What comes closest:

- `NativeBlade::log()` (`src/ShellConfig.php:709-718`) writes
  `__NB_LOG__{json}__NB_LOG_END__` to stderr; `js/runtime/request-handler.js:135-150`
  parses it and posts a `log` action, which prints to the devtools console
  (`js/wasm-app/actions/system.js:94-107`). No timestamp, no request id, and the
  log stops at the console.
- Automatic screen tracking for Firebase Analytics
  (`js/runtime/analytics-screen.js:28-37`, called from `router.js:264`).
- Ad-hoc `console.log` lines in the fetch interceptor, the bridge and the
  budget warnings of the HTTP/DB/FS bridges.
- Rust: one `eprintln!` in the scheduler. No `log` or `tracing` crate.

Nothing carries an id or a timestamp from one layer to the next. Each layer
identifies work with its own local scheme:

| Layer | Identifier | Where |
|---|---|---|
| Page (iframe) fetch | `_id++` per frame, reset on every page swap | `js/wasm-app/interceptor/fetch-override.js:4` |
| Navigation | `navigationVersion` (staleness guard, not an id) | `js/wasm-app/router.js:21,224` |
| Satellite window relay | `<window>-s<seq>` (globally unique) | `js/wasm-app/window-relay.js:39` |
| Native action | optional user `id` echoed on the result event | `src/NativeResponse.php` builders, e.g. `:271-276` |
| HTTP / DB / FS bridge op | `key = md5(content + index)` | `WasmHttpHandler.php:56`, `NativeConnection.php:170`, `NativeFilesystemAdapter.php:147` |
| Shell module | Livewire component id | `src/Concerns/HasNativeShell.php:93` |

## Hop map

Every place a unit of work changes layer, with what is known there. These are
the instrumentation points.

### A. Request lifecycle (page -> shell -> PHP -> page)

| # | Hop | Where | Data available |
|---|---|---|---|
| A1 | Page issues a request (`fetch` override) | `interceptor/fetch-override.js:2-47` `wasmFetch` | method, path, body. The Livewire payload (`components[].snapshot`, `calls[].method`) is only visible here on the JS side |
| A2 | Shell receives `nativeblade-request` | `router.js:145-152` -> `frame-request.js:35-57` `serveFrameRequest` | frame id, path, options, generation |
| A3 | Serial queue | `frame-request.js:15-22`, `router.js:39-49` `requestFull` | queue wait time |
| A4 | Server vars written for PHP | `request-handler.js:41-64` | REQUEST_URI, REQUEST_METHOD, headers. **The only place a header can be injected into PHP** |
| A5 | PHP executes | `request-handler.js:100` `php.run({ code })` | exact PHP wall time boundary |
| A6 | stderr parsed | `request-handler.js:103,135-150` `processStderr` | `__NB_LOG__` entries, PHP errors |
| A7 | Bridge pending detected, re-entry | `request-handler.js:105-121`, `:152-164` `fulfillInBackground` | one logical request = N `php.run()` executions |
| A8 | Response posted back | `frame-request.js:48-51` `nativeblade-response` | http status, text size |
| A9 | Render | `router.js:260-496` `renderPage` | srcdoc swap, transition, inlining |

### B. Native action (PHP -> JS -> handler)

| # | Hop | Where | Data available |
|---|---|---|---|
| B1 | Action queued in PHP | `src/NativeResponse.php:1373-1377` `push()` | action name, data. Single append point |
| B2 | Sent to the page | `:1396-1412` `toResponse()`: Livewire `dispatch('__nativeblade')` or JSON `{nativeblade, actions}` | Livewire component when on the Livewire path |
| B3 | Page forwards to shell | `interceptor.js:66-68` `__nbBridge` -> `nativeblade-native` | action, payload |
| B4 | `wire:nb-bridge` click (no PHP) | `interceptor.js:137-142` | action, payload from the attribute |
| B5 | Shell dispatches | `bridge.js:158-185` `handleNativeAction` | handler lookup, `buildCtx` (`:133-156`), `replyWindow` |
| B6 | Result back to the page | `bridge.js:150-151` `ctx.post` -> `interceptor.js:85-109` -> `Livewire.dispatch('nb:...')` | event name, payload |

### C. JS -> Rust -> native

| # | Hop | Where | Data available |
|---|---|---|---|
| C1 | Tauri invoke | `bridge.js:75-78` `apis.invokeTauri` | command string `plugin:<name>|<cmd>` or a framework command, args |
| C2 | Rust plugin forwards to mobile | e.g. `rust/plugins/sharing/src/mobile.rs:19-23` `run_mobile_plugin("share")` | plugin name, command, serialized args |
| C3 | Kotlin / Swift command | `SharePlugin.kt:13-14` `@Command fun share(invoke)`, `SharePlugin.swift:11` | `invoke.getArgs()`, `resolve()` / `reject()` |
| C4 | Framework Rust commands | `rust/src/lib.rs:29-40` (`db_query`, `nb_copy_file`, `open_window`, `register_schedules`, ...) | typed args |
| C5 | Rust-side plugin commands | only `rust/plugins/tasks/src/lib.rs:58-67` | typed args |

### D. PHP asks JS to do work (HTTP, DB, FS bridges)

Convention: PHP writes `/tmp/__nb_<kind>_pending.json`, prints a sentinel and
exits; JS fulfills, writes `/tmp/__nb_<kind>_cache/<key>.json`; PHP re-runs and
reads the cache.

| Kind | PHP | JS | Data available |
|---|---|---|---|
| HTTP | `src/Http/WasmHttpHandler.php:46-109` | `js/runtime/http-bridge.js:24-105` | method, url, status, retries (`MAX_RETRIES=10`) |
| DB | `src/Database/NativeConnection.php:167-196` | `js/runtime/db-bridge.js:15-69` -> Rust `db_query` | type, sql, bindings, driver, connection |
| FS | `src/Storage/NativeFilesystemAdapter.php:145-172` | `js/runtime/fs-bridge.js:37-160` | op, path, baseDir |

### E. Desktop only

| Hop | Where |
|---|---|
| Windows | `rust/src/commands/window.rs` `open_window/close_window/focus_window`; JS `actions/window.js` |
| Menu and tray | `rust/src/commands/menu.rs:73-83`, `tray.rs:103-114` emit `nativeblade-menu`; JS `app.js:147-159` |
| Notifications | `actions/notification.js:36-58` via `tauri_plugin_notification` |
| Satellite windows | `js/wasm-app/window-relay.js` (has its own unique request ids) |

## Design

### Principles

- One trace id per unit of work, created once in the JS shell and carried
  through every hop in both directions.
- The shell is the collector. PHP, Rust and native never talk to the outside
  world for tracing; they report to the shell, which batches and exports.
- Export in a standard shape (OpenTelemetry span semantics, OTLP/JSON over
  HTTP), so any collector works and the developer does not need a NativeBlade
  SDK on the receiving side.
- Zero cost when off. Sampling and an exporter are opt-in; the default build
  captures nothing.
- No payload bodies by default. Names, durations, status and small attributes
  only. Bodies and SQL bindings are opt-in per environment.

### Trace context

Use the W3C `traceparent` format (`00-<trace_id>-<span_id>-<flags>`) as the one
value that travels. It is what every tool already understands.

Propagation per hop:

| Direction | Carrier |
|---|---|
| Shell -> PHP | server var `HTTP_TRACEPARENT` in `request-handler.js:41-57` (A4). PHP reads `request()->header('traceparent')` |
| PHP -> shell (actions) | `NativeResponse::push()` (B1) adds `__nbTrace` to each action's data when a trace is active. `handleNativeAction` (B5) reads it and strips it before calling the handler |
| PHP -> shell (spans, logs) | stderr markers, same channel as `__NB_LOG__` (A6): `__NB_SPAN__{json}__NB_SPAN_END__` |
| Shell -> Rust | an extra `__nbTrace` key in the invoke args (C1). Framework commands and NativeBlade plugins accept and ignore it; third-party Tauri plugins get the args without it |
| Rust -> native | already in the serialized args (C2); Kotlin / Swift plugins may read it for their own spans |
| Shell -> page (results) | `ctx.post` (B6) adds `__nbTrace`; the interceptor strips it before `Livewire.dispatch`, and exposes it on `window.__nbTrace` for the next request |
| Bridges (D) | the existing `key` is the span id of the bridge op; the pending JSON gains `traceparent` |

The frame-local `_id` (A1) is not enough because it resets on every page swap.
The root span is created at A2 (`serveFrameRequest`) for requests and in
`navigateInternal` for navigations, with a `crypto.randomUUID()`-based id.

### Spans by layer

**JS shell (`js/runtime/trace.js`, new)**

- `startSpan(name, attrs, parent)` / `end()`; a `currentTrace` per navigation
  generation; a batch buffer flushed on an interval and on `pagehide`.
- Root spans: `navigate <path>`, `request <method> <path>` (A2 to A8).
- Child spans: `php.run` (A5, exact PHP wall time), `render` (A9), `action
  <name>` (B5 to the handler's promise settling), `invoke <command>` (C1),
  `bridge.http <method> <host>`, `bridge.db <type>`, `bridge.fs <op>` (D).
- Queue wait (A3) recorded as an attribute on the request span.

**PHP (`src/Observability/`, new)**

- A middleware opens the request span from `HTTP_TRACEPARENT`, records route,
  Livewire component and called method (from the `X-Livewire` payload), status.
- `NativeBlade::span('name', fn () => ...)` and `NativeBlade::log()` gain the
  trace id and a timestamp.
- Spans are emitted to stderr as `__NB_SPAN__` on `terminating()`, with
  durations measured inside PHP (`hrtime`). The shell attaches them under the
  `php.run` span and does not compare PHP and JS clocks; only durations cross.
- The bridge classes (D) write `traceparent` into the pending JSON.

**Rust (`nativeblade-tauri` and plugins)**

- Add the `tracing` crate. Framework commands (C4) and plugin entry points
  (C2) open a span named after the command, with the `__nbTrace` argument as
  parent context.
- A `tracing` subscriber that emits finished spans to the shell through
  `app.emit("nativeblade-trace", span)`, batched. The shell attaches them to the
  right trace by id.
- Also captures what happens without a JS caller: the scheduler loop, tray
  and menu events.

**Native (Kotlin / Swift)**

- Optional. Plugins that do slow work (camera, payments, secure storage) can
  time their `@Command` and return the duration in the resolved payload as
  `__nbDuration`; the Rust span records it as an attribute. No native SDK.

### Exporters

Configured in PHP, like every other NativeBlade setting:

```php
NativeBladeConfig::observability(function (Observability $o) {
    $o->enabled(env('NB_TRACE', false))
      ->sampleRate(1.0)                        // 0.0 to 1.0
      ->exporter('otlp')                       // console | otlp | event
      ->endpoint('http://collector.local:4318/v1/traces')
      ->headers(['Authorization' => 'Bearer ...'])
      ->includeSql(false)                      // bindings and bodies off by default
      ->attributes(['service.name' => 'orders-app', 'app.version' => '1.4.0']);
});
```

Written to `public/nativeblade-config.json` by `nativeblade:config`, read by the
shell at boot.

- `console`: pretty-printed span tree in the devtools console. Default when
  enabled in dev.
- `otlp`: OTLP/JSON over HTTP, batched, sent with the Tauri HTTP plugin on
  mobile (bypasses WebView CORS) and `fetch` elsewhere. Works with the
  OpenTelemetry Collector, Grafana Tempo, Jaeger, Datadog, Honeycomb and Sentry's
  OTLP endpoint without an SDK on the app side.
- `event`: every finished trace is dispatched to the app as the `nb:trace`
  Livewire event, so a developer can store or forward it from PHP.

A custom exporter is a JS module registered from a shell component
(`nativeblade-components/`), the same mechanism shell modules use.

### Developer tooling

- `nativeblade:dev` prints the span tree of each request in the terminal when
  `--trace` is passed (uses the `console` exporter through the existing
  `nativeblade-native` log channel).
- A `traceparent` value can be pasted into the collector's UI to find the same
  request the developer saw in the terminal.

## Phases

1. **Correlation and JS spans.** `trace.js`, root spans for requests and
   navigations, `php.run`, `action`, `invoke` and bridge spans, `traceparent`
   into PHP server vars, `__nbTrace` on actions and results. `console` exporter.
   Everything in this phase is JS and one line in `NativeResponse::push()`.
2. **PHP spans.** Middleware, `NativeBlade::span()`, stderr `__NB_SPAN__`
   emission, Livewire component and method attributes, bridges carrying
   `traceparent`.
3. **Config and OTLP.** `NativeBladeConfig::observability()`, sampling,
   batching, `otlp` and `event` exporters, docs page.
4. **Rust.** `tracing` in framework commands and NativeBlade plugins,
   `nativeblade-trace` emit, scheduler and menu spans.
5. **Native durations** in the plugins where it matters, and the `--trace`
   terminal view.

Phases 1 to 3 already give the developer the PHP -> JS -> Rust boundaries with
timing, because the JS `invoke` span wraps the whole Rust plus native call.
Phase 4 adds detail inside Rust; phase 5 inside Kotlin / Swift.

## Risks and open questions

- **Clocks.** php-wasm and the shell share the JS clock, but PHP `hrtime` and
  `performance.now()` have different origins. Only durations and ordering cross
  from PHP; the shell assigns absolute timestamps.
- **stderr parsing cost.** `processStderr` already runs a regex over stderr on
  every request. Span JSON must stay small; cap spans per request (e.g. 200)
  and drop with a counter attribute when exceeded.
- **Livewire payload size.** `__nbTrace` on every action and result is one
  short string; acceptable. Never attach span data to Livewire events.
- **Page swaps.** The iframe is replaced on every navigation, so page-side
  state cannot hold the trace. The shell owns it and re-injects
  `window.__nbTrace` through the interceptor.
- **Bridge re-entry.** One request runs `php.run()` several times (A7). Each
  run is a child span of the same request span; the PHP middleware must not
  open a new root on re-entry (it sees the same `traceparent`).
- **Privacy.** URLs can carry tokens and SQL can carry personal data. Default
  attributes: method, host and path without query string; SQL with bindings
  replaced by `?`. `includeSql` and `includeBodies` are explicit opt-ins.
- **Mobile network.** Batches are sent on an interval and on `pagehide`; a
  failed export is dropped, never retried into a backlog that grows.
- **Third-party Tauri plugins.** The `__nbTrace` arg cannot be passed to
  official plugins (geolocation, biometric, ...) since they validate args. The
  JS `invoke` span still times them; only the Rust-internal detail is missing.
- **Satellite windows** already have unique request ids; the relay should
  carry `traceparent` so a request served through the main window joins the
  satellite's trace.
