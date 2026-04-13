<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a native dialog (alert or confirm).
 *
 * Dialog instances are constructed through a closure passed to
 * `NativeBlade::alert()` or `NativeBlade::confirm()` and converted to an
 * action payload when the enclosing NativeResponse is rendered. The shape
 * is identical for both alert and confirm — the only difference is that
 * confirm shows a Cancel button in addition to the OK button.
 *
 * @see \NativeBlade\NativeResponse::alert()
 * @see \NativeBlade\NativeResponse::confirm()
 */
class Dialog
{
    private string $title = 'NativeBlade';
    private string $message = '';
    private ?string $kind = null;
    private ?string $confirmLabel = null;
    private ?string $cancelLabel = null;
    private ?string $id = null;

    /**
     * Set the dialog title shown above the message.
     */
    public function title(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the dialog body text shown to the user.
     */
    public function message(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Set the severity of the dialog — affects the icon and color chosen
     * by the OS when rendering.
     *
     * @param  string  $kind  One of `'info'`, `'warning'`, `'error'`.
     */
    public function kind(string $kind): static
    {
        $this->kind = $kind;
        return $this;
    }

    /**
     * Override the label of the OK / confirm button.
     */
    public function confirmLabel(string $label): static
    {
        $this->confirmLabel = $label;
        return $this;
    }

    /**
     * Override the label of the Cancel button (confirm dialogs only).
     */
    public function cancelLabel(string $label): static
    {
        $this->cancelLabel = $label;
        return $this;
    }

    /**
     * Tag the dialog with an identifier echoed back in the result event.
     *
     * Use this when a component has multiple confirm dialogs — the id
     * arrives as a second argument on the `nb:confirm-result` listener
     * so you can route the response without tracking state between the
     * request and the reply.
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
            'title' => $this->title,
            'message' => $this->message,
        ];

        if ($this->kind !== null)         $payload['kind'] = $this->kind;
        if ($this->confirmLabel !== null) $payload['confirmLabel'] = $this->confirmLabel;
        if ($this->cancelLabel !== null)  $payload['cancelLabel'] = $this->cancelLabel;
        if ($this->id !== null)           $payload['id'] = $this->id;

        return $payload;
    }
}
