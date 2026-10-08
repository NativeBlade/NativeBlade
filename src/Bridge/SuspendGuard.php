<?php

namespace NativeBlade\Bridge;

/**
 * Refuses a native call from inside code the runtime cannot pause in.
 *
 * With JSPI any PHP stack can be suspended. With Asyncify (the build used
 * where the WebView has no JSPI, WebKit on iOS and macOS today) only the
 * instrumented C paths can; a suspension inside a user callback invoked by
 * one of the functions below kills the PHP instance ("unreachable") or hangs
 * it. Checking the PHP stack before pausing turns that into an ordinary
 * exception, the same on every platform and in PHPUnit, with a message that
 * says what to move.
 *
 * The lists come from a sweep of callback-taking constructs on the Asyncify
 * build of @php-wasm/web 3.1.57 (PHP 8.5.10): a native call was made inside
 * each one and the outcome recorded. Re-run the sweep when php-wasm changes.
 * Verified fine there, so not listed: array_map, array_filter, array_reduce,
 * call_user_func, call_user_func_array, iterator_apply, ReflectionMethod,
 * Closure::call, generators, __toString, __get, __destruct, IteratorAggregate,
 * catch and finally blocks.
 */
final class SuspendGuard
{
    public const MEASURED_ON = '@php-wasm/web 3.1.57 (PHP 8.5.10), asyncify build';

    /**
     * Internal functions whose user callback cannot contain a native call.
     *
     * @var array<string, string> function => what happened in the sweep
     */
    public const UNPAUSABLE_FUNCTIONS = [
        'usort' => 'crash',
        'uasort' => 'crash',
        'uksort' => 'crash',
        'array_walk' => 'crash',
        'array_walk_recursive' => 'crash',
        'array_udiff' => 'crash',
        // Same comparison path as array_udiff, not swept one by one.
        'array_udiff_assoc' => 'crash',
        'array_udiff_uassoc' => 'crash',
        'array_uintersect' => 'crash',
        'array_uintersect_assoc' => 'crash',
        'array_uintersect_uassoc' => 'crash',
        'array_diff_ukey' => 'crash',
        'array_diff_uassoc' => 'crash',
        'array_intersect_ukey' => 'crash',
        'array_intersect_uassoc' => 'crash',
        'json_encode' => 'crash',           // JsonSerializable::jsonSerialize
        'count' => 'crash',                 // Countable::count
        'preg_replace_callback' => 'hang',
        'preg_replace_callback_array' => 'hang',
        'ob_start' => 'crash',              // the output handler, when flushed
        'ob_end_flush' => 'crash',
        'ob_get_flush' => 'crash',
        'ob_end_clean' => 'crash',
        'ob_get_clean' => 'crash',
        'ob_flush' => 'crash',
        'trigger_error' => 'hang',          // a user error handler
        'spl_autoload_call' => 'freeze',    // an autoloader
        'class_exists' => 'freeze',
        'interface_exists' => 'freeze',
        'trait_exists' => 'freeze',
        'enum_exists' => 'freeze',
    ];

    /** @var array<string, string> Class::method => what happened in the sweep */
    public const UNPAUSABLE_METHODS = [
        'ReflectionFunction::invoke' => 'crash',
        'ReflectionFunction::invokeArgs' => 'crash',
    ];

    /**
     * @throws NativeCallNotAllowed when the current PHP stack cannot be paused
     */
    public static function assertCanSuspend(string $call): void
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $function = $frame['function'] ?? '';
            if ($function === '') {
                continue;
            }

            if (isset($frame['class'])) {
                $qualified = $frame['class'] . '::' . $function;
                if (isset(self::UNPAUSABLE_METHODS[$qualified])) {
                    throw NativeCallNotAllowed::inside($call, $qualified . '()');
                }
                continue;
            }

            if (isset(self::UNPAUSABLE_FUNCTIONS[$function])) {
                throw NativeCallNotAllowed::inside($call, $function . '()');
            }
        }
    }
}
