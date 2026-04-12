<?php

namespace NativeBlade;

use Illuminate\Http\JsonResponse;
use Livewire\Livewire;

/**
 * Fluent builder for native actions.
 *
 * Every method pushes an action onto an internal queue. When the response is
 * returned from a Livewire component or a controller, all queued actions are
 * dispatched to the JavaScript bridge in order, which then invokes the
 * corresponding Tauri plugin on the native side.
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
     * Show a native alert dialog with a single OK button.
     *
     * Chain `->title()`, `->kind()`, `->confirmLabel()` or `->cancelLabel()`
     * to customize the dialog. The user's choice (if cancel/confirm buttons
     * are used) is delivered via the `nb:confirm-result` Livewire event.
     *
     * @param  string  $message  Body text displayed inside the dialog.
     */
    public function alert(string $message): static
    {
        return $this->push('alert', ['message' => $message]);
    }

    /**
     * Show a native confirmation dialog with OK/Cancel buttons.
     *
     * The user's choice is delivered via the `nb:confirm-result` Livewire
     * event with a boolean `$confirmed` parameter.
     *
     * @param  string  $message  Question or statement shown to the user.
     */
    public function confirm(string $message): static
    {
        return $this->push('confirm', ['message' => $message]);
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    /**
     * Send a system notification.
     *
     * On first use, NativeBlade automatically requests notification
     * permission from the user. If the user denies permission, the
     * notification is silently dropped. Chain `->title()`, `->sound()`,
     * `->icon()` or `->channel()` to customize.
     *
     * @param  string  $body  Main notification text shown to the user.
     */
    public function notification(string $body): static
    {
        return $this->push('notification', [
            'title' => 'NativeBlade',
            'body' => $body,
        ]);
    }

    // ------------------------------------------------------------------
    // Clipboard
    // ------------------------------------------------------------------

    /**
     * Write text to the system clipboard.
     *
     * Works on both desktop and mobile. No permission required.
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
     * The result is delivered via the `nb:clipboard` Livewire event with a
     * `$text` parameter.
     */
    public function clipboardRead(): static
    {
        return $this->push('clipboard_read', []);
    }

    // ------------------------------------------------------------------
    // Geolocation
    // ------------------------------------------------------------------

    /**
     * Request the device's current geographic position.
     *
     * On first use, NativeBlade automatically requests location permission.
     * The result is delivered via the `nb:geolocation` Livewire event with
     * a `$position` array containing `coords.latitude`, `coords.longitude`,
     * `coords.accuracy`, and `timestamp`.
     */
    public function geolocation(): static
    {
        return $this->push('geolocation', []);
    }

    // ------------------------------------------------------------------
    // Haptics
    // ------------------------------------------------------------------

    /**
     * Trigger a simple vibration.
     *
     * Mobile only — no-op on desktop. For user-interaction feedback on
     * buttons, prefer the `nb-feedback` Blade attribute instead of calling
     * this method from PHP, as the attribute fires instantly without a
     * server round-trip.
     *
     * @param  int  $duration  Vibration length in milliseconds.
     */
    public function vibrate(int $duration = 100): static
    {
        return $this->push('vibrate', ['duration' => $duration]);
    }

    /**
     * Trigger a haptic impact feedback.
     *
     * Mobile only. Produces a crisp tap sensation used to reinforce UI
     * events like toggling, confirming, or completing an action.
     *
     * @param  string  $style  One of: `'light'`, `'medium'`, `'heavy'`.
     */
    public function impact(string $style = 'medium'): static
    {
        return $this->push('impact', ['style' => $style]);
    }

    /**
     * Trigger a haptic selection feedback.
     *
     * Mobile only. Produces a subtle tick sensation used when the user
     * changes a selection (e.g. scrolling through a picker).
     */
    public function selection(): static
    {
        return $this->push('selection', []);
    }

    // ------------------------------------------------------------------
    // Biometric
    // ------------------------------------------------------------------

    /**
     * Prompt the user for biometric authentication (fingerprint / Face ID).
     *
     * Mobile only. The result is delivered via the `nb:biometric` Livewire
     * event with `$success` (bool) and an optional `$error` (string) when
     * authentication fails. By default, the device passcode is accepted as
     * a fallback; disable with `->allowDeviceCredential(false)`.
     *
     * @param  string  $reason  Explanation shown to the user in the system prompt.
     */
    public function biometric(string $reason = 'Authenticate'): static
    {
        return $this->push('biometric', [
            'reason' => $reason,
            'allowDeviceCredential' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Barcode scanner
    // ------------------------------------------------------------------

    /**
     * Open the camera to scan a barcode or QR code.
     *
     * Mobile only. On first use, NativeBlade automatically requests camera
     * permission. The result is delivered via the `nb:scan` Livewire event
     * with a `$result` array containing the decoded `content` and `format`.
     *
     * @param  array<int, string>  $formats  Restricts what codes are accepted
     *                                       (e.g. `['QR_CODE', 'EAN_13']`).
     *                                       Empty array accepts all formats.
     */
    public function scan(array $formats = []): static
    {
        return $this->push('scan', ['formats' => $formats]);
    }

    // ------------------------------------------------------------------
    // NFC
    // ------------------------------------------------------------------

    /**
     * Wait for the user to tap an NFC tag and read its contents.
     *
     * Mobile only. The result is delivered via the `nb:nfc` Livewire event
     * with a `$tag` array containing the tag `id` and NDEF `records`.
     */
    public function nfcRead(): static
    {
        return $this->push('nfc_read', []);
    }

    // ------------------------------------------------------------------
    // Opener
    // ------------------------------------------------------------------

    /**
     * Open a URL using the system's default web browser.
     *
     * Works on both desktop and mobile. For external links — use
     * `navigate()` instead for internal app routes.
     *
     * @param  string  $url  Absolute URL (http/https/mailto/tel/etc).
     */
    public function openUrl(string $url): static
    {
        return $this->push('open_url', ['url' => $url]);
    }

    /**
     * Open a file using the OS's default application for its type.
     *
     * Example: opening a PDF launches the system PDF viewer; opening an
     * image launches the default image viewer. Typically used together
     * with `native_path()` to target files written via `Storage::disk('native')`.
     *
     * @param  string  $path  Absolute filesystem path to the file.
     */
    public function openFile(string $path): static
    {
        return $this->push('open_file', ['path' => $path]);
    }

    // ------------------------------------------------------------------
    // OS info
    // ------------------------------------------------------------------

    /**
     * Request information about the host operating system.
     *
     * The result is delivered via the `nb:os-info` Livewire event with an
     * `$info` array containing `platform`, `version`, `arch` and `locale`.
     * For simple platform checks, prefer `NativeBlade::isMobile()` and
     * friends — they are synchronous and don't need a round-trip.
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
     * On first use, NativeBlade automatically requests camera permission.
     * The result is delivered via the `nb:camera-result` Livewire event
     * with a `$data` parameter containing the image as a base64 data URL.
     *
     * @param  array<string, mixed>  $options  Optional: `maxWidth`, `maxHeight`,
     *                                         `quality` (0.0 – 1.0). Defaults
     *                                         to 800x800 at quality 0.8.
     */
    public function camera(array $options = []): static
    {
        return $this->push('camera', $options + [
            'maxWidth' => 800,
            'maxHeight' => 800,
            'quality' => 0.8,
        ]);
    }

    /**
     * Open the device photo library to pick an existing image.
     *
     * On first use, NativeBlade automatically requests photo library
     * permission. The result is delivered via the same `nb:camera-result`
     * Livewire event used by `camera()`.
     *
     * @param  array<string, mixed>  $options  Optional: `maxWidth`, `maxHeight`,
     *                                         `quality` (0.0 – 1.0). Defaults
     *                                         to 800x800 at quality 0.8.
     */
    public function gallery(array $options = []): static
    {
        return $this->push('gallery', $options + [
            'maxWidth' => 800,
            'maxHeight' => 800,
            'quality' => 0.8,
        ]);
    }

    // ------------------------------------------------------------------
    // Navigation
    // ------------------------------------------------------------------

    /**
     * Navigate to an internal app route without reloading the PHP runtime.
     *
     * This is an SPA-style transition — the PHP WASM runtime stays alive
     * and only the rendered page changes. Chain `->replace()` to replace
     * the current history entry instead of pushing a new one, or
     * `->transition()` to pick an animation.
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
    // Modal
    // ------------------------------------------------------------------

    /**
     * Show the shell-level modal component (`<x-nativeblade-modal>`).
     *
     * The modal must already be present in the page markup — this method
     * only toggles its visibility. For dialogs with dynamic content,
     * prefer `alert()` or `confirm()`.
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
    // Process
    // ------------------------------------------------------------------

    /**
     * Quit the application cleanly.
     *
     * On desktop this terminates the Tauri process. On mobile, the OS
     * may choose to suspend the app instead of killing it.
     */
    public function exit(): static
    {
        return $this->push('exit', []);
    }

    // ------------------------------------------------------------------
    // Modifiers — attach extra data to the last pushed action
    // ------------------------------------------------------------------

    /**
     * Set the title of the most recently queued action.
     *
     * Applies to: `alert`, `confirm`, `notification`.
     *
     * @param  string  $title  Title text shown above the message/body.
     */
    public function title(string $title): static
    {
        return $this->modify('title', $title);
    }

    /**
     * Set the severity level of an alert or confirm dialog.
     *
     * Applies to: `alert`, `confirm`. Affects the icon and color chosen
     * by the OS when rendering the dialog.
     *
     * @param  string  $kind  One of: `'info'`, `'warning'`, `'error'`.
     */
    public function kind(string $kind): static
    {
        return $this->modify('kind', $kind);
    }

    /**
     * Set the label of the confirm/OK button on a dialog.
     *
     * Applies to: `alert`, `confirm`.
     *
     * @param  string  $label  Button text (e.g. `'Delete'`, `'Yes'`).
     */
    public function confirmLabel(string $label): static
    {
        return $this->modify('confirmLabel', $label);
    }

    /**
     * Set the label of the cancel button on a dialog.
     *
     * Applies to: `alert`, `confirm`.
     *
     * @param  string  $label  Button text (e.g. `'Keep'`, `'No'`).
     */
    public function cancelLabel(string $label): static
    {
        return $this->modify('cancelLabel', $label);
    }

    /**
     * Set the animation used for a navigate action.
     *
     * Applies to: `navigate`.
     *
     * @param  string  $type  One of: `'slide'`, `'fade'`, `'zoom'`,
     *                        `'flip'`, `'bounce'`, `'blur'`.
     */
    public function transition(string $type): static
    {
        return $this->modify('transition', $type);
    }

    /**
     * Mark a navigate action as a history-replace instead of push.
     *
     * Applies to: `navigate`. Use for flows where going back doesn't
     * make sense (e.g. after login, replacing `/login` with `/`).
     *
     * @param  bool  $replace  Whether to replace the current history entry.
     */
    public function replace(bool $replace = true): static
    {
        return $this->modify('replace', $replace);
    }

    /**
     * Set the sound played with a notification.
     *
     * Applies to: `notification`.
     *
     * @param  string  $sound  Platform-specific sound name or `'default'`.
     */
    public function sound(string $sound): static
    {
        return $this->modify('sound', $sound);
    }

    /**
     * Set the icon shown with a notification.
     *
     * Applies to: `notification`. Android uses a small icon from the
     * drawable folder; iOS uses an attachment image.
     *
     * @param  string  $icon  Resource identifier or absolute path.
     */
    public function icon(string $icon): static
    {
        return $this->modify('icon', $icon);
    }

    /**
     * Set the Android notification channel for a notification.
     *
     * Applies to: `notification`. Android 8+ requires notifications to be
     * posted to a channel. Ignored on iOS.
     *
     * @param  string  $channel  Channel identifier (e.g. `'lessons'`).
     */
    public function channel(string $channel): static
    {
        return $this->modify('channel', $channel);
    }

    /**
     * Control whether biometric prompts accept the device passcode as fallback.
     *
     * Applies to: `biometric`. When true (default), users can fall back to
     * their PIN / pattern / passcode if biometric hardware fails or is
     * unavailable.
     *
     * @param  bool  $allow  Whether to allow device credential fallback.
     */
    public function allowDeviceCredential(bool $allow = true): static
    {
        return $this->modify('allowDeviceCredential', $allow);
    }

    /**
     * Override the reason text displayed in the biometric prompt.
     *
     * Applies to: `biometric`. Same effect as passing the reason to
     * `biometric()` directly — useful when chaining conditionally.
     *
     * @param  string  $reason  Explanation shown to the user.
     */
    public function reason(string $reason): static
    {
        return $this->modify('reason', $reason);
    }

    /**
     * Restrict the barcode formats accepted by a scan action.
     *
     * Applies to: `scan`. See the `tauri-plugin-barcode-scanner` docs for
     * the full list of format identifiers.
     *
     * @param  array<int, string>  $formats  Allowed format identifiers.
     */
    public function formats(array $formats): static
    {
        return $this->modify('formats', $formats);
    }

    /**
     * Set the maximum width of a captured or selected image.
     *
     * Applies to: `camera`, `gallery`. The image is resized on the native
     * side before being returned to PHP, saving memory and payload size.
     *
     * @param  int  $value  Maximum width in pixels.
     */
    public function maxWidth(int $value): static
    {
        return $this->modify('maxWidth', $value);
    }

    /**
     * Set the maximum height of a captured or selected image.
     *
     * Applies to: `camera`, `gallery`.
     *
     * @param  int  $value  Maximum height in pixels.
     */
    public function maxHeight(int $value): static
    {
        return $this->modify('maxHeight', $value);
    }

    /**
     * Set the JPEG compression quality of a captured or selected image.
     *
     * Applies to: `camera`, `gallery`. Lower values produce smaller
     * payloads but visibly reduce image fidelity.
     *
     * @param  float  $value  Quality between `0.0` (smallest) and `1.0` (best).
     */
    public function quality(float $value): static
    {
        return $this->modify('quality', $value);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Append a new action to the queue.
     *
     * @param  string  $action  Action identifier recognized by the JS bridge.
     * @param  array<string, mixed>  $data  Parameters passed to the native plugin.
     */
    private function push(string $action, array $data): static
    {
        $this->actions[] = ['action' => $action, 'data' => $data];
        return $this;
    }

    /**
     * Attach a key/value pair to the data of the most recently queued action.
     *
     * Used internally by modifier methods (`title`, `kind`, `transition`, etc)
     * to apply configuration to the preceding action without creating a new one.
     */
    private function modify(string $key, mixed $value): static
    {
        if (!empty($this->actions)) {
            $last = &$this->actions[count($this->actions) - 1];
            $last['data'][$key] = $value;
        }
        return $this;
    }

    /**
     * Return the raw actions queue.
     *
     * Useful for testing or for packages that want to inspect or forward
     * the actions to a different transport.
     *
     * @return array<int, array{action: string, data: array<string, mixed>}>
     */
    public function toArray(): array
    {
        return $this->actions;
    }

    /**
     * Convert the queued actions into an HTTP response.
     *
     * When called during a Livewire update, the actions are dispatched via
     * the `__nativeblade` Livewire event instead of returning a JSON body,
     * which lets the bridge fire while the component continues to render.
     * Outside of Livewire, a JSON payload is returned so that controllers
     * and routes can use the same builder.
     */
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
