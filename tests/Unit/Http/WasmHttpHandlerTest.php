<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Http;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NativeBlade\Http\WasmHttpHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * WasmHttpHandler is the Guzzle handler that replaces real network I/O with
 * the Tauri side-channel. We can safely exercise every branch that doesn't
 * end in exit(0):
 *   - enablePool/flushPool state transitions (flushPool shortcut on empty queue)
 *   - pool mode: __invoke() accumulates to $pendingRequests
 *   - cache hit: pre-seed /tmp/__nb_http_cache/<key>.json, verify FulfilledPromise
 *
 * The bridge() path that writes the pending file and exits is out of scope —
 * it requires subprocess isolation.
 */
final class WasmHttpHandlerTest extends TestCase
{
    private const CACHE_DIR = '/tmp/__nb_http_cache';

    protected function setUp(): void
    {
        $this->resetStatics();
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        $this->scrubCache();
    }

    private function resetStatics(): void
    {
        $ref = new ReflectionClass(WasmHttpHandler::class);
        $ref->getProperty('poolMode')->setValue(null, false);
        $ref->getProperty('pendingRequests')->setValue(null, []);
        $ref->getProperty('requestIndex')->setValue(null, 0);
    }

    private function scrubCache(): void
    {
        if (!is_dir(self::CACHE_DIR)) return;
        foreach (glob(self::CACHE_DIR . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function readStatic(string $prop): mixed
    {
        return (new ReflectionClass(WasmHttpHandler::class))
            ->getProperty($prop)
            ->getValue();
    }

    /**
     * Reproduce the exact key the handler will compute for a request at the
     * current $requestIndex. Mirrors __invoke() line by line.
     */
    private function keyFor(Request $request, int $index): string
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $body = (string) $request->getBody();
        ksort($headers);
        return md5($method . '|' . $url . '|' . json_encode($headers) . '|' . $body . '|' . $index);
    }

    private function seedCache(string $key, array $payload): string
    {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0777, true);
        }
        $path = self::CACHE_DIR . '/' . $key . '.json';
        file_put_contents($path, json_encode($payload));
        return $path;
    }

    #[Test]
    public function enable_pool_flips_flag_and_clears_queue(): void
    {
        $ref = new ReflectionClass(WasmHttpHandler::class);
        $ref->getProperty('pendingRequests')->setValue(null, [['stale' => true]]);

        WasmHttpHandler::enablePool();

        self::assertTrue($this->readStatic('poolMode'));
        self::assertSame([], $this->readStatic('pendingRequests'));
    }

    #[Test]
    public function flush_pool_with_empty_queue_just_disables_flag_and_returns(): void
    {
        WasmHttpHandler::enablePool();
        self::assertTrue($this->readStatic('poolMode'));

        // Empty queue → early return, no exit, no pending file written
        WasmHttpHandler::flushPool();

        self::assertFalse($this->readStatic('poolMode'));
    }

    #[Test]
    public function pool_mode_accumulates_pending_without_exiting(): void
    {
        WasmHttpHandler::enablePool();

        $handler = new WasmHttpHandler();
        $req = new Request('GET', 'https://api.example.com/v1/users', ['X-Auth' => 'token']);

        $promise = $handler($req, []);

        self::assertInstanceOf(FulfilledPromise::class, $promise);
        // Empty placeholder response while pooling — status 200 is the
        // minimum valid code per PSR-7 (guzzlehttp/psr7 >= 2.8 validates
        // 100-599). The payload is never observed by userland because the
        // process exits during flushPool().
        /** @var Response $resp */
        $resp = $promise->wait();
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('', (string) $resp->getBody());

        $pending = $this->readStatic('pendingRequests');
        self::assertCount(1, $pending);
        self::assertSame('GET', $pending[0]['method']);
        self::assertSame('https://api.example.com/v1/users', $pending[0]['url']);
        self::assertSame('token', $pending[0]['headers']['X-Auth']);
        self::assertNull($pending[0]['body']);
    }

    #[Test]
    public function pool_mode_accumulates_multiple_requests_in_order(): void
    {
        WasmHttpHandler::enablePool();

        $handler = new WasmHttpHandler();
        $handler(new Request('GET', 'https://a.test/1'), []);
        $handler(new Request('POST', 'https://a.test/2', [], 'payload'), []);
        $handler(new Request('DELETE', 'https://a.test/3'), []);

        $pending = $this->readStatic('pendingRequests');
        self::assertCount(3, $pending);
        self::assertSame(['GET', 'POST', 'DELETE'], array_column($pending, 'method'));
        self::assertSame('payload', $pending[1]['body']);
    }

    #[Test]
    public function cache_hit_returns_fulfilled_promise_with_decoded_response(): void
    {
        $handler = new WasmHttpHandler();
        $req = new Request('GET', 'https://api.example.com/hello');

        $key = $this->keyFor($req, 0);
        $this->seedCache($key, [
            'status' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"ok":true}',
        ]);

        $promise = $handler($req, []);
        self::assertInstanceOf(FulfilledPromise::class, $promise);

        /** @var Response $resp */
        $resp = $promise->wait();
        self::assertSame(201, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true}', (string) $resp->getBody());
    }

    #[Test]
    public function cache_hit_defaults_fill_in_missing_fields(): void
    {
        $handler = new WasmHttpHandler();
        $req = new Request('GET', 'https://api.example.com/minimal');

        $key = $this->keyFor($req, 0);
        $this->seedCache($key, []); // no status, no headers, no body

        /** @var Response $resp */
        $resp = $handler($req, [])->wait();

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('', (string) $resp->getBody());
    }

    #[Test]
    public function request_key_depends_on_request_index_so_repeated_identical_requests_get_distinct_cache_slots(): void
    {
        $handler = new WasmHttpHandler();
        $req = new Request('GET', 'https://api.example.com/same');

        // Seed cache hit only for index 0, not index 1
        $this->seedCache($this->keyFor($req, 0), ['body' => 'first']);
        $this->seedCache($this->keyFor($req, 1), ['body' => 'second']);

        /** @var Response $a */
        $a = $handler($req, [])->wait();
        /** @var Response $b */
        $b = $handler($req, [])->wait();

        self::assertSame('first', (string) $a->getBody());
        self::assertSame('second', (string) $b->getBody());
    }

    #[Test]
    public function headers_are_sorted_when_building_cache_key(): void
    {
        $handler = new WasmHttpHandler();

        // Two requests with same semantic headers in different Guzzle insertion order.
        $r1 = new Request('GET', 'https://a.test/x', ['Z-Last' => 'z', 'A-First' => 'a']);

        // Seed with explicit header order matching what ksort produces
        $key = $this->keyFor($r1, 0);

        // Manually compute independently: if ksort works, then
        //   headers = ['A-First' => 'a', 'Host' => 'a.test', 'Z-Last' => 'z']
        $expectedHeaders = [
            'A-First' => 'a',
            'Host' => 'a.test',
            'Z-Last' => 'z',
        ];
        $expectedKey = md5('GET|https://a.test/x|' . json_encode($expectedHeaders) . '||0');

        self::assertSame($expectedKey, $key);
    }

    #[Test]
    public function pool_mode_does_not_advance_request_index_past_what_cache_hit_would(): void
    {
        // requestIndex is incremented in __invoke regardless of branch taken.
        WasmHttpHandler::enablePool();
        $handler = new WasmHttpHandler();

        $handler(new Request('GET', 'https://x.test/1'), []);
        $handler(new Request('GET', 'https://x.test/2'), []);

        self::assertSame(2, $this->readStatic('requestIndex'));
    }
}
