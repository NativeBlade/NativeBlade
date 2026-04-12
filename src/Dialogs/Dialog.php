<?php

namespace NativeBlade\Dialogs;

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
    /**
     * Title shown above the dialog body (all platforms).
     */
    private string $title = 'NativeBlade';

    /**
     * Main text displayed inside the dialog.
     */
    private string $message = '';

    /**
     * Severity level — affects the icon and color chosen by the OS.
     *
     * One of `'info'`, `'warning'`, `'error'`. Null lets the OS pick
     * the default appearance.
     */
    private ?string $kind = null;

    /**
     * Label of the OK / confirm button.
     *
     * Null uses the OS-default label (usually "OK" or "Yes"). Only
     * shown on confirm dialogs by default, but alert dialogs also
     * respect this override.
     */
    private ?string $confirmLabel = null;

    /**
     * Label of the Cancel button.
     *
     * Only meaningful for confirm dialogs. Null uses the OS-default
     * label (usually "Cancel" or "No").
     */
    private ?string $cancelLabel = null;

    /**
     * Set the dialog title.
     *
     * @param  string  $title  Title text shown above the message.
     */
    public function title(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the dialog body text.
     *
     * @param  string  $message  Main text shown to the user.
     */
    public function message(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Set the severity of the dialog.
     *
     * Affects the icon and color chosen by the OS when rendering.
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
     *
     * @param  string  $label  Button text (e.g. `'Delete'`, `'Yes'`).
     */
    public function confirmLabel(string $label): static
    {
        $this->confirmLabel = $label;
        return $this;
    }

    /**
     * Override the label of the Cancel button.
     *
     * Only meaningful on confirm dialogs.
     *
     * @param  string  $label  Button text (e.g. `'Keep'`, `'No'`).
     */
    public function cancelLabel(string $label): static
    {
        $this->cancelLabel = $label;
        return $this;
    }

    /**
     * Convert the builder to the payload shape expected by the JS bridge.
     *
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

        return $payload;
    }
}
