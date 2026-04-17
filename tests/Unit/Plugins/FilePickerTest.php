<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\FilePicker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilePickerTest extends TestCase
{
    #[Test]
    public function default_payload_is_empty(): void
    {
        self::assertSame([], (new FilePicker())->toArray());
    }

    #[Test]
    public function setters_are_chainable(): void
    {
        $picker = new FilePicker();

        self::assertSame($picker, $picker->multiple());
        self::assertSame($picker, $picker->directory());
        self::assertSame($picker, $picker->defaultPath('/tmp'));
        self::assertSame($picker, $picker->title('Pick a file'));
        self::assertSame($picker, $picker->id('attach'));
        self::assertSame($picker, $picker->filters(['Images' => ['png', 'jpg']]));
    }

    #[Test]
    public function filters_with_named_keys_become_labelled_groups(): void
    {
        $payload = (new FilePicker())
            ->filters([
                'Images' => ['png', 'jpg', 'gif'],
                'Docs'   => ['pdf', 'docx'],
            ])
            ->toArray();

        self::assertSame([
            [
                'name' => 'Images',
                'extensions' => ['png', 'jpg', 'gif'],
            ],
            [
                'name' => 'Docs',
                'extensions' => ['pdf', 'docx'],
            ],
        ], $payload['filters']);
    }

    #[Test]
    public function filters_without_named_keys_fall_back_to_an_extension_joined_name(): void
    {
        $payload = (new FilePicker())
            ->filters([['png', 'jpg']])
            ->toArray();

        self::assertSame([
            [
                'name' => 'png, jpg',
                'extensions' => ['png', 'jpg'],
            ],
        ], $payload['filters']);
    }

    #[Test]
    public function filter_extensions_are_coerced_to_an_array(): void
    {
        // Integer key with a scalar extension — builder should wrap it in an array.
        $payload = (new FilePicker())
            ->filters([0 => 'pdf'])
            ->toArray();

        self::assertSame([
            ['name' => 'pdf', 'extensions' => ['pdf']],
        ], $payload['filters']);
    }

    #[Test]
    public function multiple_and_directory_default_to_true_when_called_empty(): void
    {
        $payload = (new FilePicker())->multiple()->directory()->toArray();

        self::assertTrue($payload['multiple']);
        self::assertTrue($payload['directory']);
    }

    #[Test]
    public function multiple_and_directory_accept_explicit_false(): void
    {
        $payload = (new FilePicker())->multiple(false)->directory(false)->toArray();

        self::assertFalse($payload['multiple']);
        self::assertFalse($payload['directory']);
    }

    #[Test]
    public function it_forwards_every_field(): void
    {
        $payload = (new FilePicker())
            ->title('Attach')
            ->defaultPath('/home/user')
            ->multiple()
            ->id('att')
            ->filters(['Any' => ['*']])
            ->toArray();

        self::assertSame('Attach', $payload['title']);
        self::assertSame('/home/user', $payload['defaultPath']);
        self::assertTrue($payload['multiple']);
        self::assertSame('att', $payload['id']);
        self::assertSame([['name' => 'Any', 'extensions' => ['*']]], $payload['filters']);
    }
}
