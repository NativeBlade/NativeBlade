<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a geolocation request.
 *
 * The current position is delivered via the `nb:geolocation` Livewire
 * event with a `$position` array containing `coords.latitude`,
 * `coords.longitude`, `coords.accuracy` and `timestamp`.
 *
 * @see \NativeBlade\NativeResponse::geolocation()
 */
class Geolocation
{
    private ?string $id = null;

    /**
     * Tag the request with an identifier echoed back in the result event.
     *
     * Use this when a component requests location for multiple purposes
     * (nearby users vs delivery address vs activity tracker).
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
        $payload = [];

        if ($this->id !== null) $payload['id'] = $this->id;

        return $payload;
    }
}
