<?php

declare(strict_types=1);

namespace NativeBlade\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Render a QR code as an array of console-styled strings.
 *
 * Each module is printed as two characters wide (using Symfony console bg tags
 * "<bg=white>  </>" / "<bg=black>  </>") so the aspect ratio stays roughly
 * square in terminals where each cell is about 2:1 tall-to-wide.
 *
 * The renderer is intentionally dependency-light and side-effect free: it
 * returns the lines without printing anything, so the caller decides how to
 * present them (stdout, log, stored snapshot, test assertion).
 */
final class TerminalQrCode
{
    public const CELL_LIGHT = '<bg=white>  </>';

    public const CELL_DARK = '<bg=black>  </>';

    /**
     * @return array<int, string>
     */
    public static function render(string $content, int $quietZone = 2): array
    {
        if ($quietZone < 0) {
            $quietZone = 0;
        }

        $qrCode = Encoder::encode($content, ErrorCorrectionLevel::L());
        $matrix = $qrCode->getMatrix();
        $width = $matrix->getWidth();
        $height = $matrix->getHeight();

        $totalWidth = $width + $quietZone * 2;
        $quietRow = str_repeat(self::CELL_LIGHT, $totalWidth);

        $lines = [];

        for ($i = 0; $i < $quietZone; $i++) {
            $lines[] = $quietRow;
        }

        for ($y = 0; $y < $height; $y++) {
            $line = str_repeat(self::CELL_LIGHT, $quietZone);
            for ($x = 0; $x < $width; $x++) {
                $line .= $matrix->get($x, $y) ? self::CELL_DARK : self::CELL_LIGHT;
            }
            $line .= str_repeat(self::CELL_LIGHT, $quietZone);
            $lines[] = $line;
        }

        for ($i = 0; $i < $quietZone; $i++) {
            $lines[] = $quietRow;
        }

        return $lines;
    }
}
