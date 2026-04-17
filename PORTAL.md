# NativeBlade Portal

The Portal is a development flow that lets you iterate on a NativeBlade app
without installing Rust, Android SDK, or Xcode. A single pre-compiled host app
(the "Portal") is installed once on the device, and your Laravel bundle is
served over HTTP from your dev machine. The Portal downloads the bundle,
mounts it inside PHP-WASM, and renders your app — same runtime, same native
bridges, same everything as a full build, except you never package a binary.

## How it works

```
 ┌───────────────────────────┐          ┌──────────────────────────────┐
 │ Dev machine               │          │ Device (phone / desktop)     │
 │ php artisan nativeblade:  │          │ NativeBlade Portal app       │
 │   dev --platform=portal   │          │ (pre-installed: PHP-WASM,    │
 │                           │ ◀──HTTP──│  Livewire runtime, bridges)  │
 │  ├─ bundle-laravel.js     │          │                              │
 │  │  generates             │          │  Paste URL / Scan QR ───────▶│
 │  │  public/               │          │                              │
 │  │    laravel-bundle.json │          │  fetch(base + 'laravel-...')│
 │  │    laravel-bundle.json.gz         │  polls __php_changes         │
 │  │                        │          │                              │
 │  └─ vite dev server       │          │                              │
 │     serves publicDir +    │          │                              │
 │     __php_version /       │          │                              │
 │     __php_changes         │          │                              │
 └───────────────────────────┘          └──────────────────────────────┘
```

Everything that runs on the device runs in WASM, same as any other NativeBlade
build. The Portal app just resolves the bundle from a remote URL instead of
from its own binary. Native bridges (fs, db, http, notifications, camera, etc.)
work exactly the same, because PHP is still running on the device.

## Running the dev server

In your Laravel project root:

```bash
php artisan nativeblade:dev --platform=portal
```

The command will:

1. Run `bundle-laravel.js` once, producing `public/laravel-bundle.json` and
   `.gz` from your source.
2. Print a QR code and a URL like `http://192.168.1.42:1420`.
3. Start Vite in the foreground, serving the bundle, the translations, and
   the `/__php_version` + `/__php_changes` polling endpoints.

Open the Portal app, paste the URL (or scan the QR), and your app loads.

Stop the dev server with Ctrl+C like any other process.

### Options

- `--host=<ip>` — override the auto-detected LAN IP. Useful if the detector
  picks a virtual adapter.
- `--port=<n>` — override the default 1420.

### Hot reload

The Vite plugin watches `app/`, `resources/views/`, `routes/`, `config/`, and
`lang/`. On save, the change is enqueued. The Portal app polls
`/__php_changes?since=N` and applies changes without a full rebuild.

If you change `composer.json`, Blade directives, or anything outside the
watched dirs, stop and restart the dev server to re-bundle.

Reloading manually: F5 on desktop, close and reopen on mobile.

## Connecting from the Portal app

Until the Portal UI ships, you can still test the runtime wiring by setting
the bundle base manually in the Portal app's DevTools (desktop) or via a
`localStorage` seed during development:

```js
localStorage.setItem('nb:bundleBase', 'http://192.168.1.42:1420');
location.reload();
```

On next boot, `filesystem.js::getBundleBase()` picks this up, and
`fetch(base + 'laravel-bundle.json.gz')` pulls the remote bundle. The
`hot-reload.js::resolveServerUrl()` reads the same source, so polling for
changes goes to the same origin automatically.

To go back to the in-app bundle:

```js
localStorage.removeItem('nb:bundleBase');
location.reload();
```

The Portal UI (Livewire app shipped inside the Portal binary) will eventually
expose "Paste URL" / "Scan QR" / "Recently opened" screens that set and clear
this key, so the user never touches DevTools.

## Smoke test checklist

After making changes to the Portal code path, validate with curl from another
machine on the same LAN (or from localhost for a quick sanity check):

1. **Dev server is reachable**
   ```bash
   curl -I http://<IP>:1420/
   ```
   Expect HTTP 200 and `Access-Control-Allow-Origin: *`.

2. **Bundle is served**
   ```bash
   curl -o /tmp/bundle.json.gz http://<IP>:1420/laravel-bundle.json.gz
   file /tmp/bundle.json.gz  # → gzip compressed data
   ```

3. **Translation files are served**
   ```bash
   curl -I http://<IP>:1420/lang/en.json
   ```
   Expect HTTP 200.

4. **Polling endpoints respond**
   ```bash
   curl http://<IP>:1420/__php_version
   # → {"version":N}
   curl "http://<IP>:1420/__php_changes?since=0"
   # → {"version":N,"changes":[...]}
   ```

5. **Change detection works**
   - Touch a file: `touch app/Livewire/Counter.php`
   - Re-query `/__php_changes?since=0` and confirm it returns the change with
     its `wasmPath` and `content`.

6. **CORS preflight**
   ```bash
   curl -X OPTIONS -H 'Origin: tauri://localhost' \
     -H 'Access-Control-Request-Method: GET' \
     http://<IP>:1420/laravel-bundle.json
   ```
   Expect HTTP 204 with `Access-Control-Allow-Origin: *`.

If any step fails, check that `NATIVEBLADE_HOST` is set to the LAN IP, that
your firewall allows inbound on port 1420, and that the device is on the same
network (no guest VLAN isolation).

## What's still open

- **Portal app UI**: the Livewire-based "Connect" / "Recents" / "Scan QR"
  screens that set `localStorage['nb:bundleBase']`. Today the wiring is
  reachable only by manual DevTools seeding. Tracked separately.
- **Signed distributable Portal binaries** for Play / App Store / Microsoft
  Store. This is what makes the Portal actually installable by end users.
- **Tunnel mode** (e.g. via cloudflared) for when the device isn't on the
  same Wi-Fi as the dev machine. Nice-to-have.
- **Deep linking** with a `nativeblade://` URL scheme so the QR opens the
  Portal app directly instead of requiring a paste step.

## Limits of Portal mode

- Requires PHP + Composer on the dev machine (the bundler runs
  `composer install` once). No Rust, Android SDK, or Xcode needed — that's
  the whole point.
- The dev machine and the device must be on the same LAN (no tunnel yet).
- A bundle produced by a newer NativeBlade than what's in the Portal binary
  may reference runtime APIs the Portal doesn't expose. In practice, keep
  the dev package and the Portal app on close versions.
