<?php

namespace NativeBlade\Commands;

use Illuminate\Console\Command;
use NativeBlade\ShellConfig;

class DeepLinksCommand extends Command
{
    protected $signature = 'nativeblade:deeplinks
        {--team= : Apple Developer Team ID for the apple-app-site-association appID}
        {--fingerprint= : Android signing certificate SHA-256 fingerprint for assetlinks.json}';

    protected $description = 'Generate the .well-known association files (assetlinks.json + apple-app-site-association) for universal/app links';

    public function handle(): int
    {
        $configs = ShellConfig::getAppConfigs();
        $domains = $configs['deepLinks']['domains'] ?? [];

        if (empty($domains)) {
            $this->error('  No deep link domains configured. Add NativeBladeConfig::deepLinks([...]) in your AppServiceProvider first.');
            return self::FAILURE;
        }

        $androidPackage = $configs['android']['identifier'] ?? 'com.example.app';
        $iosBundle = $configs['ios']['identifier'] ?? 'com.example.app';
        $team = $this->option('team') ?: 'YOUR_TEAM_ID';
        $fingerprint = $this->option('fingerprint') ?: 'REPLACE_WITH_YOUR_SHA256_FINGERPRINT';

        $dir = base_path('public/.well-known');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->writeAssetLinks($dir, $androidPackage, $fingerprint);
        $this->writeAppleAssociation($dir, $team, $iosBundle);
        $this->printInstructions($domains, $team, $fingerprint);

        return self::SUCCESS;
    }

    private function writeAssetLinks(string $dir, string $package, string $fingerprint): void
    {
        $assetlinks = [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => [$fingerprint],
            ],
        ]];
        file_put_contents(
            $dir . '/assetlinks.json',
            json_encode($assetlinks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $this->line('  <fg=green>✓</> public/.well-known/assetlinks.json');
    }

    private function writeAppleAssociation(string $dir, string $team, string $bundle): void
    {
        // Served with NO file extension and Content-Type: application/json.
        $aasa = [
            'applinks' => [
                'details' => [[
                    'appID' => "{$team}.{$bundle}",
                    'components' => [['/' => '*']],
                ]],
            ],
        ];
        file_put_contents(
            $dir . '/apple-app-site-association',
            json_encode($aasa, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $this->line('  <fg=green>✓</> public/.well-known/apple-app-site-association');
    }

    private function printInstructions(array $domains, string $team, string $fingerprint): void
    {
        $this->line('');
        $this->info('  Host both files, for each domain, at:');
        foreach ($domains as $domain) {
            $this->line("    https://{$domain}/.well-known/assetlinks.json");
            $this->line("    https://{$domain}/.well-known/apple-app-site-association");
        }
        $this->line('');
        $this->line('  <fg=yellow>→</> apple-app-site-association must be served with NO extension and Content-Type: application/json.');

        if ($team === 'YOUR_TEAM_ID') {
            $this->line('  <fg=yellow>→</> Replace YOUR_TEAM_ID with your Apple Developer Team ID (or pass --team=).');
        }
        if ($fingerprint === 'REPLACE_WITH_YOUR_SHA256_FINGERPRINT') {
            $this->line('  <fg=yellow>→</> Replace the SHA-256 fingerprint (keytool -list -v -keystore your.keystore) or pass --fingerprint=.');
        }

        $this->line('  <fg=yellow>→</> iOS only: in Xcode add the Associated Domains capability (Signing & Capabilities -> + Capability), with applinks:<domain> for each domain.');
        $this->line('');
    }
}
