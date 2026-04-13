<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a clipboard read.
 *
 * Only used by `NativeBlade::clipboardRead()` — writes are fire-and-forget
 * and expose a simple `clipboardWrite($text)` method instead. The read
 * result is delivered via the `nb:clipboard` Livewire event with a
 * `$text` argument.
 *
 * @see \NativeBlade\NativeResponse::clipboardRead()
 */
class Clipboard
{
    private ?string $id = null;

    /**
     * Tag the read with an identifier echoed back in the result event.
     *
     * Use this when a component pastes clipboard content into more than
     * one field — the id tells the listener which field should receive
     * the value.
     */
    public function id(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->id !== null) $payload['id'] = $this->id;

        return $payload;
    }
}
