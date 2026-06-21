<?php

namespace NativeBlade\Support;

/**
 * Best-effort detection of the machine's LAN IP, so a phone on the same
 * network can reach the dev server. Shared by `nativeblade:dev` and
 * `nativeblade:serve`. Falls back to 127.0.0.1 when nothing usable is found.
 */
class LanIp
{
    public static function detect(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::detectWindows();
        }

        $output = [];
        exec('hostname -I 2>/dev/null', $output);
        if (!empty($output[0])) {
            $ips = array_values(array_filter(explode(' ', trim($output[0])), fn($ip) => self::isUsableLanIp($ip)));
            foreach ($ips as $ip) {
                if (str_starts_with($ip, '192.168.')) return $ip;
            }
            return $ips[0] ?? '127.0.0.1';
        }
        return '127.0.0.1';
    }

    private static function detectWindows(): string
    {
        $output = [];
        exec('ipconfig', $output);

        $skip = false;
        $candidates = [];

        foreach ($output as $line) {
            if (preg_match('/^(Ethernet|Wireless LAN|Wireless|Unknown) adapter (.+):$/', $line, $m)) {
                $adapter = $m[2];
                $skip = stripos($adapter, 'VirtualBox') !== false
                     || stripos($adapter, 'VMware') !== false
                     || stripos($adapter, 'vEthernet') !== false
                     || stripos($adapter, 'Loopback') !== false
                     || stripos($adapter, 'WSL') !== false
                     || stripos($adapter, 'Hyper-V') !== false;
                continue;
            }

            if ($skip) continue;

            if (preg_match('/IPv4.*?:\s*(\d+\.\d+\.\d+\.\d+)/', $line, $m) && self::isUsableLanIp($m[1])) {
                $candidates[] = $m[1];
            }
        }

        foreach ($candidates as $ip) {
            if (str_starts_with($ip, '192.168.')) return $ip;
        }
        foreach ($candidates as $ip) {
            if (str_starts_with($ip, '10.')) return $ip;
        }
        return $candidates[0] ?? '127.0.0.1';
    }

    private static function isUsableLanIp(string $ip): bool
    {
        if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip)) return false;
        if (str_starts_with($ip, '127.')) return false;
        if (str_starts_with($ip, '169.254.')) return false;
        // VirtualBox default host-only
        if (str_starts_with($ip, '192.168.56.')) return false;
        // VirtualBox alternate default
        if (str_starts_with($ip, '192.168.99.')) return false;
        return true;
    }
}
