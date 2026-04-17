<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Geolocation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeolocationTest extends TestCase
{
    #[Test]
    public function default_payload_is_empty(): void
    {
        self::assertSame([], (new Geolocation())->toArray());
    }

    #[Test]
    public function setter_is_chainable(): void
    {
        $geo = new Geolocation();
        self::assertSame($geo, $geo->id('delivery'));
    }

    #[Test]
    public function it_includes_the_id_when_set(): void
    {
        self::assertSame(
            ['id' => 'delivery'],
            (new Geolocation())->id('delivery')->toArray(),
        );
    }
}
