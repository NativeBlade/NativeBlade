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
     * Like notification(), but a *committed* scheduled reminder: it asks the OS
     * to fire at the exact time even in deep Doze (Android exact alarm; iOS is
     * already exact). Requires a schedule (->at()/->dailyAt()/->every()).
     *
     * Android exact alarms need the opt-in Permission::EXACT_ALARM in your
     * AndroidConfig; without it the OS quietly falls back to inexact timing
     * (a few minutes of slack) rather than failing.
     *
     * @param  Closure(Notification): void  $callback
     */
    public function scheduleNotification(Closure $callback): static
    {
        $notification = new Notification();
        $callback($notification);
        $notification->exact();
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

    /**
     * Request ad consent (UMP on both platforms, App Tracking Transparency on
     * iOS). Call once at boot before showing ads. Optional hashed test device
     * ids force the EEA consent form in debug. Requires `Plugin::ADMOB`.
     */
    public function requestAdConsent(array $testDeviceIds = []): static
    {
        return $this->push('request_ad_consent', ['testDeviceIds' => array_values($testDeviceIds)]);
    }

    /**
     * Load and present a rewarded ad. The outcome arrives on the `nb:ad-reward`
     * (earned/amount/rewardType/id) and `nb:ad-result` (status/error/id) events.
     * Mobile only; a no-op that reports a failure result on desktop.
     *
     * @param  \Closure(\NativeBlade\Plugins\RewardedAd): void  $callback
     */
    public function rewardedAd(\Closure $callback): static
    {
        $ad = new \NativeBlade\Plugins\RewardedAd();
        $callback($ad);
        return $this->push('rewarded_ad', $ad->toArray());
    }

    /**
     * Load and present an interstitial ad, with frequency capping. The outcome
     * arrives on the `nb:ad-result` event (`status: dismissed|failed|capped`).
     * Mobile only.
     *
     * @param  \Closure(\NativeBlade\Plugins\InterstitialAd): void  $callback
     */
    public function interstitialAd(\Closure $callback): static
    {
        $ad = new \NativeBlade\Plugins\InterstitialAd();
        $callback($ad);
        return $this->push('interstitial_ad', $ad->toArray());
    }

    /**
     * Show an anchored adaptive banner pinned below the WebView; the page
     * shrinks to make room. The result arrives on the `nb:ad-result` event
     * (`status: shown|failed`). Showing again replaces the current banner;
     * remove it with `hideBannerAd()`. Mobile only.
     *
     * @param  \Closure(\NativeBlade\Plugins\BannerAd): void  $callback
     */
    public function bannerAd(\Closure $callback): static
    {
        $ad = new \NativeBlade\Plugins\BannerAd();
        $callback($ad);
        return $this->push('banner_ad', $ad->toArray());
    }

    /**
     * Remove the banner shown by `bannerAd()` and give the WebView its space
     * back. A silent no-op when no banner is showing.
     */
    public function hideBannerAd(): static
    {
        return $this->push('hide_banner_ad', []);
    }

    // ------------------------------------------------------------------
    // Network (connectivity status)

    /**
     * Read connectivity. The result arrives on the `nb:network-status` event
     * as `connected` (validated internet, not just an interface up), `type`
     * (`wifi|cellular|ethernet|none|unknown`) and `metered`. Live changes
     * arrive on `nb:network-changed` with the same payload, no call needed.
     * On desktop and web this reports the browser's online flag with
     * `type: 'unknown'`. Requires `Plugin::NETWORK` on mobile.
     *
     * @param  string|null  $id  Tag echoed back on `nb:network-status` for routing concurrent requests
     */
    public function networkStatus(?string $id = null): static
    {
        return $this->push('network_status', $id !== null ? ['id' => $id] : []);
    }

    // ------------------------------------------------------------------
    // Background tasks (the native courier)

    /**
     * Read the latest parked result of a background task. The answer arrives
     * on the `nb:task` event as `name`, `found`, `payload`, `ranAt` (unix
     * seconds of the run — possibly while the app was closed), `status`
     * (HTTP) and `error`. Idempotent: nothing is consumed; the payload stays
     * until the next run overwrites it. Requires `Plugin::TASK_MANAGER`.
     */
    public function getTask(string $name): static
    {
        return $this->push('get_task', ['name' => $name]);
    }

    /**
     * Dispatch payloads into `BackgroundTask::queue(...)` outboxes. Parking
     * only — "send now" is what Laravel's Http is for; delivery happens on
     * the queue's clock (open-app timer, catch-up at open, or an OS wake
     * with the app closed). Payloads are JSON objects up to 1 MB, delivered
     * in dispatch order with an automatic `queuedAt` timestamp; each entry
     * is acked (= parked) on `nb:task-queued` (`name`, `ok`, `error`).
     *
     * @param  \Closure(\NativeBlade\Plugins\Task): void  $callback
     */
    public function task(\Closure $callback): static
    {
        $task = new \NativeBlade\Plugins\Task();
        $callback($task);
        return $this->push('enqueue_task', ['entries' => $task->toArray()]);
    }

    /**
     * Peek at a queue's pending entries — what was dispatched but not yet
     * delivered. The answer arrives on the `nb:task-queue` event as `name`,
     * `entries` (oldest first, each with its `queuedAt`) and `count`.
     * Non-consuming: entries leave the list as runs deliver them.
     */
    public function getTaskOnQueue(string $name): static
    {
        return $this->push('get_task_queue', ['name' => $name]);
    }

    /**
     * Drop pending entries of a queue — dispatched but not yet delivered
     * payloads are discarded for good (results and meta are untouched).
     * Without `$id`, the whole queue; with it, only entries dispatched with
     * that id. The ack arrives on `nb:task-queue-cleared` as `name` and
     * `removed` (count).
     */
    public function clearTaskOnQueue(string $name, ?string $id = null): static
    {
        return $this->push('clear_task_queue', array_filter([
            'name' => $name,
            'id' => $id,
        ], fn($v) => $v !== null));
    }

    // ------------------------------------------------------------------
    // Sensors

    /**
     * Run sensor operations: `available()`, `read()` (one-shot → `nb:sensor`),
     * `watch()` (polling stream → `nb:sensor-changed`) and `stop()`. Mobile
     * only; on desktop every operation reports `available: false`. Requires
     * `Plugin::SENSORS`.
     *
     * @param  \Closure(\NativeBlade\Plugins\Sensor): void  $callback
     */
    public function sensors(\Closure $callback): static
    {
        $sensor = new \NativeBlade\Plugins\Sensor();
        $callback($sensor);
        return $this->push('sensors', ['entries' => $sensor->toArray()]);
    }

    // ------------------------------------------------------------------
    // Payments (in-app purchases and subscriptions)

    /**
     * Fetch products from the store so real localized prices can be shown. The
     * result arrives on the `nb:products` event as
     * `[['id' => ..., 'price' => ..., 'title' => ..., 'type' => ...], ...]`.
     * Mobile only; reports an empty list on desktop. Requires `Plugin::PAYMENTS`.
     *
     * @param  string[]     $productIds
     * @param  string|null  $id          Tag echoed back on `nb:products` for routing concurrent requests
     */
    public function products(array $productIds, ?string $id = null): static
    {
        return $this->push('query_products', ['products' => array_values($productIds), 'id' => $id]);
    }

    /**
     * Start an in-app purchase. The outcome arrives on the `nb:purchase-result`
     * event (`success`, `status`, `receipt`, `productId`, `error`, `id`). Always
     * validate the receipt on a server before granting entitlement. Mobile only;
     * on desktop it opens the builder's `external(...)` web checkout if set
     * (reporting `status: 'external'`), otherwise reports a failure result.
     *
     * @param  \Closure(\NativeBlade\Plugins\Purchase): void  $callback
     */
    public function purchase(\Closure $callback): static
    {
        $purchase = new \NativeBlade\Plugins\Purchase();
        $callback($purchase);
        return $this->push('purchase', $purchase->toArray());
    }

    /**
     * Restore previous purchases (required by Apple for non-consumables and
     * subscriptions). The result arrives on the `nb:purchases-restored` event
     * as `['purchases' => [['productId' => ..., 'receipt' => ...], ...]]`.
     * Requires `Plugin::PAYMENTS`.
     *
     * @param  string|null  $id  Tag echoed back on `nb:purchases-restored` for routing concurrent requests
     */
    public function restorePurchases(?string $id = null): static
    {
        return $this->push('restore_purchases', ['id' => $id]);
    }

    /**
     * Read active entitlements (owned non-consumables and active subscriptions).
     * The result arrives on the `nb:subscription-status` event as
     * `['entitlements' => [['productId' => ..., 'active' => ..., 'receipt' => ...], ...]]`.
     * Pass product ids to narrow the report, or none for every entitlement.
     *
     * @param  string[]     $productIds
     * @param  string|null  $id          Tag echoed back on `nb:subscription-status` for routing concurrent requests
     */
    public function subscriptionStatus(array $productIds = [], ?string $id = null): static
    {
        return $this->push('subscription_status', ['products' => array_values($productIds), 'id' => $id]);
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
     * When the builder calls `->spawn()`, the command runs as a long-lived
     * streamed process: output is delivered incrementally on `nb:shell-data`
     * and completion on `nb:shell-exit`, and the process accepts stdin via
     * `shellWrite()` and termination via `shellKill()`.
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

    /**
     * Write to the stdin of a process started with `shell(fn ($s) => $s->id($id)->spawn())`.
     *
     * A trailing newline is appended unless `$newline` is false — line-delimited
     * CLIs (e.g. `claude --output-format stream-json`) consume one line per
     * message. No-op on the JS side if no process with `$id` is running.
     */
    public function shellWrite(string $id, string $data, bool $newline = true): static
    {
        return $this->push('shell_write', [
            'id' => $id,
            'data' => $data,
            'newline' => $newline,
        ]);
    }

    /**
     * Terminate a spawned process (and its child tree) by its id. Idempotent —
     * an unknown or already-finished id is a no-op. The `nb:shell-exit` event
     * still fires from the process's own close.
     */
    public function shellKill(string $id): static
    {
        return $this->push('shell_kill', ['id' => $id]);
    }

    /**
     * Terminate every process spawned via `shell()->spawn()`. Intended for app
     * teardown so no child is left orphaned when the window closes.
     */
    public function shellKillAll(): static
    {
        return $this->push('shell_kill_all', []);
    }

    // ------------------------------------------------------------------
    // Realtime (WebSocket / Reverb / Pusher)
    // ------------------------------------------------------------------

    /**
     * Subscribe the current component to realtime channels via a fluent
     * `Realtime` builder. Connections are declared once in
     * `NativeBladeConfig::realtimeConfig()`; a single connection multiplexes many
     * channels, so several open chats are just several `subscribe()` calls.
     *
     * Incoming messages arrive on the `nb:realtime` Livewire event
     * (`$connection`, `$channel`, `$event`, `$payload`) and, for convenience, on
     * the specific `nb:realtime:{channel}:{event}` event. Presence members arrive
     * on `nb:realtime-presence`; accumulating `stream()` deltas on
     * `nb:realtime-stream`. Connection lifecycle (for gap-fill after a drop) is
     * reported on `nb:realtime-connected` / `nb:realtime-reconnected` /
     * `nb:realtime-disconnected`.
     *
     * @param  Closure(\NativeBlade\Plugins\Realtime): void  $callback
     */
    public function realtime(Closure $callback): static
    {
        $realtime = new \NativeBlade\Plugins\Realtime();
        $callback($realtime);
        return $this->push('realtime', ['ops' => $realtime->toArray()]);
    }

    /**
     * Publish a message on a channel. The `ws` driver sends it as a real frame
     * over the socket. On Reverb/Pusher there is no server-side send over the
     * socket, so this maps to a client event (whisper)
     * and works only on private/presence channels — for a persisted send, POST to
     * your backend, which then `broadcast()`s. `$connection` defaults to the one
     * marked default in the config.
     *
     * @param  array<string, mixed>  $payload
     */
    public function realtimeSend(string $channel, string $event, array $payload = [], ?string $connection = null): static
    {
        return $this->push('realtime_send', array_filter(
            compact('channel', 'event', 'payload', 'connection'),
            fn ($v) => $v !== null
        ));
    }

    /**
     * Send an ephemeral client event (typing indicators, cursors, presence pings)
     * on a private/presence channel — not persisted, rate-limited, delivered only
     * to other connected clients. Reverb/Pusher only.
     *
     * @param  array<string, mixed>  $payload
     */
    public function realtimeWhisper(string $channel, string $event, array $payload = [], ?string $connection = null): static
    {
        return $this->push('realtime_whisper', array_filter(
            compact('channel', 'event', 'payload', 'connection'),
            fn ($v) => $v !== null
        ));
    }

    /**
     * Leave a channel outside the subscribe builder (e.g. on component
     * `unmount()`). The JS layer ref-counts, so the channel is only truly closed
     * when its last subscriber leaves.
     */
    public function realtimeLeave(string $channel, ?string $connection = null): static
    {
        return $this->push('realtime_leave', array_filter(
            compact('channel', 'connection'),
            fn ($v) => $v !== null
        ));
    }

    /**
     * Set the bearer token used to authorize private/presence channel
     * subscriptions (Echo POSTs it to the connection's `authEndpoint`). Call it
     * after login, before subscribing to a private/presence channel; pass `null`
     * on logout to clear it. Public channels don't need this. Already-open
     * connections are updated in place.
     */
    public function realtimeAuth(?string $token, ?string $connection = null): static
    {
        $data = ['token' => $token];
        if ($connection !== null) {
            $data['connection'] = $connection;
        }
        return $this->push('realtime_auth', $data);
    }

    // ------------------------------------------------------------------
    // Native shell modules
    // ------------------------------------------------------------------

    /**
     * Send a command to a native shell module BY NAME, from any component or
     * service — no need to be the module's owner or round-trip an event to it.
     * The JS side resolves the running instance of that `$shell` (persistent
     * modules are singletons per name, so the match is unambiguous).
     *
     * ```
     * return NativeBlade::shellCommand('player', 'play', [
     *     'trackId' => $trackId,
     *     'queue'   => $this->queueIds,
     * ])->toResponse();
     * ```
     *
     * Commands are for making the module DO something. To change what the
     * module IS showing (its `#[NativeProp]` state), message the owner
     * component instead — props keep a single owner. See NATIVE-SHELL.md.
     *
     * @param  array<int|string, mixed>  $args  Passed to `module.command($command, $args)`.
     */
    public function shellCommand(string $shell, string $command, array $args = []): static
    {
        return $this->push('shell_module_command', [
            'shell' => $shell,
            'command' => $command,
            'args' => array_values($args) === $args ? $args : [$args],
        ]);
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
