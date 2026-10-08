<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature;

use Carbon\Carbon;
use Livewire\Component;
use Livewire\Livewire;
use NativeBlade\Facades\NativeBlade;
use NativeBlade\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class LocaleProbe extends Component
{
    public string $seen = '';

    public function probe(): void
    {
        $this->seen = app()->getLocale() . '|' . Carbon::getLocale();
    }

    public function render(): string
    {
        return '<div>{{ $seen }}</div>';
    }
}

/**
 * Livewire restores the locale a component was dehydrated with on every one of
 * its requests. After NativeBlade::setLanguage() the persisted choice must win,
 * otherwise components mounted before the change keep the old language.
 */
final class ChosenLocaleOnLivewireRequestsTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(public_path('nativeblade-locale.json'));
        parent::tearDown();
    }

    #[Test]
    public function a_component_mounted_before_set_language_runs_its_actions_in_the_new_language(): void
    {
        NativeBlade::setLanguage('en');
        $component = Livewire::test(LocaleProbe::class);

        NativeBlade::setLanguage('pt_BR');

        $component->call('probe');

        self::assertSame('pt_BR|pt_BR', $component->get('seen'));
    }

    #[Test]
    public function an_app_that_never_chose_a_language_keeps_livewire_default_behaviour(): void
    {
        app()->setLocale('fr');
        $component = Livewire::test(LocaleProbe::class);

        app()->setLocale('en');
        $component->call('probe');

        self::assertStringStartsWith('fr|', $component->get('seen'), 'the snapshot locale is restored, nothing overrides it');
    }
}
