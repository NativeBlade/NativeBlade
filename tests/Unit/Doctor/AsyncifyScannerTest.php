<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit\Doctor;

use NativeBlade\Doctor\AsyncifyScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AsyncifyScannerTest extends TestCase
{
    private AsyncifyScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new AsyncifyScanner();
    }

    /** @return list<array{line: int, rule: string, severity: string, message: string}> */
    private function scan(string $body): array
    {
        return $this->scanner->scanSource("<?php\n" . $body);
    }

    #[Test]
    public function reports_the_sleep_family_as_errors(): void
    {
        $findings = $this->scan("sleep(1);\n\\usleep(500);\ntime_nanosleep(0, 1);");

        self::assertCount(3, $findings);
        self::assertSame([2, 3, 4], array_column($findings, 'line'));
        self::assertSame(['error', 'error', 'error'], array_column($findings, 'severity'));
        self::assertStringContainsString('sleep() pauses PHP inside php-wasm', $findings[0]['message']);
        self::assertStringContainsString('usleep()', $findings[1]['message']);
    }

    #[Test]
    public function reports_sockets_processes_and_curl(): void
    {
        $findings = $this->scan("fsockopen('a', 80);\nproc_open('ls', [], \$p);\nexec('ls');\ncurl_exec(\$ch);");

        self::assertCount(4, $findings);
        self::assertStringContainsString('opens a socket', $findings[0]['message']);
        self::assertStringContainsString('starts a process', $findings[1]['message']);
        self::assertStringContainsString('starts a process', $findings[2]['message']);
        self::assertStringContainsString('curl is not compiled', $findings[3]['message']);
    }

    #[Test]
    public function ignores_methods_and_declarations_with_the_same_name(): void
    {
        $findings = $this->scan(
            "\$this->sleep(1);\n\$job?->exec();\nfunction sleep(int \$s) {}\nclass Runner { public function system() {} }\nconst exec = 1;"
        );

        self::assertSame([], $findings);
    }

    #[Test]
    public function reports_the_sleep_helper_class_but_not_its_import(): void
    {
        $findings = $this->scan("use Illuminate\\Support\\Sleep;\nSleep::for(1)->second();\n\\Illuminate\\Support\\Sleep::usleep(5);");

        self::assertCount(2, $findings);
        self::assertSame([3, 4], array_column($findings, 'line'));
        self::assertStringContainsString('Sleep:: pauses PHP', $findings[0]['message']);
    }

    #[Test]
    public function warns_about_retry_only_when_it_has_a_delay(): void
    {
        self::assertSame([], $this->scan("retry(3, fn () => 1);\nretry(3, fn () => 1, 0);"));

        $findings = $this->scan("retry(3, function () { return 1; }, 100);");
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('retry() with a delay', $findings[0]['message']);
    }

    #[Test]
    public function reports_http_retry_with_a_delay_as_an_error_and_the_method_form_as_a_warning(): void
    {
        self::assertSame([], $this->scan("Http::retry(3)->get(\$u);\nHttp::retry(3, 0)->get(\$u);\n\$client->retry(3, 0);"));

        $findings = $this->scan(
            "use Illuminate\\Support\\Facades\\Http;\n"
            . "Http::retry(3, 100)->get(\$u);\n"
            . "\$client->retry(3, [100, 200]);\n"
            . "\\Illuminate\\Support\\Facades\\Http::retry(2, \$ms);"
        );

        self::assertSame([3, 4, 5], array_column($findings, 'line'));
        self::assertSame(['error', 'warning', 'error'], array_column($findings, 'severity'));
        self::assertStringContainsString('Http::retry() with a delay', $findings[0]['message']);
        self::assertStringContainsString('retry() on an HTTP client with a delay', $findings[1]['message']);
    }

    #[Test]
    public function warns_about_laravel_methods_that_sleep_underneath(): void
    {
        $findings = $this->scan("Cache::lock('k', 10)->block(5);\nLazyCollection::make(\$g)->throttle(2);\n\$x->block;");

        self::assertSame([2, 3], array_column($findings, 'line'));
        self::assertSame(['warning', 'warning'], array_column($findings, 'severity'));
        self::assertStringContainsString('block() on a cache lock', $findings[0]['message']);
        self::assertStringContainsString('throttle() on a lazy collection', $findings[1]['message']);
    }

    #[Test]
    public function reports_the_process_facade_and_class_only_when_the_file_names_them(): void
    {
        // A model or service called Process is common; without an import it is not Laravel's.
        self::assertSame([], $this->scan("Process::where('a', 1)->get();\nnew Process(\$data);"));

        $findings = $this->scan(
            "use Illuminate\\Support\\Facades\\Process;\n"
            . "Process::run('ls');\n"
            . "new \\Symfony\\Component\\Process\\Process(['ls']);\n"
            . "\\Illuminate\\Support\\Facades\\Process::start('ls');"
        );

        self::assertSame([3, 4, 5], array_column($findings, 'line'));
        self::assertSame(['error', 'error', 'error'], array_column($findings, 'severity'));
        self::assertStringContainsString('Process starts a process', $findings[0]['message']);
        self::assertStringContainsString('new Process() starts a process', $findings[1]['message']);
    }

    #[Test]
    public function resolves_use_aliases_and_group_imports(): void
    {
        $findings = $this->scan(
            "use Illuminate\\Support\\Sleep as Pause;\n"
            . "use Illuminate\\Support\\{Str, Sleep as Nap};\n"
            . "use App\\Models\\Sleep;\n"
            . "Pause::for(1)->second();\nNap::usleep(1);\nSleep::create([]);"
        );

        self::assertSame([5, 6], array_column($findings, 'line'));
        self::assertStringContainsString('Sleep:: pauses PHP', $findings[0]['message']);
    }

    #[Test]
    public function reports_a_suspending_function_called_by_name(): void
    {
        $findings = $this->scan("call_user_func('usleep', 5);\ncall_user_func_array('\\\\sleep', [1]);\ncall_user_func([\$this, 'usleep']);\ncall_user_func(\$fn);");

        self::assertSame([2, 3], array_column($findings, 'line'));
        self::assertStringContainsString("call_user_func('usleep') pauses PHP", $findings[0]['message']);
        self::assertStringContainsString("call_user_func_array('sleep') pauses PHP", $findings[1]['message']);
    }

    #[Test]
    public function reports_url_literals_in_file_openers_only(): void
    {
        $findings = $this->scan(
            "file_get_contents('https://api.test/x');\nfile_get_contents(\$path);\nfopen(\"http://a.test\", 'r');\nfile_get_contents(storage_path('a.json'));"
        );

        self::assertSame([2, 4], array_column($findings, 'line'));
        self::assertStringContainsString('file_get_contents() on a URL opens a socket', $findings[0]['message']);
    }

    #[Test]
    public function reports_a_native_call_inside_an_unpausable_callback(): void
    {
        $findings = $this->scan(
            "usort(\$items, fn (\$a, \$b) => Http::get(\$a['url'])->json('n') <=> 0);\n"
            . "json_encode(\$x);\n"
            . "array_walk(\$rows, function (&\$row) { \$row['disk'] = Storage::disk('native')->get(\$row['path']); });\n"
            . "preg_replace_callback('/x/', static fn (\$m) => \\NativeBlade::scan(), \$s);"
        );

        self::assertSame([2, 4, 5], array_column($findings, 'line'));
        self::assertSame('callback', $findings[0]['rule']);
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('Http::get() inside the callback of usort()', $findings[0]['message']);
        self::assertStringContainsString('Storage::disk() inside the callback of array_walk()', $findings[1]['message']);
        self::assertStringContainsString('NativeBlade::scan() inside the callback of preg_replace_callback()', $findings[2]['message']);
    }

    #[Test]
    public function a_sleep_inside_an_unpausable_callback_is_reported_once_per_cause(): void
    {
        $findings = $this->scan("usort(\$a, function (\$x, \$y) { usleep(1); return 0; });");

        // The usleep() itself (error) and the callback (warning): both are real.
        self::assertSame(['callback', 'suspension'], array_column($findings, 'rule'));
    }

    #[Test]
    public function leaves_pausable_callbacks_and_plain_sorts_alone(): void
    {
        $findings = $this->scan(
            "array_map(fn (\$u) => Http::get(\$u), \$urls);\n"
            . "usort(\$a, fn (\$x, \$y) => \$x <=> \$y);\n"
            . "collect(\$a)->map(fn (\$x) => DB::table('t')->find(\$x));\n"
            . "usort(\$a, [\$this, 'compare']);"
        );

        self::assertSame([], $findings);
    }

    #[Test]
    public function compiles_blade_views_before_scanning(): void
    {
        $dir = sys_get_temp_dir() . '/nb-doctor-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/a.blade.php', "<div>{{ sleep(1) }}</div>");
        file_put_contents($dir . '/b.php', "<?php\nusleep(1);");
        file_put_contents($dir . '/c.txt', "sleep(1);");

        try {
            $raw = (new AsyncifyScanner())->scanPaths([$dir]);
            self::assertSame(['b.php'], array_map('basename', array_column($raw, 'file')));

            $compiled = (new AsyncifyScanner(fn (string $s) => str_replace(['{{ ', ' }}'], ['<?php echo ', '; ?>'], $s)))->scanPaths([$dir]);
            self::assertSame(['a.blade.php', 'b.php'], array_map('basename', array_column($compiled, 'file')));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    #[Test]
    public function tolerates_source_that_does_not_tokenize(): void
    {
        self::assertSame([], $this->scanner->scanSource(''));
        self::assertSame([], $this->scanner->scanSource("<?php class {"));
    }
}
