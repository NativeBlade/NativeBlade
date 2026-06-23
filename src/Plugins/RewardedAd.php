<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a rewarded ad request.
 *
 * Collected via `NativeBlade::rewardedAd(function (RewardedAd $a) { ... })`.
 * The native plugin loads and presents the ad, then reports the outcome on the
 * `nb:ad-reward` and `nb:ad-result` Livewire events.
 */
class RewardedAd
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** AdMob ad unit id. In debug builds the plugin serves a Google test unit instead. */
    public function unit(string $adUnitId): static
    {
        $this->data['unit'] = $adUnitId;
        return $this;
    }

    /** Tag echoed back on the result events, for routing the reward. */
    public function id(string $tag): static
    {
        $this->data['id'] = $tag;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
