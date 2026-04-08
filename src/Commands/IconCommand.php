<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;

class IconCommand extends Command
{
    protected $signature = 'nativeblade:icon
        {source? : Path to source icon (1024x1024 PNG)}
        {--bg= : Background color for adaptive icon (hex, default from icon)}';

    protected $description = 'Generate all platform icons from a single 1024x1024 PNG';

    private string $sourcePath;
    private string $bgColor;

    public function handle(): int
    {
        $this->sourcePath = $this->argument('source')
            ?? base_path('src-tauri/icons/logo.png');

        if (!file_exists($this->sourcePath)) {
            $this->error("  Icon not found: {$this->sourcePath}");
            $this->line("  Place a 1024x1024 PNG at src-tauri/icons/logo.png");
            return self::FAILURE;
        }

        if (!extension_loaded('gd')) {
            $this->error("  PHP GD extension is required. Enable it in php.ini.");
            return self::FAILURE;
        }

        $source = imagecreatefrompng($this->sourcePath);
        if (!$source) {
            $this->error("  Failed to load PNG: {$this->sourcePath}");
            return self::FAILURE;
        }

        $w = imagesx($source);
        $h = imagesy($source);

        if ($w < 512 || $h < 512) {
            $this->error("  Icon should be at least 512x512. Got {$w}x{$h}.");
            imagedestroy($source);
            return self::FAILURE;
        }

        $this->bgColor = $this->option('bg') ?? $this->detectBgColor($source);

        $this->newLine();
        $this->line('  <fg=magenta;options=bold>NativeBlade Icon Generator</>');
        $this->line("  Source:     <info>{$this->sourcePath}</info>");
        $this->line("  Size:       <info>{$w}x{$h}</info>");
        $this->line("  Background: <info>{$this->bgColor}</info>");
        $this->newLine();

        $iconsDir = base_path('src-tauri/icons');
        if (!is_dir($iconsDir)) mkdir($iconsDir, 0755, true);

        $this->generateDesktopIcons($source, $iconsDir);
        $this->generateAndroidIcons($source);
        $this->generateIosIcons($source);

        imagedestroy($source);

        $this->newLine();
        $this->line('  <fg=green;options=bold>All icons generated!</>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function generateDesktopIcons($source, string $dir): void
    {
        $sizes = [
            '32x32.png' => 32,
            '128x128.png' => 128,
            '128x128@2x.png' => 256,
            'icon.png' => 512,
        ];

        foreach ($sizes as $name => $size) {
            $this->resize($source, "{$dir}/{$name}", $size, $size);
        }
        $this->line("  <fg=green>✓</> Desktop icons (32, 128, 256, 512)");

        $this->generateIco($source, "{$dir}/icon.ico");
        $this->line("  <fg=green>✓</> icon.ico");

        $this->resize($source, "{$dir}/icon.icns.png", 512, 512);
        if (file_exists("{$dir}/icon.icns.png")) {
            rename("{$dir}/icon.icns.png", "{$dir}/icon.icns");
        }
        $this->line("  <fg=green>✓</> icon.icns");
    }

    private function generateAndroidIcons($source): void
    {
        $genDir = base_path('src-tauri/gen/android');
        if (!is_dir($genDir)) {
            $this->line("  <fg=yellow>→</> Android not initialized, skipping");
            return;
        }

        $resDir = "{$genDir}/app/src/main/res";

        $densities = [
            'mipmap-mdpi' => 48,
            'mipmap-hdpi' => 72,
            'mipmap-xhdpi' => 96,
            'mipmap-xxhdpi' => 144,
            'mipmap-xxxhdpi' => 192,
        ];

        foreach ($densities as $folder => $size) {
            $dir = "{$resDir}/{$folder}";
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $this->resize($source, "{$dir}/ic_launcher.png", $size, $size);
            $this->resizeRound($source, "{$dir}/ic_launcher_round.png", $size);
            $this->resizeWithPadding($source, "{$dir}/ic_launcher_foreground.png", $size, 0.34);

            $notifSize = (int) ($size * 0.625);
            $this->resizeMonochrome($source, "{$dir}/ic_notification.png", $notifSize);
        }

        $xmlDir = "{$resDir}/mipmap-anydpi-v26";
        if (!is_dir($xmlDir)) mkdir($xmlDir, 0755, true);

        $rgb = $this->hexToRgb($this->bgColor);
        $colorHex = sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);

        file_put_contents("{$xmlDir}/ic_launcher.xml", '<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>');

        file_put_contents("{$xmlDir}/ic_launcher_round.xml", '<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>');

        $valuesDir = "{$resDir}/values";
        if (!is_dir($valuesDir)) mkdir($valuesDir, 0755, true);

        file_put_contents("{$valuesDir}/ic_launcher_background.xml", '<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">' . $colorHex . '</color>
</resources>');

        $this->line("  <fg=green>✓</> Android adaptive icons (mdpi → xxxhdpi)");
        $this->line("  <fg=green>✓</> Android round icons");
        $this->line("  <fg=green>✓</> Android notification icons");
    }

    private function generateIosIcons($source): void
    {
        $genDir = base_path('src-tauri/gen/apple');
        if (!is_dir($genDir)) {
            $this->line("  <fg=yellow>→</> iOS not initialized, skipping");
            return;
        }

        $assetsDir = "{$genDir}/Assets.xcassets/AppIcon.appiconset";
        if (!is_dir($assetsDir)) mkdir($assetsDir, 0755, true);

        $sizes = [
            ['size' => 20, 'scales' => [2, 3]],
            ['size' => 29, 'scales' => [2, 3]],
            ['size' => 40, 'scales' => [2, 3]],
            ['size' => 60, 'scales' => [2, 3]],
            ['size' => 76, 'scales' => [1, 2]],
            ['size' => 83.5, 'scales' => [2]],
            ['size' => 1024, 'scales' => [1]],
        ];

        $images = [];

        foreach ($sizes as $entry) {
            foreach ($entry['scales'] as $scale) {
                $px = (int) ($entry['size'] * $scale);
                $name = "icon_{$px}x{$px}.png";

                $this->resizeWithBackground($source, "{$assetsDir}/{$name}", $px);

                $images[] = [
                    'size' => "{$entry['size']}x{$entry['size']}",
                    'idiom' => 'universal',
                    'filename' => $name,
                    'scale' => "{$scale}x",
                ];
            }
        }

        file_put_contents("{$assetsDir}/Contents.json", json_encode([
            'images' => $images,
            'info' => ['version' => 1, 'author' => 'nativeblade'],
        ], JSON_PRETTY_PRINT));

        $this->line("  <fg=green>✓</> iOS app icons + Contents.json");
    }

    private function resize($source, string $path, int $w, int $h): void
    {
        $dest = imagecreatetruecolor($w, $h);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $w, $h, imagesx($source), imagesy($source));
        imagepng($dest, $path, 9);
        imagedestroy($dest);
    }

    private function resizeRound($source, string $path, int $size): void
    {
        $dest = imagecreatetruecolor($size, $size);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));

        $mask = imagecreatetruecolor($size, $size);
        imagefill($mask, 0, 0, imagecolorallocate($mask, 0, 0, 0));
        imagefilledellipse($mask, (int)($size / 2), (int)($size / 2), $size, $size, imagecolorallocate($mask, 255, 255, 255));

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                if ((imagecolorat($mask, $x, $y) & 0xFF) === 0) {
                    imagesetpixel($dest, $x, $y, $transparent);
                }
            }
        }

        imagepng($dest, $path, 9);
        imagedestroy($dest);
        imagedestroy($mask);
    }

    private function resizeWithPadding($source, string $path, int $size, float $paddingRatio): void
    {
        $rgb = $this->hexToRgb($this->bgColor);
        $dest = imagecreatetruecolor($size, $size);
        imagefill($dest, 0, 0, imagecolorallocate($dest, $rgb[0], $rgb[1], $rgb[2]));

        $innerSize = (int) ($size * (1 - $paddingRatio));
        $offset = (int) (($size - $innerSize) / 2);

        imagecopyresampled($dest, $source, $offset, $offset, 0, 0, $innerSize, $innerSize, imagesx($source), imagesy($source));
        imagepng($dest, $path, 9);
        imagedestroy($dest);
    }

    private function resizeWithBackground($source, string $path, int $size): void
    {
        $rgb = $this->hexToRgb($this->bgColor);
        $dest = imagecreatetruecolor($size, $size);
        imagefill($dest, 0, 0, imagecolorallocate($dest, $rgb[0], $rgb[1], $rgb[2]));
        imagealphablending($dest, true);
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
        imagepng($dest, $path, 9);
        imagedestroy($dest);
    }

    private function resizeMonochrome($source, string $path, int $size): void
    {
        $dest = imagecreatetruecolor($size, $size);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);

        $temp = imagecreatetruecolor($size, $size);
        imagealphablending($temp, false);
        imagesavealpha($temp, true);
        imagefill($temp, 0, 0, imagecolorallocatealpha($temp, 0, 0, 0, 127));
        imagecopyresampled($temp, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));

        $white = imagecolorallocate($dest, 255, 255, 255);
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                if (((imagecolorat($temp, $x, $y) >> 24) & 0x7F) < 64) {
                    imagesetpixel($dest, $x, $y, $white);
                }
            }
        }

        imagepng($dest, $path, 9);
        imagedestroy($dest);
        imagedestroy($temp);
    }

    private function generateIco($source, string $path): void
    {
        $sizes = [16, 32, 48, 256];
        $images = [];

        foreach ($sizes as $size) {
            $img = imagecreatetruecolor($size, $size);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagecopyresampled($img, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
            ob_start();
            imagepng($img, null, 9);
            $images[] = ['size' => $size, 'data' => ob_get_clean()];
            imagedestroy($img);
        }

        $ico = pack('vvv', 0, 1, count($images));
        $offset = 6 + (count($images) * 16);

        foreach ($images as $img) {
            $s = $img['size'] >= 256 ? 0 : $img['size'];
            $ico .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, strlen($img['data']), $offset);
            $offset += strlen($img['data']);
        }

        foreach ($images as $img) {
            $ico .= $img['data'];
        }

        file_put_contents($path, $ico);
    }

    private function detectBgColor($source): string
    {
        $color = imagecolorat($source, 0, 0);
        $alpha = ($color >> 24) & 0x7F;

        if ($alpha > 60) return '#0a0a0a';

        return sprintf('#%02X%02X%02X', ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF);
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
