<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Support;

use NativeBlade\Support\GitIgnore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GitIgnoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nb-gitignore-' . uniqid() . '/.gitignore';
        mkdir(dirname($this->path));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
    }

    #[Test]
    public function appends_missing_entries_under_a_marker_and_keeps_existing_content(): void
    {
        file_put_contents($this->path, "/vendor\n/node_modules\n");

        $added = GitIgnore::ensure($this->path, ['/public/lang', '/public/bundle-meta.json']);

        self::assertSame(['/public/lang', '/public/bundle-meta.json'], $added);
        self::assertSame(
            "/vendor\n/node_modules\n\n" . GitIgnore::MARKER . "\n/public/lang\n/public/bundle-meta.json\n",
            file_get_contents($this->path)
        );
    }

    #[Test]
    public function is_idempotent_and_accepts_entries_already_present_without_the_leading_slash(): void
    {
        file_put_contents($this->path, "public/lang\n" . GitIgnore::MARKER . "\n/public/bundle-meta.json\n");

        self::assertSame([], GitIgnore::ensure($this->path, ['/public/lang', '/public/bundle-meta.json']));
        self::assertSame(
            "public/lang\n" . GitIgnore::MARKER . "\n/public/bundle-meta.json\n",
            file_get_contents($this->path)
        );
    }

    #[Test]
    public function creates_the_file_with_every_generated_entry_when_there_is_none(): void
    {
        $added = GitIgnore::ensure($this->path);

        self::assertSame(GitIgnore::GENERATED, $added);
        self::assertStringStartsWith(GitIgnore::MARKER . "\n", file_get_contents($this->path));
        self::assertStringContainsString("/public/nativeblade-locale.json\n", file_get_contents($this->path));
    }
}
