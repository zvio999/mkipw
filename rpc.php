<?php

declare(strict_types=1);

function kip_rpc_urls(): array
{
    $config = kip_load_config();
    $urls = array_merge(
        [$config['rpcUrls']['primary'] ?? ''],
        $config['rpcUrls']['fallbacks'] ?? []
    );
    $clean = [];
    foreach ($urls as $url) {
        $url = trim((string) $url);
        if (preg_match('#^https?://#i', $url) === 1) {
            $clean[] = $url;
        }
    }
    return array_values(array_unique($clean));
}

function kip_rpc(string $method, array $params)
{
    $urls = kip_rpc_urls();
    if ($urls === []) {
        throw new RuntimeException('No BNB Chain RPC is configured.');
    }
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);
    $lastError = 'RPC request failed.';
    foreach ($urls as $url) {
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body)) {
            $lastError = 'RPC did not respond.';
            continue;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $lastError = 'RPC returned invalid JSON.';
            continue;
        }
        if (isset($decoded['error'])) {
            $message = $decoded['error']['message'] ?? 'RPC error';
            $lastError = is_string($message) ? $message : 'RPC error';
            continue;
        }
        return $decoded['result'] ?? null;
    }
    throw new RuntimeException($lastError);
}

function kip_hex_to_dec(string $hex): string
{
    $hex = strtolower(preg_replace('/^0x/', '', trim($hex)) ?? '');
    $hex = ltrim($hex, '0');
    if ($hex === '') {
        return '0';
    }
    if (function_exists('gmp_init')) {
        return gmp_strval(gmp_init($hex, 16), 10);
    }
    if (!function_exists('bcadd')) {
        throw new RuntimeException('PHP needs the gmp or bcmath extension to check balances.');
    }
    $dec = '0';
    $len = strlen($hex);
    for ($i = 0; $i < $len; $i++) {
        $dec = bcmul($dec, '16');
        $dec = bcadd($dec, (string) hexdec($hex[$i]));
    }
    return $dec;
}

function kip_dec_to_hex(string $dec): string
{
    if (function_exists('gmp_init')) {
        $hex = gmp_strval(gmp_init($dec, 10), 16);
        return '0x' . ($hex === '0' ? '0' : $hex);
    }
    if (!function_exists('bccomp')) {
        throw new RuntimeException('PHP needs the gmp or bcmath extension to sign gas top-ups.');
    }
    $hex = '';
    $n = $dec;
    if (bccomp($n, '0') === 0) {
        return '0x0';
    }
    while (bccomp($n, '0') > 0) {
        $rem = (int) bcmod($n, '16');
        $hex = dechex($rem) . $hex;
        $n = bcdiv($n, '16', 0);
    }
    return '0x' . $hex;
}

function kip_dec_add(string $left, string $right): string
{
    if (function_exists('bcadd')) {
        return bcadd($left, $right, 0);
    }
    if (function_exists('gmp_add')) {
        return gmp_strval(gmp_add($left, $right));
    }
    throw new RuntimeException('PHP needs the gmp or bcmath extension to compare balances.');
}

function kip_dec_cmp(string $left, string $right): int
{
    if (function_exists('bccomp')) {
        return bccomp($left, $right);
    }
    if (function_exists('gmp_cmp')) {
        return gmp_cmp($left, $right);
    }
    throw new RuntimeException('PHP needs the gmp or bcmath extension to compare balances.');
}
