<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Mcp;

use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\CustomPlugin;
use NativeBlade\Config\Plugin;
use NativeBlade\Config\PluginRegistry;
use NativeBlade\Mcp\Server;
use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;

class PublishingToolsTest extends TestCase
{
    private array $originalConfigs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigs = ShellConfig::getAppConfigs();
        $this->setConfigs([]);
    }

    protected function tearDown(): void
    {
        $this->setConfigs($this->originalConfigs);
        parent::tearDown();
    }

    private function setConfigs(array $configs): void
    {
        (new \ReflectionProperty(ShellConfig::class, 'appConfigs'))->setValue(null, $configs);
    }

    private function callPublishingTool(string $name, array $args = []): array
    {
        $result = (new Server())->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $args],
        ])['result'];
        $this->assertFalse($result['isError'] ?? false, $result['content'][0]['text']);
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_undeclared_plugins_resolve_to_all_and_guides_are_available(): void
    {
        foreach (['publish_android', 'publish_ios'] as $tool) {
            $data = $this->callPublishingTool($tool);
            $this->assertNull($data['project']['plugins']['declared']);
            $this->assertSame(array_map(fn ($p) => $p->value, Plugin::cases()), $data['project']['plugins']['effective']);
            $this->assertSame('all-included', $data['project']['plugins']['mode']);
            $this->assertCount(3, $data['next_questions']);
            $this->assertContains('plugin_analytics', array_column($data['remaining_questions'], 'id'));
            $this->assertFalse($data['freshness']['live_verified']);
            $this->assertSame('2026-09-12', $data['freshness']['reviewed_at']);
            $this->assertStringContainsString('https://', $data['guide']);
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $data['guide']);
        }
    }

    public function test_explicit_plugins_reuse_registry_and_keep_core_plugins(): void
    {
        $config = new ShellConfig();
        $config->plugins([Plugin::PUSH]);
        $config->android(fn (AndroidConfig $android) => $android->identifier('dev.example.app')->version('2.0.0', 8)->targetSdk(36));
        $data = $this->callPublishingTool('publish_android');
        $this->assertSame(['push'], $data['project']['plugins']['declared']);
        $this->assertSame(array_map(fn ($p) => $p->value, PluginRegistry::resolve([Plugin::PUSH])), $data['project']['plugins']['effective']);
        $this->assertNotContains('plugin_analytics', array_column($data['remaining_questions'], 'id'));
        $this->assertContains('plugin_push', array_column($data['remaining_questions'], 'id'));
        $permissions = array_column($data['project']['plugin_permissions'], null, 'plugin');
        $this->assertContains('POST_NOTIFICATIONS', $permissions['push']['android_permissions']);
        $this->assertSame('dev.example.app', $data['project']['platforms']['android']['identifier']);
        $this->assertSame([], $data['missing_configuration']);
    }

    public function test_explicit_empty_plugins_does_not_mean_all(): void
    {
        (new ShellConfig())->plugins([]);
        $data = $this->callPublishingTool('publish_ios');
        $this->assertSame([], $data['project']['plugins']['declared']);
        $this->assertSame(array_map(fn ($p) => $p->value, PluginRegistry::alwaysOn()), $data['project']['plugins']['effective']);
        $this->assertNotContains('plugin_admob', array_column($data['remaining_questions'], 'id'));
    }

    public function test_context_includes_custom_permissions_but_excludes_unrelated_config(): void
    {
        $this->setConfigs(['android' => ['identifier' => 'dev.app', 'secret' => 'never-expose-this'], 'firebase' => ['secret' => 'never-expose-this']]);
        (new ShellConfig())->customPlugins([CustomPlugin::init(
            feature: 'example', feature_crate: 'tauri-plugin-example', rust_init: 'secret_init()',
            version: '1.0', android_permissions: ['CAMERA'], ios_plist: ['NSCameraUsageDescription'],
        )]);
        $data = $this->callPublishingTool('publish_android');
        $this->assertSame(['CAMERA'], $data['project']['custom_plugins'][0]['android_permissions']);
        $this->assertStringNotContainsString('never-expose-this', json_encode($data));
        $this->assertStringNotContainsString('secret_init', json_encode($data));
    }

    public function test_answered_questions_and_known_release_are_not_repeated(): void
    {
        $data = $this->callPublishingTool('publish_android', ['release_type' => 'update', 'answers' => ['account' => 'Organization', 'distribution' => 'Internal testing']]);
        $ids = array_column($data['remaining_questions'], 'id');
        $this->assertNotContains('release_type', $ids);
        $this->assertNotContains('account', $ids);
        $this->assertNotContains('distribution', $ids);
        $this->assertSame('Organization', $data['answers']['account']);
        $this->assertSame('audience', $data['next_questions'][0]['id']);
    }

    public function test_release_type_answered_as_a_question_is_consumed(): void
    {
        $data = $this->callPublishingTool('publish_android', ['answers' => ['release_type' => 'First release']]);
        $ids = array_column($data['remaining_questions'], 'id');
        $this->assertSame('first_release', $data['release_type']);
        $this->assertNotContains('release_type', $ids);
    }

    public function test_unrecognized_release_type_answer_keeps_the_question(): void
    {
        $data = $this->callPublishingTool('publish_android', ['answers' => ['release_type' => 'not sure yet']]);
        $ids = array_column($data['remaining_questions'], 'id');
        $this->assertSame('unknown', $data['release_type']);
        $this->assertContains('release_type', $ids);
    }

    public function test_listing_without_facts_does_not_invent_copy(): void
    {
        $data = $this->callPublishingTool('store_listing');
        $this->assertSame('', $data['draft']['android']['release_notes']);
        $this->assertSame('', $data['draft']['ios']['description']);
        $this->assertFalse($data['text_checks_passed']);
        $this->assertContains('ios.keywords', $data['missing_fields']);
    }

    public function test_listing_distinguishes_product_copy_and_changes(): void
    {
        $data = $this->callPublishingTool('store_listing', [
            'name' => 'Agenda', 'summary' => 'Organize seu dia.', 'features' => ['Veja seus compromissos.'],
            'changes' => ['Agora você pode editar lembretes.'],
            'metadata' => ['ios' => ['keywords' => 'agenda,lembretes']],
        ]);
        $this->assertSame("Organize seu dia.\n\nVeja seus compromissos.", $data['draft']['android']['description']);
        $this->assertSame('Agora você pode editar lembretes.', $data['draft']['ios']['release_notes']);
        $this->assertTrue($data['text_checks_passed']);
    }

    public function test_unicode_limits_use_characters_except_ios_byte_fields(): void
    {
        $data = $this->callPublishingTool('store_listing', ['metadata' => [
            'android' => ['release_notes' => str_repeat('é', 500)],
            'ios' => ['keywords' => str_repeat('é', 51), 'review_notes' => str_repeat('é', 2001)],
        ]]);
        $this->assertSame(500, $data['validation']['android']['release_notes']['length']);
        $this->assertSame([], $data['validation']['android']['release_notes']['issues']);
        $this->assertSame(102, $data['validation']['ios']['keywords']['length']);
        $this->assertContains('over_limit', $data['validation']['ios']['keywords']['issues']);
        $this->assertContains('over_limit', $data['validation']['ios']['review_notes']['issues']);
        $this->assertSame(str_repeat('é', 51), $data['draft']['ios']['keywords']);
    }

    public function test_long_dashes_html_and_short_ios_names_are_flagged(): void
    {
        $data = $this->callPublishingTool('store_listing', ['metadata' => ['ios' => [
            'name' => 'A', 'description' => "Agenda\u{2014}organize\u{2013}hoje", 'subtitle' => '<b>Agenda</b>',
        ]]]);
        $this->assertContains('use_plain_punctuation', $data['validation']['ios']['description']['issues']);
        $this->assertContains('use_plain_text', $data['validation']['ios']['subtitle']['issues']);
        $this->assertContains('minimum_2_characters', $data['validation']['ios']['name']['issues']);
    }

    public function test_first_release_does_not_require_release_notes(): void
    {
        $data = $this->callPublishingTool('store_listing', ['platform' => 'ios', 'release_type' => 'first_release', 'name' => 'Agenda', 'summary' => 'Organize seu dia.', 'metadata' => ['ios' => ['keywords' => 'agenda']]]);
        $this->assertTrue($data['text_checks_passed']);
        $this->assertArrayNotHasKey('android', $data['draft']);
    }

    public function test_invalid_arguments_are_mcp_tool_errors(): void
    {
        foreach ([
            ['publish_android', ['release_type' => 'bad']],
            ['publish_ios', ['answers' => ['account' => true]]],
            ['publish_ios', ['answers' => ['password' => 'secret']]],
            ['store_listing', ['platform' => 'desktop']],
            ['store_listing', ['locale' => '../pt']],
            ['store_listing', ['features' => ['x' => 'not a list']]],
            ['store_listing', ['changes' => [123]]],
            ['store_listing', ['platform' => 'android', 'metadata' => ['ios' => []]]],
            ['store_listing', ['metadata' => ['android' => ['unknown' => 'text']]]],
        ] as [$tool, $args]) {
            $result = (new Server())->handle(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]])['result'];
            $this->assertTrue($result['isError'] ?? false, $tool . ': ' . json_encode($args));
        }
    }
}
