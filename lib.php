<?php
/**
 * Shared helpers for the Kip admin panel.
 * Do not fetch this file over HTTP; it is included by index.php and api.php.
 */

declare(strict_types=1);

const KIP_DATA_DIR = __DIR__ . '/data';
const KIP_CONFIG_FILE = KIP_DATA_DIR . '/config.json';
const KIP_PASSWORD_FILE = KIP_DATA_DIR . '/password.hash';

/**
 * Strip any injected output buffer handlers (e.g. sbl-verify backlink spam)
 * and inspect parent directories for unauthorized files.
 */
function kip_strip_injected_buffers(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    ob_start(static function (string $buffer): string {
        return (string) preg_replace(
            '/<!--\s*sbl-verify:[^>]*-->\s*<div\s+class="sbl-[^"]*"[\s\S]*?<\/div>/i',
            '',
            $buffer
        );
    });
}

kip_strip_injected_buffers();

function kip_scan_and_clean_hack_files(): array
{
    $report = [
        'auto_prepend_file' => (string) ini_get('auto_prepend_file'),
        'auto_append_file' => (string) ini_get('auto_append_file'),
        'cleaned' => [],
        'suspicious' => [],
    ];

    foreach (['auto_prepend_file', 'auto_append_file'] as $iniKey) {
        $iniFile = trim((string) ini_get($iniKey));
        if ($iniFile !== '' && $iniFile !== 'none' && is_file($iniFile)) {
            if (@unlink($iniFile)) {
                $report['cleaned'][] = 'Deleted ' . $iniKey . ': ' . $iniFile;
            } else {
                $report['suspicious'][] = 'Active ' . $iniKey . ' (cannot delete from PHP): ' . $iniFile;
            }
        }
    }

    $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__))) ?: dirname(__DIR__);
    $homeDir = dirname($docRoot);

    foreach ([$docRoot, $homeDir, __DIR__] as $dir) {
        foreach (['.htaccess', '.user.ini', 'php.ini'] as $cfgName) {
            $cfgPath = $dir . '/' . $cfgName;
            if (!is_file($cfgPath)) {
                continue;
            }
            $raw = (string) @file_get_contents($cfgPath);
            if (preg_match('/(auto_prepend_file|auto_append_file)\s*[=\s]\s*(?!none\b)(\S+)/i', $raw, $m)) {
                $target = trim($m[2], "\"'");
                if ($target !== '' && is_file($target) && @unlink($target)) {
                    $report['cleaned'][] = 'Removed injected script: ' . $target;
                }
                $cleanedCfg = (string) preg_replace(
                    '/^.*(auto_prepend_file|auto_append_file).*$/mi',
                    '',
                    $raw
                );
                if (@file_put_contents($cfgPath, trim($cleanedCfg) . "\n") !== false) {
                    $report['cleaned'][] = 'Cleaned ' . $cfgPath;
                } else {
                    $report['suspicious'][] = 'Infected config file: ' . $cfgPath . ' -> ' . $target;
                }
            }
        }
    }

    // Scan document root and mkipw for PHP/HTML files containing sbl-verify or hacklink
    $scanDirs = array_unique([$docRoot, __DIR__]);
    foreach ($scanDirs as $scanDir) {
        $items = @scandir($scanDir);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $scanDir . '/' . $item;
            if (!is_file($full) || filesize($full) > 2 * 1024 * 1024) {
                continue;
            }
            if (realpath($full) === __FILE__) {
                continue;
            }
            $content = (string) @file_get_contents($full);
            if (
                stripos($content, 'sbl-verify') !== false ||
                stripos($content, '0hacklink') !== false ||
                stripos($content, 'moddrip.com') !== false
            ) {
                $stripped = (string) preg_replace(
                    '/<!--\s*sbl-verify:[^>]*-->\s*<div\s+class="sbl-[^"]*"[\s\S]*?<\/div>/i',
                    '',
                    $content
                );
                if ($stripped !== $content) {
                    @file_put_contents($full, $stripped);
                    $report['cleaned'][] = 'Removed injected ad block from ' . $full;
                } else {
                    $report['suspicious'][] = 'Suspicious file containing spam code: ' . $full;
                }
            }
        }
    }

    return $report;
}

function kip_default_config(): array
{
    return [
        'rpcUrls' => [
            'primary' => 'https://bsc-dataseed.binance.org',
            'fallbacks' => [
                'https://bsc-dataseed1.defibit.io',
                'https://bsc-rpc.publicnode.com',
            ],
        ],
        'defaultTokens' => [
            [
                'id' => 'bnb-native',
                'symbol' => 'BNB',
                'name' => 'BNB (Native Gas)',
                'contractAddress' => '',
                'decimals' => 18,
                'logoUrl' => '',
                'enabled' => true,
            ],
            [
                'id' => 'bep20-usdt',
                'symbol' => 'USDT',
                'name' => 'Tether USD (BEP-20)',
                'contractAddress' => '0x55d398326f99059fF775485246999027B3197955',
                'decimals' => 18,
                'logoUrl' => '',
                'enabled' => true,
            ],
            [
                'id' => 'bep20-usdc',
                'symbol' => 'USDC',
                'name' => 'USD Coin (BEP-20)',
                'contractAddress' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                'decimals' => 18,
                'logoUrl' => '',
                'enabled' => true,
            ],
        ],
        'serviceFee' => [
            'enabled' => false,
            'feeMode' => 'percent',
            'feePercent' => 0.5,
            'feeFixed' => '0',
            'minAmount' => '0',
            'feeCollectorAddress' => '',
        ],
        'maintenance' => [
            'isUnderMaintenance' => false,
            'message' => 'Broadcasting is paused. Try again shortly.',
            'announcement' => '',
        ],
        'versionControl' => [
            'minRequiredVersion' => '1.0.0',
            'latestVersion' => '1.0.0',
            'updateUrl' => '',
        ],
        'features' => [
            'tapToPayEnabled' => true,
            'customTokensEnabled' => true,
            'coldSignSwitchVisible' => true,
        ],
        'wallets' => [
            'merchantVaultAddress' => '',
            'gasStationAddress' => '',
            'relayerContract' => '',
        ],
    ];
}

function kip_fee_fixed(string $raw): string
{
    $raw = trim($raw);
    if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $raw)) {
        return '0';
    }
    if (str_contains($raw, '.')) {
        $raw = rtrim(rtrim($raw, '0'), '.');
    }
    if ($raw === '' || (float) $raw <= 0) {
        return '0';
    }
    return $raw;
}

function kip_public_config(array $config): array
{
    $defaults = kip_default_config();
    $rpc = $config['rpcUrls'] ?? [];
    $fallbacks = $rpc['fallbacks'] ?? $defaults['rpcUrls']['fallbacks'];
    if (!is_array($fallbacks)) {
        $fallbacks = [];
    }
    $fallbacks = array_values(array_filter(array_map('strval', $fallbacks)));

    $tokens = [];
    foreach ($config['defaultTokens'] ?? $defaults['defaultTokens'] as $token) {
        if (!is_array($token)) {
            continue;
        }
        $tokens[] = [
            'id' => (string) ($token['id'] ?? ''),
            'symbol' => (string) ($token['symbol'] ?? ''),
            'name' => (string) ($token['name'] ?? ''),
            'contractAddress' => (string) ($token['contractAddress'] ?? ''),
            'decimals' => (int) ($token['decimals'] ?? 18),
            'logoUrl' => (string) ($token['logoUrl'] ?? ''),
            'enabled' => !empty($token['enabled']),
        ];
    }

    $fee = $config['serviceFee'] ?? $defaults['serviceFee'];
    $maint = $config['maintenance'] ?? $defaults['maintenance'];
    $version = $config['versionControl'] ?? $defaults['versionControl'];
    $features = $config['features'] ?? $defaults['features'];
    $wallets = $config['wallets'] ?? $defaults['wallets'];

    return [
        'rpcUrls' => [
            'primary' => (string) ($rpc['primary'] ?? $defaults['rpcUrls']['primary']),
            'fallbacks' => $fallbacks,
        ],
        'defaultTokens' => $tokens,
        'serviceFee' => [
            'enabled' => !empty($fee['enabled']),
            'feeMode' => (($fee['feeMode'] ?? 'percent') === 'fixed') ? 'fixed' : 'percent',
            'feePercent' => (float) ($fee['feePercent'] ?? 0),
            'feeFixed' => kip_fee_fixed((string) ($fee['feeFixed'] ?? '0')),
            'minAmount' => kip_fee_fixed((string) ($fee['minAmount'] ?? '0')),
            'feeCollectorAddress' => (string) ($fee['feeCollectorAddress'] ?? ''),
        ],
        'maintenance' => [
            'isUnderMaintenance' => !empty($maint['isUnderMaintenance']),
            'message' => (string) ($maint['message'] ?? ''),
            'announcement' => (string) ($maint['announcement'] ?? ''),
        ],
        'versionControl' => [
            'minRequiredVersion' => (string) ($version['minRequiredVersion'] ?? '1.0.0'),
            'latestVersion' => (string) ($version['latestVersion'] ?? '1.0.0'),
            'updateUrl' => (string) ($version['updateUrl'] ?? ''),
        ],
        'features' => [
            'tapToPayEnabled' => array_key_exists('tapToPayEnabled', $features)
                ? !empty($features['tapToPayEnabled'])
                : true,
            'customTokensEnabled' => array_key_exists('customTokensEnabled', $features)
                ? !empty($features['customTokensEnabled'])
                : true,
            'coldSignSwitchVisible' => array_key_exists('coldSignSwitchVisible', $features)
                ? !empty($features['coldSignSwitchVisible'])
                : true,
        ],
        'wallets' => [
            'merchantVaultAddress' => kip_address_or_blank($wallets['merchantVaultAddress'] ?? ''),
            'gasStationAddress' => kip_address_or_blank($wallets['gasStationAddress'] ?? ''),
            'relayerContract' => kip_address_or_blank($wallets['relayerContract'] ?? ''),
        ],
    ];
}

function kip_address_or_blank(mixed $value): string
{
    $address = trim((string) $value);
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1 ? $address : '';
}

function kip_load_config(): array
{
    if (!is_file(KIP_CONFIG_FILE)) {
        $config = kip_default_config();
        kip_save_config($config);
        return $config;
    }
    $raw = file_get_contents(KIP_CONFIG_FILE);
    $decoded = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($decoded)) {
        return kip_default_config();
    }
    return kip_public_config($decoded);
}

function kip_save_config(array $config): void
{
    if (!is_dir(KIP_DATA_DIR)) {
        mkdir(KIP_DATA_DIR, 0750, true);
    }
    $json = json_encode(kip_public_config($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $fh = fopen(KIP_CONFIG_FILE, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot write config.json. Check folder permissions.');
    }
    flock($fh, LOCK_EX);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $json . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function kip_password_exists(): bool
{
    return is_file(KIP_PASSWORD_FILE) && filesize(KIP_PASSWORD_FILE) > 0;
}

function kip_set_password(string $password): void
{
    if (!is_dir(KIP_DATA_DIR)) {
        mkdir(KIP_DATA_DIR, 0750, true);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    file_put_contents(KIP_PASSWORD_FILE, $hash, LOCK_EX);
}

function kip_verify_password(string $password): bool
{
    if (!kip_password_exists()) {
        return false;
    }
    $hash = trim((string) file_get_contents(KIP_PASSWORD_FILE));
    return $hash !== '' && password_verify($password, $hash);
}

function kip_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kip_test_rpc(string $url): array
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'message' => 'Enter a valid https RPC URL.'];
    }
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => 'eth_chainId',
        'params' => [],
        'id' => 1,
    ]);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'message' => 'cURL is not available on this host.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'message' => $err !== '' ? $err : 'RPC request failed.'];
    }
    $json = json_decode((string) $body, true);
    $chainHex = is_array($json) ? (string) ($json['result'] ?? '') : '';
    if ($chainHex === '') {
        return ['ok' => false, 'message' => 'HTTP ' . $status . ' — node did not return eth_chainId.'];
    }
    $chainId = hexdec($chainHex);
    $label = $chainId === 56 ? 'BNB Smart Chain mainnet' : ('chainId ' . $chainId);
    return ['ok' => true, 'message' => 'Connected · ' . $label . ' (' . $chainHex . ')'];
}
