<?php

declare(strict_types=1);

const KIP_GAS_ENV = KIP_DATA_DIR . '/gas-station.env';
const KIP_GAS_LIMITS = KIP_DATA_DIR . '/gas-limits.json';
const KIP_USDT = '0x55d398326f99059fF775485246999027B3197955';

// BNB Smart Chain (Chain ID 56)
const KIP_MAX_USER_BNB_WEI = '200000000000000'; // 0.0002 BNB
const KIP_TOPUP_WEI = '350000000000000';        // 0.00035 BNB
const KIP_GAS_CHAIN_ID = 56;

// Ethereum Mainnet (Chain ID 1) — dynamically sized to live eth_gasPrice with floor & safety cap
const KIP_ETH_CHAIN_ID = 1;
const KIP_ETH_MIN_TOPUP_WEI = '80000000000000';   // 0.00008 ETH floor
const KIP_ETH_MAX_TOPUP_WEI = '1500000000000000'; // 0.0015 ETH safety cap per top-up
const KIP_ETH_TARGET_GAS_UNITS = '180000';        // Covers ERC-20 transfer + Wallet 2 service-fee transfer + EIP-1559 headroom

const KIP_GAS_DAILY_CAP = 15;

function kip_gas_private_key(): string
{
    $fromEnv = getenv('GAS_STATION_PRIVATE_KEY');
    $raw = is_string($fromEnv) ? $fromEnv : '';
    if ($raw === '' && is_file(KIP_GAS_ENV)) {
        $lines = file(KIP_GAS_ENV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'GAS_STATION_PRIVATE_KEY=')) {
                $raw = substr($line, strlen('GAS_STATION_PRIVATE_KEY='));
                break;
            }
        }
    }
    $raw = trim($raw);
    $raw = preg_replace('/\s+/', '', $raw) ?? '';
    $raw = preg_replace('/^0x/i', '', $raw) ?? '';
    if (preg_match('/^[a-fA-F0-9]{64}$/', $raw) !== 1) {
        throw new RuntimeException('Gas station key is not configured on the server.');
    }
    return $raw;
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

function kip_rate_allow(string $address, string $ip, int $chainId = 56): bool
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
        if (count($hits) >= KIP_GAS_DAILY_CAP) {
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

function kip_request_gas(string $userAddress, int $chainId, bool $forceTopup = false): array
{
    if ($chainId !== KIP_GAS_CHAIN_ID && $chainId !== KIP_ETH_CHAIN_ID) {
        throw new InvalidArgumentException('Gas top-up is only available on BNB Smart Chain (56) and Ethereum (1).');
    }
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $userAddress) !== 1) {
        throw new InvalidArgumentException('userAddress is not a valid 0x address.');
    }

    $privateKey = kip_gas_private_key();
    $station = kip_gas_station_address($privateKey);
    $config = kip_load_config();
    $published = strtolower((string) ($config['wallets']['gasStationAddress'] ?? ''));
    if ($published !== '' && $published !== strtolower($station)) {
        throw new RuntimeException('Gas station address does not match the server key.');
    }

    $isEth = ($chainId === KIP_ETH_CHAIN_ID);
    $nativeSym = $isEth ? 'ETH' : 'BNB';

    $gasPriceHex = kip_rpc('eth_gasPrice', [], $chainId);
    if (!is_string($gasPriceHex)) {
        throw new RuntimeException('Could not fetch gas price on ' . $nativeSym . ' network.');
    }
    $gasPriceDec = kip_hex_to_dec($gasPriceHex);
    if ($isEth) {
        // Boost gasPrice by +15% on Ethereum so the 21k-gas top-up confirms in the next block
        $sendGasPriceDec = kip_dec_div(kip_dec_mul($gasPriceDec, '115'), '100');
        if (kip_dec_cmp($sendGasPriceDec, '100000000') < 0) {
            $sendGasPriceDec = '100000000'; // min 0.1 gwei
        }
        $dynamicTargetWei = kip_dec_mul($sendGasPriceDec, KIP_ETH_TARGET_GAS_UNITS);
        if (kip_dec_cmp($dynamicTargetWei, KIP_ETH_MIN_TOPUP_WEI) < 0) {
            $dynamicTargetWei = KIP_ETH_MIN_TOPUP_WEI;
        }
        if (kip_dec_cmp($dynamicTargetWei, KIP_ETH_MAX_TOPUP_WEI) > 0) {
            $dynamicTargetWei = KIP_ETH_MAX_TOPUP_WEI;
        }
        $thresholdWei = kip_dec_div(kip_dec_mul($dynamicTargetWei, '70'), '100');
        $topupWei = $dynamicTargetWei;
    } else {
        $sendGasPriceDec = $gasPriceDec;
        $thresholdWei = KIP_MAX_USER_BNB_WEI;
        $topupWei = KIP_TOPUP_WEI;
    }

    $userNative = kip_bnb_balance_wei($userAddress, $chainId);
    if (!$forceTopup && kip_dec_cmp($userNative, $thresholdWei) >= 0) {
        return [
            'ok' => true,
            'gasTxHash' => null,
            'amountNative' => '0',
            'nativeSymbol' => $nativeSym,
            'gasStationAddress' => $station,
        ];
    }

    $stationNative = kip_bnb_balance_wei($station, $chainId);
    $ownTxFeeWei = kip_dec_mul($sendGasPriceDec, '21000');
    $needed = kip_dec_add($topupWei, $ownTxFeeWei);
    if (kip_dec_cmp($stationNative, $needed) < 0) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient ' . $nativeSym . '. Fund it with a little ' . $nativeSym . '.'
        );
    }
    if (!kip_rate_allow($userAddress, kip_client_ip(), $chainId)) {
        throw new InvalidArgumentException('Gas top-up limit reached for this address or network today.');
    }

    $nonce = kip_rpc('eth_getTransactionCount', [$station, 'pending'], $chainId);
    if (!is_string($nonce)) {
        throw new RuntimeException('Could not prepare the gas top-up.');
    }

    $tx = new Web3p\EthereumTx\Transaction([
        'nonce' => $nonce,
        'gasPrice' => kip_dec_to_hex($sendGasPriceDec),
        'gasLimit' => '0x5208',
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
