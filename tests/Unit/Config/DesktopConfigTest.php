<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Config;

use InvalidArgumentException;
use NativeBlade\Config\DesktopConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DesktopConfig::position() accepts either exact pixel coordinates or a named
 * anchor, and the two are mutually exclusive within the builder.
 */
final class DesktopConfigTest extends TestCase
{
    #[Test]
    public function position_with_two_ints_stores_exact_coordinates(): void
    {
        $config = (new DesktopConfig())->position(100, 80)->toArray();

        self::assertSame(100, $config['x']);
        self::assertSame(80, $config['y']);
        self::assertArrayNotHasKey('positionAnchor', $config);
    }

    #[Test]
    public function position_with_a_string_stores_a_named_anchor(): void
    {
        $config = (new DesktopConfig())->position('bottom-right')->toArray();

        self::assertSame('bottom-right', $config['positionAnchor']);
        self::assertArrayNotHasKey('x', $config);
        self::assertArrayNotHasKey('y', $config);
    }

    #[Test]
    public function switching_to_an_anchor_clears_prior_coordinates(): void
    {
        $config = (new DesktopConfig())->position(10, 20)->position('center')->toArray();

        self::assertSame('center', $config['positionAnchor']);
        self::assertArrayNotHasKey('x', $config);
        self::assertArrayNotHasKey('y', $config);
    }

    #[Test]
    public function switching_to_coordinates_clears_a_prior_anchor(): void
    {
        $config = (new DesktopConfig())->position('top-left')->position(10, 20)->toArray();

        self::assertSame(10, $config['x']);
        self::assertSame(20, $config['y']);
        self::assertArrayNotHasKey('positionAnchor', $config);
    }

    #[Test]
    public function every_documented_anchor_is_accepted(): void
    {
        $anchors = [
            'center', 'top-left', 'top-center', 'top-right',
            'bottom-left', 'bottom-center', 'bottom-right',
        ];

        foreach ($anchors as $anchor) {
            $config = (new DesktopConfig())->position($anchor)->toArray();
            self::assertSame($anchor, $config['positionAnchor'], "anchor '{$anchor}' should be accepted");
        }
    }

    #[Test]
    public function an_unknown_anchor_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid window anchor 'middle-earth'");

        (new DesktopConfig())->position('middle-earth');
    }

    #[Test]
    public function a_single_integer_without_the_second_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires both coordinates');

        (new DesktopConfig())->position(100);
    }
}
