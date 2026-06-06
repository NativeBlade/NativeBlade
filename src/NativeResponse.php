<?php

namespace NativeBlade;

use Closure;
use Illuminate\Http\JsonResponse;
use Livewire\Livewire;
use NativeBlade\Plugins\Biometric;
use NativeBlade\Plugins\Camera;
use NativeBlade\Plugins\Clipboard;
use NativeBlade\Plugins\Dialog;
use NativeBlade\Plugins\FilePicker;
use NativeBlade\Plugins\Geolocation;
use NativeBlade\Plugins\Media;
use NativeBlade\Plugins\Nfc;
use NativeBlade\Plugins\Notification;
use NativeBlade\Plugins\Scan;
use NativeBlade\Plugins\Shell;
use NativeBlade\Plugins\Upload;

/**
 * Fluent builder for native actions.
 *
 * Every method pushes an action onto an internal queue. When the response
 * is returned from a Livewire component or a controller, all queued actions
 * are dispatched to the JavaScript bridge in order, which then invokes the
 * corresponding Tauri plugin on the native side.
 *
 * Rich actions (dialogs, notifications, camera, biometric, scan, etc.) are
 * configured through dedicated builder closures from `NativeBlade\Plugins\*`.
 * Simple actions (navigate, haptics, openUrl, exit) take their parameters
 * directly to keep trivial usages short.
 *
 * @see \NativeBlade\Facades\NativeBlade
 */
class NativeResponse
{
    /**
     * Queue of native actions to dispatch when this response is rendered.
     *
     * @var array<int, array{action: string, data: array<string, mixed>}>
     */
    private array $actions = [];

    // ------------------------------------------------------------------
    // Dialogs
    // ------------------------------------------------------------------

    /**
     * Queue a native alert dialog built via a fluent `Dialog` builder.
     *
     * Shows a modal with a single OK button. The dialog is fire-and-forget
     * unless you set `->id()`, in which case the OK tap is reported via
     * `nb:confirm-result` like `confirm()`.
     *
     * @param  Closure(Dialog): void  $callback
     */
    public function alert(Closure $callback): static
    {
        $dialog = new Dialog();
        $callback($dialog);
        return $this->push('alert', $dialog->toArray());
    }

    /**
     * Queue a native confirmation dialog built via a fluent `Dialog` builder.
     *
     * Shows both OK and Cancel buttons. The user's choice is delivered via
     * the `nb:confirm-result` Livewire event with a boolean `$confirmed`
     * argument and an optional `$id` when `->id()` was set on the builder.
     *
     * @param  Closure(Dialog): void  $callback
     */
    public function confirm(Closure $callback): static
    {
        $dialog = new Dialog();
        $callback($dialog);
        return $this->push('confirm', $dialog->toArray());
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    /**
     * Queue a system notification built via a fluent `Notification` builder.
     *
     * Fire-and-forget — no result is returned to PHP.
     *
     * @param  Closure(Notification): void  $callback
     */
    public function notification(Closure $callback): static
    {
        $notification = new Notification();
        $callback($notification);
        return $this->push('notification', $notification->toArray());
    }

    /**
     * Cancel a previously scheduled or active notification by its id.
     *
     * The id is the one passed to `Notification::id($id)` when the
     * notification was created. Cancelling an id that doesn't exist is
     * a no-op.
     */
    public function cancelNotification(string $id): static
    {
        return $this->push('cancel_notification', ['id' => $id]);
    }

    /**
     * Cancel every pending and active notification posted by this app.
     */
    public function cancelAllNotifications(): static
    {
        return $this->push('cancel_all_notifications', []);
    }

    // ------------------------------------------------------------------
    // Clipboard
    // ------------------------------------------------------------------

    /**
     * Write text to the system clipboard.
     *
     * @param  string  $text  Content to place on the clipboard.
     */
    public function clipboardWrite(string $text): static
    {
        return $this->push('clipboard_write', ['text' => $text]);
    }

    /**
     * Read the current content of the system clipboard.
     *
     * The result is delivered via the `nb:clipboard` Livewire event with
     * a `$text` argument. Pass a closure and call `->id()` when the
     * component has multiple clipboard reads to distinguish.
     *
     * @param  ?Closure(Clipboard): void  $callback  Optional builder callback.
     */
    public function clipboardRead(?Closure $callback = null): static
    {
        $clipboard = new Clipboard();
        if ($callback) $callback($clipboard);
        return $this->push('clipboard_read', $clipboard->toArray());
    }

    // ------------------------------------------------------------------
    // Geolocation
    // ------------------------------------------------------------------

    /**
     * Request the device's current geographic position.
     *
     * Automatically requests location permission on first use. The result
     * is delivered via the `nb:geolocation` Livewire event.
     *
     * @param  ?Closure(Geolocation): void  $callback  Optional builder callback.
     */
    public function geolocation(?Closure $callback = null): static
    {
        $geolocation = new Geolocation();
        if ($callback) $callback($geolocation);
        return $this->push('geolocation', $geolocation->toArray());
    }

    // ------------------------------------------------------------------
    // Haptics
    // ------------------------------------------------------------------

    /**
     * Trigger a simple vibration (mobile only).
     *
     * For button-press feedback, prefer the `nb-feedback` Blade attribute
     * which fires instantly without a server round-trip.
     *
     * @param  int  $duration  Vibration length in milliseconds.
     */
    public function vibrate(int $duration = 100): static
    {
        return $this->push('vibrate', ['duration' => $duration]);
    }

    /**
     * Trigger a haptic impact feedback (mobile only).
     *
     * @param  string  $style  One of `'light'`, `'medium'`, `'heavy'`.
     */
    public function impact(string $style = 'medium'): static
    {
        return $this->push('impact', ['style' => $style]);
    }

    /**
     * Trigger a haptic selection feedback (mobile only).
     */
    public function selection(): static
    {
        return $this->push('selection', []);
    }

    // ------------------------------------------------------------------
    // Biometric
    // ------------------------------------------------------------------

    /**
     * Prompt the user for biometric authentication (mobile only).
     *
     * The result is delivered via the `nb:biometric` Livewire event with
     * `$success` (bool) and optional `$error` (string) arguments.
     *
     * @param  Closure(Biometric): void  $callback
     */
    public function biometric(Closure $callback): static
    {
        $biometric = new Biometric();
        $callback($biometric);
        return $this->push('biometric', $biometric->toArray());
    }

    // ------------------------------------------------------------------
    // Barcode scanner
    // ------------------------------------------------------------------

    /**
     * Open the camera to scan a barcode or QR code (mobile only).
     *
     * The result is delivered via the `nb:scan` Livewire event.
     *
     * @param  ?Closure(Scan): void  $callback  Optional builder callback.
     */
    public function scan(?Closure $callback = null): static
    {
        $scan = new Scan();
        if ($callback) $callback($scan);
        return $this->push('scan', $scan->toArray());
    }

    // ------------------------------------------------------------------
    // NFC
    // ------------------------------------------------------------------

    /**
     * Wait for the user to tap an NFC tag and read its contents (mobile only).
     *
     * The result is delivered via the `nb:nfc` Livewire event.
     *
     * @param  ?Closure(Nfc): void  $callback  Optional builder callback.
     */
    public function nfcRead(?Closure $callback = null): static
    {
        $nfc = new Nfc();
        if ($callback) $callback($nfc);
        return $this->push('nfc_read', $nfc->toArray());
    }

    // ------------------------------------------------------------------
    // Opener
    // ------------------------------------------------------------------

    /**
     * Open a URL using the system's default web browser.
     */
    public function openUrl(string $url): static
    {
        return $this->push('open_url', ['url' => $url]);
    }

    /**
     * Open a file using the OS's default application for its type.
     */
    public function openFile(string $path): static
    {
        return $this->push('open_file', ['path' => $path]);
    }

    // ------------------------------------------------------------------
    // In-app review
    // ------------------------------------------------------------------

    /**
     * Ask the OS to show its native in-app review prompt (StoreKit on iOS,
     * Play In-App Review on Android). Mobile only; a no-op on desktop.
     * Requires `Plugin::IN_APP_REVIEW`.
     *
     * The OS decides whether to actually display the prompt (it is heavily
     * rate-limited and may show nothing), and you get no result back, so do
     * not tie any reward to it. For a "rate us" link on desktop, call
     * `openUrl()` with your store listing yourself.
     */
    public function requestReview(): static
    {
        return $this->push('request_review', []);
    }

    // ------------------------------------------------------------------
    // Secure storage
    // ------------------------------------------------------------------

    /**
     * Store a secret in the OS keystore (Keychain on iOS, Tink AEAD sealed by
     * the Android Keystore). Mobile only; a no-op on desktop.
     * Requires `Plugin::SECURE_STORAGE`.
     *
     * For small secrets (tokens, keys), not large blobs. For structured data,
     * `json_encode()` it yourself and `json_decode()` what `getSecure()` gives
     * back.
     */
    public function setSecure(string $key, string $value): static
    {
        return $this->push('set_secure', ['key' => $key, 'value' => $value]);
    }

    /**
     * Read a secret back. The value is delivered asynchronously via the
     * `nb:secure` Livewire event: `#[On('nb:secure')] onSecure($value, $id)`.
     * `$value` is `null` when the key is absent (or on desktop). Pass `$id`
     * to route the result when a component reads more than one key.
     */
    public function getSecure(string $key, ?string $id = null): static
    {
        return $this->push('get_secure', ['key' => $key, 'id' => $id]);
    }

    /**
     * Remove a secret from the OS keystore. Mobile only; a no-op on desktop.
     */
    public function forgetSecure(string $key): static
    {
        return $this->push('forget_secure', ['key' => $key]);
    }

    // ------------------------------------------------------------------
    // Sharing
    // ------------------------------------------------------------------

    /**
     * Open the native share sheet (UIActivityViewController on iOS,
     * Intent.ACTION_SEND on Android) to share text and/or a URL with other
     * apps. Mobile only; a no-op on desktop. Requires `Plugin::SHARING`.
     *
     * Pass at least one of `$text` / `$url`. File sharing is not in v1.
     */
    public function share(?string $text = null, ?string $url = null): static
    {
        return $this->push('share', ['text' => $text ?? '', 'url' => $url ?? '']);
    }

    // ------------------------------------------------------------------
    // Analytics
    // ------------------------------------------------------------------

    /**
     * Log Firebase Analytics operations (events, screens, user id/properties,
     * consent on/off) via a closure builder. The ops are applied natively in
     * order. Mobile only; a no-op on desktop. Requires `Plugin::ANALYTICS`
     * and `NativeBladeConfig::firebase(...)`.
     *
     * @param  \Closure(\NativeBlade\Plugins\Analytics): void  $callback
     */
    public function analytics(\Closure $callback): static
    {
        $analytics = new \NativeBlade\Plugins\Analytics();
        $callback($analytics);
        return $this->push('analytics', $analytics->toArray());
    }

    // ------------------------------------------------------------------
    // OS info
    // ------------------------------------------------------------------

    /**
     * Request information about the host operating system.
     *
     * The result is delivered via the `nb:os-info` Livewire event. For
     * simple platform checks, prefer `NativeBlade::isMobile()` and friends.
     */
    public function osInfo(): static
    {
        return $this->push('os_info', []);
    }

    // ------------------------------------------------------------------
    // Camera & gallery
    // ------------------------------------------------------------------

    /**
     * Open the device camera to capture a photo.
     *
     * Automatically requests camera permission on first use. The result is
     * delivered via the `nb:camera-result` Livewire event with a `$data`
     * parameter containing the image as a base64 data URL.
     *
     * @param  ?Closure(Camera): void  $callback  Optional builder callback.
     */
    public function camera(?Closure $callback = null): static
    {
        $camera = new Camera();
        if ($callback) $callback($camera);
        return $this->push('camera', $camera->toArray());
    }

    /**
     * Open the device photo library to pick an existing image.
     *
     * Shares the same `Camera` builder and the same `nb:camera-result`
     * event as `camera()`. Use `->id()` if a component has both a camera
     * and a gallery pointing to different targets.
     *
     * @param  ?Closure(Camera): void  $callback  Optional builder callback.
     */
    public function gallery(?Closure $callback = null): static
    {
        $camera = new Camera();
        if ($callback) $callback($camera);
        return $this->push('gallery', $camera->toArray());
    }

    // ------------------------------------------------------------------
    // Media (nativeblade-media plugin): native camera/gallery/video with
    // on-device resize. Preferred over camera()/gallery() on mobile because
    // the work is done natively instead of via a JS canvas.
    // ------------------------------------------------------------------

    /**
     * Open the native camera via the `nativeblade-media` plugin.
     *
     * Produces a resized JPEG on the native side, avoiding the WebView
     * memory pressure of JS-canvas resizing. Result arrives on the
     * `nb:media-result` Livewire event with `$items`, `$source` (= `'camera'`),
     * and optional `$id`.
     *
     * @param  ?Closure(Media): void  $callback  Optional builder callback.
     */
    public function pickCamera(?Closure $callback = null): static
    {
        $media = new Media();
        if ($callback) $callback($media);
        return $this->push('pick_camera', $media->toArray());
    }

    /**
     * Open the native photo picker to choose one or more existing images.
     *
     * Permission-free on Android 13+ and iOS 14+. Result arrives on
     * `nb:media-result` with `$source` = `'gallery'`.
     *
     * @param  ?Closure(Media): void  $callback  Optional builder callback.
     */
    public function pickGallery(?Closure $callback = null): static
    {
        $media = new Media();
        if ($callback) $callback($media);
        return $this->push('pick_gallery', $media->toArray());
    }

    /**
     * Open the native video picker to choose one or more existing videos.
     *
     * Result arrives on `nb:media-result` with `$source` = `'video'`.
     *
     * @param  ?Closure(Media): void  $callback  Optional builder callback.
     */
    public function pickVideo(?Closure $callback = null): static
    {
        $media = new Media();
        if ($callback) $callback($media);
        return $this->push('pick_video', $media->toArray());
    }

    // ------------------------------------------------------------------
    // File picker
    // ------------------------------------------------------------------

    /**
     * Open the OS file picker so the user can choose one or more files.
     *
     * Result arrives on the `nb:file-picker` Livewire event with `$paths`
     * (string[]) and an optional `$id` from the builder.
     *
     * @param  ?Closure(FilePicker): void  $callback  Optional builder callback.
     */
    public function filePicker(?Closure $callback = null): static
    {
        $picker = new FilePicker();
        if ($callback) $callback($picker);
        return $this->push('file_picker', $picker->toArray());
    }

    /**
     * Open the OS "save file" dialog so the user can pick a destination path.
     *
     * Result arrives on the `nb:file-save` Livewire event with `$path`
     * (string) and an optional `$id` from the builder.
     *
     * @param  string  $defaultName  Suggested file name shown in the dialog.
     * @param  ?Closure(FilePicker): void  $callback  Optional builder callback.
     */
    public function fileSave(string $defaultName, ?Closure $callback = null): static
    {
        $picker = new FilePicker();
        $picker->id($defaultName);
        if ($callback) $callback($picker);
        $data = $picker->toArray();
        $data['defaultName'] = $defaultName;
        return $this->push('file_save', $data);
    }

    // ------------------------------------------------------------------
    // File operations
    // ------------------------------------------------------------------

    /**
     * Copy a file from `$from` to `$to` resolved against a `$purpose` root.
     *
     * `$purpose` selects the base directory the relative `$to` path is
     * resolved against. One of: `'app'` (app data), `'export'` (Documents),
     * `'downloads'`, `'cache'`, `'temp'`. Default `'app'`.
     */
    public function copyFile(string $from, string $to, string $purpose = 'app'): static
    {
        return $this->push('copy_file', ['from' => $from, 'to' => $to, 'purpose' => $purpose]);
    }

    /**
     * Move a file from `$from` to `$to`. See `copyFile()` for `$purpose` values.
     */
    public function moveFile(string $from, string $to, string $purpose = 'app'): static
    {
        return $this->push('move_file', ['from' => $from, 'to' => $to, 'purpose' => $purpose]);
    }

    // ------------------------------------------------------------------
    // Upload
    // ------------------------------------------------------------------

    /**
     * Upload a local file to a remote URL with optional headers and progress.
     *
     * Progress is reported via the `nb:upload-progress` Livewire event.
     * Completion is reported via `nb:upload-result` with `$status` (int)
     * and `$body` (string).
     *
     * @param  string  $path  Absolute path to the file on the device.
     * @param  string  $url  Destination URL (full URL with scheme).
     * @param  ?Closure(Upload): void  $callback  Optional builder callback for headers / id.
     */
    public function upload(string $path, string $url, ?Closure $callback = null): static
    {
        $upload = new Upload();
        $upload->url($url);
        if ($callback) $callback($upload);
        $data = $upload->toArray();
        $data['path'] = $path;
        return $this->push('upload', $data);
    }

    // ------------------------------------------------------------------
    // Navigation
    // ------------------------------------------------------------------

    /**
     * Navigate to an internal app route without reloading the PHP runtime.
     *
     * @param  string  $path     Absolute app path (e.g. `/dashboard`).
     * @param  bool    $replace  If true, replaces the current history entry.
     */
    public function navigate(string $path, bool $replace = false): static
    {
        return $this->push('navigate', [
            'path' => $path,
            'replace' => $replace,
        ]);
    }

    // ------------------------------------------------------------------
    // Custom Tauri plugin invocation
    // ------------------------------------------------------------------

    /**
     * Invoke any Tauri plugin command directly. Use this when you've added
     * a third-party Tauri plugin (or your own) and want to call it from PHP
     * without writing a JS action handler.
     *
     * The result is delivered as a Livewire event. You choose the event
     * name via the `emit` argument; it always gets the `nb:` prefix on
     * the listener side.
     *
     * ```php
     * NativeBlade::tauriInvoke(
     *     command: 'plugin:fingerprint|authenticate',
     *     args: ['reason' => 'Authenticate to continue'],
     *     emit: 'fingerprint-result',
     * )->toResponse();
     *
     * // Then in your Livewire component:
     * #[On('nb:fingerprint-result')]
     * public function onAuth($result = null, $error = null) { ... }
     * ```
     *
     * @param  string  $command  Tauri command name (e.g. `'plugin:foo|bar'` or a custom Rust command).
     * @param  array<string,mixed>  $args  Arguments passed to the command.
     * @param  string|null  $emit  Event name (without `nb:` prefix) for the result. When null, no event is dispatched.
     */
    public function tauriInvoke(string $command, array $args = [], ?string $emit = null): static
    {
        return $this->push('tauri_invoke', [
            'command' => $command,
            'args' => $args,
            'emit' => $emit,
        ]);
    }

    // ------------------------------------------------------------------
    // Modal
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Bundle updates (OTA)
    // ------------------------------------------------------------------

    /**
     * Probe the OTA manifest for a newer Laravel bundle without downloading.
     *
     * The result is delivered via the `nb:update-check` Livewire event with
     * arguments: `$available` (bool), `$currentVersion` (?string),
     * `$nextVersion` (?string), `$reason` (?string), `$error` (?string).
     * `$reason` is `'not-configured'`, `'fetch-failed'`, `'invalid-manifest'`,
     * `'up-to-date'`, `'shell-too-old'`, or absent when an update is available.
     */
    public function checkUpdate(): static
    {
        return $this->push('check_update', []);
    }

    /**
     * Force-download the latest Laravel bundle right now and persist it.
     * Applies on the next app launch — does NOT swap the running bundle.
     *
     * The result is delivered via the `nb:update-applied` Livewire event with
     * arguments: `$applied` (bool), `$version` (?string), `$reason` (?string),
     * `$error` (?string).
     */
    public function forceUpdate(): static
    {
        return $this->push('force_update', []);
    }

    /**
     * Show the shell-level modal component (`<x-nativeblade-modal>`).
     */
    public function showModal(): static
    {
        return $this->push('showModal', []);
    }

    /**
     * Hide the shell-level modal component.
     */
    public function hideModal(): static
    {
        return $this->push('hideModal', []);
    }

    // ------------------------------------------------------------------
    // Shell
    // ------------------------------------------------------------------

    /**
     * Execute a shell command on the host (desktop only).
     *
     * Runs the command in the platform shell (`cmd /C` on Windows,
     * `/bin/sh -c` on Unix) and captures its output. The result is
     * delivered via the `nb:shell-result` Livewire event with `$stdout`,
     * `$stderr`, `$exitCode` and `$id` arguments.
     *
     * When the builder calls `->openTerminal()`, the command is spawned
     * inside a visible OS terminal window instead — that path is
     * fire-and-forget and no result event is emitted.
     *
     * Mobile platforms emit a failure result with `exitCode = -1` and
     * a stderr of `"not supported on this platform"` so listeners can
     * handle both paths with the same code.
     *
     * @param  Closure(Shell): void  $callback
     */
    public function shell(Closure $callback): static
    {
        $shell = new Shell();
        $callback($shell);
        return $this->push('shell', $shell->toArray());
    }

    // ------------------------------------------------------------------
    // Process
    // ------------------------------------------------------------------

    /**
     * Quit the application cleanly.
     */
    public function exit(): static
    {
        return $this->push('exit', []);
    }

    /**
     * Minimize the main window to the taskbar / dock. Desktop only.
     */
    public function minimize(): static
    {
        return $this->push('minimize', []);
    }

    /**
     * Maximize the main window to fill the screen. Desktop only.
     */
    public function maximize(): static
    {
        return $this->push('maximize', []);
    }

    /**
     * Restore the window from maximized state. Desktop only.
     */
    public function unmaximize(): static
    {
        return $this->push('unmaximize', []);
    }

    /**
     * Toggle between maximized and restored window state. Desktop only.
     */
    public function toggleMaximize(): static
    {
        return $this->push('toggle_maximize', []);
    }

    /**
     * Hide the main window without quitting the app. Useful for the
     * "minimize to tray" pattern when paired with `Tray::hideOnClose()`.
     * Desktop only.
     */
    public function hide(): static
    {
        return $this->push('hide', []);
    }

    /**
     * Show the main window after it was hidden. Desktop only.
     */
    public function show(): static
    {
        return $this->push('show', []);
    }

    // ------------------------------------------------------------------
    // Modifiers — attach extra data to the last pushed action
    // ------------------------------------------------------------------

    /**
     * Set the animation used for a navigate action.
     *
     * @param  string  $type  One of: `'none'`, `'slide'`, `'fade'`.
     * @throws \InvalidArgumentException If `$type` is not one of the supported transitions.
     */
    public function transition(string $type): static
    {
        if (!in_array($type, ['none', 'slide', 'fade'], true)) {
            throw new \InvalidArgumentException(
                "Invalid transition '{$type}'. Use one of: none, slide, fade."
            );
        }
        return $this->modify('transition', $type);
    }

    /**
     * Mark a navigate action as a history-replace instead of push.
     */
    public function replace(bool $replace = true): static
    {
        return $this->modify('replace', $replace);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function push(string $action, array $data): static
    {
        $this->actions[] = ['action' => $action, 'data' => $data];
        return $this;
    }

    private function modify(string $key, mixed $value): static
    {
        if (!empty($this->actions)) {
            $last = &$this->actions[count($this->actions) - 1];
            $last['data'][$key] = $value;
        }
        return $this;
    }

    /**
     * @return array<int, array{action: string, data: array<string, mixed>}>
     */
    public function toArray(): array
    {
        return $this->actions;
    }

    public function toResponse(): ?JsonResponse
    {
        $isLivewireUpdate = request()->is('livewire/update') || request()->header('X-Livewire');

        if ($isLivewireUpdate) {
            $component = Livewire::current();
            if ($component) {
                $component->dispatch('__nativeblade', actions: $this->actions);
                return null;
            }
        }

        return response()->json([
            'nativeblade' => true,
            'actions' => $this->actions,
        ]);
    }
}
