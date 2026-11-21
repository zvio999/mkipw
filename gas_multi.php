<?php

declare(strict_types=1);

const KIP_BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
const KIP_TON_V4_ID = 698983191;
const KIP_TON_V4R2_CODE_B64 = 'te6ccgECFAEAAtQAART/APSkE/S88sgLAQIBIAIDAgFIBAUE+PKDCNcYINMf0x/THwL4I7vyZO1E0NMf0x/T//QE0VFDuvKhUVG68qIF+QFUEGT5EPKj+AAkpMjLH1JAyx9SMMv/UhD0AMntVPgPAdMHIcAAn2xRkyDXSpbTB9QC+wDoMOAhwAHjACHAAuMAAcADkTDjDQOkyMsfEssfy/8QERITAubQAdDTAyFxsJJfBOAi10nBIJJfBOAC0x8hghBwbHVnvSKCEGRzdHK9sJJfBeAD+kAwIPpEAcjKB8v/ydDtRNCBAUDXIfQEMFyBAQj0Cm+hMbOSXwfgBdM/yCWCEHBsdWe6kjgw4w0DghBkc3RyupJfBuMNBgcCASAICQB4AfoA9AQw+CdvIjBQCqEhvvLgUIIQcGx1Z4MesXCAGFAEywUmzxZY+gIZ9ADLaRfLH1Jgyz8gyYBA+wAGAIpQBIEBCPRZMO1E0IEBQNcgyAHPFvQAye1UAXKwjiOCEGRzdHKDHrFwgBhQBcsFUAPPFiP6AhPLassfyz/JgED7AJJfA+ICASAKCwBZvSQrb2omhAgKBrkPoCGEcNQICEekk30pkQzmkD6f+YN4EoAbeBAUiYcVnzGEAgFYDA0AEbjJftRNDXCx+AA9sp37UTQgQFA1yH0BDACyMoHy//J0AGBAQj0Cm+hMYAIBIA4PABmtznaiaEAga5Drhf/AABmvHfaiaEAQa5DrhY/AAG7SB/oA1NQi+QAFyMoHFcv/ydB3dIAYyMsFywIizxZQBfoCFMtrEszMyXP7AMhAFIEBCPRR8qcCAHCBAQjXGPoA0z/IVCBHgQEI9FHyp4IQbm90ZXB0gBjIywXLAlAGzxZQBPoCFMtqEssfyz/Jc/sAAgBsgQEI1xj6ANM/MFIkgQEI9Fnyp4IQZHN0cnB0gBjIywXLAlAFzxZQA/oCE8tqyx8Syz/Jc/sAAAr0AMntVA==';
const KIP_TON_V4R2_CODE_HASH_HEX = 'feb5ff6820e2ff0d9483e7e0d62c817d846789fb4ae580c878866d959dabd5c0';
const KIP_TON_V4R2_CODE_DEPTH = 7;
const KIP_DOT_SYSTEM_ACCOUNT_PREFIX = '0x26aa394eea5630e07c48ae0c9558cef7b99d880ec681799c0cf30e8886371da9';

function kip_non_evm_chain_ids(): array
{
    return [195, 501, -239, 1001, 397, 354];
}

function kip_http_json(string $url, ?array $postBody = null, array $extraHeaders = [], int $timeout = 12): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize HTTP client.');
    }
    $headers = array_merge(['Accept: application/json'], $extraHeaders);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
    ];
    if ($postBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($postBody, JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || !is_string($raw)) {
        throw new RuntimeException('Upstream node did not respond.');
    }
    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'raw' => $raw,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function kip_chain_endpoints(string $chainKey, array $defaults): array
{
    $config = kip_load_config();
    $map = (array) ($config['chainRpcUrls'] ?? []);
    $custom = isset($map[$chainKey]) && is_array($map[$chainKey]) ? $map[$chainKey] : [];
    $out = [];
    foreach (array_merge($custom, $defaults) as $u) {
        $u = rtrim(trim((string) $u), '/');
        if (preg_match('#^https?://#i', $u) === 1) {
            $out[] = $u;
        }
    }
    return array_values(array_unique($out));
}

function kip_base58_encode(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }
    $zeros = 0;
    $len = strlen($bytes);
    while ($zeros < $len && $bytes[$zeros] === "\x00") {
        $zeros++;
    }
    $prefix = str_repeat('1', $zeros);
    if ($zeros === $len) {
        return $prefix;
    }
    $hex = bin2hex(substr($bytes, $zeros));
    $out = '';
    if (function_exists('gmp_init')) {
        $num = gmp_init($hex, 16);
        $zero = gmp_init(0);
        $base = gmp_init(58);
        while (gmp_cmp($num, $zero) > 0) {
            [$num, $rem] = gmp_div_qr($num, $base);
            $out = KIP_BASE58_ALPHABET[gmp_intval($rem)] . $out;
        }
        return $prefix . $out;
    }
    $dec = kip_hex_to_dec($hex);
    while (bccomp($dec, '0') > 0) {
        $rem = (int) bcmod($dec, '58');
        $out = KIP_BASE58_ALPHABET[$rem] . $out;
        $dec = bcdiv($dec, '58', 0);
    }
    return $prefix . $out;
}

function kip_base58_decode(string $b58): ?string
{
    $b58 = trim($b58);
    if ($b58 === '') {
        return null;
    }
    $zeros = 0;
    $len = strlen($b58);
    while ($zeros < $len && $b58[$zeros] === '1') {
        $zeros++;
    }
    $prefix = str_repeat("\x00", $zeros);
    if ($zeros === $len) {
        return $prefix;
    }
    if (function_exists('gmp_init')) {
        $num = gmp_init(0);
        $base = gmp_init(58);
        for ($i = $zeros; $i < $len; $i++) {
            $pos = strpos(KIP_BASE58_ALPHABET, $b58[$i]);
            if ($pos === false) {
                return null;
            }
            $num = gmp_add(gmp_mul($num, $base), $pos);
        }
        $hex = gmp_strval($num, 16);
    } else {
        $dec = '0';
        for ($i = $zeros; $i < $len; $i++) {
            $pos = strpos(KIP_BASE58_ALPHABET, $b58[$i]);
            if ($pos === false) {
                return null;
            }
            $dec = bcadd(bcmul($dec, '58', 0), (string) $pos, 0);
        }
        $hex = substr(kip_dec_to_hex($dec), 2);
    }
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    $bin = hex2bin($hex);
    return $bin === false ? null : ($prefix . $bin);
}

function kip_non_evm_private_key_name(int $chainId): string
{
    $names = [
        195 => 'GAS_STATION_TRON_PRIVATE_KEY',
        501 => 'GAS_STATION_SOL_SEED',
        -239 => 'GAS_STATION_TON_SEED',
        1001 => 'GAS_STATION_APT_SEED',
        397 => 'GAS_STATION_NEAR_SEED',
        354 => 'GAS_STATION_DOT_SEED',
    ];
    if (!isset($names[$chainId])) {
        throw new InvalidArgumentException('No gas station key is defined for chainId ' . $chainId . '.');
    }
    return $names[$chainId];
}

function kip_non_evm_secret_bin(int $chainId): string
{
    $hex = kip_gas_secret_hex(kip_non_evm_private_key_name($chainId));
    $bin = hex2bin($hex);
    if ($bin === false || strlen($bin) !== 32) {
        throw new RuntimeException('Invalid gas station key for chainId ' . $chainId . '.');
    }
    return $bin;
}

function kip_ed25519_pubkey(string $seed32): string
{
    if (function_exists('sodium_crypto_sign_seed_keypair')) {
        $kp = sodium_crypto_sign_seed_keypair($seed32);
        return sodium_crypto_sign_publickey($kp);
    }
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Composer autoloader missing for Ed25519.');
    }
    require_once $autoload;
    $ed = new \Elliptic\EdDSA('ed25519');
    $key = $ed->keyFromSecret(bin2hex($seed32));
    $arr = $key->pubBytes();
    return pack('C*', ...$arr);
}

function kip_ed25519_sign(string $msgBin, string $seed32): string
{
    if (function_exists('sodium_crypto_sign_seed_keypair')) {
        $kp = sodium_crypto_sign_seed_keypair($seed32);
        return sodium_crypto_sign_detached($msgBin, sodium_crypto_sign_secretkey($kp));
    }
    $autoload = __DIR__ . '/vendor/autoload.php';
    require_once $autoload;
    $ed = new \Elliptic\EdDSA('ed25519');
    $sigArr = $ed->sign(bin2hex($msgBin), bin2hex($seed32))->toBytes();
    return pack('C*', ...$sigArr);
}

function kip_blake2b(string $data, int $outLen = 32): string
{
    if (function_exists('sodium_crypto_generichash')) {
        return sodium_crypto_generichash($data, '', $outLen);
    }
    return kip_blake2b_pure($data, $outLen);
}

function kip_blake2b_pure(string $data, int $outLen): string
{
    static $iv = [
        [0x6a09e667, 0xf3bcc908], [0xbb67ae85, 0x84caa73b],
        [0x3c6ef372, 0xfe94f82b], [0xa54ff53a, 0x5f1d36f1],
        [0x510e527f, 0xade682d1], [0x9b05688c, 0x2b3e6c1f],
        [0x1f83d9ab, 0xfb41bd6b], [0x5be0cd19, 0x137e2179],
    ];
    static $sigma = [
        [0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15],
        [14,10,4,8,9,15,13,6,1,12,0,2,11,7,5,3],
        [11,8,12,0,5,2,15,13,10,14,3,6,7,1,9,4],
        [7,9,3,1,13,12,11,14,2,6,5,10,4,0,15,8],
        [9,0,5,7,2,4,10,15,14,1,11,12,6,8,3,13],
        [2,12,6,10,0,11,8,3,4,13,7,5,15,14,1,9],
        [12,5,1,15,14,13,4,10,0,7,6,3,9,2,8,11],
        [13,11,7,14,12,1,3,9,5,0,15,4,8,6,2,10],
        [6,15,14,9,11,3,0,8,12,2,13,7,1,4,10,5],
        [10,2,8,4,7,6,1,5,15,11,9,14,3,12,13,0],
        [0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15],
        [14,10,4,8,9,15,13,6,1,12,0,2,11,7,5,3],
    ];
    $add64 = static function (array $a, array $b): array {
        $lo = $a[1] + $b[1];
        $carry = ($lo > 0xffffffff) ? 1 : 0;
        $lo &= 0xffffffff;
        $hi = ($a[0] + $b[0] + $carry) & 0xffffffff;
        return [$hi, $lo];
    };
    $xor64 = static fn(array $a, array $b): array => [($a[0] ^ $b[0]) & 0xffffffff, ($a[1] ^ $b[1]) & 0xffffffff];
    $rotr64 = static function (array $x, int $n): array {
        $h = $x[0];
        $l = $x[1];
        if ($n === 32) return [$l, $h];
        if ($n < 32) {
            return [(($h >> $n) | (($l << (32 - $n)) & 0xffffffff)) & 0xffffffff, (($l >> $n) | (($h << (32 - $n)) & 0xffffffff)) & 0xffffffff];
        }
        $s = $n - 32;
        return [(($l >> $s) | (($h << (32 - $s)) & 0xffffffff)) & 0xffffffff, (($h >> $s) | (($l << (32 - $s)) & 0xffffffff)) & 0xffffffff];
    };
    $g = static function (array &$v, int $a, int $b, int $c, int $d, array $x, array $y) use ($add64, $xor64, $rotr64): void {
        $v[$a] = $add64($add64($v[$a], $v[$b]), $x);
        $v[$d] = $rotr64($xor64($v[$d], $v[$a]), 32);
        $v[$c] = $add64($v[$c], $v[$d]);
        $v[$b] = $rotr64($xor64($v[$b], $v[$c]), 24);
        $v[$a] = $add64($add64($v[$a], $v[$b]), $y);
        $v[$d] = $rotr64($xor64($v[$d], $v[$a]), 16);
        $v[$c] = $add64($v[$c], $v[$d]);
        $v[$b] = $rotr64($xor64($v[$b], $v[$c]), 63);
    };

    $h = $iv;
    $h[0][1] = ($h[0][1] ^ 0x01010000 ^ $outLen) & 0xffffffff;
    $len = strlen($data);
    $pos = 0;
    $tLo = 0;
    $tHi = 0;

    while ($len - $pos > 128 || $pos === 0) {
        $take = min(128, $len - $pos);
        $block = substr($data, $pos, $take);
        $pos += $take;
        $tLo += $take;
        if ($tLo > 0xffffffff) {
            $tHi = ($tHi + 1) & 0xffffffff;
            $tLo &= 0xffffffff;
        }
        $isLast = ($pos >= $len);
        if (strlen($block) < 128) {
            $block = str_pad($block, 128, "\x00");
        }
        $m = [];
        for ($i = 0; $i < 16; $i++) {
            $u = unpack('V2', substr($block, $i * 8, 8));
            $m[$i] = [$u[2] & 0xffffffff, $u[1] & 0xffffffff];
        }
        $v = array_merge($h, $iv);
        $v[12] = $xor64($v[12], [$tHi, $tLo]);
        if ($isLast) {
            $v[14] = [($v[14][0] ^ 0xffffffff) & 0xffffffff, ($v[14][1] ^ 0xffffffff) & 0xffffffff];
        }
        for ($r = 0; $r < 12; $r++) {
            $s = $sigma[$r];
            $g($v, 0, 4, 8, 12, $m[$s[0]], $m[$s[1]]);
            $g($v, 1, 5, 9, 13, $m[$s[2]], $m[$s[3]]);
            $g($v, 2, 6, 10, 14, $m[$s[4]], $m[$s[5]]);
            $g($v, 3, 7, 11, 15, $m[$s[6]], $m[$s[7]]);
            $g($v, 0, 5, 10, 15, $m[$s[8]], $m[$s[9]]);
            $g($v, 1, 6, 11, 12, $m[$s[10]], $m[$s[11]]);
            $g($v, 2, 7, 8, 13, $m[$s[12]], $m[$s[13]]);
            $g($v, 3, 4, 9, 14, $m[$s[14]], $m[$s[15]]);
        }
        for ($i = 0; $i < 8; $i++) {
            $h[$i] = $xor64($h[$i], $xor64($v[$i], $v[$i + 8]));
        }
        if ($isLast) {
            break;
        }
    }
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= pack('VV', $h[$i][1], $h[$i][0]);
    }
    return substr($out, 0, $outLen);
}

function kip_u128_le(string $dec): string
{
    $hex = substr(kip_dec_to_hex($dec), 2);
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    $be = hex2bin($hex) ?: '';
    $le = strrev($be);
    return str_pad(substr($le, 0, 16), 16, "\x00");
}

function kip_u64_le(string $dec): string
{
    $hex = substr(kip_dec_to_hex($dec), 2);
    if (strlen($hex) > 16) {
        throw new InvalidArgumentException('Value does not fit in an unsigned 64-bit integer.');
    }
    $hex = str_pad($hex, 16, '0', STR_PAD_LEFT);
    $be = hex2bin($hex);
    if ($be === false) {
        throw new RuntimeException('Could not encode an unsigned 64-bit integer.');
    }
    return strrev($be);
}

function kip_read_u128_le(string $bytes, int $offset = 0): string
{
    $slice = substr($bytes, $offset, 16);
    if (strlen($slice) < 16) {
        return '0';
    }
    $beHex = bin2hex(strrev($slice));
    return kip_hex_to_dec($beHex);
}

function kip_scale_compact(string $dec): string
{
    if (kip_dec_cmp($dec, '64') < 0) {
        return chr(((int) $dec) << 2);
    }
    if (kip_dec_cmp($dec, '16384') < 0) {
        return pack('v', (((int) $dec) << 2) | 1);
    }
    if (kip_dec_cmp($dec, '1073741824') < 0) {
        return pack('V', (((int) $dec) << 2) | 2);
    }
    $shifted = kip_u128_le($dec);
    $trimmed = rtrim($shifted, "\x00");
    if ($trimmed === '') {
        $trimmed = "\x00";
    }
    $len = strlen($trimmed);
    return chr((($len - 4) << 2) | 3) . $trimmed;
}

// ---------- Station Address Derivation Across All Non-EVM Chains ----------

function kip_tron_station_address(string $privateKey): string
{
    $evmAddr = kip_gas_station_address($privateKey);
    $payload = "\x41" . hex2bin(substr($evmAddr, 2));
    $chk = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    return kip_base58_encode($payload . $chk);
}

function kip_sol_station_address(string $privateKey): string
{
    return kip_base58_encode(kip_ed25519_pubkey(hex2bin($privateKey)));
}

function kip_apt_station_address(string $privateKey): string
{
    $pub = kip_ed25519_pubkey(hex2bin($privateKey));
    return '0x' . bin2hex(hash('sha3-256', $pub . "\x00", true));
}

function kip_near_station_address(string $privateKey): string
{
    return strtolower(bin2hex(kip_ed25519_pubkey(hex2bin($privateKey))));
}

function kip_dot_station_address(string $privateKey): string
{
    $pub = kip_ed25519_pubkey(hex2bin($privateKey));
    $chk = substr(kip_blake2b("SS58PRE\x00" . $pub, 64), 0, 2);
    return kip_base58_encode("\x00" . $pub . $chk);
}

function kip_ton_crc16(string $data): string
{
    $poly = 0x1021;
    $reg = 0;
    $msg = $data . "\x00\x00";
    $len = strlen($msg);
    for ($i = 0; $i < $len; $i++) {
        $byte = ord($msg[$i]);
        $mask = 0x80;
        while ($mask > 0) {
            $reg <<= 1;
            if (($byte & $mask) !== 0) {
                $reg += 1;
            }
            $mask >>= 1;
            if ($reg > 0xffff) {
                $reg &= 0xffff;
                $reg ^= $poly;
            }
        }
    }
    return chr(($reg >> 8) & 0xff) . chr($reg & 0xff);
}

function kip_ton_friendly_address(int $workchain, string $hash32, bool $bounceable = false): string
{
    $addr = chr($bounceable ? 0x11 : 0x51) . chr($workchain & 0xff) . $hash32;
    $full = $addr . kip_ton_crc16($addr);
    return rtrim(strtr(base64_encode($full), '+/', '-_'), '=');
}

function kip_ton_parse_address(string $input): ?array
{
    $compact = preg_replace('/\s+/', '', trim($input)) ?? '';
    if ($compact === '') {
        return null;
    }
    if (preg_match('/^(-?\d+):([a-fA-F0-9]{64})$/', $compact, $m) === 1) {
        $wc = (int) $m[1];
        if ($wc !== 0 && $wc !== -1) {
            return null;
        }
        return ['workchain' => $wc, 'hash' => hex2bin($m[2])];
    }
    if (preg_match('/^([UEk0]Q[A-Za-z0-9_-]{46}|[UEk0]Q[A-Za-z0-9+\/]{46})$/', $compact) !== 1) {
        return null;
    }
    $b64 = strtr($compact, '-_', '+/');
    $raw = base64_decode($b64, true);
    if (!is_string($raw) || strlen($raw) !== 36) {
        return null;
    }
    if (substr($raw, 34, 2) !== kip_ton_crc16(substr($raw, 0, 34))) {
        return null;
    }
    $wcByte = ord($raw[1]);
    $wc = $wcByte === 0xff ? -1 : $wcByte;
    if ($wc !== 0 && $wc !== -1) {
        return null;
    }
    return ['workchain' => $wc, 'hash' => substr($raw, 2, 32)];
}

function kip_ton_v4_addr_hash(string $pub32): string
{
    $dataRepr = "\x00\x51\x00\x00\x00\x00\x29\xa9\xa3\x17" . $pub32 . "\x40";
    $dataHash = hash('sha256', $dataRepr, true);
    $stateRepr = "\x02\x01\x34"
        . chr((KIP_TON_V4R2_CODE_DEPTH >> 8) & 0xff)
        . chr(KIP_TON_V4R2_CODE_DEPTH & 0xff)
        . "\x00\x00"
        . hex2bin(KIP_TON_V4R2_CODE_HASH_HEX)
        . $dataHash;
    return hash('sha256', $stateRepr, true);
}

function kip_ton_station_address(string $privateKey): string
{
    $pub = kip_ed25519_pubkey(hex2bin($privateKey));
    return kip_ton_friendly_address(0, kip_ton_v4_addr_hash($pub), false);
}

function kip_all_gas_station_addresses(): array
{
    $out = ['configured' => true];
    $definitions = [
        'evm' => ['GAS_STATION_PRIVATE_KEY', 'kip_gas_station_address'],
        'tron' => ['GAS_STATION_TRON_PRIVATE_KEY', 'kip_tron_station_address'],
        'sol' => ['GAS_STATION_SOL_SEED', 'kip_sol_station_address'],
        'ton' => ['GAS_STATION_TON_SEED', 'kip_ton_station_address'],
        'apt' => ['GAS_STATION_APT_SEED', 'kip_apt_station_address'],
        'near' => ['GAS_STATION_NEAR_SEED', 'kip_near_station_address'],
        'dot' => ['GAS_STATION_DOT_SEED', 'kip_dot_station_address'],
    ];
    foreach ($definitions as $key => [$envName, $derive]) {
        try {
            $out[$key] = $derive(kip_gas_secret_hex($envName));
        } catch (Throwable $e) {
            $out[$key] = null;
            $out[$key . 'Error'] = $e->getMessage();
        }
    }
    $out['configured'] = is_string($out['evm'] ?? null);
    return $out;
}

function kip_gas_station_health(?string $onlyChain = null): array
{
    $policies = kip_sanitize_gas_sponsorship(kip_load_config()['gasSponsorship'] ?? null);
    $addresses = kip_all_gas_station_addresses();
    $evmKeys = ['bep20', 'ethereum', 'base', 'arbitrum', 'optimism', 'avalanche', 'polygon'];
    $addressKey = [
        'bep20' => 'evm', 'ethereum' => 'evm', 'base' => 'evm', 'arbitrum' => 'evm',
        'optimism' => 'evm', 'avalanche' => 'evm', 'polygon' => 'evm',
        'tron' => 'tron', 'sol' => 'sol', 'ton' => 'ton', 'apt' => 'apt',
        'near' => 'near', 'dot' => 'dot',
    ];
    $out = [];
    foreach ($policies as $chainKey => $policy) {
        if ($onlyChain !== null && $onlyChain !== '' && $onlyChain !== $chainKey) {
            continue;
        }
        $addr = $addresses[$addressKey[$chainKey]] ?? null;
        $row = [
            'chain' => $chainKey,
            'chainId' => $policy['chainId'],
            'symbol' => $policy['symbol'],
            'enabled' => $policy['enabled'],
            'configured' => is_string($addr) && $addr !== '',
            'address' => is_string($addr) ? $addr : null,
            'balanceRaw' => null,
            'status' => !is_string($addr) || $addr === '' ? 'unconfigured' : ($policy['enabled'] ? 'unchecked' : 'disabled'),
        ];
        if ($onlyChain !== null && $row['configured']) {
            try {
                if (in_array($chainKey, $evmKeys, true)) {
                    $row['balanceRaw'] = kip_bnb_balance_wei($addr, (int) $policy['chainId']);
                } elseif ($chainKey === 'tron') {
                    $account = kip_tron_post('/wallet/getaccount', ['address' => $addr, 'visible' => true]);
                    $row['balanceRaw'] = (string) ((int) ($account['balance'] ?? 0));
                } elseif ($chainKey === 'sol') {
                    $balance = kip_sol_rpc('getBalance', [$addr, ['commitment' => 'confirmed']]);
                    $row['balanceRaw'] = (string) ((int) ($balance['value'] ?? 0));
                } elseif ($chainKey === 'ton') {
                    $account = kip_ton_api('/accounts/' . rawurlencode($addr));
                    $row['balanceRaw'] = (string) ((int) ($account['balance'] ?? 0));
                } elseif ($chainKey === 'apt') {
                    $row['balanceRaw'] = (string) kip_apt_balance_octas($addr);
                } elseif ($chainKey === 'near') {
                    $account = kip_near_rpc('query', ['request_type' => 'view_account', 'finality' => 'final', 'account_id' => $addr]);
                    $row['balanceRaw'] = !empty($account['_unknown_account']) ? '0' : (string) ($account['amount'] ?? '0');
                } elseif ($chainKey === 'dot') {
                    $pub = kip_dot_decode_ss58($addr);
                    $row['balanceRaw'] = $pub === null ? '0' : kip_dot_account_info($pub)['free'];
                }
                $row['status'] = kip_dec_cmp((string) ($row['balanceRaw'] ?? '0'), '0') > 0
                    ? ($policy['enabled'] ? 'ready' : 'funded-disabled')
                    : 'unfunded';
            } catch (Throwable $e) {
                $row['status'] = 'rpc-error';
                $row['error'] = $e->getMessage();
            }
        }
        $out[$chainKey] = $row;
    }
    return $out;
}

function kip_gas_signer_self_test(): array
{
    $seedHex = str_repeat('01', 32);
    $seed = hex2bin($seedHex);
    if ($seed === false) {
        throw new RuntimeException('Could not create signer fixture seed.');
    }
    $checks = [];
    $assert = static function (string $name, bool $passed) use (&$checks): void {
        if (!$passed) {
            throw new RuntimeException('Gas signer self-test failed: ' . $name);
        }
        $checks[] = $name;
    };

    $fixture = "\x00\x00Kip gas signer fixture\xff";
    $assert('base58', kip_base58_decode(kip_base58_encode($fixture)) === $fixture);
    $assert('u64-le', bin2hex(kip_u64_le('72623859790382856')) === '0807060504030201');
    $assert('u128-le', strlen(kip_u128_le('340282366920938463463374607431768211455')) === 16);

    $pub = kip_ed25519_pubkey($seed);
    $signature = kip_ed25519_sign('Kip deterministic signer test', $seed);
    $assert('ed25519-shape', strlen($pub) === 32 && strlen($signature) === 64);
    if (function_exists('sodium_crypto_sign_verify_detached')) {
        $assert('ed25519-verify', sodium_crypto_sign_verify_detached($signature, 'Kip deterministic signer test', $pub));
    }

    $assert('solana-address', strlen((string) kip_base58_decode(kip_sol_station_address($seedHex))) === 32);
    $assert('aptos-address', preg_match('/^0x[a-f0-9]{64}$/', kip_apt_station_address($seedHex)) === 1);
    $assert('near-address', preg_match('/^[a-f0-9]{64}$/', kip_near_station_address($seedHex)) === 1);
    $ton = kip_ton_parse_address(kip_ton_station_address($seedHex));
    $assert('ton-address', is_array($ton) && strlen($ton['hash']) === 32);
    $assert('polkadot-address', kip_dot_decode_ss58(kip_dot_station_address($seedHex)) === $pub);
    $tron = kip_base58_decode(kip_tron_station_address($seedHex));
    $assert('tron-address', is_string($tron) && strlen($tron) === 25 && ord($tron[0]) === 0x41);

    return ['ok' => true, 'checks' => $checks];
}

// ---------- Minimal TON Cell & BOC Serializer ----------

class KipTonCell
{
    public array $bits;
    public array $refs;
    public bool $exotic;
    private ?string $hashCache = null;
    private ?int $depthCache = null;

    public function __construct(array $bits = [], array $refs = [], bool $exotic = false)
    {
        $this->bits = $bits;
        $this->refs = $refs;
        $this->exotic = $exotic;
    }

    public function depth(): int
    {
        if ($this->depthCache !== null) {
            return $this->depthCache;
        }
        $d = 0;
        foreach ($this->refs as $r) {
            $d = max($d, $r->depth());
        }
        if (count($this->refs) > 0) {
            $d += 1;
        }
        $this->depthCache = $d;
        return $d;
    }

    public function paddedBytes(): string
    {
        $bitLen = count($this->bits);
        $byteLen = (int) ceil($bitLen / 8);
        if ($byteLen === 0) {
            return '';
        }
        $arr = array_fill(0, $byteLen, 0);
        for ($i = 0; $i < $bitLen; $i++) {
            if ($this->bits[$i]) {
                $arr[$i >> 3] |= 1 << (7 - ($i & 7));
            }
        }
        if ($bitLen % 8 !== 0) {
            $arr[$bitLen >> 3] |= 1 << (7 - ($bitLen & 7));
        }
        return pack('C*', ...$arr);
    }

    public function d1(): int
    {
        return count($this->refs) + ($this->exotic ? 8 : 0);
    }

    public function d2(): int
    {
        $bitLen = count($this->bits);
        return ($bitLen >> 3) + (int) ceil($bitLen / 8);
    }

    public function hash(): string
    {
        if ($this->hashCache !== null) {
            return $this->hashCache;
        }
        $repr = chr($this->d1()) . chr($this->d2()) . $this->paddedBytes();
        foreach ($this->refs as $r) {
            $dep = $r->depth();
            $repr .= chr(($dep >> 8) & 0xff) . chr($dep & 0xff);
        }
        foreach ($this->refs as $r) {
            $repr .= $r->hash();
        }
        $this->hashCache = hash('sha256', $repr, true);
        return $this->hashCache;
    }

    public function hashHex(): string
    {
        return bin2hex($this->hash());
    }
}

class KipTonBuilder
{
    public array $bits = [];
    public array $refs = [];

    public function storeBit(int $b): self
    {
        $this->bits[] = $b ? 1 : 0;
        return $this;
    }

    public function storeUint(int $val, int $bitCount): self
    {
        for ($i = $bitCount - 1; $i >= 0; $i--) {
            $this->bits[] = ($val >> $i) & 1;
        }
        return $this;
    }

    public function storeInt(int $val, int $bitCount): self
    {
        if ($val < 0) {
            $val = (1 << $bitCount) + $val;
        }
        return $this->storeUint($val, $bitCount);
    }

    public function storeBytes(string $bin): self
    {
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bin[$i]);
            for ($j = 7; $j >= 0; $j--) {
                $this->bits[] = ($b >> $j) & 1;
            }
        }
        return $this;
    }

    public function storeCoins(int $amount): self
    {
        if ($amount <= 0) {
            return $this->storeUint(0, 4);
        }
        $hex = dechex($amount);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        $byteLen = strlen($hex) >> 1;
        $this->storeUint($byteLen, 4);
        return $this->storeBytes(hex2bin($hex) ?: '');
    }

    public function storeAddress(int $workchain, string $hash32): self
    {
        return $this->storeUint(2, 2)->storeBit(0)->storeInt($workchain, 8)->storeBytes($hash32);
    }

    public function storeRef(KipTonCell $cell): self
    {
        $this->refs[] = $cell;
        return $this;
    }

    public function storeBuilder(self $other): self
    {
        foreach ($other->bits as $b) {
            $this->bits[] = $b;
        }
        foreach ($other->refs as $r) {
            $this->refs[] = $r;
        }
        return $this;
    }

    public function storeCellSlice(KipTonCell $cell): self
    {
        foreach ($cell->bits as $b) {
            $this->bits[] = $b;
        }
        foreach ($cell->refs as $r) {
            $this->refs[] = $r;
        }
        return $this;
    }

    public function storeEither(KipTonCell $cell): self
    {
        if (count($this->bits) + 1 + count($cell->bits) <= 1023 && count($this->refs) + count($cell->refs) <= 4) {
            return $this->storeBit(0)->storeCellSlice($cell);
        }
        return $this->storeBit(1)->storeRef($cell);
    }

    public function endCell(): KipTonCell
    {
        return new KipTonCell($this->bits, $this->refs);
    }
}

function kip_ton_crc32c(string $data): string
{
    $poly = 0x82f63b78;
    $crc = 0xffffffff;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= ord($data[$i]);
        for ($b = 0; $b < 8; $b++) {
            $crc = ($crc & 1) ? ((($crc >> 1) & 0x7fffffff) ^ $poly) : (($crc >> 1) & 0x7fffffff);
        }
    }
    $crc = (~$crc) & 0xffffffff;
    return pack('V', $crc);
}

function kip_ton_deserialize_boc(string $bytes): KipTonCell
{
    $pos = 4;
    $flagsByte = ord($bytes[$pos++]);
    $hasIdx = ($flagsByte & 0x80) !== 0;
    $sizeBytes = $flagsByte & 0x07;
    $offBytes = ord($bytes[$pos++]);
    $readU = static function (int $n) use ($bytes, &$pos): int {
        $v = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($bytes[$pos++]);
        }
        return $v;
    };
    $cellsCount = $readU($sizeBytes);
    $rootsCount = $readU($sizeBytes);
    $readU($sizeBytes);
    $readU($offBytes);
    $roots = [];
    for ($i = 0; $i < $rootsCount; $i++) {
        $roots[] = $readU($sizeBytes);
    }
    if ($hasIdx) {
        $pos += $cellsCount * $offBytes;
    }
    $rawCells = [];
    for ($i = 0; $i < $cellsCount; $i++) {
        $d1 = ord($bytes[$pos++]);
        $d2 = ord($bytes[$pos++]);
        $refsCount = $d1 & 7;
        $exotic = ($d1 & 8) !== 0;
        $dataBytes = (int) ceil($d2 / 2);
        $padded = ($d2 % 2) !== 0;
        $slice = substr($bytes, $pos, $dataBytes);
        $pos += $dataBytes;
        $bits = [];
        for ($bIdx = 0; $bIdx < $dataBytes; $bIdx++) {
            $b = ord($slice[$bIdx]);
            for ($j = 7; $j >= 0; $j--) {
                $bits[] = ($b >> $j) & 1;
            }
        }
        if ($padded) {
            while (count($bits) > 0 && $bits[count($bits) - 1] === 0) {
                array_pop($bits);
            }
            if (count($bits) > 0) {
                array_pop($bits);
            }
        }
        $refs = [];
        for ($r = 0; $r < $refsCount; $r++) {
            $refs[] = $readU($sizeBytes);
        }
        $rawCells[] = ['bits' => $bits, 'refs' => $refs, 'exotic' => $exotic, 'cell' => null];
    }
    for ($i = $cellsCount - 1; $i >= 0; $i--) {
        $childCells = [];
        foreach ($rawCells[$i]['refs'] as $idx) {
            $childCells[] = $rawCells[$idx]['cell'];
        }
        $rawCells[$i]['cell'] = new KipTonCell($rawCells[$i]['bits'], $childCells, $rawCells[$i]['exotic']);
    }
    return $rawCells[$roots[0]]['cell'];
}

function kip_ton_serialize_boc(KipTonCell $root): string
{
    $pending = [$root];
    $allCells = [];
    $notPerm = [];
    $sorted = [];

    while (count($pending) > 0) {
        $batch = $pending;
        $pending = [];
        foreach ($batch as $c) {
            $h = $c->hashHex();
            if (isset($allCells[$h])) {
                continue;
            }
            $notPerm[$h] = true;
            $refHashes = [];
            foreach ($c->refs as $r) {
                $refHashes[] = $r->hashHex();
                $pending[] = $r;
            }
            $allCells[$h] = ['cell' => $c, 'refs' => $refHashes];
        }
    }

    $visit = null;
    $visit = static function (string $h) use (&$visit, &$notPerm, &$allCells, &$sorted): void {
        if (!isset($notPerm[$h])) {
            return;
        }
        $refs = $allCells[$h]['refs'];
        for ($i = count($refs) - 1; $i >= 0; $i--) {
            $visit($refs[$i]);
        }
        $sorted[] = $h;
        unset($notPerm[$h]);
    };

    while (count($notPerm) > 0) {
        $first = (string) array_key_first($notPerm);
        $visit($first);
    }

    $indexes = [];
    $countSorted = count($sorted);
    for ($i = 0; $i < $countSorted; $i++) {
        $indexes[$sorted[$countSorted - 1 - $i]] = $i;
    }

    $ordered = [];
    for ($i = $countSorted - 1; $i >= 0; $i--) {
        $ent = $allCells[$sorted[$i]];
        $rIdx = [];
        foreach ($ent['refs'] as $rh) {
            $rIdx[] = $indexes[$rh];
        }
        $ordered[] = ['cell' => $ent['cell'], 'refs' => $rIdx];
    }

    $cellsNum = count($ordered);
    $sizeBytes = 1;
    $totalCellSize = 0;
    foreach ($ordered as $item) {
        $totalCellSize += 2 + (int) ceil(count($item['cell']->bits) / 8) + count($item['refs']) * $sizeBytes;
    }
    $offsetBytes = $totalCellSize > 255 ? 2 : 1;
    $out = "\xb5\xee\x9c\x72"
        . chr(0x40 | $sizeBytes)
        . chr($offsetBytes)
        . chr($cellsNum)
        . chr(1)
        . chr(0);
    if ($offsetBytes === 2) {
        $out .= chr(($totalCellSize >> 8) & 0xff) . chr($totalCellSize & 0xff);
    } else {
        $out .= chr($totalCellSize & 0xff);
    }
    $out .= chr(0);
    foreach ($ordered as $item) {
        $out .= chr($item['cell']->d1()) . chr($item['cell']->d2()) . $item['cell']->paddedBytes();
        foreach ($item['refs'] as $r) {
            $out .= chr($r);
        }
    }
    return $out . kip_ton_crc32c($out);
}

// ---------- Per-Chain Non-EVM Top-Up Handlers ----------

function kip_request_non_evm_gas(string $userAddress, int $chainId, bool $forceTopup, array $policy): array
{
    $privateKey = kip_gas_secret_hex(kip_non_evm_private_key_name($chainId));
    switch ($chainId) {
        case 195:
            return kip_request_tron_gas($userAddress, $forceTopup, $privateKey, $policy);
        case 501:
            return kip_request_sol_gas($userAddress, $forceTopup, $privateKey, $policy);
        case -239:
            return kip_request_ton_gas($userAddress, $forceTopup, $privateKey, $policy);
        case 1001:
            return kip_request_apt_gas($userAddress, $forceTopup, $privateKey, $policy);
        case 397:
            return kip_request_near_gas($userAddress, $forceTopup, $privateKey, $policy);
        case 354:
            return kip_request_dot_gas($userAddress, $forceTopup, $privateKey, $policy);
        default:
            throw new InvalidArgumentException('Unsupported chainId ' . $chainId . ' for gas sponsorship.');
    }
}

function kip_tron_post(string $path, array $body): array
{
    $bases = kip_chain_endpoints('tron', [
        'https://api.trongrid.io',
        'https://tron-rpc.publicnode.com',
    ]);
    $lastErr = 'TRON node did not respond.';
    foreach ($bases as $base) {
        try {
            $res = kip_http_json($base . $path, $body);
            if (is_array($res['json'])) {
                return $res['json'];
            }
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
    }
    throw new RuntimeException($lastErr);
}

function kip_request_tron_gas(string $userAddress, bool $forceTopup, string $privateKey, array $policy): array
{
    $decoded = kip_base58_decode($userAddress);
    if ($decoded === null || strlen($decoded) !== 25 || ord($decoded[0]) !== 0x41) {
        throw new InvalidArgumentException('userAddress is not a valid TRON T-address.');
    }
    $chk = substr(hash('sha256', hash('sha256', substr($decoded, 0, 21), true), true), 0, 4);
    if ($chk !== substr($decoded, 21, 4)) {
        throw new InvalidArgumentException('userAddress checksum is invalid on TRON.');
    }

    $station = kip_tron_station_address($privateKey);
    if ($userAddress === $station) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'TRX', 'gasStationAddress' => $station];
    }

    $userAcc = kip_tron_post('/wallet/getaccount', ['address' => $userAddress, 'visible' => true]);
    $userSun = (int) ($userAcc['balance'] ?? 0);
    $thresholdSun = (int) $policy['thresholdRaw'];

    if ($userSun >= $thresholdSun) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'TRX', 'gasStationAddress' => $station];
    }

    $targetSun = min((int) $policy['topupRaw'], (int) $policy['maxTopupRaw']);
    $topupSun = max(1000000, $targetSun - max(0, $userSun));
    $stationAcc = kip_tron_post('/wallet/getaccount', ['address' => $station, 'visible' => true]);
    $stationSun = (int) ($stationAcc['balance'] ?? 0);
    if ($stationSun < $topupSun + 1500000) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient TRX. Fund it with TRX to sponsor TRON fees.'
        );
    }
    if (!kip_rate_allow($userAddress, kip_client_ip(), 195, (int) $policy['dailyCap'])) {
        throw new InvalidArgumentException('Gas top-up limit reached for this TRON address today.');
    }

    $created = kip_tron_post('/wallet/createtransaction', [
        'owner_address' => $station,
        'to_address' => $userAddress,
        'amount' => $topupSun,
        'visible' => true,
    ]);
    $txId = (string) ($created['txID'] ?? '');
    if ($txId === '' || !isset($created['raw_data'])) {
        throw new RuntimeException('Could not build TRON gas top-up transaction.');
    }

    require_once __DIR__ . '/vendor/autoload.php';
    $ec = new \Elliptic\EC('secp256k1');
    $sig = $ec->keyFromPrivate($privateKey, 'hex')->sign($txId, ['canonical' => true]);
    $rHex = str_pad($sig->r->toString(16), 64, '0', STR_PAD_LEFT);
    $sHex = str_pad($sig->s->toString(16), 64, '0', STR_PAD_LEFT);
    $vHex = sprintf('%02x', (int) $sig->recoveryParam);
    $created['signature'] = [$rHex . $sHex . $vHex];
    $created['visible'] = true;

    $bcast = kip_tron_post('/wallet/broadcasttransaction', $created);
    if (empty($bcast['result'])) {
        throw new RuntimeException('TRON gas top-up broadcast was rejected by the network.');
    }

    return [
        'ok' => true,
        'gasTxHash' => (string) ($bcast['txid'] ?? $txId),
        'amountRaw' => (string) $topupSun,
        'nativeSymbol' => 'TRX',
        'gasStationAddress' => $station,
    ];
}

function kip_sol_rpc(string $method, array $params)
{
    $urls = kip_chain_endpoints('sol', [
        'https://solana-rpc.publicnode.com',
        'https://api.mainnet-beta.solana.com',
    ]);
    $lastErr = 'Solana RPC did not respond.';
    foreach ($urls as $url) {
        try {
            $res = kip_http_json($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);
            if (is_array($res['json'])) {
                if (isset($res['json']['error'])) {
                    $lastErr = (string) ($res['json']['error']['message'] ?? 'Solana RPC error');
                    continue;
                }
                return $res['json']['result'] ?? null;
            }
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
    }
    throw new RuntimeException($lastErr);
}

function kip_request_sol_gas(string $userAddress, bool $forceTopup, string $privateKey, array $policy): array
{
    $userPub = kip_base58_decode($userAddress);
    if ($userPub === null || strlen($userPub) !== 32) {
        throw new InvalidArgumentException('userAddress is not a valid Solana address.');
    }

    $seed = hex2bin($privateKey);
    if ($seed === false) {
        throw new RuntimeException('Invalid Solana gas station seed.');
    }
    $stationPub = kip_ed25519_pubkey($seed);
    $station = kip_base58_encode($stationPub);
    if ($userAddress === $station) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'SOL', 'gasStationAddress' => $station];
    }

    $userBalRes = kip_sol_rpc('getBalance', [$userAddress, ['commitment' => 'confirmed']]);
    $userLamports = (int) ($userBalRes['value'] ?? 0);
    $thresholdLamports = (int) $policy['thresholdRaw'];
    $topupLamports = min((int) $policy['topupRaw'], (int) $policy['maxTopupRaw']);

    if ($userLamports >= $thresholdLamports) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'SOL', 'gasStationAddress' => $station];
    }
    $topupLamports = max(1, $topupLamports - $userLamports);

    $stationBalRes = kip_sol_rpc('getBalance', [$station, ['commitment' => 'confirmed']]);
    $stationLamports = (int) ($stationBalRes['value'] ?? 0);
    if ($stationLamports < $topupLamports + 950000) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient SOL. Fund it with SOL to sponsor Solana fees.'
        );
    }
    if (!kip_rate_allow($userAddress, kip_client_ip(), 501, (int) $policy['dailyCap'])) {
        throw new InvalidArgumentException('Gas top-up limit reached for this Solana address today.');
    }

    $latest = kip_sol_rpc('getLatestBlockhash', [['commitment' => 'confirmed']]);
    $bhB58 = (string) ($latest['value']['blockhash'] ?? '');
    $bh32 = kip_base58_decode($bhB58);
    if ($bh32 === null || strlen($bh32) !== 32) {
        throw new RuntimeException('Could not fetch Solana recent blockhash.');
    }

    $msg = "\x01\x00\x01\x03"
        . $stationPub
        . $userPub
        . str_repeat("\x00", 32)
        . $bh32
        . "\x01"
        . "\x02\x02\x00\x01\x0c"
        . pack('V', 2) . kip_u64_le((string) $topupLamports);

    $sig64 = kip_ed25519_sign($msg, $seed);
    $wireB64 = base64_encode("\x01" . $sig64 . $msg);
    $txSig = kip_sol_rpc('sendTransaction', [
        $wireB64,
        ['encoding' => 'base64', 'preflightCommitment' => 'confirmed'],
    ]);
    if (!is_string($txSig) || $txSig === '') {
        throw new RuntimeException('Solana gas top-up was not accepted by the network.');
    }

    return [
        'ok' => true,
        'gasTxHash' => $txSig,
        'amountRaw' => (string) $topupLamports,
        'nativeSymbol' => 'SOL',
        'gasStationAddress' => $station,
    ];
}

function kip_ton_api(string $path, ?array $postBody = null): array
{
    $bases = kip_chain_endpoints('ton', ['https://tonapi.io/v2']);
    $lastErr = 'TonAPI did not respond.';
    foreach ($bases as $base) {
        try {
            $res = kip_http_json($base . $path, $postBody);
            if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['json'])) {
                return $res['json'];
            }
            $lastErr = (string) ($res['json']['error'] ?? ('TonAPI HTTP ' . $res['status']));
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
    }
    throw new RuntimeException($lastErr);
}

function kip_request_ton_gas(string $userAddress, bool $forceTopup, string $privateKey, array $policy): array
{
    $parsedDest = kip_ton_parse_address($userAddress);
    if ($parsedDest === null) {
        throw new InvalidArgumentException('userAddress is not a valid TON address.');
    }

    $seed = hex2bin($privateKey);
    if ($seed === false) {
        throw new RuntimeException('Invalid TON gas station seed.');
    }
    $stationPub = kip_ed25519_pubkey($seed);
    $stationHash32 = kip_ton_v4_addr_hash($stationPub);
    $station = kip_ton_friendly_address(0, $stationHash32, false);
    if ($parsedDest['workchain'] === 0 && $parsedDest['hash'] === $stationHash32) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'GRAM', 'gasStationAddress' => $station];
    }

    $userState = kip_ton_api('/accounts/' . rawurlencode($userAddress));
    $userNano = (int) ($userState['balance'] ?? 0);
    $thresholdNano = (int) $policy['thresholdRaw'];
    $topupNano = min((int) $policy['topupRaw'], (int) $policy['maxTopupRaw']);

    if ($userNano >= $thresholdNano) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'GRAM', 'gasStationAddress' => $station];
    }
    $topupNano = max(1, $topupNano - $userNano);

    $stationState = kip_ton_api('/accounts/' . rawurlencode($station));
    $stationNano = (int) ($stationState['balance'] ?? 0);
    if ($stationNano < $topupNano + 30000000) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient GRAM. Fund it with GRAM (TON) to sponsor TON fees.'
        );
    }
    if (!kip_rate_allow($userAddress, kip_client_ip(), -239, (int) $policy['dailyCap'])) {
        throw new InvalidArgumentException('Gas top-up limit reached for this TON address today.');
    }

    $seqno = 0;
    if (($stationState['status'] ?? '') === 'active') {
        $seqResp = kip_ton_api('/wallet/' . rawurlencode($station) . '/seqno');
        $seqno = (int) ($seqResp['seqno'] ?? 0);
    }

    $internalMsg = (new KipTonBuilder())
        ->storeBit(0)->storeBit(1)->storeBit(0)->storeBit(0)
        ->storeUint(0, 2)
        ->storeAddress($parsedDest['workchain'], $parsedDest['hash'])
        ->storeCoins($topupNano)
        ->storeBit(0)
        ->storeCoins(0)
        ->storeCoins(0)
        ->storeUint(0, 64)
        ->storeUint(0, 32)
        ->storeBit(0)
        ->storeBit(0)
        ->endCell();

    $timeout = time() + 60;
    $signingBuilder = (new KipTonBuilder())
        ->storeUint(KIP_TON_V4_ID, 32)
        ->storeUint($timeout, 32)
        ->storeUint($seqno, 32)
        ->storeUint(0, 8)
        ->storeUint(1, 8)
        ->storeRef($internalMsg);

    $sig64 = kip_ed25519_sign($signingBuilder->endCell()->hash(), $seed);
    $bodyCell = (new KipTonBuilder())
        ->storeBytes($sig64)
        ->storeBuilder($signingBuilder)
        ->endCell();

    $ext = (new KipTonBuilder())
        ->storeUint(2, 2)
        ->storeUint(0, 2)
        ->storeAddress(0, $stationHash32)
        ->storeCoins(0);

    if (($stationState['status'] ?? '') !== 'active') {
        $codeCell = kip_ton_deserialize_boc((string) base64_decode(KIP_TON_V4R2_CODE_B64, true));
        $dataCell = (new KipTonBuilder())
            ->storeUint(0, 32)
            ->storeUint(KIP_TON_V4_ID, 32)
            ->storeBytes($stationPub)
            ->storeBit(0)
            ->endCell();
        $stateInitCell = (new KipTonBuilder())
            ->storeBit(0)->storeBit(0)->storeBit(1)->storeBit(1)->storeBit(0)
            ->storeRef($codeCell)
            ->storeRef($dataCell)
            ->endCell();
        $ext->storeBit(1)->storeEither($stateInitCell);
    } else {
        $ext->storeBit(0);
    }

    $ext->storeEither($bodyCell);
    $bocB64 = base64_encode(kip_ton_serialize_boc($ext->endCell()));
    $sent = kip_ton_api('/blockchain/message', ['boc' => $bocB64]);

    return [
        'ok' => true,
        'gasTxHash' => (string) ($sent['message_hash'] ?? bin2hex($ext->endCell()->hash())),
        'amountRaw' => (string) $topupNano,
        'nativeSymbol' => 'GRAM',
        'gasStationAddress' => $station,
    ];
}

function kip_apt_api(string $path, ?array $postBody = null)
{
    $bases = kip_chain_endpoints('apt', ['https://fullnode.mainnet.aptoslabs.com/v1']);
    $lastErr = 'Aptos node did not respond.';
    foreach ($bases as $base) {
        try {
            $res = kip_http_json($base . $path, $postBody);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                if ($res['json'] !== null) {
                    return $res['json'];
                }
                $str = json_decode($res['raw'], true);
                if (is_string($str)) {
                    return $str;
                }
            }
            $lastErr = (string) ($res['json']['message'] ?? ('Aptos HTTP ' . $res['status']));
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
    }
    throw new RuntimeException($lastErr);
}

function kip_apt_balance_octas(string $addr): int
{
    try {
        $res = kip_apt_api('/view', [
            'function' => '0x1::coin::balance',
            'type_arguments' => ['0x1::aptos_coin::AptosCoin'],
            'arguments' => [$addr],
        ]);
        return is_array($res) && isset($res[0]) ? (int) $res[0] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function kip_request_apt_gas(string $userAddress, bool $forceTopup, string $privateKey, array $policy): array
{
    $clean = strtolower(preg_replace('/^0x/i', '', trim($userAddress)) ?? '');
    if ($clean === '' || strlen($clean) > 64 || preg_match('/^[a-f0-9]+$/', $clean) !== 1) {
        throw new InvalidArgumentException('userAddress is not a valid Aptos 0x address.');
    }
    $recipient = '0x' . str_pad($clean, 64, '0', STR_PAD_LEFT);

    $seed = hex2bin($privateKey);
    if ($seed === false) {
        throw new RuntimeException('Invalid Aptos gas station seed.');
    }
    $stationPub = kip_ed25519_pubkey($seed);
    $station = '0x' . bin2hex(hash('sha3-256', $stationPub . "\x00", true));
    if ($recipient === $station) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'APT', 'gasStationAddress' => $station];
    }

    $userOctas = kip_apt_balance_octas($recipient);
    $thresholdOctas = (int) $policy['thresholdRaw'];
    $topupOctas = min((int) $policy['topupRaw'], (int) $policy['maxTopupRaw']);

    if ($userOctas >= $thresholdOctas) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'APT', 'gasStationAddress' => $station];
    }
    $topupOctas = max(1, $topupOctas - $userOctas);

    $stationOctas = kip_apt_balance_octas($station);
    if ($stationOctas < $topupOctas + 500000) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient APT. Fund it with APT to sponsor Aptos fees.'
        );
    }
    if (!kip_rate_allow($recipient, kip_client_ip(), 1001, (int) $policy['dailyCap'])) {
        throw new InvalidArgumentException('Gas top-up limit reached for this Aptos address today.');
    }

    $accInfo = kip_apt_api('/accounts/' . rawurlencode($station));
    $seqNum = (string) ($accInfo['sequence_number'] ?? '0');

    $txRequest = [
        'sender' => $station,
        'sequence_number' => $seqNum,
        'max_gas_amount' => '20000',
        'gas_unit_price' => '100',
        'expiration_timestamp_secs' => (string) (time() + 600),
        'payload' => [
            'type' => 'entry_function_payload',
            'function' => '0x1::aptos_account::transfer',
            'type_arguments' => [],
            'arguments' => [$recipient, (string) $topupOctas],
        ],
    ];

    $signingHex = kip_apt_api('/transactions/encode_submission', $txRequest);
    if (!is_string($signingHex)) {
        throw new RuntimeException('Could not encode Aptos gas top-up transaction.');
    }
    $signingBytes = hex2bin(preg_replace('/^0x/i', '', $signingHex) ?? '');
    if ($signingBytes === false) {
        throw new RuntimeException('Invalid Aptos signing payload.');
    }
    $sig64 = kip_ed25519_sign($signingBytes, $seed);
    $txRequest['signature'] = [
        'type' => 'ed25519_signature',
        'public_key' => '0x' . bin2hex($stationPub),
        'signature' => '0x' . bin2hex($sig64),
    ];

    $submitted = kip_apt_api('/transactions', $txRequest);
    $hash = is_array($submitted) ? (string) ($submitted['hash'] ?? '') : '';
    if ($hash === '') {
        throw new RuntimeException('Aptos gas top-up was rejected by the network.');
    }

    return [
        'ok' => true,
        'gasTxHash' => $hash,
        'amountRaw' => (string) $topupOctas,
        'nativeSymbol' => 'APT',
        'gasStationAddress' => $station,
    ];
}

function kip_near_rpc(string $method, $params): array
{
    $urls = kip_chain_endpoints('near', [
        'https://free.rpc.fastnear.com',
        'https://near.lava.build',
        'https://1rpc.io/near',
    ]);
    $lastErr = 'NEAR RPC did not respond.';
    foreach ($urls as $url) {
        try {
            $res = kip_http_json($url, [
                'jsonrpc' => '2.0',
                'id' => 'kip',
                'method' => $method,
                'params' => $params,
            ]);
            if (is_array($res['json'])) {
                if (isset($res['json']['error'])) {
                    $cause = (string) ($res['json']['error']['cause']['name'] ?? '');
                    if ($cause === 'UNKNOWN_ACCOUNT') {
                        return ['_unknown_account' => true];
                    }
                    $lastErr = (string) ($res['json']['error']['message'] ?? 'NEAR RPC error');
                    continue;
                }
                return is_array($res['json']['result'] ?? null) ? $res['json']['result'] : [];
            }
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
    }
    throw new RuntimeException($lastErr);
}

function kip_request_near_gas(string $userAddress, bool $forceTopup, string $privateKey, array $policy): array
{
    $recipient = strtolower(trim($userAddress));
    $validImplicit = preg_match('/^[a-f0-9]{64}$/', $recipient) === 1;
    $validNamed = strlen($recipient) >= 2 && strlen($recipient) <= 64 && preg_match('/^(([a-z\d]+[-_])*[a-z\d]+\.)*([a-z\d]+[-_])*[a-z\d]+$/', $recipient) === 1;
    if (!$validImplicit && !$validNamed) {
        throw new InvalidArgumentException('userAddress is not a valid NEAR account ID.');
    }

    $seed = hex2bin($privateKey);
    if ($seed === false) {
        throw new RuntimeException('Invalid NEAR gas station seed.');
    }
    $stationPub = kip_ed25519_pubkey($seed);
    $station = strtolower(bin2hex($stationPub));
    if ($recipient === $station) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'NEAR', 'gasStationAddress' => $station];
    }

    $userAcc = kip_near_rpc('query', [
        'request_type' => 'view_account',
        'finality' => 'final',
        'account_id' => $recipient,
    ]);
    $userYocto = !empty($userAcc['_unknown_account']) ? '0' : (string) ($userAcc['amount'] ?? '0');
    $thresholdYocto = $policy['thresholdRaw'];
    $topupYocto = kip_dec_cmp($policy['topupRaw'], $policy['maxTopupRaw']) <= 0
        ? $policy['topupRaw']
        : $policy['maxTopupRaw'];

    if (kip_dec_cmp($userYocto, $thresholdYocto) >= 0) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'NEAR', 'gasStationAddress' => $station];
    }
    $topupYocto = kip_dec_cmp($userYocto, $topupYocto) < 0
        ? kip_dec_sub($topupYocto, $userYocto)
        : '1';

    $stationAcc = kip_near_rpc('query', [
        'request_type' => 'view_account',
        'finality' => 'final',
        'account_id' => $station,
    ]);
    $stationYocto = !empty($stationAcc['_unknown_account']) ? '0' : (string) ($stationAcc['amount'] ?? '0');
    if (kip_dec_cmp($stationYocto, '10000000000000000000000') < 0) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient NEAR. Fund it with NEAR to sponsor NEAR fees.'
        );
    }
    if (!kip_rate_allow($recipient, kip_client_ip(), 397, (int) $policy['dailyCap'])) {
        throw new InvalidArgumentException('Gas top-up limit reached for this NEAR address today.');
    }

    $ak = kip_near_rpc('query', [
        'request_type' => 'view_access_key',
        'finality' => 'final',
        'account_id' => $station,
        'public_key' => 'ed25519:' . kip_base58_encode($stationPub),
    ]);
    $nextNonce = ((int) ($ak['nonce'] ?? 0)) + 1;
    $bh32 = kip_base58_decode((string) ($ak['block_hash'] ?? ''));
    if ($bh32 === null || strlen($bh32) !== 32) {
        throw new RuntimeException('Could not prepare NEAR access key blockhash.');
    }

    $txBytes = pack('V', strlen($station)) . $station
        . "\x00" . $stationPub
        . kip_u64_le((string) $nextNonce)
        . pack('V', strlen($recipient)) . $recipient
        . $bh32
        . pack('V', 1)
        . "\x03" . kip_u128_le($topupYocto);

    $txHash32 = hash('sha256', $txBytes, true);
    $sig64 = kip_ed25519_sign($txHash32, $seed);
    $signedB64 = base64_encode($txBytes . "\x00" . $sig64);

    try {
        $sent = kip_near_rpc('send_tx', [
            'signed_tx_base64' => $signedB64,
            'wait_until' => 'EXECUTED_OPTIMISTIC',
        ]);
    } catch (Throwable $e) {
        $sent = kip_near_rpc('broadcast_tx_commit', [$signedB64]);
    }

    $hash = (string) ($sent['transaction']['hash'] ?? $sent['transaction_outcome']['id'] ?? kip_base58_encode($txHash32));
    return [
        'ok' => true,
        'gasTxHash' => $hash,
        'amountRaw' => $topupYocto,
        'nativeSymbol' => 'NEAR',
        'gasStationAddress' => $station,
    ];
}

function kip_dot_rpc(string $method, array $params)
{
    $urls = kip_chain_endpoints('dot', [
        'https://polkadot-asset-hub-rpc.polkadot.io',
        'https://asset-hub-polkadot-rpc.n.dwellir.com',
        'https://statemint.api.onfinality.io/public',
    ]);
    $lastErr = 'Polkadot Asset Hub RPC did not respond.';
    foreach ($urls as $url) {
        try {
            $res = kip_http_json($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);
            if (is_array($res['json'])) {
                if (isset($res['json']['error'])) {
                    $lastErr = (string) ($res['json']['error']['message'] ?? 'Polkadot RPC error');
                    continue;
                }
                return $res['json']['result'] ?? null;
            }
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
        }
    }
    throw new RuntimeException($lastErr);
}

function kip_dot_decode_ss58(string $address): ?string
{
    $raw = kip_base58_decode(trim($address));
    if ($raw === null || strlen($raw) < 35) {
        return null;
    }
    $first = ord($raw[0]);
    $prefixLen = ($first & 0x40) !== 0 ? 2 : 1;
    if (strlen($raw) !== $prefixLen + 32 + 2) {
        return null;
    }
    $payload = substr($raw, 0, $prefixLen + 32);
    $chk = substr(kip_blake2b('SS58PRE' . $payload, 64), 0, 2);
    if ($chk !== substr($raw, $prefixLen + 32, 2)) {
        return null;
    }
    return substr($raw, $prefixLen, 32);
}

function kip_dot_account_info(string $pub32): array
{
    $key = KIP_DOT_SYSTEM_ACCOUNT_PREFIX . bin2hex(kip_blake2b($pub32, 16) . $pub32);
    $hexVal = kip_dot_rpc('state_getStorage', [$key]);
    if (!is_string($hexVal) || $hexVal === '') {
        return ['nonce' => 0, 'free' => '0'];
    }
    $bin = hex2bin(preg_replace('/^0x/i', '', $hexVal) ?? '');
    if ($bin === false || strlen($bin) < 32) {
        return ['nonce' => 0, 'free' => '0'];
    }
    $u = unpack('Vnonce', substr($bin, 0, 4));
    return [
        'nonce' => (int) ($u['nonce'] ?? 0),
        'free' => kip_read_u128_le($bin, 16),
    ];
}

function kip_request_dot_gas(string $userAddress, bool $forceTopup, string $privateKey, array $policy): array
{
    $destPub = kip_dot_decode_ss58($userAddress);
    if ($destPub === null) {
        throw new InvalidArgumentException('userAddress is not a valid Polkadot SS58 address.');
    }

    $seed = hex2bin($privateKey);
    if ($seed === false) {
        throw new RuntimeException('Invalid Polkadot gas station seed.');
    }
    $stationPub = kip_ed25519_pubkey($seed);
    $station = kip_dot_station_address($privateKey);
    if ($destPub === $stationPub) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'DOT', 'gasStationAddress' => $station];
    }

    $userInfo = kip_dot_account_info($destPub);
    $thresholdPlancks = $policy['thresholdRaw'];
    $topupPlancks = kip_dec_cmp($policy['topupRaw'], $policy['maxTopupRaw']) <= 0
        ? $policy['topupRaw']
        : $policy['maxTopupRaw'];

    if (kip_dec_cmp($userInfo['free'], $thresholdPlancks) >= 0) {
        return ['ok' => true, 'gasTxHash' => null, 'nativeSymbol' => 'DOT', 'gasStationAddress' => $station];
    }
    $topupPlancks = kip_dec_cmp($userInfo['free'], $topupPlancks) < 0
        ? kip_dec_sub($topupPlancks, $userInfo['free'])
        : '1';

    $stationInfo = kip_dot_account_info($stationPub);
    if (kip_dec_cmp($stationInfo['free'], '1500000000') < 0) {
        throw new RuntimeException(
            'Gas station wallet (' . $station . ') has insufficient DOT on Polkadot Asset Hub. Fund it with DOT to sponsor Polkadot fees.'
        );
    }
    if (!kip_rate_allow($userAddress, kip_client_ip(), 354, (int) $policy['dailyCap'])) {
        throw new InvalidArgumentException('Gas top-up limit reached for this Polkadot address today.');
    }

    $runtimeVer = kip_dot_rpc('state_getRuntimeVersion', []);
    $genesisHex = kip_dot_rpc('chain_getBlockHash', [0]);
    if (!is_array($runtimeVer) || !is_string($genesisHex)) {
        throw new RuntimeException('Could not read Polkadot Asset Hub runtime metadata.');
    }
    $genesis32 = hex2bin(preg_replace('/^0x/i', '', $genesisHex) ?? '');
    if ($genesis32 === false || strlen($genesis32) !== 32) {
        throw new RuntimeException('Invalid Polkadot genesis hash.');
    }

    $callBytes = "\x0a\x00\x00" . $destPub . kip_scale_compact($topupPlancks);
    $extraBytes = "\x00" . kip_scale_compact((string) $stationInfo['nonce']) . "\x00\x00\x00";
    $additionalSigned = pack('V', (int) ($runtimeVer['specVersion'] ?? 0))
        . pack('V', (int) ($runtimeVer['transactionVersion'] ?? 0))
        . $genesis32 . $genesis32 . "\x00";

    $rawPayload = $callBytes . $extraBytes . $additionalSigned;
    $toSign = strlen($rawPayload) > 256 ? kip_blake2b($rawPayload, 32) : $rawPayload;
    $sig64 = kip_ed25519_sign($toSign, $seed);

    $extBody = "\x84\x00" . $stationPub . "\x00" . $sig64 . $extraBytes . $callBytes;
    $extFull = kip_scale_compact((string) strlen($extBody)) . $extBody;
    $extHex = '0x' . bin2hex($extFull);

    $submittedHash = kip_dot_rpc('author_submitExtrinsic', [$extHex]);
    $hash = is_string($submittedHash) && $submittedHash !== ''
        ? $submittedHash
        : ('0x' . bin2hex(kip_blake2b($extFull, 32)));

    return [
        'ok' => true,
        'gasTxHash' => $hash,
        'amountRaw' => $topupPlancks,
        'nativeSymbol' => 'DOT',
        'gasStationAddress' => $station,
    ];
}
