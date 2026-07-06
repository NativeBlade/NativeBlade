<?php

namespace NativeBlade\Mcp;

use NativeBlade\Mcp\Tools\ArchitectureRecipe;
use NativeBlade\Mcp\Tools\DescribeFacadeMethod;
use NativeBlade\Mcp\Tools\ListDocs;
use NativeBlade\Mcp\Tools\ListFacadeMethods;
use NativeBlade\Mcp\Tools\ProjectState;
use NativeBlade\Mcp\Tools\ReadDoc;
use Throwable;

/**
 * NativeBlade MCP server.
 *
 * Speaks the Model Context Protocol over newline-delimited JSON-RPC on
 * stdin/stdout. Hosted by `php artisan nativeblade:mcp` so AI coding
 * agents (Claude Code, Cursor, Windsurf, etc.) can introspect the live
 * project — installed plugins, current config, available facade methods,
 * the framework's own .md docs — instead of relying on stale guidelines.
 *
 * Protocol: <https://modelcontextprotocol.io>
 */
class Server
{
    private const LATEST_PROTOCOL_VERSION = '2025-11-25';

    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /**
     * @param  Tool[]|null  $tools  Override the default tool set (used by tests).
     * @param  resource|null  $stdin  Input stream (defaults to STDIN).
     * @param  resource|null  $stdout Output stream (defaults to STDOUT).
     */
    public function __construct(?array $tools = null, $stdin = null, $stdout = null)
    {
        $this->stdin = $stdin ?? (defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));
        $this->stdout = $stdout ?? (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));

        $defaults = $tools ?? [
            new ListFacadeMethods(),
            new DescribeFacadeMethod(),
            new ProjectState(),
            new ListDocs(),
            new ReadDoc(),
            new ArchitectureRecipe(),
        ];

        foreach ($defaults as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function run(): void
    {
        while (($line = fgets($this->stdin)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $msg = null;
            try {
                $msg = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($msg)) {
                    throw new \RuntimeException('Invalid JSON-RPC message');
                }
                $response = $this->handle($msg);
                if ($response !== null) {
                    $this->write($response);
                }
            } catch (Throwable $e) {
                $id = is_array($msg) ? ($msg['id'] ?? null) : null;
                $this->write([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => $e->getCode() ?: -32603,
                        'message' => $e->getMessage(),
                    ],
                ]);
            }
        }
    }

    /**
     * Handle a single JSON-RPC message. Exposed so tests can drive the
     * server message-by-message without setting up streams.
     *
     * @param  array<string, mixed>  $msg
     * @return array<string, mixed>|null
     */
    public function handle(array $msg): ?array
    {
        $method = $msg['method'] ?? null;
        $id = $msg['id'] ?? null;
        $params = $msg['params'] ?? [];

        if (!is_string($method)) {
            throw new \RuntimeException('Missing method', -32600);
        }

        // Notifications carry no id and expect no response.
        if ($id === null && !$this->isRequestMethod($method)) {
            $this->handleNotification($method, $params);
            return null;
        }

        $result = match ($method) {
            'initialize' => $this->initialize(is_array($params) ? $params : []),
            'tools/list' => $this->toolsList(),
            'tools/call' => $this->toolsCall(is_array($params) ? $params : []),
            'ping' => new \stdClass(),
            default => throw new \RuntimeException("Method not found: $method", -32601),
        };

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function isRequestMethod(string $method): bool
    {
        return in_array($method, ['initialize', 'tools/list', 'tools/call', 'ping'], true);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function handleNotification(string $method, array $params): void
    {
        // notifications/initialized arrives after the initialize handshake.
        // We have nothing to do but accept it. Unknown notifications are
        // ignored per JSON-RPC convention.
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $negotiated = is_string($requested) && in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requested
            : self::LATEST_PROTOCOL_VERSION;

        return [
            'protocolVersion' => $negotiated,
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => 'nativeblade',
                'version' => $this->packageVersion(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolsList(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return ['tools' => $tools];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function toolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        if (!is_string($name) || $name === '' || !isset($this->tools[$name])) {
            throw new \RuntimeException("Tool not found: $name", -32602);
        }

        try {
            $text = $this->tools[$name]->run(is_array($args) ? $args : []);
            return [
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        } catch (Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        fwrite($this->stdout, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        fflush($this->stdout);
    }

    private function packageVersion(): string
    {
        $composerJson = dirname(__DIR__, 2) . '/composer.json';
        if (is_file($composerJson)) {
            $data = json_decode((string) file_get_contents($composerJson), true);
            if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
                return $data['version'];
            }
        }
        return 'dev';
    }
}
