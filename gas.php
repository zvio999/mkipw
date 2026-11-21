<?php

declare(strict_types=1);

const KIP_GAS_ENV = KIP_DATA_DIR . '/gas-station.env';
const KIP_GAS_LIMITS = KIP_DATA_DIR . '/gas-limits.json';
const KIP_USDT = '0x55d398326f99059fF775485246999027B3197955';

// BNB Smart Chain (Chain ID 56)
const KIP_MAX_USER_BNB_WEI = '200000000000000'; // 0.0002 BNB
const KIP_TOPUP_WEI = '350000000000000';        // 0.00035 BNB
const KIP_GAS_CHAIN_ID = 56;

const KIP_GAS_DAILY_CAP = 20;

require_once __DIR__ . '/gas_multi.php';

function kip_evm_gas_chains(): array
{
    return [
        56 => [
            'symbol' => 'BNB',
            'mode' => 'fixed',
            'thresholdWei' => KIP_MAX_USER_BNB_WEI,
            'topupWei' => KIP_TOPUP_WEI,
            'boostPct' => '100',
            'minGasPriceWei' => '1000000000', // 1 gwei
            'extraBufferWei' => '0',
        ],
        1 => [
            'symbol' => 'ETH',
            'mode' => 'dynamic',
            'targetUnits' => '180000',
            'minTopupWei' => '80000000000000',   // 0.00008 ETH
            'maxTopupWei' => '1500000000000000', // 0.0015 ETH
            'boostPct' => '115',
            'minGasPriceWei' => '100000000',     // 0.1 gwei
            'extraBufferWei' => '0',
        ],
        8453 => [
            'symbol' => 'ETH',
            'mode' => 'dynamic',
            'targetUnits' => '250000',
            'minTopupWei' => '50000000000000',   // 0.00005 ETH
            'maxTopupWei' => '400000000000000',  // 0.0004 ETH
            'boostPct' => '120',
            'minGasPriceWei' => '5000000',       // 0.005 gwei
            'extraBufferWei' => '10000000000000', // L1 calldata margin
        ],
        42161 => [
            'symbol' => 'ETH',
            'mode' => 'dynamic',
            'targetUnits' => '280000',
            'minTopupWei' => '50000000000000',   // 0.00005 ETH
            'maxTopupWei' => '400000000000000',  // 0.0004 ETH
            'boostPct' => '120',
            'minGasPriceWei' => '10000000',      // 0.01 gwei
            'extraBufferWei' => '0',
        ],
        10 => [
            'symbol' => 'ETH',
            'mode' => 'dynamic',
            'targetUnits' => '250000',
            'minTopupWei' => '50000000000000',   // 0.00005 ETH
            'maxTopupWei' => '400000000000000',  // 0.0004 ETH
            'boostPct' => '120',
            'minGasPriceWei' => '5000000',       // 0.005 gwei
            'extraBufferWei' => '10000000000000', // L1 calldata margin
        ],
        43114 => [
            'symbol' => 'AVAX',
            'mode' => 'dynamic',
            'targetUnits' => '180000',
            'minTopupWei' => '6000000000000000',  // 0.006 AVAX
            'maxTopupWei' => '40000000000000000', // 0.04 AVAX
            'boostPct' => '115',
            'minGasPriceWei' => '1000000000',     // 1 nAVAX
            'extraBufferWei' => '0',
        ],
        137 => [
            'symbol' => 'POL',
            'mode' => 'dynamic',
            'targetUnits' => '180000',
            'minTopupWei' => '40000000000000000',  // 0.04 POL
            'maxTopupWei' => '400000000000000000', // 0.40 POL
            'boostPct' => '120',
            'minGasPriceWei' => '30000000000',     // 30 gwei
            'extraBufferWei' => '0',
        ],
    ];
}

function kip_gas_env_value(string $name): string
{
    $fromEnv = getenv($name);
    $raw = is_string($fromEnv) ? $fromEnv : '';
    if ($raw === '' && is_file(KIP_GAS_ENV)) {
        $lines = file(KIP_GAS_ENV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $prefix = $name . '=';
            if (str_starts_with($line, $prefix)) {
                $raw = substr($line, strlen($prefix));
                break;
            }
        }
    }
    return trim($raw);
}

function kip_gas_secret_hex(string $name): string
{
    $raw = kip_gas_env_value($name);
    $raw = trim($raw);
    $raw = preg_replace('/\s+/', '', $raw) ?? '';
    $raw = preg_replace('/^0x/i', '', $raw) ?? '';
    if (preg_match('/^[a-fA-F0-9]{64}$/', $raw) !== 1) {
        throw new RuntimeException($name . ' is not configured on the server.');
    }
    return strtolower($raw);
}

function kip_gas_private_key(): string
{
    return kip_gas_secret_hex('GAS_STATION_PRIVATE_KEY');
}

function kip_gas_station_address(string $privateKey): string
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Gas station signer is not installed. Run composer install in admin-panel.');
    }
    require_once $autoload;
    $util = new Web3p\EthereumUtil\Util();
    $public = $util->privateKeyToPublicKey($privateKey);
    return $util->publicKeyToAddress($public);
}

function kip_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return preg_match('/^[0-9a-fA-F:.]+$/', $ip) === 1 ? $ip : 'unknown';
}

function kip_rate_allow(string $address, string $ip, int $chainId = 56, int $dailyCap = KIP_GAS_DAILY_CAP): bool
{
    if (!is_dir(KIP_DATA_DIR)) {
        mkdir(KIP_DATA_DIR, 0750, true);
    }
    $fh = fopen(KIP_GAS_LIMITS, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot open the gas rate-limit store.');
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $store = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($store)) {
        $store = ['address' => [], 'ip' => []];
    }
    $now = time();
    $cutoff = $now - 86400;
    $allowed = true;
    $addrKey = $chainId . ':' . strtolower($address);
    $ipKey = $chainId . ':' . $ip;
    foreach (['address' => $addrKey, 'ip' => $ipKey] as $bucket => $key) {
        $hits = $store[$bucket][$key] ?? [];
        if (!is_array($hits)) {
            $hits = [];
        }
        $hits = array_values(array_filter($hits, static function ($ts) use ($cutoff) {
            return is_int($ts) && $ts >= $cutoff;
        }));
        if (count($hits) >= max(1, $dailyCap)) {
            $allowed = false;
        }
        $store[$bucket][$key] = $hits;
    }
    if ($allowed) {
        $store['address'][$addrKey][] = $now;
        $store['ip'][$ipKey][] = $now;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($store) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $allowed;
}

function kip_bnb_balance_wei(string $address, int $chainId = 56): string
{
    $result = kip_rpc('eth_getBalance', [$address, 'latest'], $chainId);
    if (!is_string($result)) {
        throw new RuntimeException('Could not read the native gas balance.');
    }
    return kip_hex_to_dec($result);
}

function kip_gas_chain_key(int $chainId): ?string
{
    $map = [
        56 => 'bep20', 1 => 'ethereum', 8453 => 'base', 42161 => 'arbitrum',
        10 => 'optimism', 43114 => 'avalanche', 137 => 'polygon',
        195 => 'tron', 501 => 'sol', -239 => 'ton', 1001 => 'apt',
        397 => 'near', 354 => 'dot',
    ];
    return $map[$chainId] ?? null;
}

function kip_gas_policy(int $chainId): array
{
    $key = kip_gas_chain_key($chainId);
    if ($key === null) {
        throw new InvalidArgumentException('Gas sponsorship is not supported on chainId ' . $chainId . '.');
    }
    $config = kip_load_config();
    $policies = kip_sanitize_gas_sponsorship($config['gasSponsorship'] ?? null);
    return $policies[$key];
}

function kip_with_gas_chain_lock(int $chainId, callable $callback): array
{
    if (!is_dir(KIP_DATA_DIR)) {
        mkdir(KIP_DATA_DIR, 0750, true);
    }
    $path = KIP_DATA_DIR . '/gas-chain-' . str_replace('-', 'n', (string) $chainId) . '.lock';
    $fh = fopen($path, 'c');
    if ($fh === false) {
        throw new RuntimeException('Could not open the gas transaction lock.');
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Could not lock the gas transaction sequence.');
        }
        return $callback();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function kip_request_gas(string $userAddress, int $chainId, bool $forceTopup = false): array
{
    return kip_with_gas_chain_lock(
        $chainId,
        static fn(): array => kip_request_gas_unlocked($userAddress, $chainId, $forceTopup)
    );
}

function kip_request_gas_unlocked(string $userAddress, int $chainId, bool $forceTopup = false): array
{
    $policy = kip_gas_policy($chainId);
    if (empty($policy['enabled'])) {
        throw new InvalidArgumentException(
            'Gas sponsorship is disabled for ' . $policy['symbol'] . '. Enable it in Kip Admin after funding the sponsor wallet.'
        );
    }

    if (in_array($chainId, kip_non_evm_chain_ids(), true)) {
        return kip_request_non_evm_gas($userAddress, $chainId, $forceTopup, $policy);
    }

    $privateKey = kip_gas_private_key();
    $evmChains = kip_evm_gas_chains();
    if (!isset($evmChains[$chainId])) {
        throw new InvalidArgumentException('Gas sponsorship is not supported on chainId ' . $chainId . '.');
    }
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $userAddress) !== 1) {
        throw new InvalidArgumentException('userAddress is not a valid 0x address.');
    }

    $station = kip_gas_station_address($privateKey);
    $config = kip_load_config();
    $published = strtolower((string) ($config['wallets']['gasStationAddress'] ?? ''));
    if ($published !== '' && $published !== strtolower($station)) {
        throw new RuntimeException('Gas station address does not match the server key.');
    }

    $spec = $evmChains[$chainId];
    $nativeSym = $spec['symbol'];

    $gasPriceHex = kip_rpc('eth_gasPrice', [], $chainId);
    if (!is_string($gasPriceHex)) {
        throw new RuntimeException('Could not fetch gas price on ' . $nativeSym . ' network.');
    }
    $gasPriceDec = kip_hex_to_dec($gasPriceHex);
    $sendGasPriceDec = kip_dec_div(kip_dec_mul($gasPriceDec, $spec['boostPct']), '100');
    if (kip_dec_cmp($sendGasPriceDec, $spec['minGasPriceWei']) < 0) {
        $sendGasPriceDec = $spec['minGasPriceWei'];
    }

    if ($spec['mode'] === 'dynamic') {
        $dynamicTargetWei = kip_dec_mul($sendGasPriceDec, $spec['targetUnits']);
        if (kip_dec_cmp($dynamicTargetWei, $spec['minTopupWei']) < 0) {
            $dynamicTargetWei = $spec['minTopupWei'];
        }
        if (kip_dec_cmp($dynamicTargetWei, $spec['maxTopupWei']) > 0) {
            $dynamicTargetWei = $spec['maxTopupWei'];
        }
        $thresholdWei = kip_dec_div(kip_dec_mul($dynamicTargetWei, '70'), '100');
        $topupWei = $dynamicTargetWei;
    } else {
        $thresholdWei = $spec['thresholdWei'];
        $topupWei = $spec['topupWei'];
    }
    $thresholdWei = $policy['thresholdRaw'];
    if (kip_dec_cmp($topupWei, $policy['topupRaw']) < 0) {
        $topupWei = $policy['topupRaw'];
    }
    if (kip_dec_cmp($topupWei, $policy['maxTopupRaw']) > 0) {
        $topupWei = $policy['maxTopupRaw'];
    }

    $userNative = kip_bnb_balance_wei($userAddress, $chainId);
    if (kip_dec_cmp($userNative, $thresholdWei) >= 0) {
        return [
            'ok' => true,
            'gasTxHash' => null,
            'amountNative' => '0',
            'nativeSymbol' => $nativeSym,
            'gasStationAddress' => $station,
        ];
    }
    if (kip_dec_cmp($userNative, $topupWei) < 0) {
        $topupWei = kip_dec_sub($topupWei, $userNative);
    } else {
        $topupWei = '1';
    }

    $gasLimitDec = '21000';
    if ($chainId === 42161) {
        try {
            $estHex = kip_rpc('eth_estimateGas', [[
                'from' => $station,
                'to' => $userAddress,
                'value' => kip_dec_to_hex($topupWei),
            ]], $chainId);
            if (is_string($estHex)) {
                $estDec = kip_hex_to_dec($estHex);
                $boostedEst = kip_dec_div(kip_dec_mul($estDec, '125'), '100');
                if (kip_dec_cmp($boostedEst, '21000') > 0) {
                    $gasLimitDec = $boostedEst;
                }
            } else {
                $gasLimitDec = '100000';
            }
        } catch (Throwable $e) {
            $gasLimitDec = '100000';
        }
    }

    $stationNative = kip_bnb_balance_wei($station, $chainId);
    $ownTxFeeWei = kip_dec_add(kip_dec_mul($sendGasPriceDec, $gasLimitDec), $spec['extraBufferWei']);
    $needed = kip_dec_add($topupWei, $ownTxFeeWei);
    if (kip_dec_cmp($stationNative, $needed) < 0) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient ' . $nativeSym . '. Fund it with a little ' . $nativeSym . '.'
        );
    }
    if (!kip_rate_allow($userAddress, kip_client_ip(), $chainId, (int) $policy['dailyCap'])) {
        throw new InvalidArgumentException('Gas top-up limit reached for this address or network today.');
    }

    $nonce = kip_rpc('eth_getTransactionCount', [$station, 'pending'], $chainId);
    if (!is_string($nonce)) {
        throw new RuntimeException('Could not prepare the gas top-up.');
    }

    $tx = new Web3p\EthereumTx\Transaction([
        'nonce' => $nonce,
        'gasPrice' => kip_dec_to_hex($sendGasPriceDec),
        'gasLimit' => kip_dec_to_hex($gasLimitDec),
        'to' => $userAddress,
        'value' => kip_dec_to_hex($topupWei),
        'data' => '0x',
        'chainId' => $chainId,
    ]);
    $signed = $tx->sign($privateKey);
    $raw = str_starts_with($signed, '0x') ? $signed : '0x' . $signed;
    $hash = kip_rpc('eth_sendRawTransaction', [$raw], $chainId);
    if (!is_string($hash) || preg_match('/^0x[a-fA-F0-9]{64}$/', $hash) !== 1) {
        throw new RuntimeException('The gas top-up was not accepted by the network.');
    }
    return [
        'ok' => true,
        'gasTxHash' => $hash,
        'amountWei' => $topupWei,
        'nativeSymbol' => $nativeSym,
        'gasStationAddress' => $station,
    ];
}
