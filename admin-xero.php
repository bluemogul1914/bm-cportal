<?php
/**
 * admin-xero.php — Xero Accounting (ITFlow module_financial parity, ledger side).
 *
 * This Xero app is a CUSTOM CONNECTION: client-credentials grant, NO browser
 * consent, NO redirect URI, and a mandatory `Xero-tenant-id` header on every
 * API call. GET /connections returns an empty array for it, so the tenant id
 * cannot be discovered over the API — it is read from the Xero developer portal
 * and stored here.
 *
 * Config lives in provider_settings, which is a KEY/VALUE table
 * (provider, key_name, key_value). The previous version of this page read a
 * `provider_name` / `settings` JSONB shape that does not exist, so it always
 * rendered "Not Connected" and could never have stored a token.
 *
 * Data is pulled by server/xero-api.ts into the xero_* mirror tables; the
 * Reports tab streams live from Xero (nothing to mirror).
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$pdo = getDB();
$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');
$success_message = '';
$error_message = '';
$notice = $_GET['notice'] ?? '';
if ($error_message === '' && isset($_GET['error'])) { $error_message = (string)$_GET['error']; }

/** Call a loopback Xero endpoint with this admin session. */
function xero_api(string $origin, string $path, ?array $body = null): array {
    $ch = curl_init($origin . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'http' => 0, 'error' => $err];
    $data = json_decode((string)$resp, true);
    if ($http >= 400) {
        return ['ok' => false, 'http' => $http, 'error' => $data['error'] ?? substr((string)$resp, 0, 220)];
    }
    return ['ok' => true, 'http' => $http, 'data' => is_array($data) ? $data : []];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $patch = [];
        foreach (['client_id', 'client_secret', 'tenant_id'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '') $patch[$k] = $v;
        }
        if (!$patch) {
            $error_message = 'Nothing to save — enter at least one value.';
        } else {
            $r = xero_api($internalOrigin, '/portal/api/xero/settings', $patch);
            if ($r['ok']) {
                $success_message = 'Saved: ' . implode(', ', $r['data']['saved'] ?? array_keys($patch)) . '.';
            } else {
                $error_message = 'Could not save: ' . htmlspecialchars((string)$r['error']);
            }
        }
    } elseif ($action === 'test') {
        $r = xero_api($internalOrigin, '/portal/api/xero/test', []);
        if ($r['ok']) {
            $d = $r['data'];
            $success_message = sprintf(
                'Xero credentials valid — grant %s, %d scopes, token valid for %s seconds. %s',
                (string)($d['grant'] ?? 'client_credentials'),
                (int)($d['scopes'] ?? 0),
                (string)($d['expires_in'] ?? '—'),
                (string)($d['note'] ?? '')
            );
        } else {
            $error_message = 'Xero test failed: ' . htmlspecialchars((string)$r['error']);
        }
    } elseif ($action === 'sync') {
        $r = xero_api($internalOrigin, '/portal/api/xero/sync', []);
        if ($r['ok']) {
            $d = $r['data'];
            $success_message = sprintf(
                'Xero sync complete — %s: %d invoice(s), %d payment(s), %d contact(s), %d account(s).',
                (string)($d['organisation'] ?? 'organisation'),
                (int)($d['invoices'] ?? 0), (int)($d['payments'] ?? 0),
                (int)($d['contacts'] ?? 0), (int)($d['accounts'] ?? 0)
            );
            if (!empty($d['errors'])) {
                $success_message .= ' Warnings: ' . implode(' | ', array_map('strval', $d['errors']));
            }
        } else {
            $error_message = 'Xero sync failed: ' . htmlspecialchars((string)$r['error']);
        }
    } elseif ($action === 'save_oauth') {
        $patch = [];
        foreach (['client_id' => 'oauth_client_id', 'client_secret' => 'oauth_client_secret', 'redirect_uri' => 'oauth_redirect_uri'] as $k => $field) {
            $v = trim((string)($_POST[$field] ?? ''));
            if ($v !== '') $patch[$k] = $v;
        }
        if (!$patch) {
            $error_message = 'Nothing to save — paste the Web app client_id (and secret).';
        } else {
            $r = xero_api($internalOrigin, '/portal/api/xero/oauth/settings', $patch);
            if ($r['ok']) {
                $success_message = 'OAuth 2.0 settings saved: ' . implode(', ', $r['data']['saved'] ?? array_keys($patch)) . '. Now press “Connect with Xero”.';
            } else {
                $error_message = 'Could not save OAuth settings: ' . htmlspecialchars((string)$r['error']);
            }
        }
    } elseif ($action === 'oauth_disconnect') {
        $r = xero_api($internalOrigin, '/portal/api/xero/oauth/disconnect', []);
        $success_message = $r['ok'] ? 'OAuth 2.0 tokens and settings cleared.' : ('Could not clear OAuth config: ' . htmlspecialchars((string)$r['error']));
    } elseif ($action === 'disconnect') {
        $r = xero_api($internalOrigin, '/portal/api/xero/disconnect', []);
        $success_message = $r['ok'] ? 'Xero configuration cleared.' : ('Could not clear: ' . htmlspecialchars((string)$r['error']));
    }
}

/* ── Current config (key/value rows) ───────────────────────────────────── */
$kv = [];
try {
    $rows = $pdo->query("SELECT key_name, key_value FROM provider_settings WHERE provider = 'xero'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $kv[$r['key_name']] = $r['key_value']; }
} catch (Throwable $e) { $kv = []; }

$client_id_set     = !empty($kv['client_id']);
$client_secret_set = !empty($kv['client_secret']);
$tenant_id         = (string)($kv['tenant_id'] ?? '');
$token_cached      = !empty($kv['access_token']) && (int)($kv['expires_at'] ?? 0) > (time() * 1000);
$ready             = $client_id_set && $client_secret_set && $tenant_id !== '';

/* ── OAuth 2.0 Web app config (provider = 'xero_oauth') ─────────────────── */
$okv = [];
try {
    $rows = $pdo->query("SELECT key_name, key_value FROM provider_settings WHERE provider = 'xero_oauth'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $okv[$r['key_name']] = $r['key_value']; }
} catch (Throwable $e) { $okv = []; }

$oauth_client_id_set = !empty($okv['client_id']);
$oauth_secret_set    = !empty($okv['client_secret']);
$oauth_authorised    = !empty($okv['refresh_token']);
$oauth_tenant_id     = (string)($okv['tenant_id'] ?? '');
$oauth_tenant_name   = (string)($okv['tenant_name'] ?? '');
$oauth_redirect      = (string)($okv['redirect_uri'] ?? 'https://portal.bluemogul.us/portal/api/xero/callback');

$last_run = null; $org = null;
$counts = ['invoices' => 0, 'payments' => 0, 'contacts' => 0, 'accounts' => 0];
$totals = ['invoiced' => 0, 'collected' => 0, 'outstanding' => 0];
$recent_invoices = []; $recent_payments = []; $accounts = [];
try {
    $last_run = $pdo->query("SELECT * FROM xero_sync_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    $org = $pdo->query("SELECT * FROM xero_organisation LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    $c = $pdo->query("SELECT (SELECT COUNT(*) FROM xero_invoices) AS invoices,
                             (SELECT COUNT(*) FROM xero_payments) AS payments,
                             (SELECT COUNT(*) FROM xero_contacts) AS contacts,
                             (SELECT COUNT(*) FROM xero_accounts) AS accounts")->fetch(PDO::FETCH_ASSOC);
    if ($c) $counts = $c;
    $t = $pdo->query("SELECT COALESCE(SUM(total),0) AS invoiced, COALESCE(SUM(amount_paid),0) AS collected,
                             COALESCE(SUM(amount_due),0) AS outstanding
                      FROM xero_invoices WHERE type = 'ACCREC'")->fetch(PDO::FETCH_ASSOC);
    if ($t) $totals = $t;
    $recent_invoices = $pdo->query("SELECT i.*, c.name AS portal_client_name FROM xero_invoices i
                                    LEFT JOIN clients c ON c.id = i.client_id
                                    ORDER BY i.invoice_date DESC NULLS LAST, i.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $recent_payments = $pdo->query("SELECT p.*, c.name AS portal_client_name FROM xero_payments p
                                    LEFT JOIN clients c ON c.id = p.client_id
                                    ORDER BY p.payment_date DESC NULLS LAST, p.id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $accounts = $pdo->query("SELECT * FROM xero_accounts ORDER BY type, code LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* mirror tables not present yet */ }

function xero_status_pill($status) {
    switch (strtoupper((string)$status)) {
        case 'PAID':       return 'bg-green-100 text-green-700';
        case 'AUTHORISED': return 'bg-blue-100 text-blue-700';
        case 'SUBMITTED':  return 'bg-amber-100 text-amber-700';
        case 'DRAFT':      return 'bg-gray-100 text-gray-600';
        case 'VOIDED':
        case 'DELETED':    return 'bg-red-100 text-red-700';
        default:           return 'bg-gray-100 text-gray-600';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xero Accounting — Blue Mogul</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/tailwind-shared.js"></script>
    <script>tailwind.config = window.bmTailwindConfig;</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-book text-sky-500 mr-2"></i>Xero Accounting</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Custom-connection API — invoices, payments, contacts &amp; chart of accounts</p>
                </div>
                <div class="flex items-center gap-2">
                    <form method="POST"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="test">
                        <button class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50" data-testid="button-xero-test">
                            <i class="fas fa-plug mr-2"></i>Test connection
                        </button>
                    </form>
                    <form method="POST"><?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="sync">
                        <button class="bg-sky-600 hover:bg-sky-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition" data-testid="button-xero-sync">
                            <i class="fas fa-sync mr-2"></i>Sync from Xero
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_message !== ''): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-xero-success"><?php echo $success_message; ?></div><?php endif; ?>
            <?php if ($error_message !== ''): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-xero-error"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="mb-4 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Connection</h2>
                        <?php if ($ready): ?>
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-medium rounded-full" data-testid="status-xero-ready">Ready</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-medium rounded-full" data-testid="status-xero-incomplete">Incomplete</span>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm mb-4">
                        <span class="text-gray-500">Client ID:</span>
                        <span class="font-medium text-gray-900"><?php echo $client_id_set ? htmlspecialchars(substr((string)$kv['client_id'], 0, 12) . '…') : '— not saved —'; ?></span>
                        <span class="text-gray-500">Secret:</span>
                        <span class="font-medium text-gray-900"><?php echo $client_secret_set ? 'saved' : '— not saved —'; ?></span>
                        <span class="text-gray-500">Tenant:</span>
                        <span class="font-medium text-gray-900"><?php echo $tenant_id !== '' ? htmlspecialchars(substr($tenant_id, 0, 8) . '…') : '— not saved —'; ?></span>
                        <span class="text-gray-500">Token:</span>
                        <span class="font-medium text-gray-900"><?php echo $token_cached ? 'cached' : 'not cached'; ?></span>
                    </div>

                    <?php if ($tenant_id === ''): ?>
                    <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg text-sm mb-4">
                        <p class="font-medium mb-1"><i class="fas fa-circle-info mr-2"></i>One value left: the Tenant ID</p>
                        <p class="mb-1">This app is a <strong>custom connection</strong>, so there is no consent screen and the Redirect URI is unused.
                           Xero also cannot list it: <code class="bg-amber-100 px-1 rounded">GET /connections</code> returns <code class="bg-amber-100 px-1 rounded">[]</code> for custom connections
                           (it needs the tenant header it is supposed to return).</p>
                        <p>Get it from <a class="underline font-medium" href="https://developer.xero.com/app/manage" target="_blank">developer.xero.com → your app → Custom connection</a>
                           (the connected organisation is shown with its Tenant ID), paste it below, then press <em>Sync from Xero</em>.</p>
                    </div>
                    <?php endif; ?>

                    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_settings">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Client ID</label>
                            <input name="client_id" value="<?php echo $client_id_set ? htmlspecialchars((string)$kv['client_id']) : ''; ?>" placeholder="5C6A107ED6AB4F22AD07AABA5461D773" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Client secret <span class="text-gray-400">(blank = keep)</span></label>
                            <input name="client_secret" type="password" placeholder="<?php echo $client_secret_set ? '•••••••• (saved)' : 'paste secret'; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Tenant ID</label>
                            <input name="tenant_id" value="<?php echo htmlspecialchars($tenant_id); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" data-testid="input-xero-tenant">
                        </div>
                        <div class="md:col-span-2 flex justify-between items-center">
                            <button class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-md text-sm" data-testid="button-xero-save">Save settings</button>
                            <span class="text-xs text-gray-500">Stored in <code>provider_settings</code> — no env vars or redeploy needed.</span>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-3">Organisation</h2>
                    <?php if ($org): ?>
                        <p class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars((string)$org['name']); ?></p>
                        <?php if (!empty($org['legal_name'])): ?><p class="text-sm text-gray-500"><?php echo htmlspecialchars((string)$org['legal_name']); ?></p><?php endif; ?>
                        <p class="text-xs text-gray-500 mt-2">Base currency: <?php echo htmlspecialchars((string)($org['base_currency'] ?? '—')); ?></p>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">Not synced yet.</p>
                    <?php endif; ?>
                    <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-500">
                        Last sync:
                        <?php if ($last_run): ?>
                            <?php echo htmlspecialchars((string)($last_run['finished_at'] ?? $last_run['started_at'])); ?>
                            <span class="ml-1 px-2 py-0.5 rounded-full <?php echo $last_run['status'] === 'ok' ? 'bg-green-100 text-green-700' : ($last_run['status'] === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'); ?>"><?php echo htmlspecialchars((string)$last_run['status']); ?></span>
                            <?php if (!empty($last_run['error'])): ?><p class="mt-1 text-red-600"><?php echo htmlspecialchars((string)$last_run['error']); ?></p><?php endif; ?>
                        <?php else: ?>never<?php endif; ?>
                    </div>
                    <div class="mt-3">
                        <form method="POST" onsubmit="return confirm('Clear the stored Xero credentials and token?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="disconnect">
                            <button class="text-xs text-red-600 hover:underline" data-testid="button-xero-disconnect"><i class="fas fa-link-slash mr-1"></i>Disconnect / clear config</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">OAuth 2.0 (Web app) &mdash; consent flow</h2>
                    <?php if ($oauth_authorised): ?>
                        <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-medium rounded-full" data-testid="status-xero-oauth-authorised">Authorised</span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-medium rounded-full" data-testid="status-xero-oauth-pending">Not authorised</span>
                    <?php endif; ?>
                </div>

                <p class="text-sm text-gray-600 mb-4">
                    The custom connection is one-to-one and cannot list its tenant. A <strong>Web app</strong> gets a consent screen,
                    a refresh token and &mdash; crucially &mdash; <code class="bg-gray-100 px-1 rounded">GET /connections</code>, which returns the
                    <strong>Tenant ID</strong>. Authorise once here and the field below is filled automatically.
                </p>

                <?php if ($oauth_tenant_id !== ''): ?>
                    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm mb-4" data-testid="xero-oauth-tenant">
                        <p class="font-medium mb-1"><i class="fas fa-circle-check mr-2"></i>Connected: <?php echo htmlspecialchars($oauth_tenant_name !== '' ? $oauth_tenant_name : 'organisation'); ?></p>
                        <p class="font-mono text-xs">Tenant ID: <?php echo htmlspecialchars($oauth_tenant_id); ?></p>
                        <p class="text-xs mt-1">Copy into the <em>Tenant ID</em> field above if the Custom Connection still shows &ldquo;not saved&rdquo;.</p>
                    </div>
                <?php endif; ?>

                <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_oauth">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Web app Client ID</label>
                        <input name="oauth_client_id" value="<?php echo $oauth_client_id_set ? htmlspecialchars((string)$okv['client_id']) : ''; ?>" placeholder="from the Web app's Configuration page" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" data-testid="input-xero-oauth-client-id">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Web app Client secret <span class="text-gray-400">(blank = keep)</span></label>
                        <input name="oauth_client_secret" type="password" placeholder="<?php echo $oauth_secret_set ? '•••••••• (saved)' : 'paste secret'; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" data-testid="input-xero-oauth-secret">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Redirect URI <span class="text-gray-400">(must match the app Configuration exactly)</span></label>
                        <input name="oauth_redirect_uri" value="<?php echo htmlspecialchars($oauth_redirect); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" data-testid="input-xero-oauth-redirect">
                    </div>
                    <div class="md:col-span-2 flex flex-wrap gap-3 items-center justify-between">
                        <div class="flex gap-3">
                            <button class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-md text-sm" data-testid="button-xero-oauth-save">Save OAuth settings</button>
                            <a href="/portal/api/xero/oauth/connect" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm inline-flex items-center gap-2" data-testid="button-xero-oauth-connect">
                                <i class="fas fa-arrow-up-right-from-square"></i> Connect with Xero
                            </a>
                        </div>
                        <span class="text-xs text-gray-500">Requests 16 scopes incl. <code class="bg-gray-100 px-1 rounded">offline_access</code> (needed for the refresh token) &mdash; all must be ticked on the app&rsquo;s Configuration page.</span>
                    </div>
                </form>

                <form method="POST" onsubmit="return confirm('Clear the OAuth 2.0 tokens and settings? The custom connection is untouched.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="oauth_disconnect">
                    <button class="text-xs text-red-600 hover:underline" data-testid="button-xero-oauth-disconnect"><i class="fas fa-link-slash mr-1"></i>Clear OAuth 2.0 config</button>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Invoiced (ACCREC)</p>
                    <p class="text-3xl font-bold text-gray-900">$<?php echo number_format((float)$totals['invoiced'], 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo (int)$counts['invoices']; ?> invoice(s)</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Collected</p>
                    <p class="text-3xl font-bold text-emerald-600">$<?php echo number_format((float)$totals['collected'], 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo (int)$counts['payments']; ?> payment(s)</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Outstanding</p>
                    <p class="text-3xl font-bold text-blue-600">$<?php echo number_format((float)$totals['outstanding'], 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Unpaid balance</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Contacts / Accounts</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo (int)$counts['contacts']; ?> / <?php echo (int)$counts['accounts']; ?></p>
                    <p class="text-xs text-gray-500 mt-1">Xero contacts / chart of accounts</p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Invoices</h2>
                    <span class="text-xs text-gray-500">Latest 30</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                            <th class="px-6 py-3 text-left">Number</th><th class="px-6 py-3 text-left">Type</th>
                            <th class="px-6 py-3 text-left">Contact</th><th class="px-6 py-3 text-left">Portal client</th>
                            <th class="px-6 py-3 text-left">Date</th><th class="px-6 py-3 text-left">Due</th>
                            <th class="px-6 py-3 text-left">Status</th><th class="px-6 py-3 text-right">Total</th>
                            <th class="px-6 py-3 text-right">Paid</th><th class="px-6 py-3 text-right">Due</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                        <?php if (empty($recent_invoices)): ?>
                            <tr><td colspan="10" class="px-6 py-10 text-center text-gray-500" data-testid="xero-invoices-empty">Nothing mirrored yet — save the Tenant ID and press “Sync from Xero”.</td></tr>
                        <?php else: foreach ($recent_invoices as $r): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-medium text-gray-900"><?php echo htmlspecialchars((string)($r['invoice_number'] ?: '—')); ?></td>
                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($r['type'] ?: '—')); ?></td>
                                <td class="px-6 py-3 text-gray-700"><?php echo htmlspecialchars((string)($r['contact_name'] ?: '—')); ?></td>
                                <td class="px-6 py-3">
                                    <?php if (!empty($r['portal_client_name'])): ?>
                                        <a class="text-blue-600 hover:underline" href="admin-client-detail.php?id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars((string)$r['portal_client_name']); ?></a>
                                    <?php else: ?><span class="text-gray-400">unmatched</span><?php endif; ?>
                                </td>
                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($r['invoice_date'] ?: '—')); ?></td>
                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($r['due_date'] ?: '—')); ?></td>
                                <td class="px-6 py-3"><span class="px-2 py-0.5 rounded-full text-xs <?php echo xero_status_pill($r['status']); ?>"><?php echo htmlspecialchars((string)($r['status'] ?: '—')); ?></span></td>
                                <td class="px-6 py-3 text-right text-gray-900">$<?php echo number_format((float)$r['total'], 2); ?></td>
                                <td class="px-6 py-3 text-right text-emerald-600">$<?php echo number_format((float)$r['amount_paid'], 2); ?></td>
                                <td class="px-6 py-3 text-right <?php echo ((float)$r['amount_due'] > 0) ? 'text-red-600 font-medium' : 'text-gray-500'; ?>">$<?php echo number_format((float)$r['amount_due'], 2); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200"><h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Recent payments</h2></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                                <th class="px-6 py-3 text-left">Date</th><th class="px-6 py-3 text-left">Contact</th>
                                <th class="px-6 py-3 text-left">Invoice</th><th class="px-6 py-3 text-right">Amount</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($recent_payments)): ?>
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No payments mirrored yet.</td></tr>
                            <?php else: foreach ($recent_payments as $p): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($p['payment_date'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-700"><?php echo htmlspecialchars((string)($p['contact_name'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($p['invoice_number'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-right text-emerald-600 font-medium">$<?php echo number_format((float)$p['amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Chart of accounts</h2>
                        <span class="text-xs text-gray-500"><?php echo (int)$counts['accounts']; ?> total</span>
                    </div>
                    <div class="overflow-x-auto max-h-96">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-xs uppercase"><tr>
                                <th class="px-6 py-3 text-left">Code</th><th class="px-6 py-3 text-left">Account</th>
                                <th class="px-6 py-3 text-left">Type</th><th class="px-6 py-3 text-left">Tax</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php if (empty($accounts)): ?>
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No accounts mirrored yet.</td></tr>
                            <?php else: foreach ($accounts as $a): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars((string)($a['code'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-900"><?php echo htmlspecialchars((string)$a['name']); ?></td>
                                    <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars((string)($a['type'] ?: '—')); ?></td>
                                    <td class="px-6 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars((string)($a['tax_type'] ?: '—')); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Live reports (streamed straight from Xero — nothing to mirror) -->
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Live reports</h2>
                    <span class="text-xs text-gray-500">Fetched on demand from Xero</span>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                        <?php foreach ([
                            ['ProfitAndLoss', 'P&amp;L'],
                            ['BalanceSheet',  'Balance Sheet'],
                            ['AgedReceivablesByContact', 'Aged Receivables'],
                            ['BankSummary',   'Bank Summary'],
                        ] as [$rpt, $label]): ?>
                        <button onclick="loadReport('<?php echo $rpt; ?>')"
                            class="p-3 border border-gray-200 rounded-lg hover:border-sky-300 hover:bg-sky-50 transition text-left text-sm font-medium text-gray-700"
                            data-testid="button-report-<?php echo strtolower($rpt); ?>">
                            <?php echo $label; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div id="report-loading" class="hidden text-center py-8 text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Loading report…</div>
                    <div id="report-output" class="overflow-x-auto"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function loadReport(reportName) {
    const loadingEl = document.getElementById('report-loading');
    const outputEl  = document.getElementById('report-output');
    loadingEl.classList.remove('hidden');
    outputEl.innerHTML = '';
    try {
        const resp = await fetch('/portal/api/xero/data?resource=' + encodeURIComponent('Reports/' + reportName));
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || 'Report error');
        loadingEl.classList.add('hidden');
        const rpts = data.Reports || [];
        if (!rpts.length) { outputEl.innerHTML = '<p class="text-gray-500 text-sm">No report data returned.</p>'; return; }
        let html = '';
        rpts.forEach(rpt => {
            html += `<h3 class="font-semibold text-gray-800 mb-3">${rpt.ReportName || reportName}</h3>`;
            (rpt.Rows || []).forEach(row => {
                if (row.RowType === 'Header') {
                    html += '<table class="w-full text-sm border-collapse mb-4"><tbody><tr class="bg-gray-50 border-b border-gray-200">';
                    (row.Cells || []).forEach(c => { html += `<th class="px-3 py-2 text-left font-medium text-gray-600">${c.Value || ''}</th>`; });
                    html += '</tr>';
                } else if (row.RowType === 'Section') {
                    html += '<tr class="bg-gray-100 border-b border-gray-200"><td class="px-3 py-2 font-semibold text-gray-700">' + ((row.Title || '') ) + '</td></tr>';
                    (row.Rows || []).forEach(sub => {
                        const bold = sub.RowType === 'SummaryRow' ? 'font-semibold bg-gray-50' : '';
                        html += `<tr class="border-b border-gray-100 ${bold}">`;
                        (sub.Cells || []).forEach((c, ci) => { html += `<td class="px-3 py-1.5 ${ci > 0 ? 'text-right' : ''}">${c.Value || ''}</td>`; });
                        html += '</tr>';
                    });
                } else {
                    html += '<table class="w-full text-sm border-collapse mb-4"><tbody>';
                    const bold = row.RowType === 'SummaryRow' ? 'font-semibold bg-gray-50' : '';
                    html += `<tr class="border-b border-gray-100 ${bold}">`;
                    (row.Cells || []).forEach((c, ci) => { html += `<td class="px-3 py-1.5 ${ci > 0 ? 'text-right' : ''}">${c.Value || ''}</td>`; });
                    html += '</tr>';
                }
            });
        });
        outputEl.innerHTML = html || '<p class="text-gray-500 text-sm">Report returned no rows.</p>';
    } catch (e) {
        loadingEl.classList.add('hidden');
        outputEl.innerHTML = `<div class="text-red-600 text-sm py-4"><i class="fas fa-exclamation-circle mr-1"></i>${e.message}</div>`;
    }
}
</script>
</body>
</html>