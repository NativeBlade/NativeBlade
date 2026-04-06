# Contributing

We welcome contributions! NativeBlade is a community-driven project and there are many ways to help.

## Getting Started

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone git@github.com:YOUR_USERNAME/NativeBlade.git
   cd NativeBlade
   ```
3. Create a test Laravel project and add the package locally via `composer.json`:
   ```json
   "repositories": [
       { "type": "path", "url": "../NativeBlade" }
   ]
   ```
   ```bash
   composer require nativeblade/nativeblade:@dev
   ```
4. Make your changes, test them, and submit a PR.

## Project Structure

```
NativeBlade/
├── src/            ← PHP (ShellConfig, NativeResponse, Commands, Facades)
├── js/
│   ├── wasm-app/   ← Shell runtime (router, bridge, interceptor, components)
│   ├── runtime/    ← PHP WASM engine (request handler, filesystem, boot)
│   └── scripts/    ← Build scripts (bundle-laravel, mobile-dev)
├── rust/           ← Tauri crate (bridge, menu, tray, config)
└── stubs/          ← Templates used by nativeblade:install
```

## Areas Where You Can Help

- **New native actions** — Add support for more Tauri plugins (clipboard, updater, global shortcuts)
- **Shell components** — Built-in modal, FAB, or other shell-level UI
- **Mobile improvements** — Better Android/iOS integration, gestures, deep links
- **Performance** — WASM boot time, bundle size optimization, caching strategies
- **Testing** — Unit tests for PHP classes, integration tests for the JS runtime
- **Documentation** — Tutorials, guides, examples, translations
- **Bug fixes** — Check [open issues](https://github.com/NativeBlade/NativeBlade/issues)

## Development Tips

- PHP classes are in `src/` with PSR-4 autoloading under the `NativeBlade\` namespace
- JS changes in `js/wasm-app/` are picked up by Vite hot reload during development
- Rust changes in `rust/` require a `cargo build` (Tauri handles this during `nativeblade:dev`)
- Stubs in `stubs/` use `{{PLACEHOLDER}}` syntax replaced by the install command
- The `NativeBladeServiceProvider` is the entry point — it registers everything

## Submitting a Pull Request

1. Create a feature branch: `git checkout -b feature/my-feature`
2. Keep commits focused and descriptive
3. Test your changes against a real Laravel + Livewire project
4. Make sure existing functionality isn't broken
5. Submit your PR with a clear description of what and why

## Reporting Issues

When reporting bugs, please include:

- PHP, Laravel, and Livewire versions
- Platform (Windows/macOS/Linux/Android/iOS)
- Steps to reproduce
- Expected vs actual behavior
- Console errors if applicable
