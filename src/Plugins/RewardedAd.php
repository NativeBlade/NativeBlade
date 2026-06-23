<?php

namespace NativeBlade\Plugins;

class RewardedAd
{
    private string $unit = '';

    private ?string $id = null;

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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = ['unit' => $this->unit];

        if ($this->id !== null) $payload['id'] = $this->id;

        return $payload;
    }
}
