<?php

namespace NativeBlade\Commands\Concerns;

use Composer\InstalledVersions;
use NativeBlade\NativeBladeServiceProvider;

/**
 * Writes .nativeblade/CONTEXT.md, the briefing an AI coding agent reads before
 * touching the project: how PHP runs on the device, what it cannot do, the
 * commands and files that matter. It comes from the package, so it describes
 * the installed version and moves with it on nativeblade:update; a tool such
 * as the Studio includes it from its own CLAUDE.md instead of carrying a copy
 * that goes stale.
 */
trait PublishesAgentContext
{
    public const AGENT_CONTEXT_PATH = '.nativeblade/CONTEXT.md';

    protected function publishAgentContext(): void
    {
        $stub = NativeBladeServiceProvider::packagePath('stubs/CONTEXT.md.stub');
        if (!is_file($stub)) {
            return;
        }

        $target = base_path(self::AGENT_CONTEXT_PATH);
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $version = $this->installedNativeBladeVersion();
        file_put_contents($target, str_replace('{{VERSION}}', $version, (string) file_get_contents($stub)));
        $this->line('  <fg=green>✓</> ' . self::AGENT_CONTEXT_PATH . " (agent context for NativeBlade {$version})");
    }

    private function installedNativeBladeVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('nativeblade/nativeblade')) {
                return (string) (InstalledVersions::getPrettyVersion('nativeblade/nativeblade') ?? 'dev');
            }
        } catch (\Throwable) {
            // A path repository or a copied vendor directory: still worth writing the file.
        }

        return 'dev';
    }
}
