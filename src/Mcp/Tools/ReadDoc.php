<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Mcp\Tool;

class ReadDoc implements Tool
{
    public function name(): string
    {
        return 'read_doc';
    }

    public function description(): string
    {
        return 'Return the full Markdown content of a framework documentation page. Pass the path as returned by list_docs (e.g. "core/plugins.md"). Only files inside the framework docs directory are accessible.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Documentation page path with extension, as returned by list_docs (e.g. "mobile/media.md").',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function run(array $args): string
    {
        $name = $args['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Argument "name" is required.');
        }

        $name = str_replace('\\', '/', $name);
        if (!preg_match('#^[A-Za-z0-9_/-]+\.md$#', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Invalid doc name. Use the exact path from list_docs (e.g. "core/plugins.md").');
        }

        $root = realpath($this->docsRoot());
        $real = realpath($this->docsRoot() . '/' . $name);
        if ($root === false || $real === false
            || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            || !is_file($real)) {
            throw new \InvalidArgumentException("Doc '$name' not found. Call list_docs to see what is available.");
        }

        $content = file_get_contents($real);
        if ($content === false) {
            throw new \RuntimeException("Failed to read '$name'.");
        }

        return $content;
    }

    private function docsRoot(): string
    {
        return dirname(__DIR__, 3) . '/docs/docs';
    }
}
