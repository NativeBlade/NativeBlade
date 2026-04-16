<?php

namespace NativeBlade\Storage;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\DirectoryAttributes;

class NativeFilesystemAdapter implements FilesystemAdapter
{
    private const PENDING_FILE = '/tmp/__nb_fs_pending.json';
    private const CACHE_DIR = '/tmp/__nb_fs_cache';

    private static int $opIndex = 0;

    public function fileExists(string $path): bool
    {
        return (bool) $this->bridge('exists', ...self::parse($path));
    }

    public function directoryExists(string $path): bool
    {
        return (bool) $this->bridge('dir_exists', ...self::parse($path));
    }

    public function write(string $path, string $contents, Config $config): void
    {
        [$realPath, $baseDir] = self::parse($path);
        $this->bridge('write', $realPath, $baseDir, base64_encode($contents));
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        $data = $this->bridge('read', ...self::parse($path));
        return base64_decode($data);
    }

    public function readStream(string $path)
    {
        $content = $this->read($path);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    public function delete(string $path): void
    {
        $this->bridge('delete', ...self::parse($path));
    }

    public function deleteDirectory(string $path): void
    {
        $this->bridge('delete_dir', ...self::parse($path));
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->bridge('mkdir', ...self::parse($path));
    }

    public function setVisibility(string $path, string $visibility): void {}

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = [
            'txt' => 'text/plain', 'pdf' => 'application/pdf', 'json' => 'application/json',
            'csv' => 'text/csv', 'xml' => 'text/xml', 'html' => 'text/html',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
            'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'zip' => 'application/zip',
        ];
        return new FileAttributes($path, null, null, null, $mimes[$ext] ?? 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        $result = $this->bridge('stat', ...self::parse($path));
        return new FileAttributes($path, null, null, (int) ($result['lastModified'] ?? 0));
    }

    public function fileSize(string $path): FileAttributes
    {
        $result = $this->bridge('stat', ...self::parse($path));
        return new FileAttributes($path, (int) ($result['size'] ?? 0));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        [$realPath, $baseDir] = self::parse($path);
        $result = $this->bridge('list', $realPath, $baseDir, $deep ? '1' : '0');
        if (!is_array($result)) return [];

        $items = [];
        foreach ($result as $entry) {
            if ($entry['isDirectory'] ?? false) {
                $items[] = new DirectoryAttributes($entry['path']);
            } else {
                $items[] = new FileAttributes(
                    $entry['path'],
                    $entry['size'] ?? null,
                    null,
                    $entry['lastModified'] ?? null,
                );
            }
        }
        return $items;
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        [$srcPath, $baseDir] = self::parse($source);
        [$dstPath] = self::parse($destination);
        $this->bridge('copy', $srcPath, $baseDir, $dstPath);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        [$srcPath, $baseDir] = self::parse($source);
        [$dstPath] = self::parse($destination);
        $this->bridge('move', $srcPath, $baseDir, $dstPath);
    }

    private static function parse(string $path): array
    {
        if (str_starts_with($path, '__nb:')) {
            $parts = explode(':', $path, 3);
            return [$parts[2] ?? '', $parts[1] ?? 'app'];
        }
        return [$path, 'app'];
    }

    private function bridge(string $op, string $path, string $baseDir = 'app', string $extra = ''): mixed
    {
        $key = md5($op . '|' . $baseDir . '|' . $path . '|' . $extra . '|' . self::$opIndex);
        self::$opIndex++;
        $cachePath = self::CACHE_DIR . '/' . $key . '.json';

        if (file_exists($cachePath)) {
            $data = json_decode(file_get_contents($cachePath), true);
            return $data['result'] ?? null;
        }

        $pending = [
            'key' => $key,
            'op' => $op,
            'path' => $path,
            'baseDir' => $baseDir,
            'extra' => $extra,
        ];

        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0777, true);
        }

        file_put_contents(self::PENDING_FILE, json_encode([$pending]));

        header('X-NativeBlade-Fs-Bridge: pending');
        echo '__NB_FS_PENDING__';
        exit(0);
    }
}
