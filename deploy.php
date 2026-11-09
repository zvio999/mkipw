<?php
/**
 * Pure-PHP GitHub Auto-Deployer & Server Cleaner for kip.money + kip.money/mkipw.
 * Works without shell access, exec(), or cPanel Deploy HEAD Commit.
 */

declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');

const KIP_DEPLOY_TOKEN_HASH = 'e56a5e2dd90b9ea647c664d0f6003baff118014b7c9f1ae6bfbd97b7edc86329';

$provided = (string) ($_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_KIP_DEPLOY_TOKEN'] ?? ''));
if ($provided === '' || !hash_equals(KIP_DEPLOY_TOKEN_HASH, hash('sha256', $provided))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__))) ?: dirname(__DIR__);
$mkipwDir = $docRoot . '/mkipw';
$copied = [];
$errors = [];

// Protected runtime paths that must NEVER be overwritten
function kip_is_protected_rel(string $rel): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $protected = [
        'data/config.json',
        'data/password.hash',
        'data/gas-station.env',
        'data/invoices.json',
        'data/gas-limits.json',
        'data/admin.php',
        'data/content.json',
        'data/login-attempts.php',
    ];
    if (in_array($rel, $protected, true)) {
        return true;
    }
    if (preg_match('#^data/content\.[a-z]{2}\.json$#', $rel)) {
        return true;
    }
    if (str_starts_with($rel, 'assets/uploads/') && $rel !== 'assets/uploads/.htaccess') {
        return true;
    }
    return false;
}

function kip_fetch_url(string $url): ?string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Kip-Deployer/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Cache-Control: no-cache',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        return null;
    }
    return (string) $body;
}

/**
 * Syncs a public GitHub repository tree directly to a local directory over HTTPS using PHP cURL.
 */
function kip_sync_github_repo(
    string $repo,
    string $branch,
    string $subPathPrefix,
    string $targetBaseDir,
    array &$copied,
    array &$errors
): void {
    $treeUrl = "https://api.github.com/repos/{$repo}/git/trees/{$branch}?recursive=1";
    $treeJson = kip_fetch_url($treeUrl);
    if ($treeJson === null) {
        $errors[] = "Could not fetch tree from {$treeUrl}";
        return;
    }
    $decoded = json_decode($treeJson, true);
    if (!is_array($decoded) || !isset($decoded['tree']) || !is_array($decoded['tree'])) {
        $errors[] = "Invalid tree JSON from {$repo}";
        return;
    }

    foreach ($decoded['tree'] as $node) {
        if (($node['type'] ?? '') !== 'blob') {
            continue;
        }
        $path = (string) ($node['path'] ?? '');
        if ($path === '' || $path === '.gitattributes' || $path === '.gitignore' || $path === '.cpanel.yml') {
            continue;
        }
        if ($subPathPrefix !== '') {
            if (!str_starts_with($path, $subPathPrefix)) {
                continue;
            }
            $rel = substr($path, strlen($subPathPrefix));
        } else {
            $rel = $path;
        }
        if ($rel === '' || kip_is_protected_rel($rel)) {
            continue;
        }

        $rawUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}/{$path}";
        $contents = kip_fetch_url($rawUrl);
        if ($contents === null) {
            $errors[] = "Failed to download {$rawUrl}";
            continue;
        }

        $destPath = $targetBaseDir . '/' . $rel;
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        if (@file_put_contents($destPath, $contents) !== false) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($destPath, true);
            }
            $copied[] = $destPath;
        } else {
            $errors[] = "Failed to write {$destPath}";
        }
    }
}

$target = (string) ($_GET['target'] ?? 'all');

if ($target === 'all' || $target === 'mkipw') {
    kip_sync_github_repo('zvio999/mkipw', 'main', '', $mkipwDir, $copied, $errors);
}

if ($target === 'all' || $target === 'site') {
    kip_sync_github_repo('zvio999/kip.money', 'main', 'website/', $docRoot, $copied, $errors);
}

// Remove temporary clean.php if still present
if (is_file($docRoot . '/clean.php') && @unlink($docRoot . '/clean.php')) {
    $copied[] = 'Deleted ' . $docRoot . '/clean.php';
}

$cleanReport = [];
if (is_file($mkipwDir . '/lib.php')) {
    require_once $mkipwDir . '/lib.php';
    if (function_exists('kip_scan_and_clean_hack_files')) {
        $cleanReport = kip_scan_and_clean_hack_files();
    }
}

echo json_encode([
    'ok' => empty($errors),
    'target' => $target,
    'updatedCount' => count($copied),
    'updatedFiles' => $copied,
    'errors' => $errors,
    'cleanReport' => $cleanReport,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
