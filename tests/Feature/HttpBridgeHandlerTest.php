<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use NativeBlade\Http\WasmHttpFactory;
use NativeBlade\Http\WasmHttpHandler;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Inside the wasm runtime the Http facade must resolve to WasmHttpFactory, so
 * each request runs Laravel's full client pipeline and reaches WasmHttpHandler
 * as the Guzzle handler. The cache is seeded for the keys the handler will
 * compute, so no test ever hits the exit(0) branch.
 */
final class HttpBridgeHandlerTest extends TestCase
{
    private const CACHE_DIR = '/tmp/__nb_http_cache';

    private ?string $originalPlatform = null;

    protected function setUp(): void
    {
        $this->originalPlatform = $_SERVER['NATIVEBLADE_PLATFORM'] ?? null;
        $_SERVER['NATIVEBLADE_PLATFORM'] = 'android';

        parent::setUp();
        $this->resetHandlerStatics();
        $this->scrubCache();
    }

    protected function tearDown(): void
    {
        $this->resetHandlerStatics();
        $this->scrubCache();

        if ($this->originalPlatform === null) {
            unset($_SERVER['NATIVEBLADE_PLATFORM']);
        } else {
            $_SERVER['NATIVEBLADE_PLATFORM'] = $this->originalPlatform;
        }

        parent::tearDown();
    }

    private function resetHandlerStatics(): void
    {
        $ref = new ReflectionClass(WasmHttpHandler::class);
        $ref->getProperty('poolMode')->setValue(null, false);
        $ref->getProperty('pendingRequests')->setValue(null, []);
        $ref->getProperty('requestIndex')->setValue(null, 0);
    }

    private function scrubCache(): void
    {
        foreach (glob(self::CACHE_DIR . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function seedCache(string $method, string $url, int $index, array $payload): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0777, true);
        }
        $key = md5($method . '|' . $url . '|' . $index);
        file_put_contents(self::CACHE_DIR . '/' . $key . '.json', json_encode($payload));
    }

    #[Test]
    public function http_facade_resolves_to_the_wasm_factory_inside_the_runtime(): void
    {
        self::assertInstanceOf(WasmHttpFactory::class, $this->app->make(Factory::class));
        self::assertInstanceOf(WasmHttpFactory::class, Http::getFacadeRoot());
    }

    #[Test]
    public function requests_are_answered_by_the_bridge_handler(): void
    {
        $this->seedCache('GET', 'https://api.test/items', 0, [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"ok":true}',
        ]);

        $response = Http::get('https://api.test/items');

        self::assertTrue($response->successful());
        self::assertSame(['ok' => true], $response->json());
    }

    #[Test]
    public function before_sending_callbacks_run_with_the_bridge_handler(): void
    {
        $this->seedCache('POST', 'https://api.test/items', 0, ['status' => 201, 'headers' => [], 'body' => 'created']);

        $seen = null;
        $response = Http::beforeSending(function ($request) use (&$seen) {
            $seen = [$request->method(), $request->url(), $request->header('X-Trace')];
        })->withHeaders(['X-Trace' => 'abc'])->post('https://api.test/items', ['name' => 'x']);

        self::assertSame(['POST', 'https://api.test/items', ['abc']], $seen);
        self::assertSame(201, $response->status());
        self::assertSame('created', $response->body());
    }

    #[Test]
    public function http_fake_still_intercepts_before_the_bridge(): void
    {
        Http::fake(['api.test/*' => Http::response('faked', 418)]);

        $response = Http::get('https://api.test/anything');

        self::assertSame(418, $response->status());
        self::assertSame('faked', $response->body());
        // Nothing reached the handler: no pending file, index untouched.
        self::assertSame(0, (new ReflectionClass(WasmHttpHandler::class))->getProperty('requestIndex')->getValue());
    }

    #[Test]
    public function pool_requests_keep_the_bridge_handler(): void
    {
        $this->seedCache('GET', 'https://api.test/a', 0, ['status' => 200, 'headers' => [], 'body' => 'A']);
        $this->seedCache('GET', 'https://api.test/b', 1, ['status' => 200, 'headers' => [], 'body' => 'B']);

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->get('https://api.test/a'),
            $pool->get('https://api.test/b'),
        ]);

        self::assertSame('A', $responses[0]->body());
        self::assertSame('B', $responses[1]->body());
    }
}
