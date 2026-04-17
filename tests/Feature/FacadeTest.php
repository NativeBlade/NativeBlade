<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use NativeBlade\Facades\NativeBlade as NativeBladeFacade;
use NativeBlade\NativeResponse;
use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The NativeBlade facade is the primary public API. It resolves the
 * 'nativeblade' singleton (a ShellConfig) and forwards every action method
 * through ShellConfig::__call to NativeResponse.
 */
final class FacadeTest extends TestCase
{
    #[Test]
    public function facade_resolves_to_a_shell_config_instance(): void
    {
        $instance = NativeBladeFacade::getFacadeRoot();
        self::assertInstanceOf(ShellConfig::class, $instance);
    }

    #[Test]
    public function facade_returns_the_same_instance_across_calls(): void
    {
        $a = NativeBladeFacade::getFacadeRoot();
        $b = NativeBladeFacade::getFacadeRoot();
        self::assertSame($a, $b, 'NativeBlade must be bound as a singleton.');
    }

    #[Test]
    public function facade_vibrate_returns_native_response(): void
    {
        $response = NativeBladeFacade::vibrate(200);

        self::assertInstanceOf(NativeResponse::class, $response);
        $actions = $response->toArray();
        self::assertSame('vibrate', $actions[0]['action']);
        self::assertSame(200, $actions[0]['data']['duration']);
    }

    #[Test]
    public function facade_alert_forwards_builder_closure(): void
    {
        $response = NativeBladeFacade::alert(function ($dialog) {
            $dialog->title('Facade')->message('ok');
        });

        $actions = $response->toArray();
        self::assertSame('alert', $actions[0]['action']);
        self::assertSame('Facade', $actions[0]['data']['title']);
    }

    #[Test]
    public function facade_response_returns_empty_native_response(): void
    {
        $response = NativeBladeFacade::response();

        self::assertInstanceOf(NativeResponse::class, $response);
        self::assertSame([], $response->toArray());
    }

    #[Test]
    public function facade_platform_returns_string(): void
    {
        $platform = NativeBladeFacade::platform();
        self::assertIsString($platform);
    }

    #[Test]
    public function facade_state_methods_are_wired_to_shell_config(): void
    {
        NativeBladeFacade::setState('facade.key', 'facade.value');

        self::assertSame('facade.value', NativeBladeFacade::getState('facade.key'));
        self::assertArrayHasKey('facade.key', NativeBladeFacade::state());

        NativeBladeFacade::forget('facade.key');
        self::assertNull(NativeBladeFacade::getState('facade.key'));
    }
}
