---
title: "Getting Started"
description: "Set up and run your NativeBlade app on desktop."
---

# Getting Started on Desktop

## Create and install

```bash
# Create a Laravel project
composer create-project "laravel/laravel:^13.0" my-app
cd my-app

# Install NativeBlade
composer require nativeblade/nativeblade
php artisan nativeblade:install
```

## Build and run

```bash
npm run build
php artisan nativeblade:dev
```

This launches the desktop app on Windows, macOS, or Linux. The first run
compiles the Rust binary and takes a few minutes; later runs are fast.

## Requirements

- PHP 8.3+, Laravel 12+, Livewire 4
- Node.js 20+
- [Rust](https://www.rust-lang.org/tools/install)

See [Compatibility](/guides/compatibility/) for the versions each SDK targets.
