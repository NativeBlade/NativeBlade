<?php

namespace NativeBlade\Commands\Concerns;

use NativeBlade\Support\GitIgnore;

trait IgnoresGeneratedFiles
{
    /**
     * Add the files NativeBlade generates in public/ to the app's .gitignore.
     * Idempotent: runs on install and on every update.
     */
    protected function ignoreGeneratedFiles(): void
    {
        $added = GitIgnore::ensure(base_path('.gitignore'));
        if ($added === []) {
            return;
        }

        $this->line('  <fg=green>✓</> .gitignore: ' . implode(', ', $added));
    }
}
