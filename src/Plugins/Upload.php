<?php

namespace NativeBlade\Plugins;

class Upload
{
    private array $config = [];

    public function url(string $url): static
    {
        $this->config['url'] = $url;
        return $this;
    }

    public function headers(array $headers): static
    {
        $this->config['headers'] = $headers;
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
