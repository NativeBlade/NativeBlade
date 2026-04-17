<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Dialog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DialogTest extends TestCase
{
    #[Test]
    public function it_has_sensible_defaults(): void
    {
        $payload = (new Dialog())->toArray();

        self::assertSame('NativeBlade', $payload['title']);
        self::assertSame('', $payload['message']);
        self::assertArrayNotHasKey('kind', $payload);
        self::assertArrayNotHasKey('confirmLabel', $payload);
        self::assertArrayNotHasKey('cancelLabel', $payload);
        self::assertArrayNotHasKey('id', $payload);
    }

    #[Test]
    public function every_setter_returns_the_same_instance_for_chaining(): void
    {
        $dialog = new Dialog();

        self::assertSame($dialog, $dialog->title('t'));
        self::assertSame($dialog, $dialog->message('m'));
        self::assertSame($dialog, $dialog->kind('info'));
        self::assertSame($dialog, $dialog->confirmLabel('ok'));
        self::assertSame($dialog, $dialog->cancelLabel('nope'));
        self::assertSame($dialog, $dialog->id('x'));
    }

    #[Test]
    public function it_writes_every_field_when_set(): void
    {
        $payload = (new Dialog())
            ->title('Are you sure?')
            ->message('This cannot be undone.')
            ->kind('warning')
            ->confirmLabel('Yes, delete')
            ->cancelLabel('Keep')
            ->id('delete-123')
            ->toArray();

        self::assertSame([
            'title' => 'Are you sure?',
            'message' => 'This cannot be undone.',
            'kind' => 'warning',
            'confirmLabel' => 'Yes, delete',
            'cancelLabel' => 'Keep',
            'id' => 'delete-123',
        ], $payload);
    }

    #[Test]
    public function optional_keys_are_only_emitted_when_set(): void
    {
        $payload = (new Dialog())
            ->title('Heads up')
            ->message('Something happened')
            ->toArray();

        self::assertSame(['title', 'message'], array_keys($payload));
    }
}
