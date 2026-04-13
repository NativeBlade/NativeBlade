<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a biometric authentication prompt.
 *
 * Biometric is mobile-only (Android fingerprint/face, iOS Touch ID/Face ID).
 * The authentication result is delivered via the `nb:biometric` Livewire
 * event with `$success` (bool) and optional `$error` (string) arguments.
 *
 * @see \NativeBlade\NativeResponse::biometric()
 */
class Biometric
{
    private string $reason = 'Authenticate';
    private bool $allowDeviceCredential = true;
    private ?string $id = null;

    /**
     * Set the explanation shown to the user in the system prompt.
     */
    public function reason(string $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    /**
     * Control whether the device passcode is accepted as a fallback.
     *
     * When true (default), users can fall back to their PIN / pattern /
     * passcode if biometric hardware fails or is unavailable.
     */
    public function allowDeviceCredential(bool $allow = true): static
    {
        $this->allowDeviceCredential = $allow;
        return $this;
    }

    /**
     * Tag the prompt with an identifier echoed back in the result event.
     *
     * Use this when a component triggers biometric for multiple actions
     * (checkout vs unlock vs edit email) — the id arrives as an argument
     * on the `nb:biometric` listener so you can route the result.
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
        $payload = [
            'reason' => $this->reason,
            'allowDeviceCredential' => $this->allowDeviceCredential,
        ];

        if ($this->id !== null) $payload['id'] = $this->id;

        return $payload;
    }
}
