<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Mcp;

use NativeBlade\Mcp\Server;
use NativeBlade\Tests\TestCase;

class McpServerTest extends TestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new Server();
    }

    public function test_initialize_returns_protocol_handshake(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertSame('2025-11-25', $response['result']['protocolVersion']);
        $this->assertSame('nativeblade', $response['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function test_initialize_negotiates_supported_client_version(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ]);

        $this->assertSame('2024-11-05', $response['result']['protocolVersion']);
    }

    public function test_initialize_falls_back_to_latest_for_unknown_client_version(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '1999-01-01'],
        ]);

        $this->assertSame('2025-11-25', $response['result']['protocolVersion']);
    }

    public function test_tools_list_returns_all_six_tools(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);

        $tools = $response['result']['tools'];
        $names = array_column($tools, 'name');
        sort($names);

        $this->assertSame([
            'architecture_recipe',
            'describe_facade_method',
            'list_docs',
            'list_facade_methods',
            'project_state',
            'read_doc',
        ], $names);

        foreach ($tools as $tool) {
            $this->assertNotEmpty($tool['description']);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function test_architecture_recipe_without_args_lists_available_recipes(): void
    {
        $payload = $this->callTool('architecture_recipe');
        $data = json_decode($payload, true);

        $this->assertArrayHasKey('available', $data);
        $names = array_column($data['available'], 'name');

        $this->assertContains('component-controller', $names);
        $this->assertContains('form-validation', $names);
        $this->assertContains('global-state', $names);
        $this->assertContains('push-handler', $names);
        $this->assertContains('debugging', $names);
        $this->assertContains('anti-patterns', $names);
    }

    public function test_architecture_recipe_returns_debugging_with_nativeblade_log(): void
    {
        $payload = $this->callTool('architecture_recipe', ['use_case' => 'debugging']);

        $this->assertStringContainsString('NativeBlade::log', $payload);
        $this->assertStringContainsString('[NB:info]', $payload);
        $this->assertStringContainsString("dd()", $payload);
    }

    public function test_architecture_recipe_enums_covers_magic_strings_and_numbers(): void
    {
        $payload = $this->callTool('architecture_recipe', ['use_case' => 'enums-and-constants']);

        $this->assertStringContainsString('enum LessonStatus', $payload);
        $this->assertStringContainsString('app/Enums/', $payload);
        $this->assertStringContainsString('tryFrom', $payload);
        $this->assertStringContainsString('private const', $payload);
    }

    public function test_architecture_recipe_i18n_covers_both_apis_and_locale_state(): void
    {
        $payload = $this->callTool('architecture_recipe', ['use_case' => 'i18n']);

        $this->assertStringContainsString('LocaleState', $payload);
        $this->assertStringContainsString("__(", $payload);
        $this->assertStringContainsString("t('", $payload);
        $this->assertStringContainsString('ApplyLocale', $payload);
        $this->assertStringContainsString('lang/{locale}.json', $payload);
        $this->assertStringContainsString('lang/{locale}/{file}.php', $payload);
    }

    public function test_architecture_recipe_returns_recipe_body_for_known_name(): void
    {
        $payload = $this->callTool('architecture_recipe', ['use_case' => 'push-handler']);

        $this->assertStringContainsString('# push-handler', $payload);
        $this->assertStringContainsString('handle(PushPayload', $payload);
        $this->assertStringContainsString('app/Native/Push/', $payload);
    }

    public function test_architecture_recipe_rejects_unknown_use_case(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 40,
            'method' => 'tools/call',
            'params' => ['name' => 'architecture_recipe', 'arguments' => ['use_case' => 'no-such-recipe']],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
    }

    public function test_unknown_method_throws_method_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(-32601);

        $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'no/such/method',
        ]);
    }

    public function test_notification_without_id_returns_null(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $this->assertNull($response);
    }

    public function test_ping_is_supported(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'ping',
        ]);

        $this->assertSame(3, $response['id']);
        $this->assertIsObject($response['result']);
    }

    public function test_list_facade_methods_returns_both_facades(): void
    {
        $payload = $this->callTool('list_facade_methods');
        $data = json_decode($payload, true);

        $facades = array_column($data, 'facade');
        $this->assertContains('NativeBlade', $facades);
        $this->assertContains('NativeBladeConfig', $facades);

        // Each facade must surface at least its known core methods.
        $nbMethods = array_column($data[0]['methods'], 'name');
        $this->assertContains('notification', $nbMethods);
        $this->assertContains('isMobile', $nbMethods);
        $this->assertContains('biometric', $nbMethods);
    }

    public function test_describe_facade_method_returns_signature_and_summary(): void
    {
        $payload = $this->callTool('describe_facade_method', ['name' => 'notification']);
        $data = json_decode($payload, true);

        $this->assertTrue($data['found']);
        $this->assertSame('notification', $data['name']);
        $this->assertStringContainsString('Closure', $data['signature']);
        $this->assertNotNull($data['summary']);
        $this->assertSame('NativeBlade\\NativeResponse::notification()', $data['source']);
    }

    public function test_describe_facade_method_for_platform_check_resolves_to_shellconfig(): void
    {
        $payload = $this->callTool('describe_facade_method', ['name' => 'isMobile']);
        $data = json_decode($payload, true);

        $this->assertTrue($data['found']);
        $this->assertStringContainsString('bool', $data['signature']);
        $this->assertSame('NativeBlade\\ShellConfig::isMobile()', $data['source']);
    }

    public function test_describe_facade_method_returns_not_found_for_unknown(): void
    {
        $payload = $this->callTool('describe_facade_method', ['name' => 'totallyMadeUpThing']);
        $data = json_decode($payload, true);

        $this->assertFalse($data['found']);
    }

    public function test_describe_facade_method_requires_name_argument(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => 'describe_facade_method', 'arguments' => []],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
    }

    public function test_project_state_lists_plugin_catalog(): void
    {
        $payload = $this->callTool('project_state');
        $data = json_decode($payload, true);

        $this->assertArrayHasKey('nativeblade_version', $data);
        $this->assertArrayHasKey('plugins', $data);
        $this->assertContains('biometric', $data['plugins']['all_available']);
        $this->assertContains('push', $data['plugins']['all_available']);
        $this->assertArrayHasKey('transition', $data);
    }

    public function test_list_docs_returns_readme_and_plugins(): void
    {
        $payload = $this->callTool('list_docs');
        $data = json_decode($payload, true);

        $names = array_column($data['docs'], 'name');
        $this->assertContains('README.md', $names);
        $this->assertContains('PLUGINS.md', $names);
    }

    public function test_read_doc_returns_file_content(): void
    {
        $payload = $this->callTool('read_doc', ['name' => 'PLUGINS.md']);
        $this->assertNotEmpty($payload);
        $this->assertStringContainsString('# Native Plugins', $payload);
    }

    public function test_read_doc_rejects_path_traversal(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => ['name' => 'read_doc', 'arguments' => ['name' => '../composer.json']],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
    }

    public function test_read_doc_rejects_unknown_file(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => ['name' => 'read_doc', 'arguments' => ['name' => 'NOPE.md']],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
    }

    public function test_tools_call_with_unknown_tool_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'tools/call',
            'params' => ['name' => 'no_such_tool', 'arguments' => []],
        ]);
    }

    public function test_stdio_loop_handles_newline_delimited_json(): void
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');

        $input = json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
            ]) . "\n";
        fwrite($stdin, $input);
        rewind($stdin);

        $server = new Server(null, $stdin, $stdout);
        $server->run();

        rewind($stdout);
        $output = trim(stream_get_contents($stdout));
        $decoded = json_decode($output, true);

        $this->assertSame(1, $decoded['id']);
        $this->assertSame('nativeblade', $decoded['result']['serverInfo']['name']);

        fclose($stdin);
        fclose($stdout);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function callTool(string $tool, array $args = []): string
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $args],
        ]);

        return $response['result']['content'][0]['text'];
    }
}
