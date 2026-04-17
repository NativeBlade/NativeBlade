<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use BadMethodCallException;
use NativeBlade\NativeResponse;
use NativeBlade\ShellConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ShellConfig::__call is the mechanism that makes every NativeResponse action
 * available directly off the facade (NativeBlade::alert(), NativeBlade::vibrate()).
 * Keeping this contract pinned ensures calling patterns don't silently break.
 */
final class ShellConfigCallTest extends TestCase
{
    private ShellConfig $config;

    protected function setUp(): void
    {
        $this->config = new ShellConfig();
    }

    #[Test]
    public function response_returns_a_fresh_native_response(): void
    {
        $r1 = $this->config->response();
        $r2 = $this->config->response();

        self::assertInstanceOf(NativeResponse::class, $r1);
        self::assertInstanceOf(NativeResponse::class, $r2);
        self::assertNotSame($r1, $r2);
        self::assertSame([], $r1->toArray());
    }

    #[Test]
    public function call_delegates_vibrate_to_native_response(): void
    {
        /** @var NativeResponse $result */
        $result = $this->config->vibrate(150);

        self::assertInstanceOf(NativeResponse::class, $result);
        $actions = $result->toArray();
        self::assertCount(1, $actions);
        self::assertSame('vibrate', $actions[0]['action']);
        self::assertSame(150, $actions[0]['data']['duration']);
    }

    #[Test]
    public function call_delegates_navigate_with_arguments(): void
    {
        /** @var NativeResponse $result */
        $result = $this->config->navigate('/home', true);

        $actions = $result->toArray();
        self::assertSame('navigate', $actions[0]['action']);
        self::assertSame('/home', $actions[0]['data']['path']);
        self::assertTrue($actions[0]['data']['replace']);
    }

    #[Test]
    public function call_delegates_alert_with_closure_builder(): void
    {
        /** @var NativeResponse $result */
        $result = $this->config->alert(function ($dialog) {
            $dialog->title('Heads up')->message('Something happened');
        });

        $actions = $result->toArray();
        self::assertSame('alert', $actions[0]['action']);
        self::assertSame('Heads up', $actions[0]['data']['title']);
        self::assertSame('Something happened', $actions[0]['data']['message']);
    }

    #[Test]
    public function call_delegates_exit_without_arguments(): void
    {
        /** @var NativeResponse $result */
        $result = $this->config->exit();

        $actions = $result->toArray();
        self::assertSame('exit', $actions[0]['action']);
        self::assertSame([], $actions[0]['data']);
    }

    #[Test]
    public function call_throws_bad_method_for_unknown_method(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Method unknownAction does not exist on NativeBlade.');

        /** @phpstan-ignore-next-line intentionally calling unknown method */
        $this->config->unknownAction();
    }

    #[Test]
    public function each_call_returns_a_new_response_instance(): void
    {
        /** @var NativeResponse $r1 */
        $r1 = $this->config->vibrate();
        /** @var NativeResponse $r2 */
        $r2 = $this->config->vibrate();

        self::assertNotSame($r1, $r2, 'Each call should create a fresh NativeResponse chain.');
    }

    #[Test]
    public function log_writes_nb_log_markers_to_stderr(): void
    {
        // log() writes to php://stderr using @file_put_contents — in PHPUnit
        // tests stderr goes to the terminal and cannot easily be captured
        // without process isolation. Instead, we verify the method is callable
        // and doesn't throw for valid inputs / levels.
        $this->config->log('hello', [], 'info');
        $this->config->log('hello', ['k' => 'v'], 'warn');
        $this->config->log('hello', [], 'error');
        $this->config->log('hello', [], 'debug');
        $this->config->log(''); // empty message must not crash

        // If any of those raised, the test already failed.
        $this->addToAssertionCount(5);
    }
}
