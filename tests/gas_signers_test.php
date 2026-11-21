<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/rpc.php';
require_once dirname(__DIR__) . '/gas.php';

$result = kip_gas_signer_self_test();
$policy = kip_sanitize_gas_sponsorship([]);
if (count($policy) !== 13 || !$policy['bep20']['enabled'] || $policy['sol']['enabled']) {
    throw new RuntimeException('Gas policy defaults failed.');
}

echo 'All gas signer fixtures passed: ' . implode(', ', $result['checks']) . "\n";
