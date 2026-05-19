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

        $this->resize($source, "{$dir}/tray.png", 32, 32);
        $this->line("  <fg=green>✓</> tray.png (32x32)");
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

            // Legacy location — some old projects referenced @mipmap/ic_notification
            @unlink("{$dir}/ic_notification.png");
        }

        // Notification small icon — must live in drawable-* (24dp baseline),
        // white silhouette with alpha. Android tints it automatically.
        // Tauri's plugin-notification looks it up with getIdentifier(name, "drawable", pkg).
        $notificationDensities = [
            'drawable-mdpi'    => 24,
            'drawable-hdpi'    => 36,
            'drawable-xhdpi'   => 48,
            'drawable-xxhdpi'  => 72,
            'drawable-xxxhdpi' => 96,
        ];

        foreach ($notificationDensities as $folder => $size) {
            $dir = "{$resDir}/{$folder}";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $this->resizeMonochrome($source, "{$dir}/ic_notification.png", $size);
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

        // Wipe any stale icons from previous runs (avoids "unassigned children" warnings)
        foreach (glob("{$assetsDir}/*.png") ?: [] as $f) @unlink($f);

        // Apple's validation requires explicit idioms — `universal` alone is rejected
        // by App Store Connect upload. Each entry below maps to a required slot.
        $entries = [
            ['idiom' => 'iphone', 'size' => '20x20',     'scale' => '2x', 'px' => 40],
            ['idiom' => 'iphone', 'size' => '20x20',     'scale' => '3x', 'px' => 60],
            ['idiom' => 'iphone', 'size' => '29x29',     'scale' => '2x', 'px' => 58],
            ['idiom' => 'iphone', 'size' => '29x29',     'scale' => '3x', 'px' => 87],
            ['idiom' => 'iphone', 'size' => '40x40',     'scale' => '2x', 'px' => 80],
            ['idiom' => 'iphone', 'size' => '40x40',     'scale' => '3x', 'px' => 120],
            ['idiom' => 'iphone', 'size' => '60x60',     'scale' => '2x', 'px' => 120],
            ['idiom' => 'iphone', 'size' => '60x60',     'scale' => '3x', 'px' => 180],
            ['idiom' => 'ipad',   'size' => '20x20',     'scale' => '1x', 'px' => 20],
            ['idiom' => 'ipad',   'size' => '20x20',     'scale' => '2x', 'px' => 40],
            ['idiom' => 'ipad',   'size' => '29x29',     'scale' => '1x', 'px' => 29],
            ['idiom' => 'ipad',   'size' => '29x29',     'scale' => '2x', 'px' => 58],
            ['idiom' => 'ipad',   'size' => '40x40',     'scale' => '1x', 'px' => 40],
            ['idiom' => 'ipad',   'size' => '40x40',     'scale' => '2x', 'px' => 80],
            ['idiom' => 'ipad',   'size' => '76x76',     'scale' => '1x', 'px' => 76],
            ['idiom' => 'ipad',   'size' => '76x76',     'scale' => '2x', 'px' => 152],
            ['idiom' => 'ipad',   'size' => '83.5x83.5', 'scale' => '2x', 'px' => 167],
            ['idiom' => 'ios-marketing', 'size' => '1024x1024', 'scale' => '1x', 'px' => 1024],
        ];

        $generated = [];
        $images = [];

        foreach ($entries as $e) {
            $name = "icon-{$e['idiom']}-{$e['size']}-{$e['scale']}.png";

            // Avoid regenerating identical sizes
            if (!isset($generated[$e['px']])) {
                $this->resizeWithBackground($source, "{$assetsDir}/{$name}", $e['px']);
                $generated[$e['px']] = $name;
            } else {
                copy("{$assetsDir}/{$generated[$e['px']]}", "{$assetsDir}/{$name}");
            }

            $images[] = [
                'idiom' => $e['idiom'],
                'size' => $e['size'],
                'scale' => $e['scale'],
                'filename' => $name,
            ];
        }

        file_put_contents("{$assetsDir}/Contents.json", json_encode([
            'images' => $images,
            'info' => ['version' => 1, 'author' => 'nativeblade'],
        ], JSON_PRETTY_PRINT));

        $this->ensureCFBundleIconName($genDir);

        $this->line("  <fg=green>✓</> iOS app icons + Contents.json (" . count($images) . " entries)");
    }

    /**
     * Apple's App Store Connect validation rejects builds without
     * `CFBundleIconName` in Info.plist. The value must match the asset
     * catalog name — for our generated AppIcon.appiconset, that's "AppIcon".
     */
    private function ensureCFBundleIconName(string $genDir): void
    {
        $found = glob($genDir . '/*/Info.plist');
        $plistPath = $found[0] ?? null;
        if (!$plistPath) return;

        $plist = file_get_contents($plistPath);

        if (str_contains($plist, '<key>CFBundleIconName</key>')) {
            $plist = preg_replace(
                '/<key>CFBundleIconName<\/key>\s*<string>[^<]*<\/string>/',
                "<key>CFBundleIconName</key>\n\t<string>AppIcon</string>",
                $plist
            );
        } else {
            $plist = str_replace(
                '</dict>',
                "<key>CFBundleIconName</key>\n\t<string>AppIcon</string>\n</dict>",
                $plist
            );
        }

        file_put_contents($plistPath, $plist);
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
        // Material Design: content occupies ~75% of the canvas, with padding around.
        $inner = max(1, (int) round($size * 0.75));
        $offset = (int) round(($size - $inner) / 2);

        // Resample source into the inner area, preserving its alpha channel.
        $temp = imagecreatetruecolor($inner, $inner);
        imagealphablending($temp, false);
        imagesavealpha($temp, true);
        imagefill($temp, 0, 0, imagecolorallocatealpha($temp, 0, 0, 0, 127));
        imagecopyresampled($temp, $source, 0, 0, 0, 0, $inner, $inner, imagesx($source), imagesy($source));

        // Detect if the source has meaningful transparency.
        // If it does — the alpha channel carries the silhouette (ideal case).
        // If it doesn't — the source is a filled icon; we derive the silhouette
        // from color distance to the background sampled from corners.
        $hasAlpha = false;
        for ($y = 0; $y < $inner && !$hasAlpha; $y++) {
            for ($x = 0; $x < $inner && !$hasAlpha; $x++) {
                $a = (imagecolorat($temp, $x, $y) >> 24) & 0x7F;
                if ($a > 10) $hasAlpha = true;
            }
        }

        $bg = [255, 255, 255];
        if (!$hasAlpha) {
            $corners = [
                imagecolorat($temp, 0, 0),
                imagecolorat($temp, $inner - 1, 0),
                imagecolorat($temp, 0, $inner - 1),
                imagecolorat($temp, $inner - 1, $inner - 1),
            ];
            $bg[0] = (int) (array_sum(array_map(fn($c) => ($c >> 16) & 0xFF, $corners)) / 4);
            $bg[1] = (int) (array_sum(array_map(fn($c) => ($c >> 8) & 0xFF, $corners)) / 4);
            $bg[2] = (int) (array_sum(array_map(fn($c) => $c & 0xFF, $corners)) / 4);
        }

        // Build the destination: fully transparent canvas, white silhouette driven by alpha.
        $dest = imagecreatetruecolor($size, $size);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));

        for ($x = 0; $x < $inner; $x++) {
            for ($y = 0; $y < $inner; $y++) {
                $rgba = imagecolorat($temp, $x, $y);
                $srcAlpha = ($rgba >> 24) & 0x7F;
                $gdAlpha = 127; // start fully transparent

                if ($hasAlpha) {
                    // Source alpha is the silhouette — just remap color to white.
                    if ($srcAlpha < 127) $gdAlpha = $srcAlpha;
                } else {
                    // Opaque source — derive alpha from distance to background color.
                    $r = ($rgba >> 16) & 0xFF;
                    $g = ($rgba >> 8) & 0xFF;
                    $b = $rgba & 0xFF;
                    $dist = sqrt(
                        ($r - $bg[0]) ** 2 +
                        ($g - $bg[1]) ** 2 +
                        ($b - $bg[2]) ** 2
                    );
                    // < 40 = background (transparent); > 100 = fully opaque; smooth edge between.
                    if ($dist > 40) {
                        $opacity = min(1.0, ($dist - 40) / 60);
                        $gdAlpha = (int) ((1 - $opacity) * 127);
                    }
                }

                if ($gdAlpha < 127) {
                    $c = imagecolorallocatealpha($dest, 255, 255, 255, $gdAlpha);
                    imagesetpixel($dest, $offset + $x, $offset + $y, $c);
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
