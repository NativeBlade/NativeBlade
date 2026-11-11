<?php

namespace NativeBlade\Doctor;

use NativeBlade\Bridge\SuspendGuard;
use PhpToken;

/**
 * Finds PHP that php-wasm cannot run or cannot pause.
 *
 * Three kinds of finding:
 *
 * - "suspension": a call that pauses PHP inside php-wasm itself (sleep,
 *   sockets, processes). NativeBlade's SuspendGuard only covers its own
 *   native calls, so on the Asyncify build these end the instance with
 *   "memory access out of bounds" or run the request twice. Laravel APIs
 *   that sleep underneath (Http::retry with a delay, a lock's block(), a
 *   lazy collection's throttle(), the Process facade) are reported too.
 * - "callback": a native call (Http::, NativeBlade::, Storage::, DB::)
 *   inside the callback of a function the runtime cannot pause in
 *   (SuspendGuard::UNPAUSABLE_FUNCTIONS). NativeCallNotAllowed is thrown
 *   there at runtime; the scan shows it before the first run.
 *
 * Built on PhpToken (the PHP lexer), not on regular expressions: it tells a
 * function call from a method, a declaration or a string, resolves `use`
 * imports and aliases, and splits call arguments. It sees calls, not control
 * flow or types: a call behind a guard that never runs is still reported,
 * and a method is matched by name, so those are warnings.
 */
final class AsyncifyScanner
{
    private const PAUSES = 'pauses PHP inside php-wasm; the Asyncify build cannot resume from it';
    private const SOCKET = 'opens a socket, which php-wasm polls by pausing PHP; use Http::';
    private const PROCESS = 'starts a process, which php-wasm runs by pausing PHP; there is no process to start inside the app';
    private const CURL = 'curl is not compiled into php-wasm; use Http::';
    private const RETRY = 'with a delay sleeps between attempts, which pauses PHP inside php-wasm; retry with delay 0 or move the wait to the shell';

    /** @var array<string, string> function => why it is a problem */
    public const SUSPENDING_FUNCTIONS = [
        'sleep' => self::PAUSES,
        'usleep' => self::PAUSES,
        'time_nanosleep' => self::PAUSES,
        'time_sleep_until' => self::PAUSES,
        'fsockopen' => self::SOCKET,
        'pfsockopen' => self::SOCKET,
        'stream_socket_client' => self::SOCKET,
        'stream_socket_server' => self::SOCKET,
        'stream_select' => 'polls sockets by pausing PHP inside php-wasm',
        'socket_create' => self::SOCKET,
        'proc_open' => self::PROCESS,
        'popen' => self::PROCESS,
        'exec' => self::PROCESS,
        'shell_exec' => self::PROCESS,
        'passthru' => self::PROCESS,
        'system' => self::PROCESS,
        'curl_exec' => self::CURL,
        'curl_multi_exec' => self::CURL,
    ];

    /**
     * Classes whose static calls (or `new`) pause PHP inside php-wasm.
     * `classes` are the fully qualified names that count; `bare` says whether
     * an unimported short name counts too (Sleep is rarely anything else, a
     * Process model is common).
     *
     * @var array<string, array{classes: string[], bare: bool, message: string}>
     */
    public const SUSPENDING_CLASSES = [
        'Sleep' => [
            'classes' => ['Illuminate\Support\Sleep'],
            'bare' => true,
            'message' => 'Sleep:: ' . self::PAUSES,
        ],
        'Process' => [
            'classes' => ['Illuminate\Support\Facades\Process', 'Illuminate\Process\Factory', 'Symfony\Component\Process\Process'],
            'bare' => false,
            'message' => 'Process ' . self::PROCESS,
        ],
    ];

    /**
     * Methods of Laravel objects that sleep underneath. The receiver's type is
     * not known here, so these are warnings: a method of the same name on
     * another object is fine.
     *
     * @var array<string, string> method => message
     */
    public const SLEEPING_METHODS = [
        'block' => 'block() on a cache lock waits by sleeping, which pauses PHP inside php-wasm; use get() and handle the miss',
        'throttle' => 'throttle() on a lazy collection waits with usleep(), which pauses PHP inside php-wasm',
    ];

    /** Functions whose first argument, when a URL literal, opens a socket. */
    public const URL_OPENERS = ['file_get_contents', 'fopen', 'file', 'readfile', 'get_headers', 'simplexml_load_file'];

    /** Facades whose static calls reach the native bridge. */
    public const NATIVE_FACADES = ['Http', 'NativeBlade', 'Storage', 'DB', 'Schema'];

    private const NAME_TOKENS = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];

    /** @var callable(string): string|null compiles Blade to PHP, when available */
    private $bladeCompiler;

    /** @param  callable(string): string|null  $bladeCompiler */
    public function __construct(?callable $bladeCompiler = null)
    {
        $this->bladeCompiler = $bladeCompiler;
    }

    /**
     * @param  string[]  $paths  files or directories
     * @return list<array{file: string, line: int, rule: string, severity: string, message: string}>
     */
    public function scanPaths(array $paths): array
    {
        $findings = [];
        foreach ($this->files($paths) as $file) {
            $findings = array_merge($findings, $this->scanFile($file));
        }
        usort($findings, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $findings;
    }

    /**
     * The PHP files under the given files or directories, sorted, forward slashes.
     *
     * @param  string[]  $paths
     * @return string[]
     */
    public function files(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $files = array_merge($files, $this->phpFiles($path));
        }

        return array_values(array_unique($files));
    }

    /** @return list<array{file: string, line: int, rule: string, severity: string, message: string}> */
    public function scanFile(string $file): array
    {
        $source = (string) file_get_contents($file);
        if (str_ends_with($file, '.blade.php') && $this->bladeCompiler) {
            try {
                $source = (string) ($this->bladeCompiler)($source);
            } catch (\Throwable) {
                // A view that does not compile is the developer's next error, not this one.
            }
        }

        $findings = [];
        foreach ($this->scanSource($source) as $finding) {
            $findings[] = ['file' => $file] + $finding;
        }

        return $findings;
    }

    /** @return list<array{line: int, rule: string, severity: string, message: string}> */
    public function scanSource(string $source): array
    {
        try {
            $tokens = PhpToken::tokenize($source);
        } catch (\Throwable) {
            return [];
        }

        $imports = $this->imports($tokens);
        $findings = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!$token->is(self::NAME_TOKENS)) {
                continue;
            }

            [$short, $full] = $this->resolveClass($token, $imports);
            $lower = strtolower(ltrim($token->text, '\\'));
            $next = $this->significant($tokens, $i, +1);
            $prev = $this->significant($tokens, $i, -1);
            $prevToken = $prev === null ? null : $tokens[$prev];

            // Class::method(), Class::CONSTANT
            if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
                if ($this->isDeclaration($tokens, $prev)) {
                    continue;
                }
                if ($this->isSuspendingClass($short, $full)) {
                    $findings[] = $this->finding($token->line, 'suspension', 'error', self::SUSPENDING_CLASSES[$short]['message']);
                    continue;
                }
                if ($short === 'Http') {
                    $method = $this->significant($tokens, $next, +1);
                    $open = $method === null ? null : $this->significant($tokens, $method, +1);
                    if ($method !== null && strtolower($tokens[$method]->text) === 'retry'
                        && $open !== null && $tokens[$open]->text === '(' && $this->hasDelay($this->arguments($tokens, $open), 1)) {
                        $findings[] = $this->finding($token->line, 'suspension', 'error', 'Http::retry() ' . self::RETRY);
                    }
                }
                continue;
            }

            // new Process(...)
            if ($prevToken !== null && $prevToken->is(T_NEW)) {
                if ($this->isSuspendingClass($short, $full) && $short === 'Process') {
                    $findings[] = $this->finding($token->line, 'suspension', 'error', 'new Process() ' . self::PROCESS);
                }
                continue;
            }

            if ($next === null || $tokens[$next]->text !== '(') {
                continue;
            }

            // $object->method(...)
            if ($prevToken !== null && $prevToken->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                if (isset(self::SLEEPING_METHODS[$lower])) {
                    $findings[] = $this->finding($token->line, 'suspension', 'warning', self::SLEEPING_METHODS[$lower]);
                } elseif ($lower === 'retry' && $this->hasDelay($this->arguments($tokens, $next), 1)) {
                    $findings[] = $this->finding($token->line, 'suspension', 'warning', 'retry() on an HTTP client ' . self::RETRY);
                }
                continue;
            }

            if ($this->isMethodOrDeclaration($tokens, $prev)) {
                continue;
            }

            // function(...)
            if (isset(self::SUSPENDING_FUNCTIONS[$lower])) {
                $findings[] = $this->finding($token->line, 'suspension', 'error', "{$lower}() " . self::SUSPENDING_FUNCTIONS[$lower]);
                continue;
            }

            $args = $this->arguments($tokens, $next);

            if (($lower === 'call_user_func' || $lower === 'call_user_func_array') && ($callee = $this->stringLiteral($args[0] ?? [])) !== null) {
                $callee = strtolower(ltrim($callee, '\\'));
                if (isset(self::SUSPENDING_FUNCTIONS[$callee])) {
                    $findings[] = $this->finding($token->line, 'suspension', 'error', "{$lower}('{$callee}') " . self::SUSPENDING_FUNCTIONS[$callee]);
                }
                continue;
            }

            if ($lower === 'retry' && $this->hasDelay($args, 2)) {
                $findings[] = $this->finding($token->line, 'suspension', 'warning', 'retry() ' . self::RETRY);
                continue;
            }

            if (in_array($lower, self::URL_OPENERS, true) && $this->firstArgumentIsUrl($args)) {
                $findings[] = $this->finding($token->line, 'suspension', 'error', "{$lower}() on a URL " . self::SOCKET);
                continue;
            }

            if (isset(SuspendGuard::UNPAUSABLE_FUNCTIONS[$lower])) {
                foreach ($args as $arg) {
                    $native = $this->nativeCallIn($arg, $imports);
                    if ($native !== null) {
                        $findings[] = $this->finding($token->line, 'callback', 'warning',
                            "{$native} inside the callback of {$lower}(): the runtime cannot pause there and NativeCallNotAllowed is thrown; collect the data before calling {$lower}()");
                        break;
                    }
                }
            }
        }

        return $findings;
    }

    /** @return array{line: int, rule: string, severity: string, message: string} */
    private function finding(int $line, string $rule, string $severity, string $message): array
    {
        return ['line' => $line, 'rule' => $rule, 'severity' => $severity, 'message' => $message];
    }

    private static function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /**
     * The `use` imports of the file: short name or alias => fully qualified name.
     * Handles `use A\B;`, `use A\B as C;`, comma lists and group syntax
     * `use A\{B, C as D};`. Closure `use (...)`, `use function` and
     * `use const` are skipped.
     *
     * @param  PhpToken[]  $tokens
     * @return array<string, string>
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_USE)) {
                continue;
            }
            $j = $this->significant($tokens, $i, +1);
            if ($j === null || $tokens[$j]->text === '(' || $tokens[$j]->is([T_FUNCTION, T_CONST])) {
                continue;
            }

            $prefix = '';
            $full = null;
            for (; $j < $count && $tokens[$j]->text !== ';'; $j++) {
                $t = $tokens[$j];
                if ($t->is(self::NAME_TOKENS)) {
                    $full = $prefix . ltrim($t->text, '\\');
                    $imports[self::shortName($full)] = $full;
                } elseif ($t->is(T_NS_SEPARATOR) && $full !== null && $prefix === '') {
                    // `use A\B\{...}`: the name before the brace is the prefix.
                    $prefix = $full . '\\';
                    unset($imports[self::shortName($full)]);
                } elseif ($t->is(T_AS) && $full !== null) {
                    $alias = $this->significant($tokens, $j, +1);
                    if ($alias !== null && $tokens[$alias]->is(T_STRING)) {
                        unset($imports[self::shortName($full)]);
                        $imports[$tokens[$alias]->text] = $full;
                        $j = $alias;
                    }
                } elseif ($t->text === '}') {
                    $prefix = '';
                }
            }
        }

        return $imports;
    }

    /**
     * The short name a class token is known by in the file (an alias counts)
     * and its fully qualified name when the file says it (qualified, or
     * imported), null otherwise.
     *
     * @param  array<string, string>  $imports
     * @return array{string, string|null}
     */
    private function resolveClass(PhpToken $token, array $imports): array
    {
        $text = $token->text;
        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            $full = ltrim($text, '\\');

            return [self::shortName($full), $full];
        }
        if ($token->is(T_NAME_QUALIFIED)) {
            $first = strstr($text, '\\', true);
            $full = isset($imports[$first]) ? $imports[$first] . substr($text, strlen($first)) : $text;

            return [self::shortName($full), $full];
        }
        if (isset($imports[$text])) {
            return [self::shortName($imports[$text]), $imports[$text]];
        }

        return [$text, null];
    }

    private function isSuspendingClass(string $short, ?string $full): bool
    {
        $rule = self::SUSPENDING_CLASSES[$short] ?? null;
        if ($rule === null) {
            return false;
        }
        if ($full === null) {
            return $rule['bare'];
        }

        return in_array($full, $rule['classes'], true);
    }

    /**
     * Whether the argument at $index is present and is not the literal 0.
     *
     * @param  list<PhpToken[]>  $args
     */
    private function hasDelay(array $args, int $index): bool
    {
        $arg = $args[$index] ?? null;
        if ($arg === null || $arg === []) {
            return false;
        }

        return !(count($arg) === 1 && $arg[0]->is(T_LNUMBER) && (int) $arg[0]->text === 0);
    }

    /** @param  PhpToken[]  $arg */
    private function stringLiteral(array $arg): ?string
    {
        if (count($arg) !== 1 || !$arg[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        return substr($arg[0]->text, 1, -1);
    }

    /** @param  PhpToken[]  $tokens */
    private function significant(array $tokens, int $from, int $step): ?int
    {
        $count = count($tokens);
        for ($i = $from + $step; $i >= 0 && $i < $count; $i += $step) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param  PhpToken[]  $tokens */
    private function isMethodOrDeclaration(array $tokens, ?int $prev): bool
    {
        if ($prev === null) {
            return false;
        }

        return $tokens[$prev]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]);
    }

    /** @param  PhpToken[]  $tokens */
    private function isDeclaration(array $tokens, ?int $prev): bool
    {
        return $prev !== null && $tokens[$prev]->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NEW, T_USE, T_NAMESPACE]);
    }

    /**
     * Split the arguments of a call whose opening parenthesis is at $open.
     *
     * @param  PhpToken[]  $tokens
     * @return list<PhpToken[]> one token list per top-level argument, ignorable tokens removed
     */
    private function arguments(array $tokens, int $open): array
    {
        $args = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = $token->text;

            if ($text === '(' || $text === '[' || $text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    if ($current !== []) {
                        $args[] = $current;
                    }

                    return $args;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = $current;
                $current = [];
                continue;
            }

            if ($depth >= 1 && !$token->isIgnorable()) {
                $current[] = $token;
            }
        }

        return $args;
    }

    /** @param  list<PhpToken[]>  $args */
    private function firstArgumentIsUrl(array $args): bool
    {
        $first = $args[0][0] ?? null;
        if ($first === null) {
            return false;
        }
        if ($first->is(T_CONSTANT_ENCAPSED_STRING)) {
            return (bool) preg_match('/^[\'"]https?:\/\//i', $first->text);
        }
        if ($first->text === '"' && isset($args[0][1]) && $args[0][1]->is(T_ENCAPSED_AND_WHITESPACE)) {
            return (bool) preg_match('/^https?:\/\//i', $args[0][1]->text);
        }

        return false;
    }

    /**
     * The native call inside a closure argument, or null when the argument is
     * not a closure or makes none.
     *
     * @param  PhpToken[]  $arg
     * @param  array<string, string>  $imports
     */
    private function nativeCallIn(array $arg, array $imports): ?string
    {
        $start = isset($arg[0]) && $arg[0]->is(T_STATIC) ? 1 : 0;
        if (!isset($arg[$start]) || !$arg[$start]->is([T_FUNCTION, T_FN])) {
            return null;
        }

        $count = count($arg);
        for ($i = $start + 1; $i < $count; $i++) {
            $token = $arg[$i];
            if (!$token->is(self::NAME_TOKENS)) {
                continue;
            }
            $next = $arg[$i + 1] ?? null;
            if ($next === null) {
                continue;
            }

            [$short] = $this->resolveClass($token, $imports);
            if ($next->is(T_DOUBLE_COLON) && in_array($short, self::NATIVE_FACADES, true)) {
                $method = $arg[$i + 2] ?? null;

                return $short . '::' . ($method !== null && $method->is(T_STRING) ? $method->text . '()' : '');
            }
            $lower = strtolower(ltrim($token->text, '\\'));
            if ($next->text === '(' && isset(self::SUSPENDING_FUNCTIONS[$lower])) {
                return $lower . '()';
            }
        }

        return null;
    }

    /** @return string[] */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? [str_replace('\\', '/', $path)] : [];
        }
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
                $files[] = str_replace('\\', '/', $entry->getPathname());
            }
        }
        sort($files);

        return $files;
    }
}
