<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a camera or gallery capture.
 *
 * Used by both `NativeBlade::camera()` and `NativeBlade::gallery()` since
 * the two operations share the same set of options. The captured image is
 * delivered to PHP via the `nb:camera-result` Livewire event with a
 * `$data` parameter containing the image as a base64 data URL.
 *
 * @see \NativeBlade\NativeResponse::camera()
 * @see \NativeBlade\NativeResponse::gallery()
 */
class Camera
{
    private int $maxWidth = 800;
    private int $maxHeight = 800;
    private float $quality = 0.8;
    private ?string $id = null;

    /**
     * Set the maximum width of the captured image in pixels.
     *
     * The image is resized on the native side before being returned to
     * PHP, saving memory and payload size.
     */
    public function maxWidth(int $value): static
    {
        $this->maxWidth = $value;
        return $this;
    }

    /**
     * Set the maximum height of the captured image in pixels.
     */
    public function maxHeight(int $value): static
    {
        $this->maxHeight = $value;
        return $this;
    }

    /**
     * Set the JPEG compression quality of the captured image.
     *
     * @param  float  $value  Quality between `0.0` (smallest) and `1.0` (best).
     */
    public function quality(float $value): static
    {
        $this->quality = $value;
        return $this;
    }

    /**
     * Tag the capture with an identifier echoed back in the result event.
     *
     * Use this when a component has multiple cameras/galleries (e.g.
     * profile photo + document scan) — the id arrives as an argument on
     * the `nb:camera-result` listener so you can route the result.
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
        ];

        if ($this->id !== null) $payload['id'] = $this->id;

        return $payload;
    }
}
