<?php

namespace NativeBlade\Testing;

use Closure;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Stand-in for NativeFilesystemAdapter in tests. Every operation the native
 * adapter would send through the bridge goes through the fake's bridge with
 * the same name and path (recorded, replayed from the cache, or the stop
 * point of the current run) and is carried out on a local directory so the
 * test can read the files back. `__nb:<purpose>:<path>` virtual paths
 * (native_path()) land under `<root>/<purpose>/<path>`.
 */
final class RecordingFilesystemAdapter implements FilesystemAdapter
{
    private LocalFilesystemAdapter $local;

    /**
     * @param  Closure(array<string, mixed>, Closure): mixed  $bridge
     */
    public function __construct(private string $root, private Closure $bridge)
    {
        $this->local = new LocalFilesystemAdapter($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function fileExists(string $path): bool
    {
        return (bool) $this->via('exists', $path, fn ($real) => $this->local->fileExists($real));
    }

    public function directoryExists(string $path): bool
    {
        return (bool) $this->via('dir_exists', $path, fn ($real) => $this->local->directoryExists($real));
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->via('write', $path, fn ($real) => $this->local->write($real, $contents, $config));
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $data = stream_get_contents($contents);
        $this->via('write', $path, fn ($real) => $this->local->write($real, $data, $config));
    }

    public function read(string $path): string
    {
        return (string) $this->via('read', $path, fn ($real) => $this->local->read($real));
    }

    public function readStream($path)
    {
        $contents = (string) $this->via('read', $path, fn ($real) => $this->local->read($real));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $this->via('delete', $path, fn ($real) => $this->local->delete($real));
    }

    public function deleteDirectory(string $path): void
    {
        $this->via('delete_dir', $path, fn ($real) => $this->local->deleteDirectory($real));
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->via('mkdir', $path, fn ($real) => $this->local->createDirectory($real, $config));
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Not a bridge operation on the device either.
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
        return $this->via('stat', $path, fn ($real) => $this->local->lastModified($real));
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->via('stat', $path, fn ($real) => $this->local->fileSize($real));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->via('list', $path, fn ($real) => iterator_to_array($this->local->listContents($real, $deep), false));
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->via('move', $source, fn ($real) => $this->local->move($real, $this->real($destination), $config));
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->via('copy', $source, fn ($real) => $this->local->copy($real, $this->real($destination), $config));
    }

    /** Send the operation through the bridge as the device would. */
    private function via(string $op, string $path, Closure $execute): mixed
    {
        [$realPath, $baseDir] = self::parse($path);
        $real = $baseDir . '/' . $realPath;

        return ($this->bridge)(
            ['type' => 'fs', 'op' => $op, 'path' => $realPath, 'baseDir' => $baseDir],
            fn () => $execute($real),
        );
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
