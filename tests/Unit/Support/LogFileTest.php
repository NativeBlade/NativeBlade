<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Support;

use NativeBlade\Support\LogFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LogFile::desktopPath must match Tauri's app_log_dir() per OS, since the shell
 * writes nativeblade.log there and `nativeblade:logs` reads it back.
 */
final class LogFileTest extends TestCase
{
    #[Test]
    public function windows_uses_local_app_data(): void
    {
        self::assertSame(
            'C:\Users\me\AppData\Local\com.example.app\logs\nativeblade.log',
            LogFile::desktopPath('com.example.app', 'Windows', ['LOCALAPPDATA' => 'C:\Users\me\AppData\Local'])
        );
    }

    #[Test]
    public function macos_uses_library_logs(): void
    {
        self::assertSame(
            '/Users/me/Library/Logs/com.example.app/nativeblade.log',
            LogFile::desktopPath('com.example.app', 'Darwin', ['HOME' => '/Users/me'])
        );
    }

    #[Test]
    public function linux_uses_xdg_data_home_with_a_fallback(): void
    {
        self::assertSame(
            '/data/com.example.app/logs/nativeblade.log',
            LogFile::desktopPath('com.example.app', 'Linux', ['HOME' => '/home/me', 'XDG_DATA_HOME' => '/data'])
        );
        self::assertSame(
            '/home/me/.local/share/com.example.app/logs/nativeblade.log',
            LogFile::desktopPath('com.example.app', 'Linux', ['HOME' => '/home/me'])
        );
    }
}
