<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\PushPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PushPayloadTest extends TestCase
{
    #[Test]
    public function from_array_builds_with_defaults_for_missing_keys(): void
    {
        $payload = PushPayload::fromArray([]);

        self::assertSame('', $payload->id);
        self::assertSame([], $payload->data);
        self::assertSame([], $payload->notification);
        self::assertSame('foreground', $payload->state);
    }

    #[Test]
    public function from_array_copies_every_known_field(): void
    {
        $payload = PushPayload::fromArray([
            'id' => 'abc',
            'data' => ['type' => 'new_lesson', 'lesson_id' => '42'],
            'notification' => ['title' => 'Ding!', 'body' => 'A new lesson'],
            'state' => 'background',
        ]);

        self::assertSame('abc', $payload->id);
        self::assertSame(['type' => 'new_lesson', 'lesson_id' => '42'], $payload->data);
        self::assertSame(['title' => 'Ding!', 'body' => 'A new lesson'], $payload->notification);
        self::assertSame('background', $payload->state);
    }

    #[Test]
    public function non_array_data_falls_back_to_empty_array(): void
    {
        $payload = PushPayload::fromArray([
            'data' => 'some string',
            'notification' => 'also a string',
        ]);

        self::assertSame([], $payload->data);
        self::assertSame([], $payload->notification);
    }

    #[Test]
    public function title_and_body_read_from_notification(): void
    {
        $payload = PushPayload::fromArray([
            'notification' => ['title' => 'Ding', 'body' => 'Hi'],
        ]);

        self::assertSame('Ding', $payload->title());
        self::assertSame('Hi', $payload->body());
    }

    #[Test]
    public function title_and_body_are_null_when_missing(): void
    {
        $payload = PushPayload::fromArray([]);

        self::assertNull($payload->title());
        self::assertNull($payload->body());
    }

    #[Test]
    public function get_reads_a_key_from_data_with_default(): void
    {
        $payload = PushPayload::fromArray([
            'data' => ['type' => 'chat', 'room_id' => '7'],
        ]);

        self::assertSame('chat', $payload->get('type'));
        self::assertSame('7', $payload->get('room_id'));
        self::assertNull($payload->get('missing'));
        self::assertSame('fallback', $payload->get('missing', 'fallback'));
    }

    #[Test]
    public function id_is_coerced_to_string(): void
    {
        $payload = PushPayload::fromArray(['id' => 42]);

        self::assertSame('42', $payload->id);
    }

    #[Test]
    public function state_is_coerced_to_string(): void
    {
        $payload = PushPayload::fromArray(['state' => 123]);

        self::assertSame('123', $payload->state);
    }

    #[Test]
    public function public_properties_are_readonly(): void
    {
        $payload = PushPayload::fromArray(['id' => 'a']);
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $payload->id = 'b';
    }
}
