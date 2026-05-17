<?php

namespace NativeBlade\Plugins;

/**
 * Fluent builder for a multipart upload of a local file to a remote URL.
 *
 * The actual file path and URL are passed positionally to
 * `NativeBlade::upload($path, $url, ...)`. This builder only carries
 * the optional extras (headers, id). Progress is reported on
 * `nb:upload-progress` and completion on `nb:upload-result`.
 *
 * @see \NativeBlade\NativeResponse::upload()
 */
class Upload
{
    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * Set the destination URL. Normally `NativeBlade::upload()` does this
     * for you from the second positional argument, so calling this directly
     * is only useful if you bypass the facade and build the action by hand.
     */
    public function url(string $url): static
    {
        $this->config['url'] = $url;
        return $this;
    }

    /**
     * Extra HTTP headers sent with the upload (e.g. `Authorization`).
     *
     * @param  array<string, string>  $headers
     */
    public function headers(array $headers): static
    {
        $this->config['headers'] = $headers;
        return $this;
    }

    /**
     * Tag the upload with an identifier echoed back on the
     * `nb:upload-progress` and `nb:upload-result` events. Use this when a
     * component has multiple uploads in flight to route their results.
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
