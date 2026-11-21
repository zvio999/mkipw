<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$target = dirname(__DIR__) . '/data/gas-station.env';
$names = [
    'GAS_STATION_PRIVATE_KEY',
    'GAS_STATION_TRON_PRIVATE_KEY',
    'GAS_STATION_SOL_SEED',
    'GAS_STATION_TON_SEED',
    'GAS_STATION_APT_SEED',
    'GAS_STATION_NEAR_SEED',
    'GAS_STATION_DOT_SEED',
];

$existing = [];
if (is_file($target)) {
    foreach (file($target, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $match) === 1) {
            $existing[$match[1]] = $match[2];
        }
    }
}

$created = [];
foreach ($names as $name) {
    if (!isset($existing[$name]) || preg_match('/^(?:0x)?[a-fA-F0-9]{64}$/', $existing[$name]) !== 1) {
        $existing[$name] = '0x' . bin2hex(random_bytes(32));
        $created[] = $name;
    }
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0750, true);
}
$lines = [
    '# Kip gas station hot-wallet keys. Keep an offline encrypted backup.',
    '# Generated ' . gmdate(DATE_ATOM),
];
foreach ($names as $name) {
    $lines[] = $name . '=' . $existing[$name];
}
$temp = $target . '.tmp';
file_put_contents($temp, implode("\n", $lines) . "\n", LOCK_EX);
chmod($temp, 0600);
rename($temp, $target);

echo $created === []
    ? "All gas station keys were already configured; no keys changed.\n"
    : "Generated missing keys: " . implode(', ', $created) . "\nBack up data/gas-station.env offline before funding any address.\n";
