<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Commands;

/**
 * Creates a fresh temp directory per test and rebinds the app's base path to
 * it. Commands that write under base_path() / resource_path() / public_path()
 * can run hermetically and leave no trace.
 */
trait WithTempBasePath
{
    private string $tempBase;

    protected function setUpTempBasePath(): void
    {
        $this->tempBase = sys_get_temp_dir() . '/nb-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->tempBase, 0755, true);
        mkdir($this->tempBase . '/resources/js', 0755, true);
        $this->app->setBasePath($this->tempBase);
    }

    protected function tearDownTempBasePath(): void
    {
        if (isset($this->tempBase) && is_dir($this->tempBase)) {
            $this->rrmdir($this->tempBase);
        }
    }

    private function rrmdir(string $dir): void
    {
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
