<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use NativeBlade\Config\Window;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Window::position() takes either exact pixels or a named anchor. Anchors are
 * carried as `positionAnchor` (+ optional `positionMargin`) so the JS side can
 * resolve them against the real monitor at open time, freeing PHP from measuring
 * the screen.
 */
final class WindowConfigTest extends TestCase
{
    #[Test]
    public function exact_pixels_store_x_and_y(): void
    {
        $config = (new Window())->position(650, 948)->toArray();

        self::assertSame(650, $config['x']);
        self::assertSame(948, $config['y']);
        self::assertArrayNotHasKey('positionAnchor', $config);
    }

    #[Test]
    public function an_anchor_stores_positionAnchor_and_no_coordinates(): void
    {
        $config = (new Window())->position('bottom-center')->toArray();

        self::assertSame('bottom-center', $config['positionAnchor']);
        self::assertArrayNotHasKey('x', $config);
        self::assertArrayNotHasKey('y', $config);
        self::assertArrayNotHasKey('positionMargin', $config);
    }

    #[Test]
    public function a_margin_is_carried_only_when_non_zero(): void
    {
        $config = (new Window())->position('bottom-center', margin: 12)->toArray();
        self::assertSame(12, $config['positionMargin']);

        $noMargin = (new Window())->position('top-left')->toArray();
        self::assertArrayNotHasKey('positionMargin', $noMargin);
    }

    #[Test]
    public function an_unknown_anchor_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Window())->position('middle-of-nowhere');
    }
}
