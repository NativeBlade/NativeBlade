<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use NativeBlade\Plugins\DeepLinkRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeepLinkRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        DeepLinkRegistry::reset();
    }

    #[Test]
    public function handle_returns_null_when_no_handler_is_registered(): void
    {
        DeepLinkRegistry::reset();
        self::assertNull(DeepLinkRegistry::handle('https://myapp.com/x'));
    }

    #[Test]
    public function handle_invokes_the_registered_handler_with_the_url(): void
    {
        $seen = null;
        DeepLinkRegistry::setOnLink(function (string $url) use (&$seen) {
            $seen = $url;
            return 'routed';
        });

        $result = DeepLinkRegistry::handle('https://myapp.com/lesson/3');

        self::assertSame('https://myapp.com/lesson/3', $seen);
        self::assertSame('routed', $result);
    }
}
