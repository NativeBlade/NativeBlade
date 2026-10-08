<?php

namespace NativeBlade\Bridge;

/**
 * A native call (HTTP, native database, native filesystem) was made from
 * inside a callback the runtime cannot pause in. See SuspendGuard.
 */
final class NativeCallNotAllowed extends NativeBridgeException
{
    public static function inside(string $call, string $where): self
    {
        $what = match ($call) {
            'http', 'http_pool' => 'An HTTP request',
            'db' => 'A query on the native database',
            'fs' => 'A native filesystem operation',
            default => "A native call ({$call})",
        };

        return new self(
            "{$what} was made inside {$where}. The runtime cannot pause PHP there, so the call is refused "
            . 'on every platform instead of crashing on iOS and macOS. Move it out of the callback: collect '
            . 'the data first (the responses, the rows, the files), then sort, encode or walk the result.'
        );
    }
}
