<?php

declare(strict_types=1);

namespace NativeBlade\Tests\Unit;

use NativeBlade\Config\AndroidConfig;
use NativeBlade\Config\DesktopConfig;
use NativeBlade\Config\IosConfig;
use NativeBlade\ShellConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * ShellConfig stores desktop/android/ios build configs in a static map.
 * These tests reset that static state between runs so order doesn't matter.
 */
final class ShellConfigBuildersTest extends TestCase
{
    private ShellConfig $config;

    protected function setUp(): void
    {
        $this->config = new ShellConfig();
        $this->resetStatics();
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
    }

    private function resetStatics(): void
    {
        $ref = new ReflectionClass(ShellConfig::class);
        $p = $ref->getProperty('appConfigs');
        $p->setAccessible(true);
        $p->setValue(null, []);

        $p = $ref->getProperty('transition');
        $p->setAccessible(true);
        $p->setValue(null, 'none');
    }

    #[Test]
    public function desktop_builder_registers_config_under_desktop_key(): void
    {
        $this->config->desktop(function (DesktopConfig $cfg) {
            $cfg->title('MyApp')->version('1.2.3', 7);
        });

        $configs = ShellConfig::getAppConfigs();
        self::assertArrayHasKey('desktop', $configs);
        self::assertSame('MyApp', $configs['desktop']['title']);
        self::assertSame('1.2.3', $configs['desktop']['version']);
        self::assertSame(7, $configs['desktop']['buildNumber']);
    }

    #[Test]
    public function android_builder_registers_config_under_android_key(): void
    {
        $this->config->android(function (AndroidConfig $cfg) {
            $cfg->version('2.0.0', 10);
        });

        $configs = ShellConfig::getAppConfigs();
        self::assertArrayHasKey('android', $configs);
        self::assertSame('2.0.0', $configs['android']['version']);
    }

    #[Test]
    public function ios_builder_registers_config_under_ios_key(): void
    {
        $this->config->ios(function (IosConfig $cfg) {
            $cfg->version('3.0.0', 20);
        });

        $configs = ShellConfig::getAppConfigs();
        self::assertArrayHasKey('ios', $configs);
        self::assertSame('3.0.0', $configs['ios']['version']);
    }

    #[Test]
    public function get_app_configs_defaults_to_empty(): void
    {
        self::assertSame([], ShellConfig::getAppConfigs());
    }

    #[Test]
    public function get_version_returns_the_tuple_for_registered_platform(): void
    {
        $this->config->desktop(function (DesktopConfig $cfg) {
            $cfg->version('9.9.9', 99);
        });

        $result = ShellConfig::getVersion('desktop');

        self::assertSame(['version' => '9.9.9', 'buildNumber' => 99], $result);
    }

    #[Test]
    public function get_version_throws_when_platform_not_registered(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Version not configured for 'android'");

        ShellConfig::getVersion('android');
    }

    #[Test]
    public function get_version_throws_when_version_missing_from_config(): void
    {
        $this->config->desktop(function (DesktopConfig $cfg) {
            $cfg->title('NoVersionApp');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Version not configured for 'desktop'");

        ShellConfig::getVersion('desktop');
    }

    #[Test]
    public function bottom_nav_items_are_stored_on_instance_config(): void
    {
        $items = [
            ['label' => 'Home', 'icon' => 'home', 'path' => '/'],
            ['label' => 'Profile', 'icon' => 'user', 'path' => '/profile'],
        ];

        $this->config->bottomNav($items);

        // instance-scoped — read via reflection to verify without invoking get() (which needs Laravel)
        $ref = new ReflectionClass($this->config);
        $p = $ref->getProperty('config');
        $p->setAccessible(true);
        $stored = $p->getValue($this->config);

        self::assertSame($items, $stored['bottomNav']);
    }

    #[Test]
    public function top_bar_options_are_stored_on_instance_config(): void
    {
        $options = ['title' => 'MyApp', 'backgroundColor' => '#000'];

        $this->config->topBar($options);

        $ref = new ReflectionClass($this->config);
        $p = $ref->getProperty('config');
        $p->setAccessible(true);
        $stored = $p->getValue($this->config);

        self::assertSame($options, $stored['topBar']);
    }

    #[Test]
    public function bundle_push_stores_url_and_auto_apply_without_a_channel_by_default(): void
    {
        $this->config->bundlePush('https://releases.test/version.json');

        $configs = ShellConfig::getAppConfigs();
        self::assertArrayHasKey('bundlePush', $configs);
        self::assertSame('https://releases.test/version.json', $configs['bundlePush']['url']);
        self::assertTrue($configs['bundlePush']['autoApply']);
        self::assertArrayNotHasKey('channel', $configs['bundlePush']);
    }

    #[Test]
    public function bundle_push_records_a_non_default_channel(): void
    {
        $this->config->bundlePush('https://releases.test/version.json', channel: 'beta');

        $configs = ShellConfig::getAppConfigs();
        self::assertSame('beta', $configs['bundlePush']['channel']);
    }

    #[Test]
    public function bundle_push_omits_the_channel_key_when_explicitly_stable(): void
    {
        $this->config->bundlePush('https://releases.test/version.json', channel: 'stable');

        $configs = ShellConfig::getAppConfigs();
        self::assertArrayNotHasKey('channel', $configs['bundlePush']);
    }

    #[Test]
    public function ios_info_plist_entries_are_stored_and_merge_across_calls(): void
    {
        $this->config->ios(function (IosConfig $cfg) {
            $cfg->infoPlist(['ITSAppUsesNonExemptEncryption' => false])
                ->infoPlist(['UIBackgroundModes' => ['audio']]);
        });

        $configs = ShellConfig::getAppConfigs();
        self::assertSame([
            'ITSAppUsesNonExemptEncryption' => false,
            'UIBackgroundModes' => ['audio'],
        ], $configs['ios']['infoPlist']);
    }

    #[Test]
    public function android_manifest_meta_data_entries_are_stored_and_merge_across_calls(): void
    {
        $this->config->android(function (AndroidConfig $cfg) {
            $cfg->manifestMetaData(['com.example.A' => 'one'])
                ->manifestMetaData(['com.example.B' => 'two']);
        });

        $configs = ShellConfig::getAppConfigs();
        self::assertSame([
            'com.example.A' => 'one',
            'com.example.B' => 'two',
        ], $configs['android']['manifestMetaData']);
    }
}
