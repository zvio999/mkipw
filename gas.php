<?php

declare(strict_types=1);

const KIP_GAS_ENV = KIP_DATA_DIR . '/gas-station.env';
const KIP_GAS_LIMITS = KIP_DATA_DIR . '/gas-limits.json';
const KIP_USDT = '0x55d398326f99059fF775485246999027B3197955';
const KIP_MIN_USDT_WEI = '1000000000000000000';
const KIP_MAX_USER_BNB_WEI = '500000000000000';
const KIP_TOPUP_WEI = '300000000000000';
const KIP_GAS_CHAIN_ID = 56;
const KIP_GAS_DAILY_CAP = 3;

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

function kip_rate_allow(string $address, string $ip): bool
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
    foreach (['address' => strtolower($address), 'ip' => $ip] as $bucket => $key) {
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
        $store['address'][strtolower($address)][] = $now;
        $store['ip'][$ip][] = $now;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($store) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $allowed;
}

function kip_usdt_balance_wei(string $userAddress): string
{
    $data = '0x70a08231' . str_pad(strtolower(substr($userAddress, 2)), 64, '0', STR_PAD_LEFT);
    $result = kip_rpc('eth_call', [[
        'to' => KIP_USDT,
        'data' => $data,
    ], 'latest']);
    if (!is_string($result)) {
        throw new RuntimeException('Could not read the USDT balance.');
    }
    return kip_hex_to_dec($result);
}

function kip_bnb_balance_wei(string $address): string
{
    $result = kip_rpc('eth_getBalance', [$address, 'latest']);
    if (!is_string($result)) {
        throw new RuntimeException('Could not read the BNB balance.');
    }
    return kip_hex_to_dec($result);
}

function kip_request_gas(string $userAddress, int $chainId): array
{
    if ($chainId !== KIP_GAS_CHAIN_ID) {
        throw new InvalidArgumentException('Gas top-up is only available on BNB Smart Chain.');
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

    $usdt = kip_usdt_balance_wei($userAddress);
    if (kip_dec_cmp($usdt, KIP_MIN_USDT_WEI) < 0) {
        throw new InvalidArgumentException('This wallet needs at least 1 USDT before it can receive gas.');
    }
    $bnb = kip_bnb_balance_wei($userAddress);
    if (kip_dec_cmp($bnb, KIP_MAX_USER_BNB_WEI) >= 0) {
        throw new InvalidArgumentException('This wallet already has enough BNB for gas.');
    }

    $stationBnb = kip_bnb_balance_wei($station);
    $needed = kip_dec_add(KIP_TOPUP_WEI, '21000000000000');
    if (kip_dec_cmp($stationBnb, $needed) < 0) {
        throw new RuntimeException('Gas station wallet is empty. Fund it with a little BNB.');
    }
    if (!kip_rate_allow($userAddress, kip_client_ip())) {
        throw new InvalidArgumentException('Gas top-up limit reached for this address or network today.');
    }

    $nonce = kip_rpc('eth_getTransactionCount', [$station, 'pending']);
    $gasPrice = kip_rpc('eth_gasPrice', []);
    if (!is_string($nonce) || !is_string($gasPrice)) {
        throw new RuntimeException('Could not prepare the gas top-up.');
    }

    $tx = new Web3p\EthereumTx\Transaction([
        'nonce' => $nonce,
        'gasPrice' => $gasPrice,
        'gasLimit' => '0x5208',
        'to' => $userAddress,
        'value' => kip_dec_to_hex(KIP_TOPUP_WEI),
        'data' => '0x',
        'chainId' => KIP_GAS_CHAIN_ID,
    ]);
    $signed = $tx->sign($privateKey);
    $raw = str_starts_with($signed, '0x') ? $signed : '0x' . $signed;
    $hash = kip_rpc('eth_sendRawTransaction', [$raw]);
    if (!is_string($hash) || preg_match('/^0x[a-fA-F0-9]{64}$/', $hash) !== 1) {
        throw new RuntimeException('The gas top-up was not accepted by the network.');
    }
    return [
        'ok' => true,
        'gasTxHash' => $hash,
        'amountBnb' => '0.0003',
        'gasStationAddress' => $station,
    ];
}
