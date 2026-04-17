<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Upload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadTest extends TestCase
{
    #[Test]
    public function default_payload_is_empty(): void
    {
        self::assertSame([], (new Upload())->toArray());
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $upload = new Upload();

        self::assertSame($upload, $upload->url('https://example.com'));
        self::assertSame($upload, $upload->headers(['Authorization' => 'Bearer x']));
        self::assertSame($upload, $upload->id('u1'));
    }

    #[Test]
    public function it_forwards_every_field(): void
    {
        $payload = (new Upload())
            ->url('https://example.com/upload')
            ->headers([
                'Authorization' => 'Bearer abc',
                'X-Client' => 'nb',
            ])
            ->id('doc-42')
            ->toArray();

        self::assertSame([
            'url' => 'https://example.com/upload',
            'headers' => [
                'Authorization' => 'Bearer abc',
                'X-Client' => 'nb',
            ],
            'id' => 'doc-42',
        ], $payload);
    }
}
