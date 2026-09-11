<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Feature\Mcp;

use Composer\InstalledVersions;
use Illuminate\Support\ServiceProvider;
use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\CustomPlugin;
use NativeBlade\Config\IosConfig;
use NativeBlade\Config\Plugin;
use NativeBlade\Config\PluginRegistry;
use NativeBlade\Facades\NativeBladeConfig;
use NativeBlade\Mcp\Server;
use NativeBlade\ShellConfig;
use NativeBlade\Tests\TestCase;

class ProjectStateTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['appConfigs' => [], 'name' => null, 'transition' => 'none'] as $key => $value) {
            $property = new \ReflectionProperty(ShellConfig::class, $key);
            $this->saved[$key] = $property->getValue();
            $property->setValue(null, $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            (new \ReflectionProperty(ShellConfig::class, $key))->setValue(null, $value);
        }
        parent::tearDown();
    }

    private function state(): array
    {
        $result = (new Server())->handle([
            'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'project_state'],
        ])['result'];
        $this->assertFalse($result['isError'] ?? false, $result['content'][0]['text']);
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_configuration_is_loaded_through_a_laravel_service_provider(): void
    {
        $this->app->register(ProjectStateFixtureProvider::class);
        $data = $this->state();

        $this->assertSame('Minha Agenda', $data['project']['name']);
        $this->assertSame(base_path(), $data['project']['base_path']);
        $this->assertSame('slide', $data['transition']);
        $this->assertSame(['push'], $data['plugins']['declared']);
        $this->assertContains('store', $data['plugins']['effective']);
        $this->assertNotContains('media', $data['plugins']['effective']);
        $this->assertSame(['version' => '2.3.0', 'buildNumber' => 12], $data['versions']['android']);
        $this->assertSame(['CAMERA' => 'Fotografar recibos'], $data['app_configs']['android']['permissions']);
        $this->assertSame('dev.agenda.ios', $data['app_configs']['ios']['identifier']);
        $this->assertSame([], $data['platform_status']['ios']['missing_version_fields']);
        $this->assertFalse($data['platform_status']['desktop']['configured']);
        $this->assertNull($data['versions']['desktop']);
        $this->assertFalse($data['configuration_source']['reads_provider_from_disk_on_each_call']);
    }

    public function test_no_declaration_means_all_but_empty_selection_means_only_core(): void
    {
        $data = $this->state();
        $this->assertNull($data['plugins']['declared']);
        $this->assertSame(array_map(fn ($p) => $p->value, Plugin::cases()), $data['plugins']['effective']);
        $this->assertNotEmpty($data['diagnostics']);

        NativeBladeConfig::plugins([]);
        $data = $this->state();
        $this->assertSame([], $data['plugins']['declared']);
        $this->assertSame(array_map(fn ($p) => $p->value, PluginRegistry::alwaysOn()), $data['plugins']['effective']);
    }

    public function test_custom_plugins_have_explicit_descriptors(): void
    {
        NativeBladeConfig::customPlugins([CustomPlugin::init(
            feature: 'fingerprint', feature_crate: 'tauri-plugin-fingerprint', rust_init: 'plugin::init()',
            version: '1.2.0', android_permissions: ['USE_BIOMETRIC'], ios_plist: ['NSFaceIDUsageDescription'],
        )]);
        $data = $this->state();
        $this->assertSame('fingerprint', $data['custom_plugins'][0]['feature']);
        $this->assertSame('1.2.0', $data['custom_plugins'][0]['version']);
        $this->assertSame(['USE_BIOMETRIC'], $data['custom_plugins'][0]['android_permissions']);
    }

    public function test_partial_version_reports_the_missing_field(): void
    {
        (new \ReflectionProperty(ShellConfig::class, 'appConfigs'))->setValue(null, [
            'android' => ['identifier' => 'dev.agenda', 'version' => '2.0.0'],
        ]);
        $data = $this->state();
        $this->assertTrue($data['platform_status']['android']['configured']);
        $this->assertSame(['buildNumber'], $data['platform_status']['android']['missing_version_fields']);
        $this->assertNull($data['versions']['android']);
    }

    public function test_package_version_comes_from_composer_install_metadata(): void
    {
        $this->assertTrue(InstalledVersions::isInstalled('nativeblade/nativeblade'));
        $this->assertSame(InstalledVersions::getPrettyVersion('nativeblade/nativeblade'), $this->state()['nativeblade_version']);
    }

    public function test_invalid_configuration_surfaces_as_a_tool_error_instead_of_null(): void
    {
        (new \ReflectionProperty(ShellConfig::class, 'appConfigs'))->setValue(null, ['android' => ['identifier' => "\xB1\x31"]]);
        $response = (new Server())->handle(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'project_state']]);
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('UTF-8', $response['result']['content'][0]['text']);
    }

    public function test_stdio_returns_loaded_provider_state(): void
    {
        $this->app->register(ProjectStateFixtureProvider::class);
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');
        try {
            fwrite($stdin, json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'project_state']]) . "\n");
            rewind($stdin);
            (new Server(null, $stdin, $stdout))->run();
            rewind($stdout);
            $response = json_decode(stream_get_contents($stdout), true, 512, JSON_THROW_ON_ERROR);
            $data = json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(3, $response['id']);
            $this->assertSame('Minha Agenda', $data['project']['name']);
            $this->assertContains('push', $data['plugins']['effective']);
        } finally {
            fclose($stdin);
            fclose($stdout);
        }
    }
}

class ProjectStateFixtureProvider extends ServiceProvider
{
    public function boot(): void
    {
        NativeBladeConfig::name('Minha Agenda');
        NativeBladeConfig::transition('slide');
        NativeBladeConfig::plugins([Plugin::PUSH]);
        NativeBladeConfig::android(fn (AndroidConfig $config) => $config
            ->identifier('dev.agenda.android')->version('2.3.0', 12)
            ->permissions(['CAMERA' => 'Fotografar recibos']));
        NativeBladeConfig::ios(fn (IosConfig $config) => $config->identifier('dev.agenda.ios')->version('2.3.0', 9));
    }
}
