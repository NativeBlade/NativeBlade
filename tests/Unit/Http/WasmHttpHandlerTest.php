<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Http;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NativeBlade\Bridge\NativeBridge;
use NativeBlade\Bridge\NativeBridgeException;
use NativeBlade\Http\WasmHttpHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WasmHttpHandler is the Guzzle handler that sends each request through the
 * native bridge and turns the shell's reply into a PSR-7 response. The tests
 * answer the bridge themselves and record the messages.
 */
final class WasmHttpHandlerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var callable(array): mixed */
    private $answer;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->answer = fn () => ['status' => 200, 'headers' => [], 'body' => ''];
        NativeBridge::handleWith(function (array $message) {
            $this->calls[] = $message;

            return ['ok' => true, 'result' => ($this->answer)($message)];
        });
        WasmHttpHandler::flushPool();
    }

    protected function tearDown(): void
    {
        WasmHttpHandler::flushPool();
        NativeBridge::handleWith(null);
    }

    #[Test]
    public function a_request_is_one_http_message_and_the_reply_becomes_the_response(): void
    {
        $this->answer = fn () => ['status' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"ok":true}'];
        $handler = new WasmHttpHandler();

        $promise = $handler(new Request('POST', 'https://api.example.com/items', ['X-Trace' => 'abc'], '{"name":"x"}'), []);

        self::assertInstanceOf(FulfilledPromise::class, $promise);
        $response = $promise->wait();
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true}', (string) $response->getBody());

        self::assertSame([
            'nativeblade' => 'http',
            'method' => 'POST',
            'url' => 'https://api.example.com/items',
            'headers' => ['Host' => 'api.example.com', 'X-Trace' => 'abc'],
            'body' => base64_encode('{"name":"x"}'),
        ], $this->calls[0]);
    }

    #[Test]
    public function an_empty_body_is_sent_as_null_and_a_binary_one_survives_base64(): void
    {
        $handler = new WasmHttpHandler();
        $handler(new Request('GET', 'https://a.test/x'), []);
        self::assertNull($this->calls[0]['body']);

        $raw = "\xFF\xFE\x00binary";
        $handler(new Request('POST', 'https://up.test/x', ['Content-Type' => 'application/octet-stream'], $raw), []);
        self::assertSame($raw, base64_decode($this->calls[1]['body']));
    }

    #[Test]
    public function a_large_body_travels_as_a_file_in_php_s_filesystem(): void
    {
        $handler = new WasmHttpHandler();
        $big = str_repeat("\xFFphoto-bytes", (int) ceil((WasmHttpHandler::BODY_FILE_THRESHOLD + 1) / 11));

        $handler(new Request('POST', 'https://up.test/photos', [], $big), []);

        $message = $this->calls[0];
        self::assertNull($message['body']);
        self::assertStringStartsWith('/tmp/__nb_http_body/', $message['bodyFile']);
        self::assertFileExists($message['bodyFile']);
        self::assertSame($big, file_get_contents($message['bodyFile']));
        @unlink($message['bodyFile']);
    }

    #[Test]
    public function a_multipart_upload_goes_through_untouched(): void
    {
        $handler = new WasmHttpHandler();
        $stream = new MultipartStream([['name' => 'file', 'contents' => 'bytes', 'filename' => 'a.png']], 'bbbb2222');

        $handler(new Request('POST', 'https://up.test/upload', ['Content-Type' => 'multipart/form-data; boundary=bbbb2222'], $stream), []);

        self::assertStringContainsString('filename="a.png"', base64_decode($this->calls[0]['body']));
    }

    #[Test]
    public function a_failed_fetch_becomes_a_503_so_callers_see_a_failed_response(): void
    {
        $this->answer = fn () => ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'offline'];

        $response = (new WasmHttpHandler())(new Request('GET', 'https://a.test/x'), [])->wait();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function missing_reply_fields_get_defaults(): void
    {
        $this->answer = fn () => [];

        $response = (new WasmHttpHandler())(new Request('GET', 'https://a.test/x'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function a_bridge_failure_propagates_as_the_bridge_exception(): void
    {
        NativeBridge::handleWith(fn () => ['ok' => false, 'error' => 'shell gone']);

        $this->expectException(NativeBridgeException::class);
        $this->expectExceptionMessage('shell gone');

        (new WasmHttpHandler())(new Request('GET', 'https://a.test/x'), []);
    }

    #[Test]
    public function pool_mode_collects_requests_and_sends_them_as_one_batch_on_the_first_wait(): void
    {
        $this->answer = fn (array $message) => array_map(
            fn (array $request) => ['status' => 200, 'headers' => [], 'body' => 'for ' . $request['url']],
            $message['requests'],
        );
        $handler = new WasmHttpHandler();

        WasmHttpHandler::enablePool();
        self::assertTrue(WasmHttpHandler::isPooling());
        $a = $handler(new Request('GET', 'https://a.test/1'), []);
        $b = $handler(new Request('GET', 'https://a.test/2'), []);
        self::assertInstanceOf(Promise::class, $a);
        self::assertSame([], $this->calls, 'nothing leaves PHP until a response is waited for');

        /** @var Response $first */
        $first = $a->wait();

        self::assertCount(1, $this->calls, 'one message for the whole batch');
        self::assertSame('http_pool', $this->calls[0]['nativeblade']);
        self::assertSame(['https://a.test/1', 'https://a.test/2'], array_column($this->calls[0]['requests'], 'url'));
        self::assertSame('for https://a.test/1', (string) $first->getBody());
        self::assertSame('for https://a.test/2', (string) $b->wait()->getBody());
        self::assertFalse(WasmHttpHandler::isPooling(), 'the batch closes the pool');
    }

    #[Test]
    public function flush_pool_with_nothing_queued_only_leaves_pool_mode(): void
    {
        WasmHttpHandler::enablePool();
        WasmHttpHandler::flushPool();

        self::assertFalse(WasmHttpHandler::isPooling());
        self::assertSame([], $this->calls);
    }
}
