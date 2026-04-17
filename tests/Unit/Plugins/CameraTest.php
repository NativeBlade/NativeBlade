<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Camera;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CameraTest extends TestCase
{
    #[Test]
    public function defaults_match_documented_values(): void
    {
        $payload = (new Camera())->toArray();

        self::assertSame(800, $payload['maxWidth']);
        self::assertSame(800, $payload['maxHeight']);
        self::assertSame(0.8, $payload['quality']);
        self::assertArrayNotHasKey('id', $payload);
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $camera = new Camera();

        self::assertSame($camera, $camera->maxWidth(1024));
        self::assertSame($camera, $camera->maxHeight(768));
        self::assertSame($camera, $camera->quality(0.5));
        self::assertSame($camera, $camera->id('avatar'));
    }

    #[Test]
    public function it_overrides_every_field(): void
    {
        $payload = (new Camera())
            ->maxWidth(1920)
            ->maxHeight(1080)
            ->quality(0.95)
            ->id('hd-capture')
            ->toArray();

        self::assertSame([
            'maxWidth' => 1920,
            'maxHeight' => 1080,
            'quality' => 0.95,
            'id' => 'hd-capture',
        ], $payload);
    }

    #[Test]
    public function quality_accepts_floats_in_the_zero_to_one_range(): void
    {
        $payload = (new Camera())->quality(0.0)->toArray();
        self::assertSame(0.0, $payload['quality']);

        $payload = (new Camera())->quality(1.0)->toArray();
        self::assertSame(1.0, $payload['quality']);
    }
}
