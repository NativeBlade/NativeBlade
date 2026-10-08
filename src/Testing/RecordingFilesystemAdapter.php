<?php

namespace NativeBlade\Testing;

use Closure;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Stand-in for NativeFilesystemAdapter in tests: every operation the native
 * adapter would send to the shell is recorded with the same name and path,
 * then carried out on a local directory so the test can read the files back.
 * `__nb:<purpose>:<path>` virtual paths (native_path()) land under
 * `<root>/<purpose>/<path>`.
 */
final class RecordingFilesystemAdapter implements FilesystemAdapter
{
    private LocalFilesystemAdapter $local;

    /**
     * @param  Closure(array<string, mixed>): void  $record
     */
    public function __construct(private string $root, private Closure $record)
    {
        $this->local = new LocalFilesystemAdapter($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function fileExists(string $path): bool
    {
        return $this->local->fileExists($this->note('exists', $path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->local->directoryExists($this->note('dir_exists', $path));
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->local->write($this->note('write', $path), $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->local->write($this->note('write', $path), stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        return $this->local->read($this->note('read', $path));
    }

    public function readStream($path)
    {
        return $this->local->readStream($this->note('read', $path));
    }

    public function delete(string $path): void
    {
        $this->local->delete($this->note('delete', $path));
    }

    public function deleteDirectory(string $path): void
    {
        $this->local->deleteDirectory($this->note('delete_dir', $path));
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->local->createDirectory($this->note('mkdir', $path), $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Not a native operation on the device either.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->local->mimeType($this->real($path));
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->local->lastModified($this->note('stat', $path));
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->local->fileSize($this->note('stat', $path));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->local->listContents($this->note('list', $path), $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->local->move($this->note('move', $source), $this->real($destination), $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->local->copy($this->note('copy', $source), $this->real($destination), $config);
    }

    /** Record the operation as the device would and return the local path. */
    private function note(string $op, string $path): string
    {
        [$realPath, $baseDir] = self::parse($path);
        ($this->record)(['type' => 'fs', 'op' => $op, 'path' => $realPath, 'baseDir' => $baseDir]);

        return $baseDir . '/' . $realPath;
    }

    private function real(string $path): string
    {
        [$realPath, $baseDir] = self::parse($path);

        return $baseDir . '/' . $realPath;
    }

    /** Mirrors NativeFilesystemAdapter::parse(). */
    private static function parse(string $path): array
    {
        if (str_starts_with($path, '__nb:')) {
            $parts = explode(':', $path, 3);
            return [$parts[2] ?? '', $parts[1] ?? 'app'];
        }

        return [$path, 'app'];
    }
}
