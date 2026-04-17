<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Storage;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use NativeBlade\Storage\NativeFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * NativeFilesystemAdapter mirrors WasmHttpHandler's cache/exit pattern for
 * filesystem ops. We cover:
 *   - Pure helpers: parse() (default + __nb:baseDir:path syntax)
 *   - MIME type table (extension → mime)
 *   - visibility() / setVisibility() static defaults
 *   - Cache-hit paths for every bridge op: exists, write, read, delete, stat,
 *     list, copy, move, mkdir, delete_dir
 *   - readStream wrapping read()
 *
 * The exit(0) fallback isn't testable without subprocess isolation.
 */
final class NativeFilesystemAdapterTest extends TestCase
{
    private const CACHE_DIR = '/tmp/__nb_fs_cache';

    protected function setUp(): void
    {
        $this->resetOpIndex();
        $this->scrubCache();
    }

    protected function tearDown(): void
    {
        $this->resetOpIndex();
        $this->scrubCache();
    }

    private function resetOpIndex(): void
    {
        (new ReflectionClass(NativeFilesystemAdapter::class))
            ->getProperty('opIndex')
            ->setValue(null, 0);
    }

    private function scrubCache(): void
    {
        if (!is_dir(self::CACHE_DIR)) return;
        foreach (glob(self::CACHE_DIR . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Mirror NativeFilesystemAdapter::bridge() key generation exactly. */
    private function keyFor(string $op, string $baseDir, string $path, string $extra, int $index): string
    {
        return md5($op . '|' . $baseDir . '|' . $path . '|' . $extra . '|' . $index);
    }

    private function seed(string $key, mixed $result): void
    {
        if (!is_dir(self::CACHE_DIR)) mkdir(self::CACHE_DIR, 0777, true);
        file_put_contents(self::CACHE_DIR . '/' . $key . '.json', json_encode(['result' => $result]));
    }

    private function invokeStatic(string $method, array $args): mixed
    {
        $m = (new ReflectionClass(NativeFilesystemAdapter::class))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ---------------------------------------------------------------
    // parse() — private static helper that extracts (path, baseDir)
    // ---------------------------------------------------------------

    #[Test]
    public function parse_defaults_baseDir_to_app_when_no_prefix(): void
    {
        self::assertSame(['foo/bar.txt', 'app'], $this->invokeStatic('parse', ['foo/bar.txt']));
    }

    #[Test]
    public function parse_extracts_custom_baseDir_from_nb_prefix(): void
    {
        self::assertSame(
            ['data.json', 'cache'],
            $this->invokeStatic('parse', ['__nb:cache:data.json'])
        );
    }

    #[Test]
    public function parse_supports_colons_inside_the_path(): void
    {
        // explode(':', $path, 3) — max three parts → everything past the second
        // colon is considered path.
        self::assertSame(
            ['a:b:c', 'downloads'],
            $this->invokeStatic('parse', ['__nb:downloads:a:b:c'])
        );
    }

    #[Test]
    public function parse_with_only_prefix_and_base_yields_empty_path(): void
    {
        self::assertSame(['', 'export'], $this->invokeStatic('parse', ['__nb:export']));
    }

    // ---------------------------------------------------------------
    // mimeType table
    // ---------------------------------------------------------------

    public static function mimeProvider(): array
    {
        return [
            'txt'   => ['notes.txt',      'text/plain'],
            'pdf'   => ['report.pdf',     'application/pdf'],
            'json'  => ['payload.json',   'application/json'],
            'csv'   => ['sheet.csv',      'text/csv'],
            'xml'   => ['feed.xml',       'text/xml'],
            'html'  => ['index.html',     'text/html'],
            'png'   => ['logo.png',       'image/png'],
            'jpg'   => ['pic.jpg',        'image/jpeg'],
            'jpeg'  => ['pic.jpeg',       'image/jpeg'],
            'gif'   => ['meme.gif',       'image/gif'],
            'svg'   => ['icon.svg',       'image/svg+xml'],
            'webp'  => ['shot.webp',      'image/webp'],
            'mp3'   => ['song.mp3',       'audio/mpeg'],
            'mp4'   => ['clip.mp4',       'video/mp4'],
            'zip'   => ['bundle.zip',     'application/zip'],
            'unknown' => ['thing.foobar', 'application/octet-stream'],
            'no-ext'  => ['README',       'application/octet-stream'],
            // strtolower is applied to the extension
            'uppercase-ext' => ['PHOTO.PNG', 'image/png'],
        ];
    }

    #[Test]
    #[DataProvider('mimeProvider')]
    public function mime_type_is_driven_by_extension(string $path, string $expected): void
    {
        $adapter = new NativeFilesystemAdapter();
        self::assertSame($expected, $adapter->mimeType($path)->mimeType());
    }

    // ---------------------------------------------------------------
    // visibility / setVisibility — no-op / default public
    // ---------------------------------------------------------------

    #[Test]
    public function visibility_always_returns_public(): void
    {
        $adapter = new NativeFilesystemAdapter();
        $attrs = $adapter->visibility('anything.txt');

        self::assertInstanceOf(FileAttributes::class, $attrs);
        self::assertSame('public', $attrs->visibility());
    }

    #[Test]
    public function set_visibility_is_a_silent_noop(): void
    {
        $adapter = new NativeFilesystemAdapter();
        $adapter->setVisibility('whatever.txt', 'private');
        // No bridge call → no exit → no exception. That's the contract.
        self::assertTrue(true);
    }

    // ---------------------------------------------------------------
    // Cache-hit bridge ops
    // ---------------------------------------------------------------

    #[Test]
    public function file_exists_returns_cached_boolean(): void
    {
        $this->seed($this->keyFor('exists', 'app', 'foo.txt', '', 0), true);
        $adapter = new NativeFilesystemAdapter();
        self::assertTrue($adapter->fileExists('foo.txt'));
    }

    #[Test]
    public function directory_exists_returns_cached_boolean(): void
    {
        $this->seed($this->keyFor('dir_exists', 'app', 'some/dir', '', 0), false);
        $adapter = new NativeFilesystemAdapter();
        self::assertFalse($adapter->directoryExists('some/dir'));
    }

    #[Test]
    public function write_base64_encodes_contents_in_extra_param(): void
    {
        $payload = 'hello world';
        $b64 = base64_encode($payload);

        $this->seed($this->keyFor('write', 'app', 'out.txt', $b64, 0), true);

        $adapter = new NativeFilesystemAdapter();
        // Returns void; if cache miss, we'd exit(0). Reaching the next line
        // implies the cache hit fired.
        $adapter->write('out.txt', $payload, new Config());
        self::assertTrue(true);
    }

    #[Test]
    public function write_stream_delegates_to_write(): void
    {
        $payload = "chunked contents\n";
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $payload);
        rewind($stream);

        $this->seed(
            $this->keyFor('write', 'app', 'chunk.bin', base64_encode($payload), 0),
            true,
        );

        $adapter = new NativeFilesystemAdapter();
        $adapter->writeStream('chunk.bin', $stream, new Config());
        self::assertTrue(true);
    }

    #[Test]
    public function read_base64_decodes_cached_payload(): void
    {
        $payload = "line1\nline2";
        $this->seed($this->keyFor('read', 'app', 'src.txt', '', 0), base64_encode($payload));

        $adapter = new NativeFilesystemAdapter();
        self::assertSame($payload, $adapter->read('src.txt'));
    }

    #[Test]
    public function read_stream_returns_rewindable_stream_with_contents(): void
    {
        $payload = "stream contents";
        $this->seed($this->keyFor('read', 'app', 'stream.txt', '', 0), base64_encode($payload));

        $adapter = new NativeFilesystemAdapter();
        $stream = $adapter->readStream('stream.txt');
        self::assertIsResource($stream);
        self::assertSame($payload, stream_get_contents($stream));
    }

    #[Test]
    public function delete_bridges_with_empty_extra(): void
    {
        $this->seed($this->keyFor('delete', 'app', 'gone.txt', '', 0), true);

        $adapter = new NativeFilesystemAdapter();
        $adapter->delete('gone.txt');
        self::assertTrue(true);
    }

    #[Test]
    public function delete_directory_bridges_with_delete_dir_op(): void
    {
        $this->seed($this->keyFor('delete_dir', 'app', 'stale/', '', 0), true);

        $adapter = new NativeFilesystemAdapter();
        $adapter->deleteDirectory('stale/');
        self::assertTrue(true);
    }

    #[Test]
    public function create_directory_bridges_with_mkdir_op(): void
    {
        $this->seed($this->keyFor('mkdir', 'app', 'new/dir', '', 0), true);

        $adapter = new NativeFilesystemAdapter();
        $adapter->createDirectory('new/dir', new Config());
        self::assertTrue(true);
    }

    #[Test]
    public function last_modified_returns_cached_timestamp(): void
    {
        $ts = 1713340800;
        $this->seed($this->keyFor('stat', 'app', 'file.txt', '', 0), ['lastModified' => $ts]);

        $attrs = (new NativeFilesystemAdapter())->lastModified('file.txt');

        self::assertInstanceOf(FileAttributes::class, $attrs);
        self::assertSame($ts, $attrs->lastModified());
    }

    #[Test]
    public function file_size_returns_cached_size(): void
    {
        $this->seed($this->keyFor('stat', 'app', 'data.bin', '', 0), ['size' => 4096]);

        $attrs = (new NativeFilesystemAdapter())->fileSize('data.bin');
        self::assertSame(4096, $attrs->fileSize());
    }

    #[Test]
    public function list_contents_emits_file_and_directory_attributes(): void
    {
        $this->seed($this->keyFor('list', 'app', 'folder', '0', 0), [
            ['path' => 'folder/a.txt', 'size' => 10, 'lastModified' => 100, 'isDirectory' => false],
            ['path' => 'folder/sub',   'isDirectory' => true],
            ['path' => 'folder/b.bin', 'size' => 20, 'lastModified' => 200, 'isDirectory' => false],
        ]);

        $adapter = new NativeFilesystemAdapter();
        $items = iterator_to_array($adapter->listContents('folder', false));

        self::assertCount(3, $items);
        self::assertInstanceOf(FileAttributes::class, $items[0]);
        self::assertSame('folder/a.txt', $items[0]->path());
        self::assertSame(10, $items[0]->fileSize());
        self::assertSame(100, $items[0]->lastModified());

        self::assertInstanceOf(DirectoryAttributes::class, $items[1]);
        self::assertSame('folder/sub', $items[1]->path());

        self::assertInstanceOf(FileAttributes::class, $items[2]);
        self::assertSame(20, $items[2]->fileSize());
    }

    #[Test]
    public function list_contents_deep_flag_flips_the_extra_param(): void
    {
        // When deep=true the extra param is '1' instead of '0' → distinct cache key.
        $this->seed($this->keyFor('list', 'app', 'f', '1', 0), []);

        $adapter = new NativeFilesystemAdapter();
        $items = iterator_to_array($adapter->listContents('f', true));
        self::assertSame([], $items);
    }

    #[Test]
    public function list_contents_returns_empty_when_result_is_not_array(): void
    {
        // Tauri returns null on error; adapter must yield [].
        $this->seed($this->keyFor('list', 'app', 'missing', '0', 0), null);

        $items = iterator_to_array((new NativeFilesystemAdapter())->listContents('missing', false));
        self::assertSame([], $items);
    }

    #[Test]
    public function copy_bridges_with_destination_as_extra(): void
    {
        $this->seed($this->keyFor('copy', 'app', 'src.txt', 'dst.txt', 0), true);

        $adapter = new NativeFilesystemAdapter();
        $adapter->copy('src.txt', 'dst.txt', new Config());
        self::assertTrue(true);
    }

    #[Test]
    public function move_bridges_with_destination_as_extra(): void
    {
        $this->seed($this->keyFor('move', 'app', 'old.txt', 'new.txt', 0), true);

        $adapter = new NativeFilesystemAdapter();
        $adapter->move('old.txt', 'new.txt', new Config());
        self::assertTrue(true);
    }

    #[Test]
    public function nb_prefix_in_path_routes_to_custom_baseDir(): void
    {
        // __nb:cache:key.bin → path='key.bin', baseDir='cache'
        $this->seed($this->keyFor('exists', 'cache', 'key.bin', '', 0), true);

        $adapter = new NativeFilesystemAdapter();
        self::assertTrue($adapter->fileExists('__nb:cache:key.bin'));
    }

    #[Test]
    public function opIndex_monotonically_increases_across_ops(): void
    {
        $this->seed($this->keyFor('exists', 'app', 'a', '', 0), true);
        $this->seed($this->keyFor('exists', 'app', 'b', '', 1), false);
        $this->seed($this->keyFor('exists', 'app', 'c', '', 2), true);

        $adapter = new NativeFilesystemAdapter();
        self::assertTrue($adapter->fileExists('a'));
        self::assertFalse($adapter->fileExists('b'));
        self::assertTrue($adapter->fileExists('c'));
    }
}
