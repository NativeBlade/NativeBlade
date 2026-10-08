<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use NativeBlade\Bridge\NativeBridge;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Http\WasmHttpFactory;
use NativeBlade\Http\WasmHttpHandler;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Inside the wasm runtime the Http facade must resolve to WasmHttpFactory, so
 * each request runs Laravel's full client pipeline and reaches WasmHttpHandler
 * as the Guzzle handler. The tests answer the bridge in place of the shell.
 */
final class HttpBridgeHandlerTest extends TestCase
{
    private ?string $originalPlatform = null;

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->originalPlatform = $_SERVER['NATIVEBLADE_PLATFORM'] ?? null;
        $_SERVER['NATIVEBLADE_PLATFORM'] = 'android';

        parent::setUp();

        $this->calls = [];
        NativeBridge::handleWith(function (array $message) {
            $this->calls[] = $message;
            if ($message['nativeblade'] === 'http_pool') {
                return ['ok' => true, 'result' => array_map(
                    fn (array $r) => ['status' => 200, 'headers' => [], 'body' => strtoupper(substr($r['url'], -1))],
                    $message['requests'],
                )];
            }

            return ['ok' => true, 'result' => ['status' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"ok":true}']];
        });
    }

    protected function tearDown(): void
    {
        WasmHttpHandler::flushPool();
        NativeBridge::handleWith(null);

        if ($this->originalPlatform === null) {
            unset($_SERVER['NATIVEBLADE_PLATFORM']);
        } else {
            $_SERVER['NATIVEBLADE_PLATFORM'] = $this->originalPlatform;
        }

        parent::tearDown();
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
        $response = Http::get('https://api.test/items');

        self::assertTrue($response->successful());
        self::assertSame(['ok' => true], $response->json());
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame('https://api.test/items', $this->calls[0]['url']);
    }

    #[Test]
    public function before_sending_callbacks_run_with_the_bridge_handler(): void
    {
        $seen = null;
        $response = Http::beforeSending(function ($request) use (&$seen) {
            $seen = [$request->method(), $request->url(), $request->header('X-Trace')];
        })->withHeaders(['X-Trace' => 'abc'])->post('https://api.test/items', ['name' => 'x']);

        self::assertSame(['POST', 'https://api.test/items', ['abc']], $seen);
        self::assertSame(201, $response->status());
        self::assertSame('{"name":"x"}', base64_decode($this->calls[0]['body']));
    }

    #[Test]
    public function http_fake_still_intercepts_before_the_bridge(): void
    {
        Http::fake(['api.test/*' => Http::response('faked', 418)]);

        $response = Http::get('https://api.test/anything');

        self::assertSame(418, $response->status());
        self::assertSame('faked', $response->body());
        self::assertSame([], $this->calls, 'nothing reached the shell');
    }

    #[Test]
    public function pool_requests_leave_as_one_batch_and_keep_their_order(): void
    {
        $responses = NativeBlade::pool(fn (Pool $pool) => [
            $pool->get('https://api.test/a'),
            $pool->get('https://api.test/b'),
        ]);

        self::assertSame('A', $responses[0]->body());
        self::assertSame('B', $responses[1]->body());
        self::assertCount(1, $this->calls);
        self::assertSame('http_pool', $this->calls[0]['nativeblade']);
    }
}
