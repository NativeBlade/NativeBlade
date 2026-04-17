<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Nfc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NfcTest extends TestCase
{
    #[Test]
    public function default_payload_is_empty(): void
    {
        self::assertSame([], (new Nfc())->toArray());
    }

    #[Test]
    public function setter_is_chainable(): void
    {
        $nfc = new Nfc();
        self::assertSame($nfc, $nfc->id('ticket'));
    }

    #[Test]
    public function it_includes_the_id_when_set(): void
    {
        self::assertSame(
            ['id' => 'ticket'],
            (new Nfc())->id('ticket')->toArray(),
        );
    }
}
