<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a native media pick — camera, gallery, or video.
 *
 * Used by `NativeBlade::pickCamera()`, `NativeBlade::pickGallery()`, and
 * `NativeBlade::pickVideo()`. All three share the same option set because
 * the underlying `nativeblade-media` Tauri plugin treats them uniformly.
 *
 * The result is delivered via the `nb:media-result` Livewire event with
 * `$items` (array of media items), `$source` (`camera`|`gallery`|`video`),
 * and optional `$id` (when `->id()` was set on the builder). Use `->id()`
 * when a single component triggers multiple pickers with different targets
 * (e.g. avatar vs. document vs. product gallery).
 *
 * @see \NativeBlade\NativeResponse::pickCamera()
 * @see \NativeBlade\NativeResponse::pickGallery()
 * @see \NativeBlade\NativeResponse::pickVideo()
 */
class Media
{
    private int $maxWidth = 1600;
    private int $maxHeight = 1600;
    private float $quality = 0.85;
    private string $facing = 'back';
    private string $output = 'both';
    private bool $multiple = false;
    private ?int $max = null;
    private ?string $id = null;

    /**
     * Set the maximum width in pixels. The native plugin downsamples to
     * fit inside this bound while preserving aspect ratio.
     */
    public function maxWidth(int $value): static
    {
        $this->maxWidth = $value;
        return $this;
    }

    /**
     * Set the maximum height in pixels.
     */
    public function maxHeight(int $value): static
    {
        $this->maxHeight = $value;
        return $this;
    }

    /**
     * Convenience shortcut for setting both dimensions at once.
     */
    public function maxDimensions(int $width, int $height): static
    {
        $this->maxWidth = $width;
        $this->maxHeight = $height;
        return $this;
    }

    /**
     * Set the JPEG compression quality between `0.0` (smallest) and `1.0` (best).
     */
    public function quality(float $value): static
    {
        $this->quality = $value;
        return $this;
    }

    /**
     * Select the camera to use for `pickCamera()` calls. Ignored for
     * gallery and video picks.
     *
     * @param  'back'|'front'  $facing
     */
    public function facing(string $facing): static
    {
        $this->facing = $facing;
        return $this;
    }

    /**
     * Control what each returned item carries.
     *
     * - `'url'` — only the file path / asset URL. Cheapest on memory.
     * - `'dataUrl'` — only a base64 data URL for immediate `<img>` use.
     * - `'both'` — return both (default).
     *
     * @param  'url'|'dataUrl'|'both'  $mode
     */
    public function output(string $mode): static
    {
        $this->output = $mode;
        return $this;
    }

    /**
     * Allow the user to pick multiple items. Only meaningful for
     * `pickGallery()` and `pickVideo()`.
     */
    public function multiple(bool $value = true): static
    {
        $this->multiple = $value;
        return $this;
    }

    /**
     * Cap the number of items returned when `multiple()` is enabled.
     */
    public function max(int $count): static
    {
        $this->max = $count;
        return $this;
    }

    /**
     * Tag the pick with an identifier echoed back in the result event.
     *
     * Use this when a component has multiple pickers (e.g. profile avatar +
     * document scan + product gallery) — the id arrives as an argument on
     * the `nb:media-result` listener so you can route with `match`.
     */
    public function id(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'maxWidth' => $this->maxWidth,
            'maxHeight' => $this->maxHeight,
            'quality' => $this->quality,
            'facing' => $this->facing,
            'output' => $this->output,
            'multiple' => $this->multiple,
        ];

        if ($this->max !== null) $payload['max'] = $this->max;
        if ($this->id !== null) $payload['id'] = $this->id;

        return $payload;
    }
}
