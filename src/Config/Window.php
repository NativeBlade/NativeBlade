<?php

namespace NativeBlade\Config;

/**
 * Fluent builder for a desktop satellite window (WINDOWS.md). The `id` is the
 * handle for `closeWindow()`/`focusWindow()` and the discriminator on events
 * the window sends back. Desktop-only.
 *
 * ```php
 * NativeBlade::window(function (Window $w) {
 *     $w->id('chat')
 *       ->component(ChatPanel::class)
 *       ->size(380, 560)
 *       ->position(100, 80)
 *       ->alwaysOnTop()
 *       ->frameless()
 *       ->resizable(false)
 *       ->minSize(320, 400);
 * })->toResponse();
 * ```
 */
class Window
{
    private array $config = [];

    /** Unique handle for this window: targets close/focus and scopes its events. */
    public function id(string $id): static
    {
        $this->config['id'] = $id;
        return $this;
    }

    /** The Livewire component the window renders (rendered by the main runtime). */
    public function component(string $class): static
    {
        $this->config['component'] = $class;
        return $this;
    }

    /** Initial window size in pixels. */
    public function size(int $width, int $height): static
    {
        $this->config['width'] = $width;
        $this->config['height'] = $height;
        return $this;
    }

    /** Minimum window size in pixels. */
    public function minSize(int $width, int $height): static
    {
        $this->config['minWidth'] = $width;
        $this->config['minHeight'] = $height;
        return $this;
    }

    /** Fixed top-left position in pixels from the primary screen's top-left. */
    public function position(int $x, int $y): static
    {
        $this->config['x'] = $x;
        $this->config['y'] = $y;
        return $this;
    }

    /** Keep the window above all others. */
    public function alwaysOnTop(bool $value = true): static
    {
        $this->config['alwaysOnTop'] = $value;
        return $this;
    }

    /** Remove native window decorations (title bar, borders). */
    public function frameless(bool $value = true): static
    {
        $this->config['frameless'] = $value;
        return $this;
    }

    /** Allow the user to resize the window. Enabled by default. */
    public function resizable(bool $value = true): static
    {
        $this->config['resizable'] = $value;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->config;
    }
}
