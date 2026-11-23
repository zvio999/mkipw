<?php

declare(strict_types=1);

const KIP_RELAY_LIMITS = KIP_DATA_DIR . '/relay-limits.json';
const KIP_APPROVAL_LIMITS = KIP_DATA_DIR . '/approval-limits.json';
const KIP_RELAY_QUOTES = KIP_DATA_DIR . '/relay-quotes.json';
const KIP_RELAY_PRICES = KIP_DATA_DIR . '/relay-prices.json';
const KIP_USDT_TOKEN = '0x55d398326f99059fF775485246999027B3197955';
const KIP_RELAY_DAILY_CAP = 15;
const KIP_APPROVAL_DAILY_CAP = 2;

function kip_relayer_bsc_config(): array
{
    $config = kip_load_config();
    $relayer = kip_sanitize_relayer($config['relayer'] ?? null);
    return $relayer['chains']['bep20'];
}

function kip_relayer_token(string $address): ?array
{
    $needle = strtolower($address);
    foreach (kip_relayer_bsc_config()['tokens'] as $token) {
        if (!empty($token['enabled']) && strtolower((string) $token['address']) === $needle) {
            return $token;
        }
    }
    return null;
}

function kip_relayer_capabilities(int $chainId, string $tokenAddress = ''): array
{
    if ($chainId !== 56) {
        return ['ok' => true, 'available' => false, 'chainId' => $chainId];
    }
    $chain = kip_relayer_bsc_config();
    $tokens = array_values(array_filter(
        $chain['tokens'],
        static fn(array $token): bool => !empty($token['enabled'])
    ));
    $selected = $tokenAddress !== '' ? kip_relayer_token($tokenAddress) : null;
    return [
        'ok' => true,
        'available' => !empty($chain['enabled']) && $chain['v2Address'] !== '',
        'chainId' => 56,
        'v1Address' => $chain['v1Address'],
        'v2Address' => $chain['v2Address'],
        'token' => $selected,
        'tokens' => $tokens,
    ];
}

function kip_relayer_address(): string
{
    $config = kip_load_config();
    $chain = kip_sanitize_relayer($config['relayer'] ?? null)['chains']['bep20'];
    $address = strtolower(trim((string) ($chain['v1Address'] ?: ($config['wallets']['relayerContract'] ?? ''))));
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

function kip_decimal_scaled(string $value, int $scale): string
{
    $value = trim($value);
    if (preg_match('/^(?:0|[1-9]\d*)(?:\.(\d+))?$/', $value, $match) !== 1) {
        throw new InvalidArgumentException('Invalid decimal amount.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
    return ltrim($whole . $fraction, '0') ?: '0';
}

function kip_dec_ceil_div(string $left, string $right): string
{
    if (kip_dec_cmp($right, '0') <= 0) {
        throw new RuntimeException('Cannot divide by zero.');
    }
    return kip_dec_div(kip_dec_add($left, kip_dec_sub($right, '1')), $right);
}

function kip_pow10(int $decimals): string
{
    return '1' . str_repeat('0', max(0, $decimals));
}

function kip_fetch_token_price(array $token): string
{
    if (!empty($token['stableUsd'])) {
        return '1.00000000';
    }
    $config = kip_load_config();
    $relayer = kip_sanitize_relayer($config['relayer'] ?? null);
    $maxAge = (int) $relayer['maxPriceAgeSeconds'];
    $symbol = strtoupper((string) $token['priceSymbol']);
    $cache = [];
    if (is_file(KIP_RELAY_PRICES)) {
        $decoded = json_decode((string) file_get_contents(KIP_RELAY_PRICES), true);
        $cache = is_array($decoded) ? $decoded : [];
    }
    $cached = $cache[$symbol] ?? null;
    if (
        is_array($cached) &&
        isset($cached['price'], $cached['fetchedAt']) &&
        (int) $cached['fetchedAt'] >= time() - $maxAge
    ) {
        return (string) $cached['price'];
    }

    $url = 'https://api.binance.com/api/v3/ticker/price?symbol=' . rawurlencode($symbol);
    $ch = curl_init($url);
    if ($ch !== false) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        $price = is_array($json) ? trim((string) ($json['price'] ?? '')) : '';
        if ($status === 200 && preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $price) === 1 && (float) $price > 0) {
            $cache[$symbol] = ['price' => $price, 'fetchedAt' => time()];
            file_put_contents(KIP_RELAY_PRICES, json_encode($cache) . "\n", LOCK_EX);
            return $price;
        }
    }
    if (
        is_array($cached) &&
        isset($cached['price'], $cached['fetchedAt']) &&
        (int) $cached['fetchedAt'] >= time() - $maxAge
    ) {
        return (string) $cached['price'];
    }
    throw new RuntimeException('A fresh server price is unavailable for ' . $token['symbol'] . '.');
}

function kip_expected_relay_fee(string $amountRaw, array $token): string
{
    $config = kip_load_config();
    $fee = $config['serviceFee'];
    if (empty($fee['enabled'])) {
        return '0';
    }
    if (($fee['feeMode'] ?? 'percent') === 'percent') {
        $basisPoints = (int) round(max(0.0, (float) $fee['feePercent']) * 100);
        return $basisPoints > 0
            ? kip_dec_ceil_div(kip_dec_mul($amountRaw, (string) $basisPoints), '10000')
            : '0';
    }
    $fixedUsd = kip_decimal_scaled((string) $fee['feeFixed'], 8);
    if ($fixedUsd === '0') {
        return '0';
    }
    $priceScaled = kip_decimal_scaled(kip_fetch_token_price($token), 8);
    return kip_dec_ceil_div(
        kip_dec_mul($fixedUsd, kip_pow10((int) $token['decimals'])),
        $priceScaled
    );
}

function kip_create_relay_quote(array $body, int $chainId): array
{
    if ($chainId !== 56) {
        throw new InvalidArgumentException('The relayer is not available on this chain.');
    }
    $sender = strtolower(trim((string) ($body['sender'] ?? '')));
    $tokenAddress = strtolower(trim((string) ($body['tokenAddress'] ?? '')));
    $amountRaw = trim((string) ($body['amountWei'] ?? ''));
    if (preg_match('/^0x[a-f0-9]{40}$/', $sender) !== 1 || preg_match('/^0x[a-f0-9]{40}$/', $tokenAddress) !== 1) {
        throw new InvalidArgumentException('sender and tokenAddress must be valid 0x addresses.');
    }
    if (preg_match('/^[1-9]\d*$/', $amountRaw) !== 1) {
        throw new InvalidArgumentException('amountWei must be a positive integer.');
    }
    $chain = kip_relayer_bsc_config();
    $token = kip_relayer_token($tokenAddress);
    if (empty($chain['enabled']) || $chain['v2Address'] === '' || $token === null) {
        throw new InvalidArgumentException('This token is not enabled for gasless transfers.');
    }
    $feeRaw = kip_expected_relay_fee($amountRaw, $token);
    $relayerConfig = kip_sanitize_relayer(kip_load_config()['relayer'] ?? null);
    $expiresAt = time() + (int) $relayerConfig['quoteTtlSeconds'];
    $quoteId = bin2hex(random_bytes(24));
    if (!is_dir(KIP_DATA_DIR)) {
        mkdir(KIP_DATA_DIR, 0750, true);
    }
    $fh = fopen(KIP_RELAY_QUOTES, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        throw new RuntimeException('Could not open the relay quote store.');
    }
    $store = json_decode((string) stream_get_contents($fh), true);
    $store = is_array($store) ? $store : [];
    $store = array_filter($store, static fn($quote): bool => is_array($quote) && (int) ($quote['expiresAt'] ?? 0) >= time());
    $store[$quoteId] = [
        'sender' => $sender,
        'tokenAddress' => $tokenAddress,
        'amountWei' => $amountRaw,
        'feeWei' => $feeRaw,
        'relayerAddress' => strtolower($chain['v2Address']),
        'expiresAt' => $expiresAt,
    ];
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($store) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return [
        'ok' => true,
        'quoteId' => $quoteId,
        'feeWei' => $feeRaw,
        'expiresAt' => $expiresAt,
        'relayerAddress' => $chain['v2Address'],
        'token' => $token,
    ];
}

function kip_consume_relay_quote(array $body): array
{
    $quoteId = strtolower(trim((string) ($body['quoteId'] ?? '')));
    if (preg_match('/^[a-f0-9]{48}$/', $quoteId) !== 1) {
        throw new InvalidArgumentException('A valid relay quote is required.');
    }
    $fh = fopen(KIP_RELAY_QUOTES, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        throw new RuntimeException('Could not lock the relay quote store.');
    }
    $store = json_decode((string) stream_get_contents($fh), true);
    $store = is_array($store) ? $store : [];
    $quote = $store[$quoteId] ?? null;
    unset($store[$quoteId]);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($store) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!is_array($quote) || (int) ($quote['expiresAt'] ?? 0) < time()) {
        throw new InvalidArgumentException('The relay quote is missing, expired, or already used.');
    }
    foreach (['sender', 'tokenAddress', 'amountWei', 'feeWei'] as $field) {
        if (strtolower((string) ($body[$field] ?? '')) !== strtolower((string) $quote[$field])) {
            throw new InvalidArgumentException('The signed payment does not match its server quote.');
        }
    }
    return $quote;
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

function kip_token_allowance(string $token, string $owner, string $spender): string
{
    $data = '0xdd62ed3e' . kip_abi_word($owner) . kip_abi_word($spender);
    return kip_hex_to_dec(kip_eth_call($token, $data));
}

function kip_usdt_allowance(string $owner, string $spender): string
{
    return kip_token_allowance(KIP_USDT_TOKEN, $owner, $spender);
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

function kip_request_approval_gas(
    string $userAddress,
    int $chainId,
    string $tokenAddress = KIP_USDT_TOKEN,
    string $requiredWei = '1',
    string $relayerAddress = ''
): array
{
    if ($chainId !== 56) {
        throw new InvalidArgumentException('Approval gas is only available on BNB Smart Chain.');
    }
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $userAddress) !== 1) {
        throw new InvalidArgumentException('userAddress is not a valid 0x address.');
    }

    if (preg_match('/^[1-9]\d*$/', $requiredWei) !== 1) {
        throw new InvalidArgumentException('requiredWei must be a positive integer.');
    }
    $chain = kip_relayer_bsc_config();
    $token = kip_relayer_token($tokenAddress);
    $expectedRelayer = strtolower((string) $chain['v2Address']);
    if (
        empty($chain['enabled']) ||
        $token === null ||
        $expectedRelayer === '' ||
        ($relayerAddress !== '' && strtolower($relayerAddress) !== $expectedRelayer)
    ) {
        throw new InvalidArgumentException('This token or relayer is not enabled.');
    }
    return kip_with_gas_chain_lock($chainId, static function () use (
        $userAddress,
        $chainId,
        $tokenAddress,
        $requiredWei,
        $expectedRelayer
    ): array {
        $allowance = kip_token_allowance($tokenAddress, $userAddress, $expectedRelayer);
        if (kip_dec_cmp($allowance, $requiredWei) >= 0) {
            return ['ok' => true, 'submitted' => false, 'needed' => false, 'reason' => 'allowance covers payment'];
        }
        $policy = kip_gas_policy($chainId);
        $native = kip_bnb_balance_wei($userAddress, $chainId);
        if (kip_dec_cmp($native, (string) $policy['thresholdRaw']) >= 0) {
            return ['ok' => true, 'submitted' => false, 'needed' => false, 'reason' => 'wallet has approval gas'];
        }
        if (!kip_limit_allow(KIP_APPROVAL_LIMITS, $userAddress, kip_client_ip(), KIP_APPROVAL_DAILY_CAP)) {
            throw new InvalidArgumentException('Approval gas limit reached for this address or network today.');
        }
        $chain = kip_relayer_bsc_config();
        $gasPriceHex = kip_rpc('eth_gasPrice', [], $chainId);
        if (!is_string($gasPriceHex)) {
            throw new RuntimeException('Could not estimate approval gas.');
        }
        $target = kip_dec_div(
            kip_dec_mul(
                kip_dec_mul(kip_hex_to_dec($gasPriceHex), (string) $chain['approvalGasLimit']),
                '120'
            ),
            '100'
        );
        if (kip_dec_cmp($target, (string) $policy['thresholdRaw']) < 0) {
            $target = (string) $policy['thresholdRaw'];
        }
        if (kip_dec_cmp($target, (string) $policy['maxTopupRaw']) > 0) {
            $target = (string) $policy['maxTopupRaw'];
        }
        $deficit = kip_dec_sub($target, $native);
        if (kip_dec_cmp($deficit, '0') <= 0) {
            return ['ok' => true, 'submitted' => false, 'needed' => false, 'reason' => 'wallet has approval gas'];
        }
        $hash = kip_send_bnb_from_station($userAddress, $deficit);
        return [
            'ok' => true,
            'submitted' => true,
            'needed' => true,
            'txHash' => $hash,
            'amountNative' => $deficit,
        ];
    });
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
    $expectedFee = kip_expected_relay_fee($amount, [
        'symbol' => 'USDT',
        'decimals' => 18,
        'stableUsd' => true,
        'priceSymbol' => 'USDTUSDT',
    ]);
    if (kip_dec_cmp($fee, $expectedFee) !== 0) {
        $difference = kip_dec_cmp($fee, $expectedFee) > 0
            ? kip_dec_sub($fee, $expectedFee)
            : kip_dec_sub($expectedFee, $fee);
        if (kip_dec_cmp($difference, '1') > 0) {
            throw new InvalidArgumentException('The service fee does not match the server configuration.');
        }
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

function kip_relay_v2_calldata(array $body): string
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Gas station signer is not installed.');
    }
    require_once $autoload;
    $token = strtolower(trim((string) ($body['tokenAddress'] ?? '')));
    $sender = strtolower(trim((string) ($body['sender'] ?? '')));
    $recipient = strtolower(trim((string) ($body['recipient'] ?? '')));
    $amount = trim((string) ($body['amountWei'] ?? ''));
    $fee = trim((string) ($body['feeWei'] ?? '0'));
    $invoiceId = strtolower(trim((string) ($body['invoiceId'] ?? '')));
    $deadline = trim((string) ($body['deadline'] ?? ''));
    $signature = strtolower(preg_replace('/^0x/i', '', trim((string) ($body['signature'] ?? ''))) ?? '');
    if (
        preg_match('/^0x[a-f0-9]{40}$/', $token) !== 1 ||
        preg_match('/^0x[a-f0-9]{40}$/', $sender) !== 1 ||
        preg_match('/^0x[a-f0-9]{40}$/', $recipient) !== 1
    ) {
        throw new InvalidArgumentException('tokenAddress, sender, and recipient must be 0x addresses.');
    }
    if (
        preg_match('/^[1-9]\d*$/', $amount) !== 1 ||
        preg_match('/^\d+$/', $fee) !== 1 ||
        preg_match('/^\d+$/', $deadline) !== 1 ||
        (int) $deadline < time()
    ) {
        throw new InvalidArgumentException('The payment amount or deadline is invalid.');
    }
    if (preg_match('/^0x[a-f0-9]{64}$/', $invoiceId) !== 1 || preg_match('/^[a-f0-9]{130}$/', $signature) !== 1) {
        throw new InvalidArgumentException('The invoice or payment signature is invalid.');
    }

    $permit = isset($body['permit']) && is_array($body['permit']) ? $body['permit'] : null;
    $withPermit = $permit !== null;
    $tokenConfig = kip_relayer_token($token);
    if ($withPermit && ($tokenConfig === null || empty($tokenConfig['permitEnabled']))) {
        throw new InvalidArgumentException('EIP-2612 permit is not enabled for this token.');
    }
    $method = $withPermit
        ? 'relayPaymentWithPermit(address,address,address,uint256,uint256,bytes32,uint256,bytes,uint256,uint256,uint8,bytes32,bytes32)'
        : 'relayPayment(address,address,address,uint256,uint256,bytes32,uint256,bytes)';
    $selector = substr(\kornrunner\Keccak::hash($method, 256), 0, 8);
    $headWords = $withPermit ? 13 : 8;
    $head = kip_abi_word($token)
        . kip_abi_word($sender)
        . kip_abi_word($recipient)
        . kip_abi_word(kip_dec_to_hex($amount))
        . kip_abi_word(kip_dec_to_hex($fee))
        . kip_abi_word($invoiceId)
        . kip_abi_word(kip_dec_to_hex($deadline))
        . kip_abi_word(dechex($headWords * 32));
    if ($withPermit) {
        $permitValue = trim((string) ($permit['value'] ?? ''));
        $permitDeadline = trim((string) ($permit['deadline'] ?? ''));
        $v = (int) ($permit['v'] ?? 0);
        $r = strtolower(trim((string) ($permit['r'] ?? '')));
        $s = strtolower(trim((string) ($permit['s'] ?? '')));
        if (
            preg_match('/^\d+$/', $permitValue) !== 1 ||
            preg_match('/^\d+$/', $permitDeadline) !== 1 ||
            !in_array($v, [27, 28], true) ||
            preg_match('/^0x[a-f0-9]{64}$/', $r) !== 1 ||
            preg_match('/^0x[a-f0-9]{64}$/', $s) !== 1
        ) {
            throw new InvalidArgumentException('The EIP-2612 permit is invalid.');
        }
        $head .= kip_abi_word(kip_dec_to_hex($permitValue))
            . kip_abi_word(kip_dec_to_hex($permitDeadline))
            . kip_abi_word(dechex($v))
            . kip_abi_word($r)
            . kip_abi_word($s);
    }
    $tail = kip_abi_word('41') . str_pad($signature, 192, '0', STR_PAD_RIGHT);
    return '0x' . $selector . $head . $tail;
}

function kip_submit_relay(array $body, int $chainId): array
{
    if ($chainId !== 56) {
        throw new InvalidArgumentException('Relay is only available on BNB Smart Chain.');
    }
    $sender = trim((string) ($body['sender'] ?? ''));
    $version = (int) ($body['version'] ?? 1);
    if ($version === 2) {
        $quote = kip_consume_relay_quote($body);
        $relayer = (string) $quote['relayerAddress'];
        $data = kip_relay_v2_calldata($body);
    } elseif ($version === 1) {
        $relayer = kip_relayer_address();
        $data = kip_relay_calldata($body);
    } else {
        throw new InvalidArgumentException('Unsupported relayer version.');
    }
    if (!kip_limit_allow(KIP_RELAY_LIMITS, $sender, kip_client_ip(), KIP_RELAY_DAILY_CAP)) {
        throw new InvalidArgumentException('Relay limit reached for this address or network today.');
    }
    return kip_with_gas_chain_lock($chainId, static function () use ($relayer, $data, $version): array {
        $privateKey = kip_gas_private_key();
        $station = kip_gas_station_address($privateKey);
        $config = kip_load_config();
        $published = strtolower((string) ($config['wallets']['gasStationAddress'] ?? ''));
        if ($published !== '' && $published !== strtolower($station)) {
            throw new RuntimeException('Gas station address does not match the server key.');
        }
        $nonce = kip_rpc('eth_getTransactionCount', [$station, 'pending']);
        $gasPrice = kip_rpc('eth_gasPrice', []);
        if (!is_string($nonce) || !is_string($gasPrice)) {
            throw new RuntimeException('Could not prepare the relay transaction.');
        }
        $tx = new Web3p\EthereumTx\Transaction([
            'nonce' => $nonce,
            'gasPrice' => $gasPrice,
            'gasLimit' => $version === 2 ? '0x493e0' : '0x30d40',
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
        return ['ok' => true, 'submitted' => true, 'txHash' => $hash];
    });
}
