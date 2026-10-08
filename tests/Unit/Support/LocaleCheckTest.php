<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Support;

use NativeBlade\Support\LocaleCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocaleCheckTest extends TestCase
{
    private string $lang;

    protected function setUp(): void
    {
        $this->lang = sys_get_temp_dir() . '/nb-locale-check-' . uniqid();
        mkdir($this->lang . '/pt_BR', 0777, true);
        file_put_contents($this->lang . '/es.json', '{}');
    }

    protected function tearDown(): void
    {
        @unlink($this->lang . '/es.json');
        @rmdir($this->lang . '/pt_BR');
        @rmdir($this->lang);
    }

    #[Test]
    public function finds_translations_by_directory_or_json_file_and_tolerates_hyphen_or_underscore(): void
    {
        self::assertTrue(LocaleCheck::hasTranslations('pt_BR', $this->lang));
        self::assertTrue(LocaleCheck::hasTranslations('pt-BR', $this->lang));
        self::assertTrue(LocaleCheck::hasTranslations('es', $this->lang));
        self::assertFalse(LocaleCheck::hasTranslations('fr', $this->lang));
        self::assertFalse(LocaleCheck::hasTranslations('pt', $this->lang));
        self::assertFalse(LocaleCheck::hasTranslations('fr', $this->lang . '/missing'));
    }

    #[Test]
    public function english_and_backed_locales_are_not_a_problem(): void
    {
        self::assertNull(LocaleCheck::problem('en', 'en', $this->lang . '/missing'));
        self::assertNull(LocaleCheck::problem('', 'en', $this->lang));
        self::assertNull(LocaleCheck::problem('pt_BR', 'en', $this->lang));
        self::assertNull(LocaleCheck::problem('es', 'en', $this->lang));
    }

    #[Test]
    public function an_unbacked_locale_is_reported_with_the_fallback_it_will_use(): void
    {
        $problem = LocaleCheck::problem('fr', 'en', $this->lang);

        self::assertIsString($problem);
        self::assertStringContainsString("Locale 'fr' is active", $problem);
        self::assertStringContainsString("'fr' directory or 'fr.json'", $problem);
        self::assertStringContainsString("fall back to 'en'", $problem);
        self::assertStringContainsString('setLanguage', $problem);
    }
}
