<?php

namespace NativeBlade\Support;

/**
 * Where the shell writes nativeblade.log: Tauri's app log directory, which
 * depends on the OS and the app identifier. Mirrors `app_log_dir()` in
 * tauri/src/path/desktop.rs.
 */
class LogFile
{
    public const NAME = 'nativeblade.log';

    /**
     * @param  string  $identifier  bundle identifier (com.example.app)
     * @param  string  $osFamily    PHP_OS_FAMILY: Windows, Darwin or Linux
     * @param  array<string, string>  $env  environment (LOCALAPPDATA, XDG_DATA_HOME, HOME)
     */
    public static function desktopPath(string $identifier, string $osFamily, array $env): string
    {
        $home = $env['HOME'] ?? $env['USERPROFILE'] ?? '~';

        return match ($osFamily) {
            'Windows' => ($env['LOCALAPPDATA'] ?? $home . '\\AppData\\Local') . '\\' . $identifier . '\\logs\\' . self::NAME,
            'Darwin' => $home . '/Library/Logs/' . $identifier . '/' . self::NAME,
            default => ($env['XDG_DATA_HOME'] ?? $home . '/.local/share') . '/' . $identifier . '/logs/' . self::NAME,
        };
    }
}
