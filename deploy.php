<?php
/**
 * Remote Git Pull trigger for Kip Server.
 * Protected by HMAC-SHA256 / secret token.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

const KIP_DEPLOY_TOKEN_HASH = 'c19e09142b1c20b9f6e180865d687b589c71790677fb759d193d085d3c5264e3';

$provided = (string) ($_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_KIP_DEPLOY_TOKEN'] ?? ''));
if ($provided === '' || !hash_equals(KIP_DEPLOY_TOKEN_HASH, hash('sha256', $provided))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$target = (string) ($_GET['target'] ?? 'mkipw');
$dir = $target === 'site' ? dirname(__DIR__) : __DIR__;

$output = [];
$code = 0;
if (function_exists('exec')) {
    @exec('cd ' . escapeshellarg($dir) . ' && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1', $output, $code);
}

$cleanReport = kip_scan_and_clean_hack_files();

echo json_encode([
    'ok' => $code === 0,
    'target' => $target,
    'dir' => $dir,
    'exitCode' => $code,
    'gitOutput' => $output,
    'cleanReport' => $cleanReport,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
