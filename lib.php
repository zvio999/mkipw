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

function kip_managed_chains(): array
{
    return [
        'bep20' => 'BNB Smart Chain (BEP-20)',
        'ethereum' => 'Ethereum (ERC-20)',
        'base' => 'Base',
        'arbitrum' => 'Arbitrum',
        'optimism' => 'OP Mainnet',
        'avalanche' => 'Avalanche C-Chain',
        'polygon' => 'Polygon',
        'tron' => 'TRON (TRC-20)',
        'sol' => 'Solana (SPL)',
        'dot' => 'Polkadot (Asset Hub)',
        'near' => 'NEAR Protocol (NEP-141)',
        'ton' => 'TON (Jettons)',
        'apt' => 'Aptos',
    ];
}

function kip_default_gas_sponsorship(): array
{
    return [
        'bep20' => ['chainId' => 56, 'symbol' => 'BNB', 'enabled' => true, 'thresholdRaw' => '200000000000000', 'topupRaw' => '350000000000000', 'maxTopupRaw' => '700000000000000', 'dailyCap' => 20],
        'ethereum' => ['chainId' => 1, 'symbol' => 'ETH', 'enabled' => true, 'thresholdRaw' => '80000000000000', 'topupRaw' => '180000000000000', 'maxTopupRaw' => '1500000000000000', 'dailyCap' => 20],
        'base' => ['chainId' => 8453, 'symbol' => 'ETH', 'enabled' => false, 'thresholdRaw' => '50000000000000', 'topupRaw' => '80000000000000', 'maxTopupRaw' => '400000000000000', 'dailyCap' => 20],
        'arbitrum' => ['chainId' => 42161, 'symbol' => 'ETH', 'enabled' => false, 'thresholdRaw' => '50000000000000', 'topupRaw' => '80000000000000', 'maxTopupRaw' => '400000000000000', 'dailyCap' => 20],
        'optimism' => ['chainId' => 10, 'symbol' => 'ETH', 'enabled' => false, 'thresholdRaw' => '50000000000000', 'topupRaw' => '80000000000000', 'maxTopupRaw' => '400000000000000', 'dailyCap' => 20],
        'avalanche' => ['chainId' => 43114, 'symbol' => 'AVAX', 'enabled' => false, 'thresholdRaw' => '6000000000000000', 'topupRaw' => '8000000000000000', 'maxTopupRaw' => '40000000000000000', 'dailyCap' => 20],
        'polygon' => ['chainId' => 137, 'symbol' => 'POL', 'enabled' => false, 'thresholdRaw' => '40000000000000000', 'topupRaw' => '60000000000000000', 'maxTopupRaw' => '400000000000000000', 'dailyCap' => 20],
        'tron' => ['chainId' => 195, 'symbol' => 'TRX', 'enabled' => false, 'thresholdRaw' => '15000000', 'topupRaw' => '16500000', 'maxTopupRaw' => '17000000', 'dailyCap' => 10],
        'sol' => ['chainId' => 501, 'symbol' => 'SOL', 'enabled' => false, 'thresholdRaw' => '5000000', 'topupRaw' => '6000000', 'maxTopupRaw' => '10000000', 'dailyCap' => 20],
        'ton' => ['chainId' => -239, 'symbol' => 'GRAM', 'enabled' => false, 'thresholdRaw' => '100000000', 'topupRaw' => '120000000', 'maxTopupRaw' => '200000000', 'dailyCap' => 20],
        'apt' => ['chainId' => 1001, 'symbol' => 'APT', 'enabled' => false, 'thresholdRaw' => '2000000', 'topupRaw' => '3000000', 'maxTopupRaw' => '5000000', 'dailyCap' => 20],
        'near' => ['chainId' => 397, 'symbol' => 'NEAR', 'enabled' => false, 'thresholdRaw' => '5000000000000000000000', 'topupRaw' => '8000000000000000000000', 'maxTopupRaw' => '15000000000000000000000', 'dailyCap' => 20],
        'dot' => ['chainId' => 354, 'symbol' => 'DOT', 'enabled' => false, 'thresholdRaw' => '200000000', 'topupRaw' => '300000000', 'maxTopupRaw' => '500000000', 'dailyCap' => 20],
    ];
}

function kip_sanitize_unsigned_decimal(mixed $value, string $fallback): string
{
    $raw = trim((string) $value);
    return preg_match('/^(?:0|[1-9]\d*)$/', $raw) === 1 ? $raw : $fallback;
}

function kip_compare_unsigned_decimal(string $left, string $right): int
{
    $left = ltrim($left, '0') ?: '0';
    $right = ltrim($right, '0') ?: '0';
    if (strlen($left) !== strlen($right)) {
        return strlen($left) <=> strlen($right);
    }
    return strcmp($left, $right);
}

function kip_sanitize_gas_sponsorship(mixed $value): array
{
    $posted = is_array($value) ? $value : [];
    $out = [];
    foreach (kip_default_gas_sponsorship() as $key => $defaults) {
        $row = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : [];
        $threshold = kip_sanitize_unsigned_decimal($row['thresholdRaw'] ?? null, $defaults['thresholdRaw']);
        $topup = kip_sanitize_unsigned_decimal($row['topupRaw'] ?? null, $defaults['topupRaw']);
        $maximum = kip_sanitize_unsigned_decimal($row['maxTopupRaw'] ?? null, $defaults['maxTopupRaw']);
        if (kip_compare_unsigned_decimal($topup, $threshold) < 0) {
            $topup = $threshold;
        }
        if (kip_compare_unsigned_decimal($maximum, $topup) < 0) {
            $maximum = $topup;
        }
        $out[$key] = [
            'chainId' => $defaults['chainId'],
            'symbol' => $defaults['symbol'],
            'enabled' => array_key_exists('enabled', $row) ? !empty($row['enabled']) : $defaults['enabled'],
            'thresholdRaw' => $threshold,
            'topupRaw' => $topup,
            'maxTopupRaw' => $maximum,
            'dailyCap' => max(1, min(100, (int) ($row['dailyCap'] ?? $defaults['dailyCap']))),
        ];
    }
    return $out;
}

function kip_default_service_fee(): array
{
    return [
        'enabled' => false,
        'feeMode' => 'percent',
        'feePercent' => 0.5,
        'feeFixed' => '0',
        'minAmount' => '0',
        'feeCollectorAddress' => '',
    ];
}

function kip_sanitize_service_fee(mixed $value, ?array $fallback = null): array
{
    $defaults = $fallback ?? kip_default_service_fee();
    $fee = is_array($value) ? $value : [];
    return [
        'enabled' => array_key_exists('enabled', $fee)
            ? !empty($fee['enabled'])
            : !empty($defaults['enabled']),
        'feeMode' => (($fee['feeMode'] ?? $defaults['feeMode']) === 'fixed') ? 'fixed' : 'percent',
        'feePercent' => max(0, min(100, (float) ($fee['feePercent'] ?? $defaults['feePercent']))),
        'feeFixed' => kip_fee_fixed((string) ($fee['feeFixed'] ?? $defaults['feeFixed'])),
        'minAmount' => kip_fee_fixed((string) ($fee['minAmount'] ?? $defaults['minAmount'])),
        'feeCollectorAddress' => substr(trim((string) ($fee['feeCollectorAddress'] ?? $defaults['feeCollectorAddress'])), 0, 180),
    ];
}

function kip_default_config(): array
{
    $allChainKeys = array_keys(kip_managed_chains());
    return [
        'rpcUrls' => [
            'primary' => 'https://bsc-dataseed.binance.org',
            'fallbacks' => [
                'https://bsc-dataseed1.defibit.io',
                'https://bsc-rpc.publicnode.com',
            ],
        ],
        'chainRpcUrls' => [],
        'gasSponsorship' => kip_default_gas_sponsorship(),
        'relayer' => [
            'quoteTtlSeconds' => 120,
            'maxPriceAgeSeconds' => 300,
            'chains' => [
                'bep20' => [
                    'enabled' => true,
                    'chainId' => 56,
                    'v1Address' => '',
                    'v2Address' => '0x7Bcd283c5fc00CBcF593e6c24e09C3858344516F',
                    'approvalGasLimit' => 70000,
                    'tokens' => [
                        [
                            'symbol' => 'USDT',
                            'address' => '0x55d398326f99059fF775485246999027B3197955',
                            'decimals' => 18,
                            'enabled' => true,
                            'stableUsd' => true,
                            'priceSymbol' => 'USDTUSDT',
                            'permitEnabled' => false,
                            'permitName' => '',
                            'permitVersion' => '1',
                        ],
                        [
                            'symbol' => 'USDC',
                            'address' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                            'decimals' => 18,
                            'enabled' => true,
                            'stableUsd' => true,
                            'priceSymbol' => 'USDCUSDT',
                            'permitEnabled' => false,
                            'permitName' => '',
                            'permitVersion' => '1',
                        ],
                    ],
                ],
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
        'chainTokens' => [],
        'serviceFee' => kip_default_service_fee(),
        'serviceFees' => array_fill_keys($allChainKeys, kip_default_service_fee()),
        'maintenance' => [
            'isUnderMaintenance' => false,
            'message' => 'Broadcasting is paused. Try again shortly.',
            'announcement' => '',
        ],
        'versionControl' => [
            'minRequiredVersion' => '1.0.0',
            'latestVersion' => '1.0.0',
            'updateUrl' => '',
            'extensionLatestVersion' => '1.0.2',
            'extensionStoreUrl' => '',
        ],
        'features' => [
            'tapToPayEnabled' => true,
            'customTokensEnabled' => true,
            'coldSignSwitchVisible' => true,
            'searchBarVisible' => true,
            'allChainFiltersVisible' => true,
            'sidePanelEnabled' => true,
            'web3ProviderEnabled' => true,
            'enabledChains' => $allChainKeys,
        ],
        'wallets' => [
            'merchantVaultAddress' => '',
            'gasStationAddress' => '',
            'relayerContract' => '',
        ],
    ];
}

function kip_sanitize_relayer(mixed $value): array
{
    $defaults = kip_default_config()['relayer'];
    $input = is_array($value) ? $value : [];
    $chains = is_array($input['chains'] ?? null) ? $input['chains'] : [];
    $bscIn = is_array($chains['bep20'] ?? null) ? $chains['bep20'] : [];
    $bscDefault = $defaults['chains']['bep20'];
    $tokensIn = is_array($bscIn['tokens'] ?? null) ? $bscIn['tokens'] : $bscDefault['tokens'];
    $tokens = [];
    foreach ($tokensIn as $row) {
        if (!is_array($row)) {
            continue;
        }
        $address = kip_address_or_blank($row['address'] ?? '');
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($address === '' || preg_match('/^[A-Z0-9]{2,12}$/', $symbol) !== 1) {
            continue;
        }
        $priceSymbol = strtoupper(trim((string) ($row['priceSymbol'] ?? ($symbol . 'USDT'))));
        $tokens[] = [
            'symbol' => $symbol,
            'address' => $address,
            'decimals' => max(0, min(36, (int) ($row['decimals'] ?? 18))),
            'enabled' => !empty($row['enabled']),
            'stableUsd' => !empty($row['stableUsd']),
            'priceSymbol' => preg_match('/^[A-Z0-9]{4,24}$/', $priceSymbol) === 1 ? $priceSymbol : ($symbol . 'USDT'),
            'permitEnabled' => !empty($row['permitEnabled']),
            'permitName' => trim((string) ($row['permitName'] ?? '')),
            'permitVersion' => trim((string) ($row['permitVersion'] ?? '1')) ?: '1',
        ];
    }
    return [
        'quoteTtlSeconds' => max(30, min(600, (int) ($input['quoteTtlSeconds'] ?? $defaults['quoteTtlSeconds']))),
        'maxPriceAgeSeconds' => max(30, min(3600, (int) ($input['maxPriceAgeSeconds'] ?? $defaults['maxPriceAgeSeconds']))),
        'chains' => [
            'bep20' => [
                'enabled' => array_key_exists('enabled', $bscIn)
                    ? !empty($bscIn['enabled'])
                    : $bscDefault['enabled'],
                'chainId' => 56,
                'v1Address' => kip_address_or_blank($bscIn['v1Address'] ?? $bscDefault['v1Address']),
                'v2Address' => kip_address_or_blank($bscIn['v2Address'] ?? $bscDefault['v2Address']),
                'approvalGasLimit' => max(45000, min(250000, (int) ($bscIn['approvalGasLimit'] ?? $bscDefault['approvalGasLimit']))),
                'tokens' => $tokens,
            ],
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

function kip_sanitize_token_row(array $token): array
{
    return [
        'id' => (string) ($token['id'] ?? ''),
        'symbol' => (string) ($token['symbol'] ?? ''),
        'name' => (string) ($token['name'] ?? ''),
        'contractAddress' => (string) ($token['contractAddress'] ?? ''),
        'decimals' => max(0, min(36, (int) ($token['decimals'] ?? 18))),
        'logoUrl' => (string) ($token['logoUrl'] ?? ''),
        'enabled' => !empty($token['enabled']),
    ];
}

function kip_public_config(array $config): array
{
    $defaults = kip_default_config();
    $managedChains = kip_managed_chains();
    $allChainKeys = array_keys($managedChains);

    $rpc = $config['rpcUrls'] ?? [];
    $fallbacks = $rpc['fallbacks'] ?? $defaults['rpcUrls']['fallbacks'];
    if (!is_array($fallbacks)) {
        $fallbacks = [];
    }
    $fallbacks = array_values(array_filter(array_map('strval', $fallbacks)));

    $chainRpcUrls = [];
    if (isset($config['chainRpcUrls']) && is_array($config['chainRpcUrls'])) {
        foreach ($allChainKeys as $ck) {
            if (!isset($config['chainRpcUrls'][$ck]) || !is_array($config['chainRpcUrls'][$ck])) {
                continue;
            }
            $urls = [];
            foreach ($config['chainRpcUrls'][$ck] as $u) {
                $trimmed = trim((string) $u);
                if ($trimmed !== '' && preg_match('#^https?://#i', $trimmed) === 1) {
                    $urls[] = $trimmed;
                }
            }
            if (!empty($urls)) {
                $chainRpcUrls[$ck] = array_values(array_unique($urls));
            }
        }
    }

    $tokens = [];
    foreach ($config['defaultTokens'] ?? $defaults['defaultTokens'] as $token) {
        if (!is_array($token)) {
            continue;
        }
        $tokens[] = kip_sanitize_token_row($token);
    }

    $chainTokens = [];
    if (isset($config['chainTokens']) && is_array($config['chainTokens'])) {
        foreach ($allChainKeys as $ck) {
            if (!isset($config['chainTokens'][$ck]) || !is_array($config['chainTokens'][$ck])) {
                continue;
            }
            $rows = [];
            foreach ($config['chainTokens'][$ck] as $row) {
                if (is_array($row) && trim((string) ($row['symbol'] ?? '')) !== '') {
                    $rows[] = kip_sanitize_token_row($row);
                }
            }
            if (!empty($rows)) {
                $chainTokens[$ck] = $rows;
            }
        }
    }

    $legacyFee = kip_sanitize_service_fee($config['serviceFee'] ?? $defaults['serviceFee']);
    $configuredFees = isset($config['serviceFees']) && is_array($config['serviceFees'])
        ? $config['serviceFees']
        : [];
    $evmFeeChains = ['bep20', 'ethereum', 'base', 'arbitrum', 'optimism', 'avalanche', 'polygon'];
    $serviceFees = [];
    foreach ($allChainKeys as $chainKey) {
        $fallback = in_array($chainKey, $evmFeeChains, true)
            ? $legacyFee
            : kip_default_service_fee();
        $serviceFees[$chainKey] = kip_sanitize_service_fee(
            $configuredFees[$chainKey] ?? null,
            $fallback
        );
    }
    $fee = $serviceFees['bep20'];
    $maint = $config['maintenance'] ?? $defaults['maintenance'];
    $version = $config['versionControl'] ?? $defaults['versionControl'];
    $features = $config['features'] ?? $defaults['features'];
    $wallets = $config['wallets'] ?? $defaults['wallets'];
    $gasSponsorship = kip_sanitize_gas_sponsorship($config['gasSponsorship'] ?? null);
    $relayer = kip_sanitize_relayer($config['relayer'] ?? null);
    if ($relayer['chains']['bep20']['v1Address'] === '') {
        $relayer['chains']['bep20']['v1Address'] = kip_address_or_blank($wallets['relayerContract'] ?? '');
    }

    $enabledChains = $allChainKeys;
    if (isset($features['enabledChains']) && is_array($features['enabledChains'])) {
        $filtered = array_values(array_intersect($allChainKeys, array_map('strval', $features['enabledChains'])));
        if (!empty($filtered)) {
            $enabledChains = $filtered;
        }
    }

    return [
        'rpcUrls' => [
            'primary' => (string) ($rpc['primary'] ?? $defaults['rpcUrls']['primary']),
            'fallbacks' => $fallbacks,
        ],
        'chainRpcUrls' => (object) $chainRpcUrls,
        'gasSponsorship' => $gasSponsorship,
        'relayer' => $relayer,
        'defaultTokens' => $tokens,
        'chainTokens' => (object) $chainTokens,
        // Legacy alias kept for already-installed clients; it mirrors BNB Chain.
        'serviceFee' => $fee,
        'serviceFees' => $serviceFees,
        'maintenance' => [
            'isUnderMaintenance' => !empty($maint['isUnderMaintenance']),
            'message' => (string) ($maint['message'] ?? ''),
            'announcement' => (string) ($maint['announcement'] ?? ''),
        ],
        'versionControl' => [
            'minRequiredVersion' => (string) ($version['minRequiredVersion'] ?? '1.0.0'),
            'latestVersion' => (string) ($version['latestVersion'] ?? '1.0.0'),
            'updateUrl' => (string) ($version['updateUrl'] ?? ''),
            'extensionLatestVersion' => (string) ($version['extensionLatestVersion'] ?? '1.0.2'),
            'extensionStoreUrl' => (string) ($version['extensionStoreUrl'] ?? ''),
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
            'searchBarVisible' => array_key_exists('searchBarVisible', $features)
                ? !empty($features['searchBarVisible'])
                : true,
            'allChainFiltersVisible' => array_key_exists('allChainFiltersVisible', $features)
                ? !empty($features['allChainFiltersVisible'])
                : true,
            'sidePanelEnabled' => array_key_exists('sidePanelEnabled', $features)
                ? !empty($features['sidePanelEnabled'])
                : true,
            'web3ProviderEnabled' => array_key_exists('web3ProviderEnabled', $features)
                ? !empty($features['web3ProviderEnabled'])
                : true,
            'enabledChains' => $enabledChains,
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
