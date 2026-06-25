<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use NativeBlade\NativeResponse;
use NativeBlade\Plugins\Biometric;
use NativeBlade\Plugins\Camera;
use NativeBlade\Plugins\Clipboard;
use NativeBlade\Plugins\Dialog;
use NativeBlade\Plugins\FilePicker;
use NativeBlade\Plugins\Geolocation;
use NativeBlade\Plugins\Nfc;
use NativeBlade\Plugins\Notification;
use NativeBlade\Plugins\Scan;
use NativeBlade\Plugins\Shell;
use NativeBlade\Plugins\Upload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the fluent NativeResponse builder: each method must push the right
 * action name and the payload shape the JS bridge expects.
 */
final class NativeResponseTest extends TestCase
{
    #[Test]
    public function a_fresh_response_has_no_actions(): void
    {
        self::assertSame([], (new NativeResponse())->toArray());
    }

    #[Test]
    public function every_action_method_returns_the_same_instance_for_chaining(): void
    {
        $r = new NativeResponse();

        self::assertSame($r, $r->clipboardWrite('x'));
        self::assertSame($r, $r->vibrate());
        self::assertSame($r, $r->openUrl('https://example.com'));
        self::assertSame($r, $r->navigate('/home'));
        self::assertSame($r, $r->transition('slide'));
        self::assertSame($r, $r->replace());
        self::assertSame($r, $r->checkUpdate());
        self::assertSame($r, $r->forceUpdate());
        self::assertSame($r, $r->requestReview());
        self::assertSame($r, $r->setSecure('k', 'v'));
        self::assertSame($r, $r->getSecure('k'));
        self::assertSame($r, $r->forgetSecure('k'));
        self::assertSame($r, $r->share('hi'));
        self::assertSame($r, $r->analytics(fn ($a) => $a->event('x')));
        self::assertSame($r, $r->products(['com.app.pro']));
        self::assertSame($r, $r->purchase(fn ($p) => $p->product('com.app.pro')));
        self::assertSame($r, $r->restorePurchases());
        self::assertSame($r, $r->subscriptionStatus());
    }

    #[Test]
    public function check_update_queues_a_check_update_action(): void
    {
        $r = (new NativeResponse())->checkUpdate();
        self::assertSame([['action' => 'check_update', 'data' => []]], $r->toArray());
    }

    #[Test]
    public function force_update_queues_a_force_update_action(): void
    {
        $r = (new NativeResponse())->forceUpdate();
        self::assertSame([['action' => 'force_update', 'data' => []]], $r->toArray());
    }

    #[Test]
    public function request_review_queues_a_request_review_action(): void
    {
        $r = (new NativeResponse())->requestReview();
        self::assertSame([['action' => 'request_review', 'data' => []]], $r->toArray());
    }

    #[Test]
    public function set_secure_queues_the_key_and_value(): void
    {
        $r = (new NativeResponse())->setSecure('auth.token', 'abc');
        self::assertSame(
            [['action' => 'set_secure', 'data' => ['key' => 'auth.token', 'value' => 'abc']]],
            $r->toArray()
        );
    }

    #[Test]
    public function get_secure_queues_the_key_and_optional_id(): void
    {
        $r = (new NativeResponse())->getSecure('auth.token', 'auth');
        self::assertSame(
            [['action' => 'get_secure', 'data' => ['key' => 'auth.token', 'id' => 'auth']]],
            $r->toArray()
        );
    }

    #[Test]
    public function get_secure_defaults_id_to_null(): void
    {
        $r = (new NativeResponse())->getSecure('auth.token');
        self::assertSame(
            [['action' => 'get_secure', 'data' => ['key' => 'auth.token', 'id' => null]]],
            $r->toArray()
        );
    }

    #[Test]
    public function forget_secure_queues_the_key(): void
    {
        $r = (new NativeResponse())->forgetSecure('auth.token');
        self::assertSame(
            [['action' => 'forget_secure', 'data' => ['key' => 'auth.token']]],
            $r->toArray()
        );
    }

    #[Test]
    public function products_queues_a_query_products_action(): void
    {
        $r = (new NativeResponse())->products(['com.app.pro', 'com.app.coins']);
        self::assertSame(
            [['action' => 'query_products', 'data' => ['products' => ['com.app.pro', 'com.app.coins'], 'id' => null]]],
            $r->toArray()
        );
    }

    #[Test]
    public function products_passes_the_optional_id_through(): void
    {
        $r = (new NativeResponse())->products(['com.app.pro'], 'pro_group');
        self::assertSame(
            [['action' => 'query_products', 'data' => ['products' => ['com.app.pro'], 'id' => 'pro_group']]],
            $r->toArray()
        );
    }

    #[Test]
    public function purchase_queues_a_purchase_action_from_the_builder(): void
    {
        $r = (new NativeResponse())->purchase(function ($p) {
            $p->product('com.app.pro.monthly')->id('pro');
        });
        self::assertSame(
            [['action' => 'purchase', 'data' => ['product' => 'com.app.pro.monthly', 'id' => 'pro']]],
            $r->toArray()
        );
    }

    #[Test]
    public function restore_purchases_queues_a_restore_action(): void
    {
        $r = (new NativeResponse())->restorePurchases();
        self::assertSame([['action' => 'restore_purchases', 'data' => ['id' => null]]], $r->toArray());
    }

    #[Test]
    public function subscription_status_queues_the_product_filter(): void
    {
        $r = (new NativeResponse())->subscriptionStatus(['com.app.pro.monthly']);
        self::assertSame(
            [['action' => 'subscription_status', 'data' => ['products' => ['com.app.pro.monthly'], 'id' => null]]],
            $r->toArray()
        );
    }

    #[Test]
    public function subscription_status_defaults_to_an_empty_filter(): void
    {
        $r = (new NativeResponse())->subscriptionStatus();
        self::assertSame(
            [['action' => 'subscription_status', 'data' => ['products' => [], 'id' => null]]],
            $r->toArray()
        );
    }

    #[Test]
    public function share_queues_the_text_and_url(): void
    {
        $r = (new NativeResponse())->share('Join me', 'https://myapp.com/i/abc');
        self::assertSame(
            [['action' => 'share', 'data' => ['text' => 'Join me', 'url' => 'https://myapp.com/i/abc']]],
            $r->toArray()
        );
    }

    #[Test]
    public function share_defaults_missing_fields_to_empty_strings(): void
    {
        $r = (new NativeResponse())->share('Just text');
        self::assertSame(
            [['action' => 'share', 'data' => ['text' => 'Just text', 'url' => '']]],
            $r->toArray()
        );
    }

    #[Test]
    public function analytics_builds_the_ops_and_queues_an_analytics_action(): void
    {
        $r = (new NativeResponse())->analytics(function (\NativeBlade\Plugins\Analytics $a) {
            $a->event('add_to_cart', ['value' => 9.99])
                ->screen('Checkout')
                ->setUserId('u1')
                ->setUserProperty('plan', 'pro')
                ->disable();
        });

        self::assertSame([[
            'action' => 'analytics',
            'data' => ['ops' => [
                ['op' => 'event', 'name' => 'add_to_cart', 'params' => ['value' => 9.99]],
                ['op' => 'screen', 'name' => 'Checkout'],
                ['op' => 'userId', 'value' => 'u1'],
                ['op' => 'userProperty', 'key' => 'plan', 'value' => 'pro'],
                ['op' => 'setEnabled', 'enabled' => false],
            ]],
        ]], $r->toArray());
    }

    #[Test]
    public function transition_modifier_rejects_unsupported_value(): void
    {
        $r = (new NativeResponse())->navigate('/dashboard');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid transition 'flip'");
        $r->transition('flip');
    }

    // -- Dialog --------------------------------------------------------

    #[Test]
    public function alert_pushes_an_alert_action_with_dialog_payload(): void
    {
        $r = (new NativeResponse())->alert(function (Dialog $d) {
            $d->title('Hi')->message('Hello');
        });

        self::assertSame([
            ['action' => 'alert', 'data' => ['title' => 'Hi', 'message' => 'Hello']],
        ], $r->toArray());
    }

    #[Test]
    public function confirm_pushes_a_confirm_action_with_dialog_payload(): void
    {
        $r = (new NativeResponse())->confirm(function (Dialog $d) {
            $d->title('Sure?')->message('Confirm')->id('del');
        });

        self::assertSame([
            ['action' => 'confirm', 'data' => ['title' => 'Sure?', 'message' => 'Confirm', 'id' => 'del']],
        ], $r->toArray());
    }

    // -- Notification --------------------------------------------------

    #[Test]
    public function notification_pushes_a_notification_action_with_builder_payload(): void
    {
        $r = (new NativeResponse())->notification(function (Notification $n) {
            $n->title('T')->body('B')->channel('messages');
        });

        self::assertSame([
            ['action' => 'notification', 'data' => ['title' => 'T', 'body' => 'B', 'channel' => 'messages']],
        ], $r->toArray());
    }

    #[Test]
    public function cancel_notification_pushes_a_cancel_action_with_the_id(): void
    {
        $r = (new NativeResponse())->cancelNotification('reminder-1');

        self::assertSame([
            ['action' => 'cancel_notification', 'data' => ['id' => 'reminder-1']],
        ], $r->toArray());
    }

    #[Test]
    public function cancel_all_notifications_pushes_a_cancel_all_action(): void
    {
        $r = (new NativeResponse())->cancelAllNotifications();

        self::assertSame([
            ['action' => 'cancel_all_notifications', 'data' => []],
        ], $r->toArray());
    }

    // -- Clipboard -----------------------------------------------------

    #[Test]
    public function clipboard_write_pushes_a_clipboard_write_action_with_the_text(): void
    {
        $r = (new NativeResponse())->clipboardWrite('copied');

        self::assertSame([
            ['action' => 'clipboard_write', 'data' => ['text' => 'copied']],
        ], $r->toArray());
    }

    #[Test]
    public function clipboard_read_without_builder_pushes_an_empty_payload(): void
    {
        $r = (new NativeResponse())->clipboardRead();

        self::assertSame([
            ['action' => 'clipboard_read', 'data' => []],
        ], $r->toArray());
    }

    #[Test]
    public function clipboard_read_with_builder_pushes_the_id(): void
    {
        $r = (new NativeResponse())->clipboardRead(function (Clipboard $c) {
            $c->id('target-1');
        });

        self::assertSame([
            ['action' => 'clipboard_read', 'data' => ['id' => 'target-1']],
        ], $r->toArray());
    }

    // -- Geolocation ---------------------------------------------------

    #[Test]
    public function geolocation_accepts_no_callback(): void
    {
        $r = (new NativeResponse())->geolocation();

        self::assertSame([
            ['action' => 'geolocation', 'data' => []],
        ], $r->toArray());
    }

    #[Test]
    public function geolocation_forwards_builder_id(): void
    {
        $r = (new NativeResponse())->geolocation(fn(Geolocation $g) => $g->id('delivery'));

        self::assertSame([
            ['action' => 'geolocation', 'data' => ['id' => 'delivery']],
        ], $r->toArray());
    }

    // -- Haptics -------------------------------------------------------

    #[Test]
    public function vibrate_defaults_to_one_hundred_milliseconds(): void
    {
        $r = (new NativeResponse())->vibrate();

        self::assertSame([
            ['action' => 'vibrate', 'data' => ['duration' => 100]],
        ], $r->toArray());
    }

    #[Test]
    public function vibrate_accepts_a_custom_duration(): void
    {
        $r = (new NativeResponse())->vibrate(250);

        self::assertSame([
            ['action' => 'vibrate', 'data' => ['duration' => 250]],
        ], $r->toArray());
    }

    #[Test]
    public function impact_defaults_to_medium_style(): void
    {
        $r = (new NativeResponse())->impact();

        self::assertSame([
            ['action' => 'impact', 'data' => ['style' => 'medium']],
        ], $r->toArray());
    }

    #[Test]
    public function impact_forwards_the_style(): void
    {
        foreach (['light', 'medium', 'heavy'] as $style) {
            $r = (new NativeResponse())->impact($style);
            self::assertSame([['action' => 'impact', 'data' => ['style' => $style]]], $r->toArray());
        }
    }

    #[Test]
    public function selection_pushes_an_empty_payload(): void
    {
        $r = (new NativeResponse())->selection();

        self::assertSame([
            ['action' => 'selection', 'data' => []],
        ], $r->toArray());
    }

    // -- Biometric -----------------------------------------------------

    #[Test]
    public function biometric_pushes_the_builder_payload(): void
    {
        $r = (new NativeResponse())->biometric(function (Biometric $b) {
            $b->reason('Unlock')->id('login');
        });

        self::assertSame([
            ['action' => 'biometric', 'data' => [
                'reason' => 'Unlock',
                'allowDeviceCredential' => true,
                'id' => 'login',
            ]],
        ], $r->toArray());
    }

    // -- Scan ----------------------------------------------------------

    #[Test]
    public function scan_defaults_payload_has_empty_formats(): void
    {
        $r = (new NativeResponse())->scan();

        self::assertSame([
            ['action' => 'scan', 'data' => ['formats' => []]],
        ], $r->toArray());
    }

    #[Test]
    public function scan_forwards_formats_via_builder(): void
    {
        $r = (new NativeResponse())->scan(fn(Scan $s) => $s->formats(['QR_CODE']));

        self::assertSame([
            ['action' => 'scan', 'data' => ['formats' => ['QR_CODE']]],
        ], $r->toArray());
    }

    // -- NFC -----------------------------------------------------------

    #[Test]
    public function nfc_read_without_builder_pushes_an_empty_payload(): void
    {
        $r = (new NativeResponse())->nfcRead();

        self::assertSame([
            ['action' => 'nfc_read', 'data' => []],
        ], $r->toArray());
    }

    #[Test]
    public function nfc_read_forwards_builder_id(): void
    {
        $r = (new NativeResponse())->nfcRead(fn(Nfc $n) => $n->id('ticket'));

        self::assertSame([
            ['action' => 'nfc_read', 'data' => ['id' => 'ticket']],
        ], $r->toArray());
    }

    // -- Opener --------------------------------------------------------

    #[Test]
    public function open_url_pushes_open_url_action(): void
    {
        $r = (new NativeResponse())->openUrl('https://example.com');

        self::assertSame([
            ['action' => 'open_url', 'data' => ['url' => 'https://example.com']],
        ], $r->toArray());
    }

    #[Test]
    public function open_file_pushes_open_file_action(): void
    {
        $r = (new NativeResponse())->openFile('/tmp/file.pdf');

        self::assertSame([
            ['action' => 'open_file', 'data' => ['path' => '/tmp/file.pdf']],
        ], $r->toArray());
    }

    // -- OS ------------------------------------------------------------

    #[Test]
    public function os_info_pushes_an_empty_payload(): void
    {
        $r = (new NativeResponse())->osInfo();

        self::assertSame([
            ['action' => 'os_info', 'data' => []],
        ], $r->toArray());
    }

    // -- Camera & gallery ----------------------------------------------

    #[Test]
    public function camera_uses_default_camera_builder_when_no_callback_is_passed(): void
    {
        $r = (new NativeResponse())->camera();

        self::assertSame([
            ['action' => 'camera', 'data' => [
                'maxWidth' => 800,
                'maxHeight' => 800,
                'quality' => 0.8,
            ]],
        ], $r->toArray());
    }

    #[Test]
    public function camera_accepts_a_builder(): void
    {
        $r = (new NativeResponse())->camera(function (Camera $c) {
            $c->quality(0.5)->id('profile');
        });

        self::assertSame([
            ['action' => 'camera', 'data' => [
                'maxWidth' => 800,
                'maxHeight' => 800,
                'quality' => 0.5,
                'id' => 'profile',
            ]],
        ], $r->toArray());
    }

    #[Test]
    public function gallery_pushes_the_gallery_action_with_the_camera_payload(): void
    {
        $r = (new NativeResponse())->gallery();

        self::assertSame([
            ['action' => 'gallery', 'data' => [
                'maxWidth' => 800,
                'maxHeight' => 800,
                'quality' => 0.8,
            ]],
        ], $r->toArray());
    }

    // -- File picker ---------------------------------------------------

    #[Test]
    public function file_picker_without_builder_pushes_an_empty_payload(): void
    {
        $r = (new NativeResponse())->filePicker();

        self::assertSame([
            ['action' => 'file_picker', 'data' => []],
        ], $r->toArray());
    }

    #[Test]
    public function file_picker_forwards_builder_options(): void
    {
        $r = (new NativeResponse())->filePicker(function (FilePicker $p) {
            $p->title('Pick')->multiple();
        });

        self::assertSame([
            ['action' => 'file_picker', 'data' => [
                'title' => 'Pick',
                'multiple' => true,
            ]],
        ], $r->toArray());
    }

    #[Test]
    public function file_save_sets_default_name_and_uses_it_as_builder_id(): void
    {
        $r = (new NativeResponse())->fileSave('report.pdf');

        self::assertSame([
            ['action' => 'file_save', 'data' => [
                'id' => 'report.pdf',
                'defaultName' => 'report.pdf',
            ]],
        ], $r->toArray());
    }

    #[Test]
    public function file_save_allows_overriding_with_a_builder(): void
    {
        $r = (new NativeResponse())->fileSave('notes.txt', function (FilePicker $p) {
            $p->title('Save as')->defaultPath('/tmp');
        });

        self::assertSame([
            ['action' => 'file_save', 'data' => [
                'id' => 'notes.txt',
                'title' => 'Save as',
                'defaultPath' => '/tmp',
                'defaultName' => 'notes.txt',
            ]],
        ], $r->toArray());
    }

    // -- File ops ------------------------------------------------------

    #[Test]
    public function copy_file_pushes_the_copy_payload(): void
    {
        $r = (new NativeResponse())->copyFile('a.txt', 'b.txt');

        self::assertSame([
            ['action' => 'copy_file', 'data' => [
                'from' => 'a.txt',
                'to' => 'b.txt',
                'purpose' => 'app',
            ]],
        ], $r->toArray());
    }

    #[Test]
    public function copy_file_allows_overriding_the_purpose(): void
    {
        $r = (new NativeResponse())->copyFile('a.txt', 'b.txt', 'cache');

        self::assertSame('cache', $r->toArray()[0]['data']['purpose']);
    }

    #[Test]
    public function move_file_pushes_the_move_payload(): void
    {
        $r = (new NativeResponse())->moveFile('a.txt', 'b.txt', 'documents');

        self::assertSame([
            ['action' => 'move_file', 'data' => [
                'from' => 'a.txt',
                'to' => 'b.txt',
                'purpose' => 'documents',
            ]],
        ], $r->toArray());
    }

    // -- Upload --------------------------------------------------------

    #[Test]
    public function upload_sets_path_and_url_and_forwards_the_builder(): void
    {
        $r = (new NativeResponse())->upload('/tmp/big.zip', 'https://example.com/u', function (Upload $u) {
            $u->headers(['Authorization' => 'Bearer abc']);
        });

        self::assertSame([
            ['action' => 'upload', 'data' => [
                'url' => 'https://example.com/u',
                'headers' => ['Authorization' => 'Bearer abc'],
                'path' => '/tmp/big.zip',
            ]],
        ], $r->toArray());
    }

    // -- Navigation ----------------------------------------------------

    #[Test]
    public function navigate_pushes_navigate_with_replace_false_by_default(): void
    {
        $r = (new NativeResponse())->navigate('/home');

        self::assertSame([
            ['action' => 'navigate', 'data' => ['path' => '/home', 'replace' => false]],
        ], $r->toArray());
    }

    #[Test]
    public function navigate_forwards_replace_flag(): void
    {
        $r = (new NativeResponse())->navigate('/login', true);

        self::assertSame([
            ['action' => 'navigate', 'data' => ['path' => '/login', 'replace' => true]],
        ], $r->toArray());
    }

    // -- Modal ---------------------------------------------------------

    #[Test]
    public function show_and_hide_modal_push_empty_payloads(): void
    {
        $r = (new NativeResponse())->showModal()->hideModal();

        self::assertSame([
            ['action' => 'showModal', 'data' => []],
            ['action' => 'hideModal', 'data' => []],
        ], $r->toArray());
    }

    // -- Shell ---------------------------------------------------------

    #[Test]
    public function shell_pushes_the_builder_payload(): void
    {
        $r = (new NativeResponse())->shell(function (Shell $s) {
            $s->id('lint')->run('php -l');
        });

        self::assertSame([
            ['action' => 'shell', 'data' => [
                'command' => 'php -l',
                'openTerminal' => false,
                'id' => 'lint',
            ]],
        ], $r->toArray());
    }

    // -- Exit ----------------------------------------------------------

    #[Test]
    public function exit_pushes_an_empty_payload(): void
    {
        $r = (new NativeResponse())->exit();

        self::assertSame([
            ['action' => 'exit', 'data' => []],
        ], $r->toArray());
    }

    #[Test]
    public function window_controls_push_their_named_actions(): void
    {
        $r = (new NativeResponse())
            ->minimize()
            ->maximize()
            ->unmaximize()
            ->toggleMaximize()
            ->hide()
            ->show();

        self::assertSame([
            ['action' => 'minimize',        'data' => []],
            ['action' => 'maximize',        'data' => []],
            ['action' => 'unmaximize',      'data' => []],
            ['action' => 'toggle_maximize', 'data' => []],
            ['action' => 'hide',            'data' => []],
            ['action' => 'show',            'data' => []],
        ], $r->toArray());
    }

    // -- Modifiers -----------------------------------------------------

    #[Test]
    public function transition_attaches_to_the_last_pushed_action(): void
    {
        $r = (new NativeResponse())->navigate('/a')->transition('slide');

        self::assertSame([
            ['action' => 'navigate', 'data' => ['path' => '/a', 'replace' => false, 'transition' => 'slide']],
        ], $r->toArray());
    }

    #[Test]
    public function replace_attaches_to_the_last_pushed_action(): void
    {
        $r = (new NativeResponse())->navigate('/a')->replace();

        self::assertSame(
            ['path' => '/a', 'replace' => true],
            $r->toArray()[0]['data'],
        );
    }

    #[Test]
    public function replace_can_be_set_to_false_via_argument(): void
    {
        $r = (new NativeResponse())->navigate('/a')->replace(false);

        self::assertFalse($r->toArray()[0]['data']['replace']);
    }

    #[Test]
    public function modifiers_on_an_empty_queue_are_silent_noops(): void
    {
        // Calling a modifier before any push used to throw — make sure it's a no-op.
        $r = (new NativeResponse())->transition('slide')->replace();

        self::assertSame([], $r->toArray());
    }

    #[Test]
    public function modifiers_only_affect_the_most_recent_action(): void
    {
        $r = (new NativeResponse())
            ->navigate('/first')
            ->navigate('/second')
            ->transition('fade');

        self::assertArrayNotHasKey('transition', $r->toArray()[0]['data']);
        self::assertSame('fade', $r->toArray()[1]['data']['transition']);
    }

    // -- Queueing semantics --------------------------------------------

    #[Test]
    public function multiple_actions_accumulate_in_insertion_order(): void
    {
        $r = (new NativeResponse())
            ->vibrate(50)
            ->impact('light')
            ->openUrl('https://example.com')
            ->exit();

        $actions = array_column($r->toArray(), 'action');

        self::assertSame(['vibrate', 'impact', 'open_url', 'exit'], $actions);
    }

    #[Test]
    public function to_array_returns_the_raw_queue_shape(): void
    {
        $r = (new NativeResponse())->clipboardWrite('x');

        $actions = $r->toArray();

        self::assertArrayHasKey('action', $actions[0]);
        self::assertArrayHasKey('data', $actions[0]);
        self::assertIsArray($actions[0]['data']);
    }
}
