<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a banner ad request.
 *
 * Collected via `NativeBlade::bannerAd(function (BannerAd $a) { ... })`.
 * Shows an anchored adaptive banner pinned below the WebView; the page
 * shrinks to make room, and `NativeBlade::hideBannerAd()` gives it back.
 */
class BannerAd
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
