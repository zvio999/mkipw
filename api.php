<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/rpc.php';
require __DIR__ . '/invoices.php';
require __DIR__ . '/gas.php';
require __DIR__ . '/relay.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function kip_json(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function kip_read_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function kip_terminal_ok(string $terminalId): bool
{
    return preg_match('/^[A-Za-z0-9_-]{2,32}$/', $terminalId) === 1;
}

$action = trim((string) ($_GET['action'] ?? ''));

try {
    if ($action === '') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            kip_json(405, ['ok' => false, 'error' => 'Method not allowed']);
        }
        echo json_encode(kip_load_config(), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'gas_stations') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            kip_json(405, ['ok' => false, 'error' => 'Method not allowed']);
        }
        kip_json(200, [
            'ok' => true,
            'stations' => kip_all_gas_station_addresses(),
        ]);
    }

    if ($action === 'gas_health') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            kip_json(405, ['ok' => false, 'error' => 'Method not allowed']);
        }
        $chain = trim((string) ($_GET['chain'] ?? ''));
        if ($chain !== '' && !array_key_exists($chain, kip_default_gas_sponsorship())) {
            kip_json(400, ['ok' => false, 'error' => 'Unknown chain.']);
        }
        kip_json(200, [
            'ok' => true,
            'chains' => kip_gas_station_health($chain === '' ? null : $chain),
        ]);
    }

    if ($action === 'gas_self_test') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            kip_json(405, ['ok' => false, 'error' => 'Method not allowed']);
        }
        kip_json(200, kip_gas_signer_self_test());
    }

    if ($action === 'relay_capabilities') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            kip_json(405, ['ok' => false, 'error' => 'Method not allowed']);
        }
        $chainId = (int) ($_GET['chainId'] ?? 0);
        $tokenAddress = trim((string) ($_GET['tokenAddress'] ?? ''));
        kip_json(200, kip_relayer_capabilities($chainId, $tokenAddress));
    }

    if ($action === 'check_invoice') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            kip_json(405, ['ok' => false, 'error' => 'Method not allowed']);
        }
        $invoiceId = trim((string) ($_GET['invoiceId'] ?? ''));
        if (!kip_invoice_id_ok($invoiceId)) {
            kip_json(400, ['ok' => false, 'error' => 'invoiceId is not valid.']);
        }
        $invoice = kip_read_invoice($invoiceId);
        kip_json(200, [
            'ok' => true,
            'found' => $invoice !== null,
            'invoice' => $invoice,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        kip_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }

    $body = kip_read_body();

    if ($action === 'create_invoice') {
        $invoiceId = trim((string) ($body['invoiceId'] ?? ''));
        $terminalId = trim((string) ($body['terminalId'] ?? ''));
        $amount = trim((string) ($body['amount'] ?? ''));
        if (!kip_invoice_id_ok($invoiceId) || !kip_terminal_ok($terminalId) || !kip_amount_ok($amount)) {
            kip_json(400, ['ok' => false, 'error' => 'invoiceId, terminalId, and amount are required.']);
        }
        kip_json(200, ['ok' => true, 'invoice' => kip_create_invoice($invoiceId, $terminalId, $amount)]);
    }

    if ($action === 'submit_tx') {
        $invoiceId = trim((string) ($body['invoiceId'] ?? ''));
        $txHash = trim((string) ($body['txHash'] ?? ''));
        $terminalId = trim((string) ($body['terminalId'] ?? ''));
        $amount = trim((string) ($body['amount'] ?? ''));
        if (!kip_invoice_id_ok($invoiceId) || !kip_tx_hash_ok($txHash)) {
            kip_json(400, ['ok' => false, 'error' => 'invoiceId and txHash are required.']);
        }
        if ($terminalId !== '' && !kip_terminal_ok($terminalId)) {
            kip_json(400, ['ok' => false, 'error' => 'terminalId is not valid.']);
        }
        if ($amount !== '' && !kip_amount_ok($amount)) {
            kip_json(400, ['ok' => false, 'error' => 'amount is not valid.']);
        }
        kip_json(200, ['ok' => true, 'invoice' => kip_submit_tx($invoiceId, $txHash, $terminalId, $amount)]);
    }

    if ($action === 'request_gas') {
        $userAddress = trim((string) ($body['userAddress'] ?? ''));
        $chainId = (int) ($body['chainId'] ?? 0);
        $forceTopup = !empty($body['forceTopup']);
        kip_json(200, kip_request_gas($userAddress, $chainId, $forceTopup));
    }

    if ($action === 'request_approval_gas') {
        $userAddress = trim((string) ($body['userAddress'] ?? ''));
        $chainId = (int) ($body['chainId'] ?? 0);
        $tokenAddress = trim((string) ($body['tokenAddress'] ?? KIP_USDT_TOKEN));
        $requiredWei = trim((string) ($body['requiredWei'] ?? '1'));
        $relayerAddress = trim((string) ($body['relayerAddress'] ?? ''));
        kip_json(200, kip_request_approval_gas(
            $userAddress,
            $chainId,
            $tokenAddress,
            $requiredWei,
            $relayerAddress
        ));
    }

    if ($action === 'relay_quote') {
        $chainId = (int) ($body['chainId'] ?? 0);
        kip_json(200, kip_create_relay_quote($body, $chainId));
    }

    if ($action === 'submit_relay') {
        $chainId = (int) ($body['chainId'] ?? 0);
        kip_json(200, kip_submit_relay($body, $chainId));
    }

    kip_json(404, ['ok' => false, 'error' => 'Unknown action.']);
} catch (InvalidArgumentException $error) {
    kip_json(400, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    kip_json(500, ['ok' => false, 'error' => $error->getMessage()]);
}
