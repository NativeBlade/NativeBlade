<?php

namespace NativeBlade\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void desktop(callable $callback)
 * @method static void android(callable $callback)
 * @method static void ios(callable $callback)
 * @method static void plugins(array $plugins)
 * @method static static onBoot(callable $callback)
 * @method static static transition(string $type = 'fade')
 * @method static array getAppConfigs()
 * @method static array getVersion(string $platform)
 * @method static string getTransition()
 */
class NativeBladeConfig extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nativeblade';
    }
}
