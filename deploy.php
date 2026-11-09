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

$homeDir = dirname(dirname(__DIR__));
$repoDir = is_dir($homeDir . '/mkipw-repo/.git') ? ($homeDir . '/mkipw-repo') : __DIR__;

$output = [];
$code = 0;
if (function_exists('exec')) {
    if ($repoDir !== __DIR__) {
        $cmd = 'cd ' . escapeshellarg($repoDir)
            . ' && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1'
            . ' && cp -Rf .htaccess .user.ini *.php composer.json vendor ' . escapeshellarg(__DIR__ . '/') . ' 2>&1';
    } else {
        $cmd = 'cd ' . escapeshellarg(__DIR__) . ' && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1';
    }
    @exec($cmd, $output, $code);
}

$cleanReport = kip_scan_and_clean_hack_files();

echo json_encode([
    'ok' => $code === 0,
    'repoDir' => $repoDir,
    'exitCode' => $code,
    'gitOutput' => $output,
    'cleanReport' => $cleanReport,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
