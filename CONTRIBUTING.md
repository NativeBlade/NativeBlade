# Contributing

NativeBlade is a community-driven, MIT-licensed project, and there are many ways
to help: docs, examples, bug reports, testing on real devices, and code across
the PHP, JS, Rust, and native layers.

## Branches

NativeBlade ships as SDK versions, one branch each: `37.x` for SDK 37, `38.x`
for the next, and so on. `main` always mirrors the latest SDK branch. Base your
work on the SDK branch you are targeting (usually the latest), not on `main`.

## Getting started

1. Fork and clone the repository, then check out the SDK branch you are
   targeting (usually the latest, for example `37.x`):
   ```bash
   git clone git@github.com:YOUR_USERNAME/NativeBlade.git
   cd NativeBlade
   git checkout 37.x
   ```
2. Create a test Laravel project and point it at your local copy:
   ```json
   "repositories": [
       { "type": "path", "url": "../NativeBlade" }
   ]
   ```
   ```bash
   composer require nativeblade/nativeblade:@dev
   php artisan nativeblade:install
   ```
3. Make your change, test it against that real app, and open a PR.

## Project structure

```
NativeBlade/
├── src/            PHP: Config, NativeResponse, Commands, Facades, Plugins, Mcp
├── js/
│   ├── wasm-app/   Shell runtime (router, bridge, interceptor, shell components)
│   ├── runtime/    php-wasm engine (request handler, http/db/fs bridges, boot)
│   └── scripts/    Build scripts (bundle-laravel, watchers)
├── rust/
│   ├── src/        Core Tauri crate (windows, menu, tray, commands)
│   └── plugins/    One Tauri plugin per native feature, each with an
│                   android/ (Kotlin) and ios/ (Swift) implementation
├── stubs/          Templates used by nativeblade:install
└── docs/           The documentation site (docmd). See docs/README.md.
```

## Where you can help

- **Documentation.** The docs live in `docs/` (a docmd site). See
  [docs/README.md](docs/README.md) for how to run it locally and the writing
  conventions. This is the best first contribution.
- **PHP layer (`src/`).** Commands, config builders, the facade, the MCP tools.
- **JS runtime (`js/`).** The bridges, the router, shell components. Picked up by
  Vite hot reload during `nativeblade:dev`.
- **Native plugins (`rust/plugins/`).** The hard part, since each feature is Rust
  plus Kotlin plus Swift. See below.
- **Examples, bug reports, and device testing.** Reproducing an issue on a real
  device and reporting cleanly is real, valued work.

## Adding a native plugin

The plugin system is declarative, so most of the wiring is generated:

1. Add a case to the `Plugin` enum in `src/Config/Plugin.php`.
2. Describe it in `src/Config/PluginRegistry.php`: the Cargo feature, crate,
   `rust_init`, capabilities, npm packages, and permissions.
3. Implement the plugin under `rust/plugins/<name>/`, with `android/` (Kotlin)
   and `ios/` (Swift). Mirror an existing one such as `network` for the layout,
   the `register_android_plugin` / `register_ios_plugin` wiring, and the
   `Package.swift`.

`php artisan nativeblade:config` then rewrites the app's `Cargo.toml`,
capabilities, `AndroidManifest`, `Info.plist`, and `package.json` from your
descriptor. Nothing is edited by hand.

## Running the tests

Three suites run on every push to `main`:

```bash
composer test                 # PHP (PHPUnit via Testbench, Laravel 12/13, PHP 8.3-8.5)
npm test                      # JS runtime (node:test, no browser)
cd rust && cargo test --lib   # Rust command handlers
```

Run everything at once:

```bash
composer test && npm test && (cd rust && cargo test --lib)
```

## Submitting a pull request

1. Branch from the SDK branch you are targeting, not `main`:
   `git checkout 37.x && git checkout -b feature/my-change`.
2. Keep commits focused and descriptive.
3. Test your change against a real Laravel + Livewire app, and run the suites
   above so nothing existing breaks.
4. Open the PR against that same SDK branch (for example `37.x`), not `main`.

**Native changes need device proof.** Automated tests do not cover the Kotlin or
Swift on a real screen. If your PR touches native code, describe what you tested
and on which platform and device. Maintainers validate native changes against
real apps before merging, so that context speeds up your review.

## License

By contributing, you agree that your contributions are licensed under the MIT
license.
