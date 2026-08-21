<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin   = true;
$pdo        = getDB();

// ── Ensure provider_settings table exists ──────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_settings (
        id SERIAL PRIMARY KEY, provider VARCHAR(50) NOT NULL,
        key_name VARCHAR(100) NOT NULL, key_value TEXT DEFAULT '',
        updated_at TIMESTAMP DEFAULT NOW(), UNIQUE(provider, key_name)
    )");
} catch (Exception $e) {}

// ── Load credentials: env constants first, then DB ────────────────────────────
$hw_api_email = HOSTWINDS_API_EMAIL;
$hw_api_key   = HOSTWINDS_API_KEY;
if (empty($hw_api_email) || empty($hw_api_key)) {
    try {
        $r = $pdo->prepare("SELECT key_name, key_value FROM provider_settings WHERE provider='hostwinds'");
        $r->execute();
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key_name'] === 'api_email') $hw_api_email = $row['key_value'];
            if ($row['key_name'] === 'api_key')   $hw_api_key   = $row['key_value'];
        }
    } catch (Exception $e) {}
}

$hw_connected = !empty($hw_api_email) && !empty($hw_api_key);
$hw_api_url   = rtrim(HOSTWINDS_API_URL, '/');

$success_msg = '';
$error_msg   = '';
$tab         = $_GET['tab'] ?? 'services';

// ── Core API helper (Reseller API — POST form-encoded) ─────────────────────────
function hw_api(string $action, array $params = []): array {
    global $hw_api_email, $hw_api_key, $hw_api_url;
    $postFields = array_merge([
        'action' => $action,
        'email'  => $hw_api_email,
        'apikey' => $hw_api_key,
    ], $params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $hw_api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
    ]);
    $resp     = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return ['error' => $curl_err, 'http_code' => 0];
    $decoded = json_decode($resp, true);
    if ($decoded === null) {
        return ['error' => "Invalid JSON response (HTTP $code)", 'http_code' => $code, 'raw' => substr($resp, 0, 500)];
    }
    if (!empty($decoded['result']) && $decoded['result'] === 'error') {
        return ['error' => $decoded['message'] ?? 'Reseller API error', 'http_code' => $code, 'data' => $decoded];
    }
    return array_merge(['http_code' => $code], $decoded);
}

// ── POST action handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_credentials') {
            $email = trim($_POST['hw_api_email'] ?? '');
            $key   = trim($_POST['hw_api_key']   ?? '');
            try {
                foreach (['api_email' => $email, 'api_key' => $key] as $k => $v) {
                    $pdo->prepare("INSERT INTO provider_settings (provider,key_name,key_value) VALUES ('hostwinds',?,?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")
                        ->execute([$k, $v]);
                }
                $hw_api_email = $email;
                $hw_api_key   = $key;
                $hw_connected = !empty($email) && !empty($key);
                $success_msg  = 'Hostwinds Reseller API credentials saved.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
            $tab = 'settings';

        } elseif ($action === 'test_connection') {
            $res = hw_api('getservicelist');
            if (isset($res['error'])) {
                $error_msg = 'Connection failed: ' . $res['error'];
            } else {
                $count = count($res['services'] ?? $res['data'] ?? []);
                $success_msg = "Connected! Found $count hosting service(s).";
            }
            $tab = 'services';
        }
    }
}

// ── Live data per tab ──────────────────────────────────────────────────────────
$services  = [];
$accounts  = [];
$invoices  = [];
$api_error = '';

if ($hw_connected) {
    if ($tab === 'services') {
        $res = hw_api('getservicelist');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $services = $res['services'] ?? $res['data'] ?? []; }
    } elseif ($tab === 'accounts') {
        $res = hw_api('listclients');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $accounts = $res['clients'] ?? $res['data'] ?? []; }
    } elseif ($tab === 'billing') {
        $res = hw_api('getinvoices');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $invoices = $res['invoices'] ?? $res['data'] ?? []; }
    }
}

$total_services = count($services);
$active_count   = 0;
foreach ($services as $s) {
    if (in_array(strtolower($s['status'] ?? ''), ['active','enabled','running'])) $active_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hostwinds Reseller - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">

        <!-- ── Page Header ── -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">
                        <i class="fas fa-server text-cyan-500 mr-2"></i>Hostwinds Reseller Integration
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">Hosting services, client accounts &amp; reseller billing</p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($hw_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test-connection">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <a href="?tab=<?= htmlspecialchars($tab) ?>" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-refresh">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-key mr-1"></i>Add Credentials
                        </a>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Not Connected
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">

            <!-- Alerts -->
            <?php if ($success_msg): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-3"></i><span><?= htmlspecialchars($success_msg) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-3"></i><span><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($api_error): ?>
                <div class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-exclamation-triangle mr-3"></i>
                    <span>Reseller API error: <strong><?= htmlspecialchars($api_error) ?></strong></span>
                </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($hw_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($hw_api_email) ?></p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Credentials not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-services">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Services</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-services"><?= $hw_connected ? ($total_services ?: '—') : '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Hosting accounts</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-active">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active</p>
                    <p class="text-2xl font-bold text-green-600" data-testid="text-active"><?= $hw_connected ? ($active_count ?: '—') : '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Running services</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-api-url">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Endpoint</p>
                    <p class="text-xs font-mono text-cyan-700 truncate mt-1" title="<?= htmlspecialchars($hw_api_url) ?>">
                        clients.hostwinds.com<br><span class="text-gray-400">/HostwindsResellerAPI/api.php</span>
                    </p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'services' => ['fas fa-globe',   'Services'],
                    'accounts' => ['fas fa-users',   'Client Accounts'],
                    'billing'  => ['fas fa-receipt', 'Billing'],
                    'settings' => ['fas fa-cog',     'Settings'],
                ];
                foreach ($tabs as $t => [$icon, $label]):
                    $active = $tab === $t;
                ?>
                <a href="?tab=<?= $t ?>"
                   class="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition <?= $active ? 'bg-white text-cyan-700 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                   data-testid="tab-<?= $t ?>">
                    <i class="<?= $icon ?> text-xs"></i><?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$hw_connected && $tab !== 'settings'): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 text-center">
                <i class="fas fa-server text-blue-300 text-4xl mb-3"></i>
                <h3 class="text-base font-semibold text-blue-800 mb-2">Hostwinds Reseller Credentials Required</h3>
                <p class="text-sm text-blue-600 mb-4">Add your API email and API key from the Hostwinds client portal to start managing reseller services from this dashboard.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fas fa-key mr-2"></i>Configure Credentials
                </a>
            </div>
            <?php else: ?>

            <!-- ════════════════════════════════════════════════════════ -->
            <!-- SERVICES TAB                                             -->
            <!-- ════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'services'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-globe text-cyan-500 mr-2"></i>Hosting Services</h2>
                    <span class="text-xs text-gray-400"><?= $total_services ?> service(s)</span>
                </div>
                <?php if (empty($services) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-globe text-4xl mb-3"></i>
                    <p class="font-medium">No services found for this account.</p>
                    <p class="text-sm mt-1">Services will appear here once the API returns data.</p>
                </div>
                <?php elseif (!empty($services)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-services">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Domain / Service</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Package</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Next Due</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Price</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($services as $svc):
                                $sid    = $svc['id'] ?? $svc['serviceid'] ?? '—';
                                $domain = $svc['domain'] ?? $svc['hostname'] ?? $svc['name'] ?? '—';
                                $pkg    = $svc['package'] ?? $svc['plan'] ?? '—';
                                $st     = strtolower($svc['status'] ?? 'unknown');
                                $due    = $svc['nextduedate'] ?? $svc['next_due'] ?? null;
                                $price  = $svc['amount'] ?? $svc['price'] ?? null;
                                $is_act = in_array($st, ['active','enabled','running']);
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-service-<?= htmlspecialchars((string)$sid) ?>">
                                <td class="px-4 py-3 text-gray-400 font-mono text-xs"><?= htmlspecialchars((string)$sid) ?></td>
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($domain) ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($pkg) ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $is_act ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
                                        <?= ucfirst($st) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs"><?= $due ? date('M d, Y', strtotime($due)) : '—' ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900"><?= $price !== null ? '$'.number_format((float)$price,2) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ════════════════════════════════════════════════════════ -->
            <!-- ACCOUNTS TAB                                             -->
            <!-- ════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'accounts'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-users text-blue-500 mr-2"></i>Client Accounts</h2>
                    <span class="text-xs text-gray-400"><?= count($accounts) ?> client(s)</span>
                </div>
                <?php if (empty($accounts) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-users text-4xl mb-3"></i>
                    <p class="font-medium">No client accounts found.</p>
                    <p class="text-sm mt-1">Client accounts you manage via the reseller portal will appear here.</p>
                </div>
                <?php elseif (!empty($accounts)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-accounts">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($accounts as $acct):
                                $aid   = $acct['id'] ?? $acct['clientid'] ?? '—';
                                $aname = trim(($acct['firstname'] ?? '') . ' ' . ($acct['lastname'] ?? '')) ?: ($acct['name'] ?? '—');
                                $aeml  = $acct['email'] ?? '—';
                                $ast   = strtolower($acct['status'] ?? 'unknown');
                                $acr   = $acct['datecreated'] ?? $acct['created_at'] ?? null;
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-account-<?= htmlspecialchars((string)$aid) ?>">
                                <td class="px-4 py-3 text-gray-400 font-mono text-xs"><?= htmlspecialchars((string)$aid) ?></td>
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($aname) ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($aeml) ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $ast==='active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
                                        <?= ucfirst($ast) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs"><?= $acr ? date('M d, Y', strtotime($acr)) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ════════════════════════════════════════════════════════ -->
            <!-- BILLING TAB                                              -->
            <!-- ════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'billing'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-receipt text-green-500 mr-2"></i>Invoices</h2>
                    <span class="text-xs text-gray-400"><?= count($invoices) ?> invoice(s)</span>
                </div>
                <?php if (empty($invoices) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-receipt text-4xl mb-3"></i>
                    <p class="font-medium">No invoices returned from the API.</p>
                    <p class="text-sm mt-1">Check your <a href="https://clients.hostwinds.com/clientarea.php?action=invoices" target="_blank" class="text-blue-500 hover:underline">Hostwinds billing portal</a> directly.</p>
                </div>
                <?php elseif (!empty($invoices)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-invoices">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Invoice #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Due Date</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($invoices as $inv):
                                $iid  = $inv['id'] ?? $inv['invoiceid'] ?? '—';
                                $idat = $inv['date'] ?? $inv['invoicedate'] ?? null;
                                $idue = $inv['duedate'] ?? null;
                                $iamt = $inv['total'] ?? $inv['amount'] ?? null;
                                $ist  = strtolower($inv['status'] ?? 'unpaid');
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-invoice-<?= htmlspecialchars((string)$iid) ?>">
                                <td class="px-4 py-3 font-mono text-xs text-gray-500">#<?= htmlspecialchars((string)$iid) ?></td>
                                <td class="px-4 py-3 text-gray-600"><?= $idat ? date('M d Y', strtotime($idat)) : '—' ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= $idue ? date('M d Y', strtotime($idue)) : '—' ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900"><?= $iamt !== null ? '$'.number_format((float)$iamt,2) : '—' ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $ist==='paid'?'bg-green-100 text-green-700':($ist==='unpaid'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-600') ?>">
                                        <?= ucfirst($ist) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ════════════════════════════════════════════════════════ -->
            <!-- SETTINGS TAB                                             -->
            <!-- ════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'settings'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Credentials -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1">
                        <i class="fas fa-key text-yellow-500 mr-2"></i>Reseller API Credentials
                    </h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Find your API email and key in the Hostwinds client portal under
                        <a href="https://clients.hostwinds.com/api_keys.php" target="_blank" class="text-blue-500 hover:underline">API Keys</a>.
                        Services API URL: <code class="bg-gray-100 px-1 rounded text-xs">https://clients.hostwinds.com/HostwindsResellerAPI/api.php</code>
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_credentials">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API E-mail</label>
                            <input type="email" name="hw_api_email" value="<?= htmlspecialchars($hw_api_email) ?>"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="traceyew@gmail.com" autocomplete="off" data-testid="input-api-email">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                            <input type="password" name="hw_api_key" value="<?= htmlspecialchars($hw_api_key) ?>"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="••••••••••••" autocomplete="off" data-testid="input-api-key">
                            <p class="text-xs text-gray-400 mt-1">
                                <?= $hw_connected ? '✓ Credentials are stored.' : 'No credentials saved yet.' ?>
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-credentials">
                                <i class="fas fa-save mr-2"></i>Save Credentials
                            </button>
                            <?php if ($hw_connected): ?>
                            <form method="POST" class="flex-1 m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test_connection">
                                <button type="submit" class="w-full px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition" data-testid="button-test-api">
                                    <i class="fas fa-plug mr-2"></i>Test Connection
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- About & Links -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About Hostwinds Reseller API</h3>
                    <p class="text-sm text-gray-600 mb-4">The Hostwinds White Label Reseller API lets you manage hosting services, client accounts, and billing programmatically. Requests use POST form-data with your <code class="bg-gray-100 px-1 rounded text-xs">email</code> and <code class="bg-gray-100 px-1 rounded text-xs">apikey</code> for authentication.</p>
                    <div class="space-y-2 mb-4">
                        <a href="https://clients.hostwinds.com/api_keys.php" target="_blank"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-key text-yellow-500 w-4"></i>
                            <span>Hostwinds API Keys</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://clients.hostwinds.com/clientarea.php" target="_blank"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-server text-cyan-500 w-4"></i>
                            <span>Hostwinds Client Portal</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://clients.hostwinds.com/clientarea.php?action=invoices" target="_blank"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-receipt text-green-500 w-4"></i>
                            <span>Billing &amp; Invoices</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                    </div>
                    <div class="mt-4 p-3 bg-cyan-50 rounded-lg border border-cyan-200">
                        <p class="text-xs text-cyan-700 font-semibold mb-1">Environment variables (optional)</p>
                        <p class="text-xs text-cyan-600">Set <code class="bg-cyan-100 px-1 rounded">HOSTWINDS_API_EMAIL</code> and <code class="bg-cyan-100 px-1 rounded">HOSTWINDS_API_KEY</code> as Replit environment secrets to avoid storing credentials in the database. Env vars take priority over saved credentials.</p>
                    </div>
                </div>

                <!-- Supported features -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-purple-500 mr-2"></i>Supported API Features</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php
                        $features = [
                            ['fas fa-globe',        'green',  'Hosting Services',    'List all active hosting packages with domain, plan, and due dates'],
                            ['fas fa-users',        'blue',   'Client Accounts',     'View reseller sub-clients with status and contact information'],
                            ['fas fa-receipt',      'green',  'Invoices',            'Fetch invoice history with amount and payment status'],
                            ['fas fa-plug',         'yellow', 'Test Connection',     'Verify API credentials are valid with a live status check'],
                            ['fas fa-shield-alt',   'cyan',   'Secure Auth',        'POST-based auth with email + API key — no passwords in URLs'],
                            ['fas fa-sync-alt',     'purple', 'Live Refresh',        'Reload any tab on demand to get the latest API data'],
                        ];
                        foreach ($features as [$icon, $color, $title, $desc]):
                            $colors = ['green'=>'bg-green-50 border-green-200','blue'=>'bg-blue-50 border-blue-200','yellow'=>'bg-yellow-50 border-yellow-200','cyan'=>'bg-cyan-50 border-cyan-200','purple'=>'bg-purple-50 border-purple-200'];
                            $icolors = ['green'=>'text-green-600','blue'=>'text-blue-600','yellow'=>'text-yellow-600','cyan'=>'text-cyan-600','purple'=>'text-purple-600'];
                        ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg border <?= $colors[$color] ?>">
                            <i class="<?= $icon ?> <?= $icolors[$color] ?> mt-0.5 w-4 text-center"></i>
                            <div>
                                <p class="text-sm font-semibold text-gray-800"><?= $title ?></p>
                                <p class="text-xs text-gray-500 mt-0.5"><?= $desc ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <?php endif; // end $hw_connected / tab-locked ?>
        </div><!-- /p-6 -->
    </div><!-- /flex-1 -->
</div><!-- /flex -->
</body>
</html>
