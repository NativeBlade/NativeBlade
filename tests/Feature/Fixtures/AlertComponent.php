<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Fixtures;

use Livewire\Component;
use NativeBlade\NativeResponse;

/**
 * Minimal Livewire component used only by NativeResponseToResponseTest to
 * exercise the Livewire branch of NativeResponse::toResponse().
 */
final class AlertComponent extends Component
{
    public function triggerAlert(): void
    {
        $response = new NativeResponse();
        $response->alert(fn ($d) => $d->title('Saved')->message('ok'));
        $response->toResponse();
    }

    public function triggerChain(): void
    {
        $response = new NativeResponse();
        $response
            ->vibrate(50)
            ->navigate('/dashboard', true)
            ->transition('slide');
        $response->toResponse();
    }

    public function triggerEmpty(): void
    {
        (new NativeResponse())->toResponse();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
