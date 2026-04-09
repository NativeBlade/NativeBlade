<?php

namespace NativeBlade\Components;

use Illuminate\View\Component;
use NativeBlade\NativeBladeServiceProvider;

class NbFont extends Component
{
    public string $name;
    public string $src;
    public array $parsedWeights;

    private static array $fontCache = [];

    public function __construct(
        string $name,
        string $weights = '400',
    ) {
        $this->name = $name;
        $this->parsedWeights = array_map('trim', explode(',', $weights));

        if (is_dir(public_path('fonts/' . $name))) {
            $this->src = 'fonts/' . $name;
        } else {
            $this->src = 'fonts/' . strtolower($name);
        }
    }

    public function fontFaces(): string
    {
        $cacheKey = $this->name . ':' . $this->src . ':' . implode(',', $this->parsedWeights);
        if (isset(self::$fontCache[$cacheKey])) {
            return self::$fontCache[$cacheKey];
        }

        $faces = '';
        foreach ($this->parsedWeights as $weight) {
            $dataUri = $this->weightToDataUri($weight);
            if (!$dataUri) continue;

            $faces .= "@font-face{font-family:'{$this->name}';font-style:normal;font-weight:{$weight};font-display:swap;src:url({$dataUri}) format('woff2');}";
        }

        self::$fontCache[$cacheKey] = $faces;
        return $faces;
    }

    private function weightToDataUri(string $weight): ?string
    {
        $patterns = [
            "{$this->name}-{$weight}.woff2",
            strtolower("{$this->name}-{$weight}.woff2"),
            "{$this->name}-{$weight}.woff",
            strtolower("{$this->name}-{$weight}.woff"),
            "{$this->name}-{$weight}.ttf",
            strtolower("{$this->name}-{$weight}.ttf"),
        ];

        foreach ($patterns as $filename) {
            $path = public_path("{$this->src}/{$filename}");
            if (file_exists($path)) {
                return $this->fileToDataUri($path);
            }
        }

        return null;
    }

    private function fileToDataUri(string $path): string
    {
        $content = file_get_contents($path);
        if (str_starts_with($content, 'data:')) {
            return $content;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    public function render()
    {
        return view('nativeblade::components.nativeblade.font');
    }
}
