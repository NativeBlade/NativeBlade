<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Livewire\Livewire;
use NativeBlade\NativeResponse;
use NativeBlade\Tests\Feature\Fixtures\AlertComponent;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NativeResponse::toResponse() branches on whether the current request is a
 * Livewire update. Two code paths:
 *
 *   (a) Livewire branch — dispatches `__nativeblade` on the current
 *       component and returns null so Livewire's normal response still wins.
 *   (b) JSON branch    — returns an Illuminate JsonResponse carrying the
 *       queued actions so a controller / push route can ship it to the bridge.
 */
final class NativeResponseToResponseTest extends TestCase
{
    #[Test]
    public function it_returns_a_json_response_outside_livewire(): void
    {
        $response = (new NativeResponse())
            ->vibrate(75)
            ->navigate('/home')
            ->toResponse();

        self::assertInstanceOf(JsonResponse::class, $response);

        $payload = $response->getData(true);
        self::assertTrue($payload['nativeblade']);
        self::assertCount(2, $payload['actions']);
        self::assertSame('vibrate', $payload['actions'][0]['action']);
        self::assertSame(75, $payload['actions'][0]['data']['duration']);
        self::assertSame('navigate', $payload['actions'][1]['action']);
        self::assertSame('/home', $payload['actions'][1]['data']['path']);
    }

    #[Test]
    public function json_response_is_valid_with_empty_queue(): void
    {
        $response = (new NativeResponse())->toResponse();

        self::assertInstanceOf(JsonResponse::class, $response);
        $payload = $response->getData(true);
        self::assertTrue($payload['nativeblade']);
        self::assertSame([], $payload['actions']);
    }

    #[Test]
    public function json_branch_preserves_modifier_data(): void
    {
        $response = (new NativeResponse())
            ->navigate('/x', true)
            ->transition('slide')
            ->toResponse();

        $actions = $response->getData(true)['actions'];
        self::assertCount(1, $actions);
        self::assertSame('/x', $actions[0]['data']['path']);
        self::assertTrue($actions[0]['data']['replace']);
        self::assertSame('slide', $actions[0]['data']['transition']);
    }

    #[Test]
    public function livewire_branch_dispatches_nativeblade_event(): void
    {
        Livewire::test(AlertComponent::class)
            ->call('triggerAlert')
            ->assertDispatched('__nativeblade', function (string $name, array $params) {
                $actions = $params['actions'] ?? [];
                return count($actions) === 1
                    && $actions[0]['action'] === 'alert'
                    && ($actions[0]['data']['title'] ?? null) === 'Saved';
            });
    }

    #[Test]
    public function livewire_branch_ships_the_full_action_queue_in_order(): void
    {
        Livewire::test(AlertComponent::class)
            ->call('triggerChain')
            ->assertDispatched('__nativeblade', function (string $name, array $params) {
                $actions = $params['actions'] ?? [];
                return count($actions) === 2
                    && $actions[0]['action'] === 'vibrate'
                    && $actions[0]['data']['duration'] === 50
                    && $actions[1]['action'] === 'navigate'
                    && $actions[1]['data']['path'] === '/dashboard'
                    && $actions[1]['data']['replace'] === true
                    && $actions[1]['data']['transition'] === 'slide';
            });
    }

    #[Test]
    public function livewire_branch_still_fires_with_an_empty_queue(): void
    {
        Livewire::test(AlertComponent::class)
            ->call('triggerEmpty')
            ->assertDispatched('__nativeblade', function (string $name, array $params) {
                return ($params['actions'] ?? null) === [];
            });
    }
}
