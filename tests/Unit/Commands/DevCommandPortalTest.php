<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Commands;

use NativeBlade\Commands\DevCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Pins the public surface of `nativeblade:dev --platform=portal`.
 *
 * The full happy-path spawns vite in foreground, so we only assert the shape
 * and wiring here: the platform is advertised, the dispatch target exists,
 * and the signature that runPortal exposes to the command body is stable.
 */
final class DevCommandPortalTest extends TestCase
{
    #[Test]
    public function platform_option_documents_portal(): void
    {
        $cmd = new DevCommand();
        $ref = new ReflectionProperty(DevCommand::class, 'signature');
        $ref->setAccessible(true);

        self::assertStringContainsString('portal', (string) $ref->getValue($cmd));
    }

    #[Test]
    public function runPortal_exists_and_is_private(): void
    {
        self::assertTrue(method_exists(DevCommand::class, 'runPortal'));
        $method = new ReflectionMethod(DevCommand::class, 'runPortal');
        self::assertTrue($method->isPrivate(), 'runPortal must stay internal to the command');
    }

    #[Test]
    public function runPortal_accepts_host_and_port_strings(): void
    {
        $method = new ReflectionMethod(DevCommand::class, 'runPortal');
        $params = $method->getParameters();

        self::assertCount(2, $params);
        self::assertSame('host', $params[0]->getName());
        self::assertSame('port', $params[1]->getName());

        $hostType = $params[0]->getType();
        $portType = $params[1]->getType();
        self::assertNotNull($hostType);
        self::assertNotNull($portType);
        self::assertSame('string', (string) $hostType);
        self::assertSame('string', (string) $portType);
    }

    #[Test]
    public function printQR_exists_and_is_private(): void
    {
        self::assertTrue(method_exists(DevCommand::class, 'printQR'));
        $method = new ReflectionMethod(DevCommand::class, 'printQR');
        self::assertTrue($method->isPrivate());
    }

    #[Test]
    public function handle_dispatches_portal_platform(): void
    {
        // The match arm in handle() is the only entry point for portal mode.
        // If this assertion fails, we've lost the dispatch wiring.
        $source = file_get_contents((new \ReflectionClass(DevCommand::class))->getFileName());
        self::assertNotFalse($source);
        self::assertMatchesRegularExpression(
            "/'portal'\\s*=>\\s*\\\$this->runPortal/",
            $source,
            "handle() must dispatch 'portal' to runPortal in the match statement"
        );
    }
}
