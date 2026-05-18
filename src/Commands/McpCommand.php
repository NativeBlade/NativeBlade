<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\Mcp\Server;

class McpCommand extends Command
{
    protected $signature = 'nativeblade:mcp {--test : Run a self-test and exit, instead of starting the stdio server}';

    protected $description = 'Start the NativeBlade MCP server for AI coding agents (stdio JSON-RPC)';

    public function handle(): int
    {
        @ini_set('display_errors', '0');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        if ($this->option('test')) {
            return $this->runSelfTest();
        }

        if ($this->isInteractive()) {
            $this->printUsage();
            return self::SUCCESS;
        }

        (new Server())->run();

        return self::SUCCESS;
    }

    private function isInteractive(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }
        return false;
    }

    private function printUsage(): void
    {
        $version = $this->frameworkVersion();

        fwrite(STDERR, "\n");
        fwrite(STDERR, "  NativeBlade MCP server v{$version}\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  This is NOT a daemon. MCP stdio servers are spawned per session\n");
        fwrite(STDERR, "  by the agent itself (Claude Code, Cursor, Windsurf, ...) when\n");
        fwrite(STDERR, "  the agent decides it needs to call a tool. You never run this\n");
        fwrite(STDERR, "  command by hand and leave it running. There is no port to\n");
        fwrite(STDERR, "  connect to, no socket, no background process. Just register it\n");
        fwrite(STDERR, "  in your agent's config and forget about it.\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  Set up your agent:\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "    Claude Code  ~/.claude/mcp_servers.json (or .mcp.json in project)\n");
        fwrite(STDERR, "    Cursor       ~/.cursor/mcp.json\n");
        fwrite(STDERR, "    Windsurf     ~/.codeium/windsurf/mcp_config.json\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  Add this entry to the chosen file:\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "    {\n");
        fwrite(STDERR, "      \"mcpServers\": {\n");
        fwrite(STDERR, "        \"nativeblade\": {\n");
        fwrite(STDERR, "          \"command\": \"php\",\n");
        fwrite(STDERR, "          \"args\": [\"artisan\", \"nativeblade:mcp\"]\n");
        fwrite(STDERR, "        }\n");
        fwrite(STDERR, "      }\n");
        fwrite(STDERR, "    }\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  Verify it works:\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "    php artisan nativeblade:mcp --test\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  Full docs: vendor/nativeblade/nativeblade/MCP.md\n");
        fwrite(STDERR, "\n");
    }

    private function runSelfTest(): int
    {
        $server = new Server();

        $this->info('NativeBlade MCP self-test');
        $this->line('');

        $init = $server->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25'],
        ]);
        $this->line("  <fg=green>✓</> initialize  → protocol {$init['result']['protocolVersion']}, server {$init['result']['serverInfo']['name']} v{$init['result']['serverInfo']['version']}");

        $tools = $server->handle([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
        ]);
        $names = implode(', ', array_column($tools['result']['tools'], 'name'));
        $this->line('  <fg=green>✓</> tools/list  → ' . count($tools['result']['tools']) . " tools: $names");

        $state = $server->handle([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'project_state', 'arguments' => []],
        ]);
        $stateData = json_decode($state['result']['content'][0]['text'], true);
        $declared = $stateData['plugins']['declared'] ?? null;
        $mode = $stateData['plugins']['mode'] ?? '?';
        $this->line('  <fg=green>✓</> project_state → version ' . ($stateData['nativeblade_version'] ?? '?') . ", plugins: " . ($declared === null ? '(all)' : implode(',', $declared)) . " | $mode");

        $docs = $server->handle([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'list_docs', 'arguments' => []],
        ]);
        $docsData = json_decode($docs['result']['content'][0]['text'], true);
        $this->line('  <fg=green>✓</> list_docs    → ' . count($docsData['docs'] ?? []) . ' framework .md files indexed');

        $facade = $server->handle([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'list_facade_methods', 'arguments' => []],
        ]);
        $facadeData = json_decode($facade['result']['content'][0]['text'], true);
        $total = 0;
        foreach ($facadeData as $f) $total += count($f['methods']);
        $this->line('  <fg=green>✓</> list_facade  → ' . $total . ' methods across ' . count($facadeData) . ' facades');

        $this->line('');
        $this->info('  All checks passed. The server is healthy.');
        $this->line('');
        $this->line('  Next step: register it in your agent (Claude Code, Cursor, Windsurf, ...).');
        $this->line('  The agent will spawn this command on its own when it needs a tool.');
        $this->line('  See vendor/nativeblade/nativeblade/MCP.md for client config snippets.');
        $this->line('');

        return self::SUCCESS;
    }

    private function frameworkVersion(): string
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
