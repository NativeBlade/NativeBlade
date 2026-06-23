<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\InterstitialAd;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InterstitialAdTest extends TestCase
{
    #[Test]
    public function defaults_to_only_the_unit_field(): void
    {
        self::assertSame(['unit' => ''], (new InterstitialAd())->toArray());
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $ad = new InterstitialAd();

        self::assertSame($ad, $ad->unit('ca-app-pub-xxx/interstitial'));
        self::assertSame($ad, $ad->id('level-break'));
        self::assertSame($ad, $ad->minInterval(120));
    }

    #[Test]
    public function it_serializes_the_full_payload(): void
    {
        $payload = (new InterstitialAd())
            ->unit('ca-app-pub-xxx/interstitial')
            ->id('level-break')
            ->minInterval(120)
            ->toArray();

        self::assertSame([
            'unit' => 'ca-app-pub-xxx/interstitial',
            'id' => 'level-break',
            'minInterval' => 120,
        ], $payload);
    }
}
