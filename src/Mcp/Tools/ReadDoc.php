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
        return 'Return the full Markdown content of a framework documentation file. Pass the file name as returned by list_docs (e.g. "PLUGINS.md"). Only files inside the framework docs directory are accessible.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Documentation file name with extension, as returned by list_docs (e.g. "MEDIA.md").',
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

        $name = basename($name);
        if (!preg_match('/^[A-Za-z0-9_-]+\.md$/', $name)) {
            throw new \InvalidArgumentException('Invalid doc name. Use the exact file name from list_docs (e.g. "PLUGINS.md").');
        }

        $path = $this->docsRoot() . '/' . $name;
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Doc '$name' not found. Call list_docs to see what is available.");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read '$name'.");
        }

        return $content;
    }

    private function docsRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
