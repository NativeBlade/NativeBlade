<?php

namespace NativeBlade\Config;

use InvalidArgumentException;

/**
 * A third-party Tauri 2 plugin declared from the AppServiceProvider instead of
 * being baked into the closed Plugin enum. It carries the same descriptor the
 * built-in PluginRegistry exposes, so the config generators wire it into
 * Cargo.toml, lib.rs, capabilities, AndroidManifest and Info.plist exactly like
 * a first-party plugin.
 *
 * The dev still authors a normal Tauri plugin crate (its android/ and ios/
 * native sources compile through Tauri's own toolchain). This object only tells
 * NativeBlade how to wire that crate into the app.
 *
 *   NativeBladeConfig::customPlugins([
 *       CustomPlugin::init(
 *           feature: 'fingerprint',
 *           feature_crate: 'tauri-plugin-fingerprint',
 *           rust_init: 'tauri_plugin_fingerprint::init()',
 *           version: '0.1',
 *           capabilities: ['fingerprint:default'],
 *           android_permissions: ['USE_BIOMETRIC'],
 *           ios_plist: ['NSFaceIDUsageDescription'],
 *       ),
 *   ]);
 */
class CustomPlugin
{
    public function __construct(
        public readonly string $feature,
        public readonly string $feature_crate,
        public readonly string $rust_init,
        public readonly ?string $version = null,
        public readonly ?string $path = null,
        public readonly bool $mobile_only = false,
        public readonly array $capabilities = [],
        public readonly array $mobile_capabilities = [],
        public readonly array $npm = [],
        public readonly array $android_permissions = [],
        public readonly array $ios_plist = [],
    ) {
        if ($version === null && $path === null) {
            throw new InvalidArgumentException(
                "CustomPlugin '{$feature}' needs either version: (crates.io) or path: (local/vendor crate)."
            );
        }
    }

    public static function init(
        string $feature,
        string $feature_crate,
        string $rust_init,
        ?string $version = null,
        ?string $path = null,
        bool $mobile_only = false,
        array $capabilities = [],
        array $mobile_capabilities = [],
        array $npm = [],
        array $android_permissions = [],
        array $ios_plist = [],
    ): self {
        return new self(
            feature: $feature,
            feature_crate: $feature_crate,
            rust_init: $rust_init,
            version: $version,
            path: $path,
            mobile_only: $mobile_only,
            capabilities: $capabilities,
            mobile_capabilities: $mobile_capabilities,
            npm: $npm,
            android_permissions: $android_permissions,
            ios_plist: $ios_plist,
        );
    }

    /**
     * Same shape PluginRegistry::descriptor() returns, so the generators treat
     * it identically to a built-in plugin.
     *
     * @return array<string, mixed>
     */
    public function toDescriptor(): array
    {
        return [
            'feature' => $this->feature,
            'feature_crate' => $this->feature_crate,
            'rust_init' => $this->rust_init,
            'mobile_only' => $this->mobile_only,
            'capabilities' => $this->capabilities,
            'mobile_capabilities' => $this->mobile_capabilities,
            'npm' => $this->npm,
            'android_permissions' => $this->android_permissions,
            'ios_plist' => $this->ios_plist,
        ];
    }

    /**
     * The Cargo.toml dependency line for the crate. Always `optional = true`
     * so the Cargo feature gates it, matching the built-in optional plugins.
     */
    public function cargoDependencyLine(): string
    {
        $source = $this->path !== null
            ? 'path = "' . $this->path . '"'
            : 'version = "' . $this->version . '"';

        return $this->feature_crate . ' = { ' . $source . ', optional = true }';
    }
}
