<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for an OS file picker dialog.
 *
 * Drives `NativeBlade::filePicker()` (open) and `NativeBlade::fileSave()`
 * (save dialog). Both expose the same options. Results arrive via
 * `nb:file-picker` (`$paths`, `$id`) and `nb:file-save` (`$path`, `$id`).
 *
 * @see \NativeBlade\NativeResponse::filePicker()
 * @see \NativeBlade\NativeResponse::fileSave()
 */
class FilePicker
{
    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * Restrict pickable files to one or more extension groups.
     *
     * Pass `[label => [ext, ...]]` to show a labeled group, or `[[ext, ...]]`
     * to use the extensions as the label. Extensions are without the dot
     * (e.g. `'pdf'`, not `'.pdf'`).
     *
     * @param  array<string|int, array<int, string>|string>  $filters
     *
     * @example
     *   $picker->filters([
     *       'Images' => ['png', 'jpg', 'jpeg'],
     *       'Docs'   => ['pdf', 'docx'],
     *   ]);
     */
    public function filters(array $filters): static
    {
        $parsed = [];
        foreach ($filters as $label => $extensions) {
            $parsed[] = [
                'name' => is_string($label) ? $label : implode(', ', (array) $extensions),
                'extensions' => (array) $extensions,
            ];
        }
        $this->config['filters'] = $parsed;
        return $this;
    }

    /**
     * Allow the user to pick more than one file. Ignored for `fileSave()`.
     */
    public function multiple(bool $multiple = true): static
    {
        $this->config['multiple'] = $multiple;
        return $this;
    }

    /**
     * Pick a directory instead of files. Mutually exclusive with `multiple()`
     * + filters on some platforms.
     */
    public function directory(bool $directory = true): static
    {
        $this->config['directory'] = $directory;
        return $this;
    }

    /**
     * Initial directory shown when the dialog opens.
     */
    public function defaultPath(string $path): static
    {
        $this->config['defaultPath'] = $path;
        return $this;
    }

    /**
     * Dialog title shown in the OS window header.
     */
    public function title(string $title): static
    {
        $this->config['title'] = $title;
        return $this;
    }

    /**
     * Tag the pick with an identifier echoed back on the result event.
     */
    public function id(string $id): static
    {
        $this->config['id'] = $id;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }
}
