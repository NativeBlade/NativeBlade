<?php

namespace NativeBlade\Http;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use NativeBlade\Bridge\NativeBridge;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle handler that sends each request through the native bridge: the
 * shell performs the fetch while PHP waits, and the response comes back as a
 * normal PSR-7 response. Laravel's whole client pipeline runs unchanged.
 *
 * Inside NativeBlade::pool() the requests are collected and sent as one
 * batch the first time a response is waited for, so the shell can run them
 * in parallel.
 */
class WasmHttpHandler
{
    private static bool $poolMode = false;

    /** @var list<array{message: array<string, mixed>, promise: Promise}> */
    private static array $pool = [];

    public static function enablePool(): void
    {
        self::$poolMode = true;
        self::$pool = [];
    }

    /** True between enablePool() and flushPool(): requests made now ride in one batch. */
    public static function isPooling(): bool
    {
        return self::$poolMode;
    }

    /** Send the collected batch and settle its promises. A no-op when nothing is queued. */
    public static function flushPool(): void
    {
        self::$poolMode = false;
        if (self::$pool === []) {
            return;
        }

        $batch = self::$pool;
        self::$pool = [];

        $results = NativeBridge::call('http_pool', ['requests' => array_column($batch, 'message')]);
        foreach ($batch as $i => $entry) {
            $entry['promise']->resolve(self::toResponse(is_array($results) ? ($results[$i] ?? []) : []));
        }
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $message = self::describe($request);

        if (self::$poolMode) {
            // Settled by flushPool(), which the first wait() triggers.
            $promise = new Promise(static function () {
                self::flushPool();
            });
            self::$pool[] = ['message' => $message, 'promise' => $promise];

            return $promise;
        }

        return new FulfilledPromise(self::toResponse(NativeBridge::call('http', $message)));
    }

    /**
     * Bodies above this size (an upload with several photos) are handed to
     * the shell as a file in PHP's own filesystem instead of base64 inside
     * the message, which spares a base64 copy and a JSON copy of every byte.
     */
    public const BODY_FILE_THRESHOLD = 256 * 1024;

    private const BODY_FILE_DIR = '/tmp/__nb_http_body';

    private static int $bodyFiles = 0;

    /** @return array<string, mixed> */
    public static function describe(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $body = (string) $request->getBody();

        $message = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $headers,
            'body' => null,
        ];

        if ($body === '') {
            return $message;
        }
        if (strlen($body) <= self::BODY_FILE_THRESHOLD) {
            // base64 so a non-UTF-8 body (a multipart upload) survives json_encode.
            $message['body'] = base64_encode($body);

            return $message;
        }

        if (!is_dir(self::BODY_FILE_DIR)) {
            @mkdir(self::BODY_FILE_DIR, 0777, true);
        }
        $path = self::BODY_FILE_DIR . '/' . getmypid() . '-' . (++self::$bodyFiles);
        file_put_contents($path, $body);
        $message['bodyFile'] = $path;

        return $message;
    }

    /** @param  array<string, mixed>  $data  what the shell returned for one request */
    public static function toResponse(array $data): Response
    {
        // A failed fetch (offline, connection refused, DNS) comes back with
        // status 0, which PSR-7 rejects. Coerce anything outside 100-599 to
        // 503 so callers get a normal failed response (->failed()).
        $status = (int) ($data['status'] ?? 200);
        if ($status < 100 || $status > 599) {
            $status = 503;
        }

        return new Response($status, $data['headers'] ?? [], $data['body'] ?? '');
    }
}
