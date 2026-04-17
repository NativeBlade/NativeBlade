<?php

declare(strict_types=1);

namespace NativeBlade\Tests;

use Livewire\LivewireServiceProvider;
use NativeBlade\NativeBladeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base TestCase for Feature tests that need a booted Laravel container
 * with NativeBlade + Livewire service providers registered.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            NativeBladeServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Livewire 3 encrypts snapshotted props and needs an APP_KEY.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // in-memory sqlite on the 'sqlite' connection used by ShellConfig state
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }
}
