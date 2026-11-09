<?php

declare(strict_types=1);

const KIP_RELAY_LIMITS = KIP_DATA_DIR . '/relay-limits.json';
const KIP_APPROVAL_LIMITS = KIP_DATA_DIR . '/approval-limits.json';
const KIP_USDT_TOKEN = '0x55d398326f99059fF775485246999027B3197955';
const KIP_APPROVAL_BNB_WEI = '150000000000000';
const KIP_RELAY_DAILY_CAP = 15;
const KIP_APPROVAL_DAILY_CAP = 2;

function kip_relayer_address(): string
{
    $config = kip_load_config();
    $address = strtolower(trim((string) ($config['wallets']['relayerContract'] ?? '')));
    if (preg_match('/^0x[a-f0-9]{40}$/', $address) !== 1) {
        throw new RuntimeException('Relayer contract is not set in the admin panel.');
    }
    return $address;
}

function kip_limit_allow(string $file, string $address, string $ip, int $cap): bool
{
    if (!is_dir(KIP_DATA_DIR)) {
        mkdir(KIP_DATA_DIR, 0750, true);
    }
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot open the relayer rate-limit store.');
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
        if (count($hits) >= $cap) {
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

function kip_abi_word(string $hex): string
{
    $hex = strtolower(preg_replace('/^0x/i', '', trim($hex)) ?? '');
    return str_pad($hex, 64, '0', STR_PAD_LEFT);
}

function kip_eth_call(string $to, string $data): string
{
    $result = kip_rpc('eth_call', [[
        'to' => $to,
        'data' => $data,
    ], 'latest']);
    if (!is_string($result)) {
        throw new RuntimeException('The contract call returned no data.');
    }
    return $result;
}

function kip_usdt_allowance(string $owner, string $spender): string
{
    $data = '0xdd62ed3e' . kip_abi_word($owner) . kip_abi_word($spender);
    return kip_hex_to_dec(kip_eth_call(KIP_USDT_TOKEN, $data));
}

function kip_send_bnb_from_station(string $to, string $wei): string
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Gas station signer is not installed.');
    }
    require_once $autoload;

    $privateKey = kip_gas_private_key();
    $station = kip_gas_station_address($privateKey);
    $config = kip_load_config();
    $published = strtolower((string) ($config['wallets']['gasStationAddress'] ?? ''));
    if ($published !== '' && $published !== strtolower($station)) {
        throw new RuntimeException('Gas station address does not match the server key.');
    }

    $stationBnb = kip_bnb_balance_wei($station);
    $needed = kip_dec_add($wei, '21000000000000');
    if (kip_dec_cmp($stationBnb, $needed) < 0) {
        throw new RuntimeException('Gas station wallet is empty. Fund it with a little BNB.');
    }

    $nonce = kip_rpc('eth_getTransactionCount', [$station, 'pending']);
    $gasPrice = kip_rpc('eth_gasPrice', []);
    if (!is_string($nonce) || !is_string($gasPrice)) {
        throw new RuntimeException('Could not prepare the gas-station transaction.');
    }

    $tx = new Web3p\EthereumTx\Transaction([
        'nonce' => $nonce,
        'gasPrice' => $gasPrice,
        'gasLimit' => '0x5208',
        'to' => $to,
        'value' => kip_dec_to_hex($wei),
        'data' => '0x',
        'chainId' => 56,
    ]);
    $signed = $tx->sign($privateKey);
    $raw = str_starts_with($signed, '0x') ? $signed : '0x' . $signed;
    $hash = kip_rpc('eth_sendRawTransaction', [$raw]);
    if (!is_string($hash) || preg_match('/^0x[a-fA-F0-9]{64}$/', $hash) !== 1) {
        throw new RuntimeException('The gas-station transaction was not accepted by the network.');
    }
    return $hash;
}

function kip_request_approval_gas(string $userAddress, int $chainId): array
{
    if ($chainId !== 56) {
        throw new InvalidArgumentException('Approval gas is only available on BNB Smart Chain.');
    }
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $userAddress) !== 1) {
        throw new InvalidArgumentException('userAddress is not a valid 0x address.');
    }

    $relayer = kip_relayer_address();
    $allowance = kip_usdt_allowance($userAddress, $relayer);
    if (kip_dec_cmp($allowance, '0') > 0) {
        return ['ok' => true, 'submitted' => false, 'needed' => false, 'reason' => 'already approved'];
    }

    $usdt = kip_usdt_balance_wei($userAddress);
    if (kip_dec_cmp($usdt, '1000000000000000000') < 0) {
        throw new InvalidArgumentException('This wallet needs at least 1 USDT before approval gas.');
    }

    $bnb = kip_bnb_balance_wei($userAddress);
    if (kip_dec_cmp($bnb, '0') > 0) {
        return ['ok' => true, 'submitted' => false, 'needed' => false, 'reason' => 'wallet has BNB'];
    }

    if (!kip_limit_allow(KIP_APPROVAL_LIMITS, $userAddress, kip_client_ip(), KIP_APPROVAL_DAILY_CAP)) {
        throw new InvalidArgumentException('Approval gas limit reached for this address or network today.');
    }

    $hash = kip_send_bnb_from_station($userAddress, KIP_APPROVAL_BNB_WEI);
    return [
        'ok' => true,
        'submitted' => true,
        'needed' => true,
        'txHash' => $hash,
    ];
}

function kip_relay_calldata(array $body): string
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Gas station signer is not installed.');
    }
    require_once $autoload;

    $sender = strtolower(trim((string) ($body['sender'] ?? '')));
    $recipient = strtolower(trim((string) ($body['recipient'] ?? '')));
    $amount = trim((string) ($body['amountWei'] ?? ''));
    $fee = trim((string) ($body['feeWei'] ?? '0'));
    $invoiceId = strtolower(trim((string) ($body['invoiceId'] ?? '')));
    $deadline = trim((string) ($body['deadline'] ?? ''));
    $signature = strtolower(preg_replace('/^0x/i', '', trim((string) ($body['signature'] ?? ''))) ?? '');

    if (preg_match('/^0x[a-f0-9]{40}$/', $sender) !== 1 || preg_match('/^0x[a-f0-9]{40}$/', $recipient) !== 1) {
        throw new InvalidArgumentException('sender and recipient must be 0x addresses.');
    }
    if (preg_match('/^\d+$/', $amount) !== 1 || preg_match('/^\d+$/', $fee) !== 1 || preg_match('/^\d+$/', $deadline) !== 1) {
        throw new InvalidArgumentException('amountWei, feeWei, and deadline must be integers.');
    }
    if (preg_match('/^0x[a-f0-9]{64}$/', $invoiceId) !== 1) {
        throw new InvalidArgumentException('invoiceId must be a bytes32 hex value.');
    }
    if (preg_match('/^[a-f0-9]{130}$/', $signature) !== 1) {
        throw new InvalidArgumentException('signature must be 65 bytes.');
    }
    if ((int) $deadline < time()) {
        throw new InvalidArgumentException('This payment signature has expired.');
    }

    $selector = substr(\kornrunner\Keccak::hash(
        'relayPayment(address,address,uint256,uint256,bytes32,uint256,bytes)',
        256
    ), 0, 8);
    $head = kip_abi_word($sender)
        . kip_abi_word($recipient)
        . kip_abi_word(kip_dec_to_hex($amount))
        . kip_abi_word(kip_dec_to_hex($fee))
        . kip_abi_word($invoiceId)
        . kip_abi_word(kip_dec_to_hex($deadline))
        . kip_abi_word('e0');
    $tail = kip_abi_word('41') . str_pad($signature, 192, '0', STR_PAD_RIGHT);
    return '0x' . $selector . $head . $tail;
}

function kip_submit_relay(array $body, int $chainId): array
{
    if ($chainId !== 56) {
        throw new InvalidArgumentException('Relay is only available on BNB Smart Chain.');
    }
    $sender = trim((string) ($body['sender'] ?? ''));
    $relayer = kip_relayer_address();
    $data = kip_relay_calldata($body);
    if (!kip_limit_allow(KIP_RELAY_LIMITS, $sender, kip_client_ip(), KIP_RELAY_DAILY_CAP)) {
        throw new InvalidArgumentException('Relay limit reached for this address or network today.');
    }
    $privateKey = kip_gas_private_key();
    $station = kip_gas_station_address($privateKey);
    $nonce = kip_rpc('eth_getTransactionCount', [$station, 'pending']);
    $gasPrice = kip_rpc('eth_gasPrice', []);
    if (!is_string($nonce) || !is_string($gasPrice)) {
        throw new RuntimeException('Could not prepare the relay transaction.');
    }

    $tx = new Web3p\EthereumTx\Transaction([
        'nonce' => $nonce,
        'gasPrice' => $gasPrice,
        'gasLimit' => '0x30d40',
        'to' => $relayer,
        'value' => '0x0',
        'data' => $data,
        'chainId' => 56,
    ]);
    $signed = $tx->sign($privateKey);
    $raw = str_starts_with($signed, '0x') ? $signed : '0x' . $signed;
    $hash = kip_rpc('eth_sendRawTransaction', [$raw]);
    if (!is_string($hash) || preg_match('/^0x[a-fA-F0-9]{64}$/', $hash) !== 1) {
        throw new RuntimeException('The relay was not accepted by the network.');
    }
    return [
        'ok' => true,
        'submitted' => true,
        'txHash' => $hash,
    ];
}
