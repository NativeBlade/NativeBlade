<?php

namespace NativeBlade\Tasks;

/**
 * One background task result delivered to a handler class on app open.
 * `ranAt` is when the Rust courier actually executed — possibly hours ago,
 * with the app closed. The payload is the fetched response body, JSON-decoded
 * when possible.
 */
class TaskResult
{
    public function __construct(
        public readonly string $name,
        public readonly int $ranAt,
        public readonly mixed $payload,
    ) {}

    /** Decoded JSON payload, or null when the response was not a JSON object/array. */
    public function json(): ?array
    {
        return is_array($this->payload) ? $this->payload : null;
    }

    public function ranAt(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::createFromTimestamp($this->ranAt);
    }
}
