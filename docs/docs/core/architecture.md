---
title: "Architecture"
description: "How the PHP runtime, the native shell, and your Livewire app fit together."
---

# Architecture

NativeBlade runs a normal Laravel and Livewire app, but the request cycle happens
on the device instead of a remote server. Understanding the three layers makes
the rest of the docs obvious.

## The three layers

::: card "Shell document"
The native window (Tauri) loads a small host page. This shell owns the PHP
runtime (php-wasm) and has access to native APIs through Tauri. It never shows
your app markup directly.
:::

::: card "App iframe"
Your Laravel app renders inside an isolated iframe. It has no direct access to
Tauri or the runtime. It talks to the shell by posting messages, which the shell
turns into PHP requests and answers.
:::

::: card "PHP runtime"
php-wasm executes your Laravel code on the device. Each request runs
index.php from scratch, exactly like PHP on a server, so Livewire behaves
normally: state lives in the DOM snapshot, and the runtime hydrates whatever
snapshot arrives.
:::

## The request cycle

1. The user interacts with a Livewire component in the iframe.
2. Livewire posts an update request. The shell intercepts it.
3. The shell runs the request through php-wasm and returns the response.
4. Livewire morphs the DOM in place. It never knows the request stayed on device.

## The native bridge

Some calls need real native work: `Http::`, database queries, and `Storage`.
When PHP reaches one of these, the request pauses, the shell performs the native
operation, and PHP re-runs with the result available. This is transparent to your
code. You write `Http::get(...)` and `Model::all()` as usual.

## Where things live

- Shell components (header, bottom nav, drawer) render outside the iframe, so they
  never flicker during navigation.
- Your own front-end scripts live in `public/js` as classic scripts.
- Bundled modules and native shell modules live in `nativeblade-components/`.
