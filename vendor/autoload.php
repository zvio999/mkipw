<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $map = [
        'Web3p\\EthereumTx\\' => __DIR__ . '/web3p/ethereum-tx/src/',
        'Web3p\\RLP\\' => __DIR__ . '/web3p/rlp/src/',
        'Web3p\\EthereumUtil\\' => __DIR__ . '/web3p/ethereum-util/src/',
        'kornrunner\\' => __DIR__ . '/kornrunner/keccak/src/',
        'Elliptic\\' => __DIR__ . '/simplito/elliptic-php/lib/',
        'BN\\' => __DIR__ . '/simplito/bn-php/lib/',
        'BI\\' => __DIR__ . '/simplito/bigint-wrapper-php/lib/',
    ];
    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
