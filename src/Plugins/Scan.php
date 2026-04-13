<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a barcode/QR scan.
 *
 * Scan is mobile-only. The scanned content is delivered via the `nb:scan`
 * Livewire event with a `$result` array containing the decoded `content`
 * and `format`.
 *
 * @see \NativeBlade\NativeResponse::scan()
 */
class Scan
{
    /** @var array<int, string> */
    private array $formats = [];

    private ?string $id = null;

    /**
     * Restrict the scan to a specific set of barcode formats.
     *
     * See the `tauri-plugin-barcode-scanner` docs for the full list of
     * format identifiers (e.g. `'QR_CODE'`, `'EAN_13'`, `'CODE_128'`).
     * Empty array accepts all formats.
     *
     * @param  array<int, string>  $formats
     */
    public function formats(array $formats): static
    {
        $this->formats = $formats;
        return $this;
    }

    /**
     * Tag the scan with an identifier echoed back in the result event.
     *
     * Use this when a component scans codes for multiple purposes
     * (product lookup vs invite QR vs event ticket).
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
        $payload = ['formats' => $this->formats];

        if ($this->id !== null) $payload['id'] = $this->id;

        return $payload;
    }
}
