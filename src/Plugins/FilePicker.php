<?php

namespace NativeBlade\Plugins;

class FilePicker
{
    private array $config = [];

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

    public function multiple(bool $multiple = true): static
    {
        $this->config['multiple'] = $multiple;
        return $this;
    }

    public function directory(bool $directory = true): static
    {
        $this->config['directory'] = $directory;
        return $this;
    }

    public function defaultPath(string $path): static
    {
        $this->config['defaultPath'] = $path;
        return $this;
    }

    public function title(string $title): static
    {
        $this->config['title'] = $title;
        return $this;
    }

    public function id(string $id): static
    {
        $this->config['id'] = $id;
        return $this;
    }

    public function toArray(): array
    {
        return $this->config;
    }
}
