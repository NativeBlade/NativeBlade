<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Scan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScanTest extends TestCase
{
    #[Test]
    public function default_accepts_all_formats(): void
    {
        $payload = (new Scan())->toArray();

        self::assertSame([], $payload['formats']);
        self::assertArrayNotHasKey('id', $payload);
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $scan = new Scan();

        self::assertSame($scan, $scan->formats(['QR_CODE']));
        self::assertSame($scan, $scan->id('invite'));
    }

    #[Test]
    public function it_forwards_the_format_list(): void
    {
        $payload = (new Scan())
            ->formats(['QR_CODE', 'EAN_13', 'CODE_128'])
            ->id('product')
            ->toArray();

        self::assertSame([
            'formats' => ['QR_CODE', 'EAN_13', 'CODE_128'],
            'id' => 'product',
        ], $payload);
    }
}
