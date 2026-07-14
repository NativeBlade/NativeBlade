<?php

namespace NativeBlade\Http;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

class WasmHttpHandler
{
    private const PENDING_FILE = '/tmp/__nb_http_pending.json';
    private const CACHE_DIR = '/tmp/__nb_http_cache';

    private static bool $poolMode = false;
    private static array $pendingRequests = [];
    private static int $requestIndex = 0;

    public static function enablePool(): void
    {
        self::$poolMode = true;
        self::$pendingRequests = [];
    }

    public static function flushPool(): void
    {
        if (empty(self::$pendingRequests)) {
            self::$poolMode = false;
            return;
        }

        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0777, true);
        }

        file_put_contents(self::PENDING_FILE, json_encode(self::$pendingRequests));

        self::$poolMode = false;
        self::$pendingRequests = [];

        header('X-NativeBlade-Http-Bridge: pending');
        echo '__NB_HTTP_PENDING__';
        exit(0);
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $body = (string) $request->getBody();

        ksort($headers);
        $key = md5($method . '|' . $url . '|' . json_encode($headers) . '|' . $body . '|' . self::$requestIndex);
        self::$requestIndex++;
        $cachePath = self::CACHE_DIR . '/' . $key . '.json';

        if (file_exists($cachePath)) {
            $data = json_decode(file_get_contents($cachePath), true);

            // A failed fetch (offline, connection refused, DNS) comes back with
            // status 0, which PSR-7 (guzzlehttp/psr7 >= 2.8) rejects as invalid.
            // Coerce anything outside 100-599 to 503 so callers get a normal
            // "failed" response (->failed()/->serverError()) instead of an
            // InvalidArgumentException blowing up the request.
            $status = (int) ($data['status'] ?? 200);
            if ($status < 100 || $status > 599) {
                $status = 503;
            }

            $response = new Response(
                $status,
                $data['headers'] ?? [],
                $data['body'] ?? '',
            );

            return new FulfilledPromise($response);
        }

        $pending = [
            'key' => $key,
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'body' => $body ?: null,
        ];

        if (self::$poolMode) {
            self::$pendingRequests[] = $pending;
            // Placeholder response — the process exits(0) inside flushPool() and
            // Tauri replays the pool with cache files populated, so this value
            // is never actually observed by userland. Status must be 100-599
            // per PSR-7 (guzzlehttp/psr7 >= 2.8 validates this).
            return new FulfilledPromise(new Response(200, [], ''));
        }

        // Single request mode
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0777, true);
        }

        file_put_contents(self::PENDING_FILE, json_encode([$pending]));

        header('X-NativeBlade-Http-Bridge: pending');
        echo '__NB_HTTP_PENDING__';
        exit(0);
    }
}
