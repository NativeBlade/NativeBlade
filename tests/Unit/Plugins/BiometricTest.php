<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Biometric;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BiometricTest extends TestCase
{
    #[Test]
    public function default_payload_has_authenticate_reason_and_allows_device_credential(): void
    {
        $payload = (new Biometric())->toArray();

        self::assertSame('Authenticate', $payload['reason']);
        self::assertTrue($payload['allowDeviceCredential']);
        self::assertArrayNotHasKey('id', $payload);
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $biometric = new Biometric();

        self::assertSame($biometric, $biometric->reason('Unlock'));
        self::assertSame($biometric, $biometric->allowDeviceCredential(false));
        self::assertSame($biometric, $biometric->id('checkout'));
    }

    #[Test]
    public function it_overrides_the_defaults(): void
    {
        $payload = (new Biometric())
            ->reason('Please authenticate to pay')
            ->allowDeviceCredential(false)
            ->id('checkout')
            ->toArray();

        self::assertSame([
            'reason' => 'Please authenticate to pay',
            'allowDeviceCredential' => false,
            'id' => 'checkout',
        ], $payload);
    }

    #[Test]
    public function allow_device_credential_defaults_to_true_when_called_without_args(): void
    {
        $payload = (new Biometric())->allowDeviceCredential(false)->allowDeviceCredential()->toArray();
        self::assertTrue($payload['allowDeviceCredential']);
    }
}
