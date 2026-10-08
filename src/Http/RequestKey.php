<?php

namespace NativeBlade\Http;

/**
 * The part of a request's cache key that comes from its body. Shared by the
 * runtime handler and the test fake so both see the same call the same way.
 */
final class RequestKey
{
    public const BOUNDARY_PLACEHOLDER = 'nb-boundary';

    /**
     * md5 of the body with the multipart boundary normalized away. Guzzle
     * draws a random boundary per request (MultipartStream uses random_bytes)
     * and it appears inside the body, so without this the same upload would
     * never match the cache between two runs of one request.
     */
    public static function bodyHash(string $body, string $contentType): string
    {
        return md5(self::normalizeBody($body, $contentType));
    }

    public static function normalizeBody(string $body, string $contentType): string
    {
        if ($body === '' || !preg_match('/boundary="?([^";]+)"?/i', $contentType, $m)) {
            return $body;
        }

        return str_replace($m[1], self::BOUNDARY_PLACEHOLDER, $body);
    }
}
