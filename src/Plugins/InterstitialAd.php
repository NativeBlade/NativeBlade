<?php

namespace NativeBlade\Plugins;

class InterstitialAd
{
    private string $unit = '';

    private ?string $id = null;

    private ?int $minInterval = null;

    public function unit(string $adUnitId): static
    {
        $this->unit = $adUnitId;
        return $this;
    }

    public function id(string $tag): static
    {
        $this->id = $tag;
        return $this;
    }

    public function minInterval(int $seconds): static
    {
        $this->minInterval = $seconds;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = ['unit' => $this->unit];

        if ($this->id !== null) $payload['id'] = $this->id;
        if ($this->minInterval !== null) $payload['minInterval'] = $this->minInterval;

        return $payload;
    }
}
