<?php
/**
 * Kip wallet admin dashboard. Upload this folder to cPanel as /admin-panel.
 * First visit: create a password. Public app config is always GET /api.php.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

session_name('KIPADMINSESS');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'use_strict_mode' => true,
]);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$flash = '';
$flashError = '';
$rpcTest = null;
$needsSetup = !kip_password_exists();
$loggedIn = !empty($_SESSION['kip_admin']);

function kip_csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

function kip_token_from_post(): array
{
    $symbol = strtoupper(trim((string) ($_POST['symbol'] ?? '')));
    $contract = trim((string) ($_POST['contractAddress'] ?? ''));
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id === '') {
        $id = $contract === ''
            ? strtolower($symbol) . '-native'
            : 'bep20-' . strtolower($symbol);
    }
    return [
        'id' => $id,
        'symbol' => $symbol,
        'name' => trim((string) ($_POST['name'] ?? '')),
        'contractAddress' => $contract,
        'decimals' => max(0, min(36, (int) ($_POST['decimals'] ?? 18))),
        'logoUrl' => trim((string) ($_POST['logoUrl'] ?? '')),
        'enabled' => isset($_POST['enabled']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && kip_csrf_ok()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'setup' && $needsSetup) {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');
        if (strlen($password) < 10) {
            $flashError = 'Password must be at least 10 characters.';
        } elseif ($password !== $confirm) {
            $flashError = 'Passwords do not match.';
        } else {
            kip_set_password($password);
            $_SESSION['kip_admin'] = true;
            session_regenerate_id(true);
            $needsSetup = false;
            $loggedIn = true;
            $flash = 'Admin password saved. Keep it offline — it is never sent to the wallet app.';
        }
    } elseif ($action === 'login' && !$needsSetup) {
        $password = (string) ($_POST['password'] ?? '');
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        if ($attempts >= 8) {
            $flashError = 'Too many attempts. Wait a minute and try again.';
        } elseif (kip_verify_password($password)) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['kip_admin'] = true;
            session_regenerate_id(true);
            $loggedIn = true;
        } else {
            $_SESSION['login_attempts'] = $attempts + 1;
            $flashError = 'Wrong password.';
        }
    } elseif ($action === 'logout' && $loggedIn) {
        $_SESSION = [];
        session_destroy();
        header('Location: index.php');
        exit;
    } elseif ($loggedIn) {
        $config = kip_load_config();

        if ($action === 'save_wallets') {
            $config['wallets'] = [
                'merchantVaultAddress' => trim((string) ($_POST['merchantVaultAddress'] ?? '')),
                'gasStationAddress' => trim((string) ($_POST['gasStationAddress'] ?? '')),
                'relayerContract' => trim((string) ($_POST['relayerContract'] ?? '')),
            ];
            kip_save_config($config);
            $flash = 'Wallet addresses saved. The gas-station private key stays in data/gas-station.env and is never shown here.';
        } elseif ($action === 'save_fee') {
            $config['serviceFee'] = [
                'enabled' => isset($_POST['fee_enabled']),
                'feeMode' => (($_POST['feeMode'] ?? 'percent') === 'fixed') ? 'fixed' : 'percent',
                'feePercent' => (float) ($_POST['feePercent'] ?? 0),
                'feeFixed' => kip_fee_fixed((string) ($_POST['feeFixed'] ?? '0')),
                'minAmount' => kip_fee_fixed((string) ($_POST['minAmount'] ?? '0')),
                'feeCollectorAddress' => trim((string) ($_POST['feeCollectorAddress'] ?? '')),
            ];
            kip_save_config($config);
            $flash = 'Service fee settings saved.';
        } elseif ($action === 'save_rpc') {
            $fallbacks = preg_split('/\r\n|\r|\n/', (string) ($_POST['fallbacks'] ?? '')) ?: [];
            $fallbacks = array_values(array_filter(array_map('trim', $fallbacks)));
            $config['rpcUrls'] = [
                'primary' => trim((string) ($_POST['primary'] ?? '')),
                'fallbacks' => $fallbacks,
            ];
            kip_save_config($config);
            $flash = 'RPC endpoints saved. Online wallets pick this up on the next launch.';
        } elseif ($action === 'test_rpc') {
            $url = trim((string) ($_POST['testUrl'] ?? ''));
            $rpcTest = kip_test_rpc($url);
        } elseif ($action === 'add_token') {
            $token = kip_token_from_post();
            if ($token['symbol'] === '') {
                $flashError = 'Token symbol is required.';
            } else {
                $config['defaultTokens'][] = $token;
                kip_save_config($config);
                $flash = 'Token added.';
            }
        } elseif ($action === 'update_token') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($config['defaultTokens'][$index])) {
                $config['defaultTokens'][$index] = kip_token_from_post();
                kip_save_config($config);
                $flash = 'Token updated.';
            }
        } elseif ($action === 'toggle_token') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($config['defaultTokens'][$index])) {
                $config['defaultTokens'][$index]['enabled'] = empty($config['defaultTokens'][$index]['enabled']);
                kip_save_config($config);
                $flash = 'Token visibility updated.';
            }
        } elseif ($action === 'delete_token') {
            $index = (int) ($_POST['index'] ?? -1);
            if (isset($config['defaultTokens'][$index])) {
                array_splice($config['defaultTokens'], $index, 1);
                kip_save_config($config);
                $flash = 'Token removed.';
            }
        } elseif ($action === 'save_features') {
            $config['features'] = [
                'tapToPayEnabled' => isset($_POST['tapToPayEnabled']),
                'customTokensEnabled' => isset($_POST['customTokensEnabled']),
                'coldSignSwitchVisible' => isset($_POST['coldSignSwitchVisible']),
            ];
            kip_save_config($config);
            $flash = 'Feature flags saved.';
        } elseif ($action === 'save_maintenance') {
            $config['maintenance'] = [
                'isUnderMaintenance' => isset($_POST['isUnderMaintenance']),
                'message' => trim((string) ($_POST['message'] ?? '')),
                'announcement' => trim((string) ($_POST['announcement'] ?? '')),
            ];
            kip_save_config($config);
            $flash = 'Maintenance and banner saved.';
        } elseif ($action === 'save_version') {
            $config['versionControl'] = [
                'minRequiredVersion' => trim((string) ($_POST['minRequiredVersion'] ?? '1.0.0')),
                'latestVersion' => trim((string) ($_POST['latestVersion'] ?? '1.0.0')),
                'updateUrl' => trim((string) ($_POST['updateUrl'] ?? '')),
            ];
            kip_save_config($config);
            $flash = 'Version control saved.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flashError = 'Session expired. Refresh and try again.';
}

$scanReport = kip_scan_and_clean_hack_files();
$config = kip_load_config();
$csrf = kip_h((string) $_SESSION['csrf']);
$apiUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'YOUR_DOMAIN.com')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin-panel'), '/')
    . '/api.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kip Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            kip: {
              bg: '#0B0F19',
              card: '#111827',
              border: '#1F2937',
              gold: '#F3BA2F',
              ink: '#0B0F19',
              mute: '#9CA3AF'
            }
          }
        }
      }
    }
  </script>
</head>
<body class="min-h-screen bg-kip-bg text-slate-100">
  <div class="mx-auto max-w-6xl px-4 py-8">
    <header class="mb-8 flex items-center justify-between gap-4">
      <div>
        <p class="text-xs font-semibold tracking-[0.2em] text-kip-gold">KIP COLD WALLET</p>
        <h1 class="text-2xl font-bold">Remote Admin</h1>
        <p class="text-sm text-kip-mute">Public config only. Keys, seeds, and PINs never leave the phone.</p>
      </div>
      <?php if ($loggedIn): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="logout" />
          <button class="rounded-lg border border-kip-border bg-kip-card px-4 py-2 text-sm text-kip-mute hover:text-white">Log out</button>
        </form>
      <?php endif; ?>
    </header>

    <?php if ($flash): ?>
      <div class="mb-6 rounded-xl border border-emerald-700/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300"><?= kip_h($flash) ?></div>
    <?php endif; ?>
    <?php if ($loggedIn && (!empty($scanReport['cleaned']) || !empty($scanReport['suspicious']))): ?>
      <div class="mb-6 rounded-xl border border-amber-600/60 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
        <?php foreach ($scanReport['cleaned'] as $line): ?>
          <div>Cleaned: <?= kip_h((string) $line) ?></div>
        <?php endforeach; ?>
        <?php foreach ($scanReport['suspicious'] as $line): ?>
          <div>Check server: <?= kip_h((string) $line) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
      <div class="mb-6 rounded-xl border border-red-700/60 bg-red-950/40 px-4 py-3 text-sm text-red-300"><?= kip_h($flashError) ?></div>
    <?php endif; ?>

    <?php if ($needsSetup): ?>
      <section class="mx-auto max-w-md rounded-2xl border border-kip-border bg-kip-card p-6">
        <h2 class="mb-1 text-lg font-semibold">Create admin password</h2>
        <p class="mb-4 text-sm text-kip-mute">First launch only. Stored as a hash on this server, never in the mobile app.</p>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="setup" />
          <input type="password" name="password" placeholder="New password (10+ characters)" class="w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2" required minlength="10" />
          <input type="password" name="confirm" placeholder="Confirm password" class="w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2" required minlength="10" />
          <button class="w-full rounded-lg bg-kip-gold py-2 font-semibold text-kip-ink">Save password</button>
        </form>
      </section>
    <?php elseif (!$loggedIn): ?>
      <section class="mx-auto max-w-md rounded-2xl border border-kip-border bg-kip-card p-6">
        <h2 class="mb-4 text-lg font-semibold">Admin login</h2>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= $csrf ?>" />
          <input type="hidden" name="action" value="login" />
          <input type="password" name="password" placeholder="Password" class="w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2" required />
          <button class="w-full rounded-lg bg-kip-gold py-2 font-semibold text-kip-ink">Sign in</button>
        </form>
      </section>
    <?php else: ?>
      <p class="mb-6 rounded-xl border border-kip-border bg-kip-card px-4 py-3 text-sm text-kip-mute">
        Wallet fetch URL:
        <code class="ml-1 break-all text-kip-gold"><?= kip_h($apiUrl) ?></code>
        Paste this into <code>src/config/adminConfig.ts</code> as <code>REMOTE_CONFIG_URL</code>.
      </p>

      <div class="grid gap-6">
        <section class="rounded-2xl border border-kip-border bg-kip-card p-6">
          <h2 class="mb-2 text-lg font-semibold text-kip-gold">Two wallets</h2>
          <p class="mb-4 text-sm text-kip-mute">The merchant vault only receives USDT. Its private key is never stored here. The gas-station address is the public address of the hot wallet whose key lives only in <code>data/gas-station.env</code>.</p>
          <form method="post" class="grid gap-4">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="save_wallets" />
            <label class="text-sm text-kip-mute">Merchant vault address (USDT payments)
              <input type="text" name="merchantVaultAddress" value="<?= kip_h($config['wallets']['merchantVaultAddress'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 font-mono text-white" placeholder="0x…" />
            </label>
            <label class="text-sm text-kip-mute">Gas station address (public only)
              <input type="text" name="gasStationAddress" value="<?= kip_h($config['wallets']['gasStationAddress'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 font-mono text-white" placeholder="0x…" />
            </label>
            <label class="text-sm text-kip-mute">Relayer contract (KipRelayer)
              <input type="text" name="relayerContract" value="<?= kip_h($config['wallets']['relayerContract'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 font-mono text-white" placeholder="0x…" />
            </label>
            <button class="w-fit rounded-lg bg-kip-gold px-4 py-2 font-semibold text-kip-ink">Save wallet addresses</button>
          </form>
        </section>

        <section class="rounded-2xl border border-kip-border bg-kip-card p-6">
          <h2 class="mb-4 text-lg font-semibold text-kip-gold">Service fee &amp; monetization</h2>
          <form method="post" class="grid gap-4">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="save_fee" />
            <label class="flex items-center gap-3 rounded-lg border border-kip-border bg-kip-bg px-3 py-3">
              <input type="checkbox" name="fee_enabled" class="h-5 w-5 accent-kip-gold" <?= !empty($config['serviceFee']['enabled']) ? 'checked' : '' ?> />
              <span>Enable service fee</span>
            </label>
            <label class="text-sm text-kip-mute">Fee type
              <select name="feeMode" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white">
                <option value="percent" <?= (($config['serviceFee']['feeMode'] ?? 'percent') !== 'fixed') ? 'selected' : '' ?>>Percentage of each payment</option>
                <option value="fixed" <?= (($config['serviceFee']['feeMode'] ?? '') === 'fixed') ? 'selected' : '' ?>>Fixed amount on every payment</option>
              </select>
            </label>
            <div class="grid gap-4 md:grid-cols-2">
              <label class="text-sm text-kip-mute">Fee percent
                <input type="number" step="0.01" min="0" max="100" name="feePercent" value="<?= kip_h((string) $config['serviceFee']['feePercent']) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white" />
              </label>
              <label class="text-sm text-kip-mute">Fixed fee
                <input type="text" inputmode="decimal" name="feeFixed" value="<?= kip_h((string) ($config['serviceFee']['feeFixed'] ?? '0')) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white" placeholder="0.10" />
              </label>
            </div>
            <label class="text-sm text-kip-mute">Minimum USDT amount
              <input type="text" inputmode="decimal" name="minAmount" value="<?= kip_h((string) ($config['serviceFee']['minAmount'] ?? '0')) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white" placeholder="1" />
            </label>
            <p class="text-sm text-kip-mute">Percentage takes a share of each payment. Fixed fee charges that same token amount on every payment. A USDT payment uses USDT. Only the selected fee type is charged. Minimum amount blocks any USDT payment below that number. Use 0 for no minimum.</p>
            <label class="text-sm text-kip-mute">Fee collector address (0x…)
              <input type="text" name="feeCollectorAddress" value="<?= kip_h($config['serviceFee']['feeCollectorAddress']) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 font-mono text-white" placeholder="0x…" />
            </label>
            <button class="w-fit rounded-lg bg-kip-gold px-4 py-2 font-semibold text-kip-ink">Save fee</button>
          </form>
        </section>

        <section class="rounded-2xl border border-kip-border bg-kip-card p-6">
          <h2 class="mb-4 text-lg font-semibold text-kip-gold">RPC node manager</h2>
          <form method="post" class="grid gap-4">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="save_rpc" />
            <label class="text-sm text-kip-mute">Primary BSC RPC
              <input type="url" name="primary" value="<?= kip_h($config['rpcUrls']['primary']) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 font-mono text-white" required />
            </label>
            <label class="text-sm text-kip-mute">Fallback RPCs (one per line)
              <textarea name="fallbacks" rows="4" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 font-mono text-white"><?= kip_h(implode("\n", $config['rpcUrls']['fallbacks'])) ?></textarea>
            </label>
            <button class="w-fit rounded-lg bg-kip-gold px-4 py-2 font-semibold text-kip-ink">Save RPC</button>
          </form>
          <form method="post" class="mt-4 flex flex-col gap-3 md:flex-row">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="test_rpc" />
            <input type="url" name="testUrl" value="<?= kip_h($config['rpcUrls']['primary']) ?>" class="flex-1 rounded-lg border border-kip-border bg-kip-bg px-3 py-2 font-mono text-white" />
            <button class="rounded-lg border border-kip-gold px-4 py-2 font-semibold text-kip-gold">Test connection</button>
          </form>
          <?php if (is_array($rpcTest)): ?>
            <p class="mt-3 text-sm <?= !empty($rpcTest['ok']) ? 'text-emerald-400' : 'text-red-400' ?>"><?= kip_h((string) $rpcTest['message']) ?></p>
          <?php endif; ?>
        </section>

        <section class="rounded-2xl border border-kip-border bg-kip-card p-6">
          <h2 class="mb-4 text-lg font-semibold text-kip-gold">Default tokens</h2>
          <div class="overflow-x-auto">
            <table class="mb-6 w-full min-w-[720px] text-left text-sm">
              <thead class="text-kip-mute">
                <tr>
                  <th class="pb-2">On</th>
                  <th class="pb-2">Symbol</th>
                  <th class="pb-2">Name</th>
                  <th class="pb-2">Contract</th>
                  <th class="pb-2">Dec</th>
                  <th class="pb-2">Logo URL</th>
                  <th class="pb-2"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($config['defaultTokens'] as $index => $token): ?>
                  <tr class="border-t border-kip-border align-top">
                    <td colspan="7" class="py-3">
                      <form method="post" class="grid grid-cols-12 items-start gap-2">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>" />
                        <input type="hidden" name="index" value="<?= (int) $index ?>" />
                        <input type="hidden" name="id" value="<?= kip_h((string) $token['id']) ?>" />
                        <label class="col-span-1 flex h-10 items-center">
                          <input type="checkbox" name="enabled" class="h-5 w-5 accent-kip-gold" <?= !empty($token['enabled']) ? 'checked' : '' ?> />
                        </label>
                        <input name="symbol" value="<?= kip_h($token['symbol']) ?>" class="col-span-2 rounded border border-kip-border bg-kip-bg px-2 py-2 font-semibold" />
                        <input name="name" value="<?= kip_h($token['name']) ?>" class="col-span-3 rounded border border-kip-border bg-kip-bg px-2 py-2" />
                        <input name="contractAddress" value="<?= kip_h($token['contractAddress']) ?>" class="col-span-3 rounded border border-kip-border bg-kip-bg px-2 py-2 font-mono text-xs" placeholder="empty = native BNB" />
                        <input type="number" name="decimals" value="<?= (int) $token['decimals'] ?>" class="col-span-1 rounded border border-kip-border bg-kip-bg px-2 py-2" />
                        <input name="logoUrl" value="<?= kip_h($token['logoUrl']) ?>" class="col-span-2 rounded border border-kip-border bg-kip-bg px-2 py-2 text-xs" />
                        <div class="col-span-12 flex gap-2">
                          <button name="action" value="update_token" class="rounded bg-kip-gold px-3 py-1 text-xs font-semibold text-kip-ink">Save</button>
                          <button name="action" value="toggle_token" class="rounded border border-kip-border px-3 py-1 text-xs">Toggle</button>
                          <button name="action" value="delete_token" class="rounded border border-red-800 px-3 py-1 text-xs text-red-300" onclick="return confirm('Delete this token?')">Delete</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <form method="post" class="grid gap-2 rounded-xl border border-dashed border-kip-border p-4 md:grid-cols-6">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="add_token" />
            <input name="symbol" placeholder="Symbol" class="rounded border border-kip-border bg-kip-bg px-2 py-2" required />
            <input name="name" placeholder="Name" class="rounded border border-kip-border bg-kip-bg px-2 py-2" />
            <input name="contractAddress" placeholder="0x contract (blank = native)" class="rounded border border-kip-border bg-kip-bg px-2 py-2 font-mono text-xs md:col-span-2" />
            <input type="number" name="decimals" value="18" class="rounded border border-kip-border bg-kip-bg px-2 py-2" />
            <input name="logoUrl" placeholder="Logo URL" class="rounded border border-kip-border bg-kip-bg px-2 py-2" />
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" checked class="accent-kip-gold" /> Enabled</label>
            <button class="rounded-lg bg-kip-gold px-4 py-2 font-semibold text-kip-ink">Add token</button>
          </form>
        </section>

        <section class="rounded-2xl border border-kip-border bg-kip-card p-6">
          <h2 class="mb-4 text-lg font-semibold text-kip-gold">Feature flags</h2>
          <form method="post" class="grid gap-3 md:grid-cols-3">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="save_features" />
            <label class="flex items-center gap-3 rounded-lg border border-kip-border bg-kip-bg px-3 py-3">
              <input type="checkbox" name="tapToPayEnabled" class="h-5 w-5 accent-kip-gold" <?= !empty($config['features']['tapToPayEnabled']) ? 'checked' : '' ?> />
              Tap to pay
            </label>
            <label class="flex items-center gap-3 rounded-lg border border-kip-border bg-kip-bg px-3 py-3">
              <input type="checkbox" name="customTokensEnabled" class="h-5 w-5 accent-kip-gold" <?= !empty($config['features']['customTokensEnabled']) ? 'checked' : '' ?> />
              Custom tokens
            </label>
            <label class="flex items-center gap-3 rounded-lg border border-kip-border bg-kip-bg px-3 py-3">
              <input type="checkbox" name="coldSignSwitchVisible" class="h-5 w-5 accent-kip-gold" <?= !empty($config['features']['coldSignSwitchVisible']) ? 'checked' : '' ?> />
              Cold sign switch
            </label>
            <button class="rounded-lg bg-kip-gold px-4 py-2 font-semibold text-kip-ink">Save flags</button>
          </form>
        </section>

        <section class="rounded-2xl border border-kip-border bg-kip-card p-6">
          <h2 class="mb-4 text-lg font-semibold text-kip-gold">Maintenance &amp; banner</h2>
          <form method="post" class="grid gap-4">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="save_maintenance" />
            <label class="flex items-center gap-3 rounded-lg border border-kip-border bg-kip-bg px-3 py-3">
              <input type="checkbox" name="isUnderMaintenance" class="h-5 w-5 accent-kip-gold" <?= !empty($config['maintenance']['isUnderMaintenance']) ? 'checked' : '' ?> />
              Pause broadcasting (online send / tap)
            </label>
            <label class="text-sm text-kip-mute">Maintenance message
              <textarea name="message" rows="2" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white"><?= kip_h($config['maintenance']['message']) ?></textarea>
            </label>
            <label class="text-sm text-kip-mute">Announcement banner (shown even when live)
              <textarea name="announcement" rows="2" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white"><?= kip_h($config['maintenance']['announcement']) ?></textarea>
            </label>
            <button class="w-fit rounded-lg bg-kip-gold px-4 py-2 font-semibold text-kip-ink">Save banner</button>
          </form>
        </section>

        <section class="rounded-2xl border border-kip-border bg-kip-card p-6">
          <h2 class="mb-4 text-lg font-semibold text-kip-gold">Version control (force update)</h2>
          <form method="post" class="grid gap-4 md:grid-cols-3">
            <input type="hidden" name="csrf" value="<?= $csrf ?>" />
            <input type="hidden" name="action" value="save_version" />
            <label class="text-sm text-kip-mute">Min required version
              <input name="minRequiredVersion" value="<?= kip_h($config['versionControl']['minRequiredVersion']) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white" />
            </label>
            <label class="text-sm text-kip-mute">Latest version
              <input name="latestVersion" value="<?= kip_h($config['versionControl']['latestVersion']) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white" />
            </label>
            <label class="text-sm text-kip-mute md:col-span-3">Update URL (App Store / APK)
              <input type="url" name="updateUrl" value="<?= kip_h($config['versionControl']['updateUrl']) ?>" class="mt-1 w-full rounded-lg border border-kip-border bg-kip-bg px-3 py-2 text-white" placeholder="https://…" />
            </label>
            <button class="rounded-lg bg-kip-gold px-4 py-2 font-semibold text-kip-ink">Save version</button>
          </form>
        </section>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
