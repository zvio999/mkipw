<?php

declare(strict_types=1);

const KIP_INVOICE_FILE = KIP_DATA_DIR . '/invoices.json';

function kip_invoice_id_ok(string $invoiceId): bool
{
    return preg_match('/^[A-Za-z0-9_-]{4,64}$/', $invoiceId) === 1;
}

function kip_tx_hash_ok(string $txHash): bool
{
    return preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash) === 1;
}

function kip_amount_ok(string $amount): bool
{
    if (preg_match('/^\d+(\.\d{1,18})?$/', $amount) !== 1) {
        return false;
    }
    return (float) $amount > 0;
}

function kip_with_invoices(callable $mutate): array
{
    if (!is_dir(KIP_DATA_DIR)) {
        mkdir(KIP_DATA_DIR, 0750, true);
    }
    $fh = fopen(KIP_INVOICE_FILE, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot open the invoice store.');
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $store = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($store)) {
        $store = [];
    }
    $store = $mutate($store);
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $json . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $store;
}

function kip_read_invoice(string $invoiceId): ?array
{
    if (!is_file(KIP_INVOICE_FILE)) {
        return null;
    }
    $raw = file_get_contents(KIP_INVOICE_FILE);
    $store = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($store) || !isset($store[$invoiceId]) || !is_array($store[$invoiceId])) {
        return null;
    }
    return $store[$invoiceId];
}

function kip_create_invoice(string $invoiceId, string $terminalId, string $amount): array
{
    $now = time();
    $record = [
        'invoiceId' => $invoiceId,
        'terminalId' => $terminalId,
        'amount' => $amount,
        'txHash' => '',
        'status' => 'pending',
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
    kip_with_invoices(static function (array $store) use ($invoiceId, $record): array {
        if (!isset($store[$invoiceId])) {
            $store[$invoiceId] = $record;
        }
        return $store;
    });
    $saved = kip_read_invoice($invoiceId);
    return $saved ?? $record;
}

function kip_submit_tx(string $invoiceId, string $txHash, string $terminalId, string $amount): array
{
    $now = time();
    kip_with_invoices(static function (array $store) use ($invoiceId, $txHash, $terminalId, $amount, $now): array {
        $current = isset($store[$invoiceId]) && is_array($store[$invoiceId]) ? $store[$invoiceId] : [
            'invoiceId' => $invoiceId,
            'terminalId' => $terminalId,
            'amount' => $amount,
            'txHash' => '',
            'status' => 'pending',
            'createdAt' => $now,
        ];
        if ($terminalId !== '') {
            $current['terminalId'] = $terminalId;
        }
        if ($amount !== '') {
            $current['amount'] = $amount;
        }
        $current['txHash'] = $txHash;
        $current['status'] = 'submitted';
        $current['updatedAt'] = $now;
        $store[$invoiceId] = $current;
        return $store;
    });
    $saved = kip_read_invoice($invoiceId);
    if ($saved === null) {
        throw new RuntimeException('Invoice was not stored.');
    }
    return $saved;
}
