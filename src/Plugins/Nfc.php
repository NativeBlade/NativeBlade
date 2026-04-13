<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for an NFC tag read.
 *
 * NFC is mobile-only. The scanned tag is delivered via the `nb:nfc`
 * Livewire event with a `$tag` array containing the tag `id` and NDEF
 * `records`.
 *
 * @see \NativeBlade\NativeResponse::nfcRead()
 */
class Nfc
{
    private ?string $id = null;

    /**
     * Tag the read with an identifier echoed back in the result event.
     *
     * Use this when a component reads NFC tags for different purposes
     * (identify product vs pair device vs scan ticket).
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
