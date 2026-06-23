<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for an interstitial ad request.
 *
 * Collected via `NativeBlade::interstitialAd(function (InterstitialAd $a) { ... })`.
 * Includes a frequency cap so the plugin nudges toward good UX: when called
 * within `minInterval` of the last interstitial for the same unit, the ad is
 * skipped and the result event reports `status: 'capped'`.
 */
class InterstitialAd
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** AdMob ad unit id. In debug builds the plugin serves a Google test unit instead. */
    public function unit(string $adUnitId): static
    {
        $this->data['unit'] = $adUnitId;
        return $this;
    }

    /** Tag echoed back on the result event, for routing. */
    public function id(string $tag): static
    {
        $this->data['id'] = $tag;
        return $this;
    }

    /** Minimum seconds between two interstitials for this unit. */
    public function minInterval(int $seconds): static
    {
        $this->data['minInterval'] = $seconds;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
