<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\RewardedAd;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RewardedAdTest extends TestCase
{
    #[Test]
    public function defaults_to_only_the_unit_field(): void
    {
        self::assertSame(['unit' => ''], (new RewardedAd())->toArray());
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $ad = new RewardedAd();

        self::assertSame($ad, $ad->unit('ca-app-pub-xxx/rewarded'));
        self::assertSame($ad, $ad->id('coins'));
    }

    #[Test]
    public function it_serializes_unit_and_id(): void
    {
        $payload = (new RewardedAd())
            ->unit('ca-app-pub-xxx/rewarded')
            ->id('coins')
            ->toArray();

        self::assertSame([
            'unit' => 'ca-app-pub-xxx/rewarded',
            'id' => 'coins',
        ], $payload);
    }
}
