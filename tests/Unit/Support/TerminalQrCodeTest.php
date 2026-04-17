<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Support;

use NativeBlade\Support\TerminalQrCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TerminalQrCode renders QR bitmaps into console-styled strings for the
 * `nativeblade:dev --platform=portal` banner. The tests pin the shape of the
 * output (uniform width, expected tag vocabulary, quiet zone sizing) without
 * asserting the exact QR bits, which are owned by bacon/bacon-qr-code.
 */
final class TerminalQrCodeTest extends TestCase
{
    #[Test]
    public function render_returns_non_empty_array_for_valid_input(): void
    {
        $lines = TerminalQrCode::render('http://192.168.1.42:1420');

        self::assertNotEmpty($lines);
        self::assertContainsOnly('string', $lines);
    }

    #[Test]
    public function every_line_has_the_same_visible_width(): void
    {
        $lines = TerminalQrCode::render('https://example.com');

        $widths = array_unique(array_map(
            static fn (string $line) => self::countCells($line),
            $lines
        ));

        self::assertCount(1, $widths, 'All rows must share the same cell count so the QR is a rectangle');
    }

    #[Test]
    public function quiet_zone_rows_contain_only_light_cells(): void
    {
        $lines = TerminalQrCode::render('nb');

        // Default quiet zone is 2 → first two and last two rows are all-light.
        self::assertStringNotContainsString(TerminalQrCode::CELL_DARK, $lines[0]);
        self::assertStringNotContainsString(TerminalQrCode::CELL_DARK, $lines[1]);
        self::assertStringNotContainsString(TerminalQrCode::CELL_DARK, end($lines));
    }

    #[Test]
    public function disabling_quiet_zone_shrinks_the_output(): void
    {
        $with = TerminalQrCode::render('nb', 2);
        $without = TerminalQrCode::render('nb', 0);

        self::assertSame(count($with) - 4, count($without));
        self::assertSame(
            self::countCells($with[0]) - 4,
            self::countCells($without[0])
        );
    }

    #[Test]
    public function negative_quiet_zone_is_clamped_to_zero(): void
    {
        $clamped = TerminalQrCode::render('nb', -5);
        $zero = TerminalQrCode::render('nb', 0);

        self::assertSame(count($zero), count($clamped));
    }

    #[Test]
    public function payload_with_longer_content_produces_a_larger_matrix(): void
    {
        $short = TerminalQrCode::render('a');
        $long = TerminalQrCode::render(str_repeat('abcdefgh', 20));

        self::assertGreaterThan(count($short), count($long));
    }

    #[Test]
    public function rendered_lines_only_use_the_expected_cell_tags(): void
    {
        $lines = TerminalQrCode::render('http://portal.local');

        foreach ($lines as $line) {
            // Strip every valid cell occurrence; what's left must be empty.
            $stripped = str_replace(
                [TerminalQrCode::CELL_LIGHT, TerminalQrCode::CELL_DARK],
                '',
                $line
            );
            self::assertSame('', $stripped, 'Lines must contain only CELL_LIGHT or CELL_DARK tokens');
        }
    }

    private static function countCells(string $line): int
    {
        $light = substr_count($line, TerminalQrCode::CELL_LIGHT);
        $dark = substr_count($line, TerminalQrCode::CELL_DARK);
        return $light + $dark;
    }
}
