<?php

namespace NativeBlade\Bridge;

/**
 * The one call from PHP into the shell.
 *
 * php-wasm runs with Asyncify or JSPI, so `post_message_to_js()` suspends the
 * PHP stack while the JavaScript handler does the native work (a fetch, a
 * query through Tauri, a file operation) and resumes it with the reply. The
 * request runs once, top to bottom, exactly as it would on a server; nothing
 * is cached, re-run or replayed.
 *
 * Message: `{"nativeblade": "<type>", ...payload}`.
 * Reply:   `{"ok": true, "result": ...}` or `{"ok": false, "error": "..."}`.
 *
 * Tests install a handler with handleWith(); outside the runtime and without
 * one, every call throws.
 */
final class NativeBridge
{
    /** @var (callable(array<string, mixed>): mixed)|null */
    private static $handler = null;

    /**
     * Answer calls from PHP instead of the shell. The handler receives the
     * message and returns the reply as an array or JSON string. Null restores
     * the runtime function.
     */
    public static function handleWith(?callable $handler): void
    {
        self::$handler = $handler;
    }

    public static function isAvailable(): bool
    {
        return self::$handler !== null || function_exists('post_message_to_js');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @throws NativeBridgeException when the shell reports an error or is not there
     */
    public static function call(string $type, array $payload = []): mixed
    {
        SuspendGuard::assertCanSuspend($type);

        $message = ['nativeblade' => $type] + $payload;

        if (self::$handler !== null) {
            $reply = (self::$handler)($message);
        } elseif (function_exists('post_message_to_js')) {
            $reply = post_message_to_js(json_encode(
                $message,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ));
        } else {
            throw new NativeBridgeException("The native bridge is only available inside the NativeBlade runtime ({$type}).");
        }

        if (is_string($reply)) {
            $reply = $reply === '' ? null : json_decode($reply, true);
        }
        if (!is_array($reply)) {
            throw new NativeBridgeException("The shell did not answer the {$type} call.");
        }
        if (($reply['ok'] ?? null) !== true) {
            throw new NativeBridgeException((string) ($reply['error'] ?? "The {$type} call failed in the shell."));
        }

        return $reply['result'] ?? null;
    }
}
