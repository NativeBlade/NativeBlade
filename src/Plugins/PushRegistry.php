<?php

namespace NativeBlade\Plugins;

use Closure;

/**
 * Static registry for push notification callbacks.
 *
 * Populated during service provider boot when the developer calls
 * `->notification(function ($push) { $push->onReceive(...); })` on
 * an `AndroidConfig` or `IosConfig` builder. Consulted by the
 * `/_nativeblade/push` and `/_nativeblade/push-token` HTTP routes
 * every time the native plugin layer forwards an event.
 *
 * Only one callback of each type is ever registered — the two push
 * config builders (Android + iOS) feed into the same registry because
 * the app only runs on one platform at a time, so platform-specific
 * registration would be redundant.
 */
class PushRegistry
{
    /** @var Closure(PushPayload): mixed|null */
    private static ?Closure $onReceive = null;

    /** @var Closure(string): mixed|null */
    private static ?Closure $onTokenRefresh = null;

    public static function setOnReceive(Closure $callback): void
    {
        self::$onReceive = $callback;
    }

    public static function setOnTokenRefresh(Closure $callback): void
    {
        self::$onTokenRefresh = $callback;
    }

    public static function handleReceive(PushPayload $payload): mixed
    {
        $callback = self::$onReceive;
        return $callback ? $callback($payload) : null;
    }

    public static function handleTokenRefresh(string $token): mixed
    {
        $callback = self::$onTokenRefresh;
        return $callback ? $callback($token) : null;
    }

    public static function reset(): void
    {
        self::$onReceive = null;
        self::$onTokenRefresh = null;
    }
}
