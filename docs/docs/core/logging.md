---
title: "Logging"
description: "Where NativeBlade::log() and PHP errors go, and how to read them."
---

# Logging

There is no server to tail. PHP runs inside the app, so a log line has to leave
the WebView to be useful. `NativeBlade::log()` and every PHP warning or error
go to three places:

| Destination | When |
|---|---|
| The WebView console (devtools) | always |
| `nativeblade.log` in the app's log directory | inside the app (desktop and mobile) |
| The `nativeblade:dev` terminal | while the app was served by the dev server, including the dev client on a phone |

```php
NativeBlade::log('Exporting stats', ['user' => auth()->id()]);
NativeBlade::log('Retrying', ['attempt' => 3], 'warn');
NativeBlade::log('Payment failed', ['error' => $e->getMessage()], 'error');
```

Each entry is one line: timestamp, level, message and the context as JSON.
PHP errors appear with the `php:error` tag. The file is rotated at 1 MB; the
previous file is kept as `nativeblade.log.1`.

## Reading the log

```bash
php artisan nativeblade:logs                       # desktop app on this machine
php artisan nativeblade:logs --platform=android    # phone or emulator over adb
php artisan nativeblade:logs --lines=500
php artisan nativeblade:logs --path                # only print the location
```

The file is written through the fs plugin, which needs `$APPLOG` in the
`fs:scope` of `src-tauri/capabilities/default.json`. New apps have it; an app
created earlier gets it the next time `nativeblade:config` runs (`nativeblade:dev`
and `nativeblade:build` run it). When the write is refused the WebView console
says so once and the other destinations keep working.

A `NativeBlade::log()` placed before an HTTP call, a query or a filesystem
operation appears once, even though PHP is re-run after each of those calls:
only the run that completes the request reports its log lines.

Desktop: the file lives in the OS log directory for the app identifier
(`~/Library/Logs/<identifier>/` on macOS, `%LOCALAPPDATA%\<identifier>\logs\`
on Windows, `~/.local/share/<identifier>/logs/` on Linux).

Android: read through `adb shell run-as`, which only works for debuggable
builds, so the dev client or a debug APK. A store build keeps its files
private.

iOS: the sandbox has no adb equivalent. Download the app container from Xcode
(Devices and Simulators) and open `Library/Logs/nativeblade.log`. During
development the dev server terminal already shows every entry.

## Development mode

While the app is served by `nativeblade:dev` (on the desktop or through the dev
client on a phone), errors also appear on screen in a red banner at the top of
the app, with a Dismiss button. A store build never shows the banner; the same
entries still go to the log file. `NativeBlade::isDev()` tells PHP which mode
it is in. `APP_DEBUG` is not a substitute: the runtime forces it on inside the
app so exceptions always render.

The banner shows every `NativeBlade::log(..., 'error')` call, every PHP warning
or fatal, and the problems the shell detects on its own:

| Problem | What it means |
|---|---|
| Replay diverged: HTTP call / query / filesystem operation #N changed between runs | PHP is re-run after each native call and must make the same calls in the same order. Something before call N is not deterministic: a random value, the clock, state changed before the call. The message shows what the call was before and what it became. |
| Bridge budget exhausted | One request made more sequential HTTP calls, queries or filesystem operations than the runtime allows and was abandoned. Batch calls with `NativeBlade::pool()`, eager-load relations, or split the work. |
| Unknown action dropped | The app pushed a native action this runtime does not know. The runtime is older than the app: restart `nativeblade:dev`, rebuild the app or update the Portal. |
| Locale has no translations | The active locale has no `lang/<locale>` directory or `lang/<locale>.json` file, so strings fall back to the fallback locale. Add the translations or change the locale. |
