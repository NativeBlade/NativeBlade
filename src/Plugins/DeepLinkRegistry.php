<?php

namespace NativeBlade\Plugins;

use Closure;

/**
 * Static registry for the universal/app-link handler.
 *
 * Populated during service provider boot when the developer calls
 * `NativeBladeConfig::deepLinks([...], fn ($url) => ...)`. Consulted by the
 * `/_nativeblade/deep-link` HTTP route every time the native layer forwards an
 * incoming https link (both cold start and while the app is running).
 *
 * Only one handler is ever registered, the same way `PushRegistry` works.
 */
class DeepLinkRegistry
{
    /** @var Closure(string): mixed|null */
    private static ?Closure $onLink = null;

    public static function setOnLink(Closure $callback): void
    {
        self::$onLink = $callback;
    }

    public static function handle(string $url): mixed
    {
        $callback = self::$onLink;
        return $callback ? $callback($url) : null;
    }

    public static function reset(): void
    {
        self::$onLink = null;
    }
}
