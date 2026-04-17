<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Shell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShellTest extends TestCase
{
    #[Test]
    public function defaults_produce_a_captured_execution_payload(): void
    {
        $payload = (new Shell())->toArray();

        self::assertNull($payload['command']);
        self::assertFalse($payload['openTerminal']);
        self::assertArrayNotHasKey('id', $payload);
        self::assertArrayNotHasKey('cwd', $payload);
        self::assertArrayNotHasKey('env', $payload);
        self::assertArrayNotHasKey('timeout', $payload);
        self::assertArrayNotHasKey('terminalType', $payload);
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $shell = new Shell();

        self::assertSame($shell, $shell->id('ls'));
        self::assertSame($shell, $shell->run('ls -la'));
        self::assertSame($shell, $shell->cwd('/tmp'));
        self::assertSame($shell, $shell->env(['FOO' => 'bar']));
        self::assertSame($shell, $shell->timeout(5));
        self::assertSame($shell, $shell->openTerminal());
    }

    #[Test]
    public function it_forwards_every_captured_field(): void
    {
        $payload = (new Shell())
            ->id('lint')
            ->run('php -l file.php')
            ->cwd('/var/www')
            ->env(['APP_ENV' => 'testing'])
            ->timeout(30)
            ->toArray();

        self::assertSame([
            'command' => 'php -l file.php',
            'openTerminal' => false,
            'id' => 'lint',
            'cwd' => '/var/www',
            'env' => ['APP_ENV' => 'testing'],
            'timeout' => 30,
        ], $payload);
    }

    #[Test]
    public function open_terminal_without_type_flips_the_flag_only(): void
    {
        $payload = (new Shell())
            ->run('npm run dev')
            ->openTerminal()
            ->toArray();

        self::assertTrue($payload['openTerminal']);
        self::assertArrayNotHasKey('terminalType', $payload);
    }

    #[Test]
    public function open_terminal_accepts_a_windows_preference(): void
    {
        foreach (['wt', 'cmd', 'powershell'] as $type) {
            $payload = (new Shell())
                ->run('dir')
                ->openTerminal($type)
                ->toArray();

            self::assertTrue($payload['openTerminal']);
            self::assertSame($type, $payload['terminalType']);
        }
    }

    #[Test]
    public function empty_env_array_is_not_emitted(): void
    {
        $payload = (new Shell())->env([])->toArray();

        self::assertArrayNotHasKey('env', $payload);
    }
}
