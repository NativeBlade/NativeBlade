<?php

namespace NativeBlade;

use Illuminate\Http\JsonResponse;
use Livewire\Livewire;

class NativeResponse
{
    private array $actions = [];

    public function alert(string $message): static
    {
        $this->actions[] = [
            'action' => 'so:alert',
            'data' => ['message' => $message],
        ];
        return $this;
    }

    public function title(string $title): static
    {
        if (!empty($this->actions)) {
            $last = &$this->actions[count($this->actions) - 1];
            $last['data']['title'] = $title;
        }
        return $this;
    }

    public function confirm(string $label): static
    {
        if (!empty($this->actions)) {
            $last = &$this->actions[count($this->actions) - 1];
            $last['data']['confirmLabel'] = $label;
        }
        return $this;
    }

    public function cancel(string $label): static
    {
        if (!empty($this->actions)) {
            $last = &$this->actions[count($this->actions) - 1];
            $last['data']['cancelLabel'] = $label;
        }
        return $this;
    }

    public function notification(string $body): static
    {
        $this->actions[] = [
            'action' => 'so:notification',
            'data' => ['body' => $body, 'title' => 'NativeBlade'],
        ];
        return $this;
    }

    public function navigate(string $path, bool $replace = false): static
    {
        $this->actions[] = [
            'action' => 'so:navigate',
            'data' => ['path' => $path, 'replace' => $replace],
        ];
        return $this;
    }

    public function transition(string $type): static
    {
        if (!empty($this->actions)) {
            $last = &$this->actions[count($this->actions) - 1];
            $last['data']['transition'] = $type;
        }
        return $this;
    }

    public function exit(): static
    {
        $this->actions[] = [
            'action' => 'so:exit',
            'data' => [],
        ];
        return $this;
    }

    public function toResponse(): ?JsonResponse
    {
        $isLivewireUpdate = request()->is('livewire/update') || request()->header('X-Livewire');

        if ($isLivewireUpdate) {
            $component = Livewire::current();
            if ($component) {
                $component->dispatch('__nativeblade', actions: $this->actions);
                return null;
            }
        }

        return response()->json([
            'nativeblade' => true,
            'actions' => $this->actions,
        ]);
    }
}
