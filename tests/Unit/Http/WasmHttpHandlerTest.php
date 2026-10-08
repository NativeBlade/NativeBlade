<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Http;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use NativeBlade\Http\RequestKey;
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
     * current $requestIndex. Mirrors __invoke(): method + url + body hash +
     * requestIndex. Headers are intentionally NOT part of the key.
     */
    private function keyFor(Request $request, int $index): string
    {
        $bodyHash = RequestKey::bodyHash((string) $request->getBody(), $request->getHeaderLine('Content-Type'));

        return md5($request->getMethod() . '|' . (string) $request->getUri() . '|' . $bodyHash . '|' . $index);
    }

    #[Test]
    public function the_random_multipart_boundary_is_not_part_of_the_key(): void
    {
        $handler = new WasmHttpHandler();
        $multipart = fn (string $boundary, string $bytes) => new Request(
            'POST',
            'https://api.example.com/upload',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            new MultipartStream([['name' => 'file', 'contents' => $bytes, 'filename' => 'a.png']], $boundary),
        );

        $first = $multipart('aaaa1111', 'bytes');
        $sameUploadNewBoundary = $multipart('bbbb2222', 'bytes');
        $otherFile = $multipart('aaaa1111', 'other');

        self::assertSame($this->keyFor($first, 0), $this->keyFor($sameUploadNewBoundary, 0));
        self::assertNotSame($this->keyFor($first, 0), $this->keyFor($otherFile, 0));

        $this->seedCache($this->keyFor($first, 0), ['body' => 'uploaded']);
        self::assertSame('uploaded', (string) $handler($sameUploadNewBoundary, [])->wait()->getBody());
    }

    #[Test]
    public function request_key_depends_on_the_body_so_a_different_payload_at_the_same_index_misses_the_cache(): void
    {
        $handler = new WasmHttpHandler();
        $first = new Request('POST', 'https://api.example.com/drafts', [], '{"id":1}');
        $second = new Request('POST', 'https://api.example.com/drafts', [], '{"id":2}');

        self::assertNotSame($this->keyFor($first, 0), $this->keyFor($second, 0));

        $this->seedCache($this->keyFor($first, 0), ['body' => 'first']);
        self::assertSame('first', (string) $handler($first, [])->wait()->getBody());
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
        self::assertSame(base64_encode('payload'), $pending[1]['body']);
    }

    #[Test]
    public function binary_body_is_base64_encoded_so_json_encode_never_chokes(): void
    {
        WasmHttpHandler::enablePool();
        $handler = new WasmHttpHandler();

        // Bytes that are NOT valid UTF-8. Left raw, json_encode() returns false
        // and the pending file is written empty, so the request vanishes.
        $rawBinary = "\x89PNG\xFF\x00\xC3\x28";
        $handler(new Request('POST', 'https://up.test/x', ['Content-Type' => 'multipart/form-data; boundary=z'], $rawBinary), []);

        $pending = $this->readStatic('pendingRequests');
        self::assertSame(base64_encode($rawBinary), $pending[0]['body']);
        // The whole pending list must survive json_encode (the real bridge writes it).
        self::assertNotFalse(json_encode($pending), 'pending list must be JSON-serializable');
        // And it round-trips to the exact bytes on the other side.
        self::assertSame($rawBinary, base64_decode($pending[0]['body']));
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
    public function headers_are_excluded_from_the_cache_key(): void
    {
        $handler = new WasmHttpHandler();

        $bare = new Request('POST', 'https://a.test/x', [], 'token=abc');
        $this->seedCache($this->keyFor($bare, 0), ['body' => 'cached']);

        $withExtras = new Request(
            'POST',
            'https://a.test/x',
            ['Idempotency-Key' => 'random-' . uniqid('', true)],
            'token=abc',
        );

        /** @var Response $resp */
        $resp = $handler($withExtras, [])->wait();
        self::assertSame('cached', (string) $resp->getBody());
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
