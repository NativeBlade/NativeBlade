<?php

namespace NativeBlade\Testing;

/**
 * Thrown by the fake at the native call the runtime would exit on. The real
 * runtime calls exit(0) there, which nothing can catch; this is an Error, not
 * an Exception, so the usual `catch (\Exception $e)` in app code lets it
 * through the same way.
 */
final class BridgePending extends \Error
{
    public function __construct()
    {
        parent::__construct('NativeBlade fake: the request exits here and is re-run once the native call completes.');
    }
}
