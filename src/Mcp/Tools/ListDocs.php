<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Mcp\Tool;

class ListDocs implements Tool
{
    public function name(): string
    {
        return 'list_docs';
    }

    public function description(): string
    {
        return 'List the framework documentation pages with their topic and a short summary. Names are paths under the docs directory (e.g. "core/plugins.md", "mobile/media.md"). Use this to discover which page to fetch via read_doc before answering a question about a feature.';
    }

    public function inputSchema(): array
    {
        // No arguments: bare object schema — never embed an empty stdClass
        // (PHP cache serialization can corrupt it into __PHP_Incomplete_Class).
        return [
            'type' => 'object',
        ];
    }

    public function run(array $args): string
    {
        $root = $this->docsRoot();
        $docs = [];

        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                    continue;
                }
                $path = $file->getPathname();
                $name = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                [$title, $summary] = $this->parseHeader($path);
                $docs[] = [
                    'name' => $name,
                    'title' => $title,
                    'summary' => $summary,
                    'size_bytes' => filesize($path) ?: 0,
                ];
            }
        }

        usort($docs, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return json_encode([
            'root' => $root,
            'docs' => $docs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function docsRoot(): string
    {
        return dirname(__DIR__, 3) . '/docs/docs';
    }

    /**
     * @return array{0:?string, 1:?string}  [title from H1, summary from first paragraph]
     */
    private function parseHeader(string $path): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) return [null, null];

        $title = null;
        $summary = null;
        $reading = false;
        $buf = '';

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line);

            if ($title === null && str_starts_with($line, '# ')) {
                $title = trim(substr($line, 2));
                $reading = true;
                continue;
            }

            if ($reading) {
                if ($line === '' && $buf !== '') {
                    $summary = trim($buf);
                    break;
                }
                if ($line !== '' && !str_starts_with($line, '#')) {
                    $buf .= ($buf === '' ? '' : ' ') . $line;
                }
            }
        }

        fclose($fh);

        if ($summary === null && $buf !== '') {
            $summary = trim($buf);
        }

        if ($summary !== null && strlen($summary) > 240) {
            $summary = substr($summary, 0, 237) . '...';
        }

        return [$title, $summary];
    }
}
