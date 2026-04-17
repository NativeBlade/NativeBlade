<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Plugins;

use NativeBlade\Plugins\Clipboard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClipboardTest extends TestCase
{
    #[Test]
    public function default_payload_is_empty(): void
    {
        self::assertSame([], (new Clipboard())->toArray());
    }

    #[Test]
    public function setter_is_chainable(): void
    {
        $clipboard = new Clipboard();
        self::assertSame($clipboard, $clipboard->id('paste-target'));
    }

    #[Test]
    public function it_includes_the_id_when_set(): void
    {
        self::assertSame(
            ['id' => 'paste-target'],
            (new Clipboard())->id('paste-target')->toArray(),
        );
    }
}
