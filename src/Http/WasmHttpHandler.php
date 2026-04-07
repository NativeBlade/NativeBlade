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

        $key = md5($method . $url . $body);
        $cachePath = self::CACHE_DIR . '/' . $key . '.json';

        if (file_exists($cachePath)) {
            $data = json_decode(file_get_contents($cachePath), true);

            $response = new Response(
                $data['status'] ?? 200,
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
            return new FulfilledPromise(new Response(0, [], ''));
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
