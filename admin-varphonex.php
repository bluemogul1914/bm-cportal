<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$is_admin  = true;
$pdo       = getDB();

// ── Self-bootstrap provider_settings ─────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_settings (
        id SERIAL PRIMARY KEY, provider VARCHAR(50) NOT NULL,
        key_name VARCHAR(100) NOT NULL, key_value TEXT DEFAULT '',
        updated_at TIMESTAMP DEFAULT NOW(), UNIQUE(provider, key_name)
    )");
} catch (Exception $e) {}

// ── Load credentials: env → DB fallback ──────────────────────────────────────
$vx_user = VARPHONEX_USERNAME;
$vx_pass = VARPHONEX_PASSWORD;
if (empty($vx_user)) {
    try {
        $rows = $pdo->prepare("SELECT key_name, key_value FROM provider_settings WHERE provider='varphonex'");
        $rows->execute();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key_name'] === 'username') $vx_user = $row['key_value'];
            if ($row['key_name'] === 'password') $vx_pass = $row['key_value'];
        }
    } catch (Exception $e) {}
}
$vx_connected = !empty($vx_user) && !empty($vx_pass);
$vx_base      = rtrim(VARPHONEX_API_URL, '/');

$success_msg = '';
$error_msg   = '';
$tab         = $_GET['tab'] ?? 'overview';

// ── API helper: POST form-data, JSON response ─────────────────────────────────
function vx_api(array $params): array {
    global $vx_user, $vx_pass, $vx_base;
    $params = array_merge([
        'api_username' => $vx_user,
        'api_password' => $vx_pass,
    ], $params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $vx_base,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['_error' => $err, '_code' => $code];
    if (empty($resp)) return ['_error' => "Empty response (HTTP $code)", '_code' => $code];

    // Try JSON first
    $d = json_decode($resp, true);
    if ($d !== null) {
        if (isset($d['status']) && strtolower($d['status']) === 'error') {
            return ['_error' => $d['message'] ?? $d['error'] ?? 'API returned error', '_raw' => $d];
        }
        return array_merge(['_code' => $code], $d);
    }

    // Try XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($resp);
    if ($xml !== false) {
        $arr = json_decode(json_encode($xml), true);
        if (isset($arr['status']) && strtolower($arr['status']) === 'error') {
            return ['_error' => $arr['message'] ?? 'API returned error', '_raw' => $arr];
        }
        return array_merge(['_code' => $code], $arr);
    }

    return ['_error' => "Unexpected response (HTTP $code): " . substr($resp, 0, 300), '_code' => $code];
}

// ── Helper: ensure list is an indexed array ───────────────────────────────────
function vx_list(array $data, string ...$keys): array {
    foreach ($keys as $k) {
        $v = $data[$k] ?? null;
        if (empty($v)) continue;
        if (isset($v[0]) && is_array($v[0])) return $v;
        if (is_array($v) && !isset($v[0])) return [$v]; // single item
    }
    return [];
}

// ── POST action handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // Save credentials
        if ($action === 'save_credentials') {
            $u = trim($_POST['vx_user'] ?? '');
            $p = trim($_POST['vx_pass'] ?? '');
            try {
                foreach (['username' => $u, 'password' => $p] as $k => $v) {
                    $pdo->prepare("INSERT INTO provider_settings (provider,key_name,key_value) VALUES ('varphonex',?,?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")
                        ->execute([$k, $v]);
                }
                $vx_user = $u; $vx_pass = $p;
                $vx_connected = !empty($u) && !empty($p);
                $success_msg = 'VarPhonex credentials saved.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
            $tab = 'settings';

        // Test connection
        } elseif ($action === 'test_connection') {
            $res = vx_api(['action' => 'getAccountBalance']);
            if (isset($res['_error'])) {
                $error_msg = 'Connection failed: ' . $res['_error'];
            } else {
                $bal = $res['balance'] ?? $res['account_balance'] ?? 'N/A';
                $success_msg = "Connected! Account balance: $bal";
            }
            $tab = 'overview';

        // Purchase DID
        } elseif ($action === 'purchase_did') {
            $did    = trim($_POST['did']    ?? '');
            $custid = trim($_POST['customer_id'] ?? '');
            if (!$did) { $error_msg = 'Phone number is required.'; }
            else {
                $params = ['action' => 'purchaseDID', 'did' => $did];
                if ($custid) $params['customer_id'] = $custid;
                $res = vx_api($params);
                if (isset($res['_error'])) {
                    $error_msg = "Purchase failed: " . $res['_error'];
                } else {
                    $success_msg = "Phone number $did purchased successfully!";
                }
            }
            $tab = 'dids';

        // Release DID
        } elseif ($action === 'release_did') {
            $did = trim($_POST['did'] ?? '');
            if (!$did) { $error_msg = 'Phone number is required.'; }
            else {
                $res = vx_api(['action' => 'releaseDID', 'did' => $did]);
                if (isset($res['_error'])) {
                    $error_msg = "Release failed: " . $res['_error'];
                } else {
                    $success_msg = "Phone number $did released.";
                }
            }
            $tab = 'dids';

        // Create customer / sub-account
        } elseif ($action === 'create_customer') {
            $fname  = trim($_POST['first_name']  ?? '');
            $lname  = trim($_POST['last_name']   ?? '');
            $email  = trim($_POST['email']       ?? '');
            $phone  = trim($_POST['phone']       ?? '');
            $company = trim($_POST['company']    ?? '');
            if (!$fname || !$lname || !$email) {
                $error_msg = 'First name, last name, and email are required.';
            } else {
                $res = vx_api([
                    'action'     => 'createCustomer',
                    'first_name' => $fname,
                    'last_name'  => $lname,
                    'email'      => $email,
                    'phone'      => $phone,
                    'company'    => $company,
                ]);
                if (isset($res['_error'])) {
                    $error_msg = "Create customer failed: " . $res['_error'];
                } else {
                    $success_msg = "Customer $fname $lname created successfully!";
                }
            }
            $tab = 'customers';

        // Update customer status
        } elseif ($action === 'update_customer_status') {
            $custid = trim($_POST['customer_id'] ?? '');
            $status = trim($_POST['status']      ?? '');
            if (!$custid) { $error_msg = 'Customer ID required.'; }
            else {
                $res = vx_api(['action' => 'updateCustomer', 'customer_id' => $custid, 'status' => $status]);
                if (isset($res['_error'])) {
                    $error_msg = "Update failed: " . $res['_error'];
                } else {
                    $success_msg = "Customer $custid updated to $status.";
                }
            }
            $tab = 'customers';

        // Add credit
        } elseif ($action === 'add_credit') {
            $custid = trim($_POST['customer_id'] ?? '');
            $amount = trim($_POST['amount']      ?? '');
            if (!$custid || !$amount) { $error_msg = 'Customer ID and amount are required.'; }
            else {
                $res = vx_api(['action' => 'addCredit', 'customer_id' => $custid, 'amount' => $amount]);
                if (isset($res['_error'])) {
                    $error_msg = "Add credit failed: " . $res['_error'];
                } else {
                    $success_msg = "Added $$amount credit to customer $custid.";
                }
            }
            $tab = 'customers';
        }
    }
}

// ── Live data per tab ──────────────────────────────────────────────────────────
$account    = [];
$dids       = [];
$avail_dids = [];
$customers  = [];
$cdrs       = [];
$rates      = [];
$api_error  = '';

if ($vx_connected) {
    if ($tab === 'overview') {
        $res = vx_api(['action' => 'getAccountBalance']);
        if (isset($res['_error'])) { $api_error = $res['_error']; }
        else { $account = $res; }

        // Quick counts for stats
        $dr = vx_api(['action' => 'getDIDList']);
        if (!isset($dr['_error'])) $dids = vx_list($dr, 'dids', 'did_list', 'data');

        $cr = vx_api(['action' => 'getCustomerList']);
        if (!isset($cr['_error'])) $customers = vx_list($cr, 'customers', 'customer_list', 'data');

    } elseif ($tab === 'dids') {
        $res = vx_api(['action' => 'getDIDList']);
        if (isset($res['_error'])) { $api_error = $res['_error']; }
        else { $dids = vx_list($res, 'dids', 'did_list', 'data'); }

        // Search for available DIDs if area code provided
        $area_code = $_GET['area_code'] ?? '';
        if ($area_code) {
            $ar = vx_api(['action' => 'searchDID', 'area_code' => $area_code]);
            if (!isset($ar['_error'])) $avail_dids = vx_list($ar, 'available', 'dids', 'data');
        }

    } elseif ($tab === 'customers') {
        $res = vx_api(['action' => 'getCustomerList']);
        if (isset($res['_error'])) { $api_error = $res['_error']; }
        else { $customers = vx_list($res, 'customers', 'customer_list', 'data'); }

    } elseif ($tab === 'cdr') {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $did  = $_GET['did']  ?? '';
        $params = ['action' => 'getCDR', 'start_date' => $from, 'end_date' => $to];
        if ($did) $params['did'] = $did;
        $res = vx_api($params);
        if (isset($res['_error'])) { $api_error = $res['_error']; }
        else { $cdrs = vx_list($res, 'cdrs', 'cdr', 'records', 'data'); }

    } elseif ($tab === 'rates') {
        $dest = $_GET['dest'] ?? '';
        if ($dest) {
            $res = vx_api(['action' => 'getRates', 'destination' => $dest]);
            if (isset($res['_error'])) { $api_error = $res['_error']; }
            else { $rates = vx_list($res, 'rates', 'rate_list', 'data'); }
        }
    }
}

// Stats
$total_dids     = count($dids);
$active_dids    = count(array_filter($dids, fn($d) => strtolower($d['status'] ?? 'active') === 'active'));
$total_cust     = count($customers);
$active_cust    = count(array_filter($customers, fn($c) => strtolower($c['status'] ?? 'active') === 'active'));
$acct_balance   = $account['balance'] ?? $account['account_balance'] ?? null;
$acct_company   = $account['company'] ?? $account['reseller_name'] ?? $account['name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VarPhonex - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">

        <!-- ── Header ── -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">
                        <i class="fas fa-phone-alt text-cyan-500 mr-2"></i>VarPhonex
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">
                        VoIP partner portal — DIDs, customers, CDRs, rates &amp; SIP management
                        <?php if ($acct_company): ?>· <span class="font-medium text-cyan-600"><?= htmlspecialchars($acct_company) ?></span><?php endif; ?>
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($vx_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <a href="?tab=<?= $tab ?>" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <a href="http://partners.varphonex.com" target="_blank" class="px-3 py-1.5 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition">
                            <i class="fas fa-external-link-alt mr-1"></i>Partner Portal
                        </a>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected · <?= htmlspecialchars(substr($vx_user,0,16)) ?>
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition">
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

            <?php if ($success_msg): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                <i class="fas fa-check-circle mr-3"></i><?= htmlspecialchars($success_msg) ?>
            </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                <i class="fas fa-exclamation-circle mr-3"></i><?= htmlspecialchars($error_msg) ?>
            </div>
            <?php endif; ?>
            <?php if ($api_error): ?>
            <div class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg flex items-center">
                <i class="fas fa-exclamation-triangle mr-3"></i>VarPhonex API: <strong class="ml-1"><?= htmlspecialchars($api_error) ?></strong>
            </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($vx_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1 truncate"><?= htmlspecialchars($vx_user) ?></p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">No credentials set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-balance">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Account Balance</p>
                    <p class="text-2xl font-bold text-cyan-700" data-testid="text-balance">
                        <?= $acct_balance !== null ? '$' . number_format((float)$acct_balance, 2) : '—' ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Reseller account</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-dids">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">DID Numbers</p>
                    <p class="text-2xl font-bold text-blue-700"><?= $total_dids ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $active_dids ?> active</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-customers">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Customers</p>
                    <p class="text-2xl font-bold text-gray-900"><?= $total_cust ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $active_cust ?> active</p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'overview'  => ['fas fa-tachometer-alt', 'Overview'],
                    'dids'      => ['fas fa-hashtag',        'Phone Numbers'],
                    'customers' => ['fas fa-users',          'Customers'],
                    'cdr'       => ['fas fa-list-alt',       'CDR Records'],
                    'rates'     => ['fas fa-dollar-sign',    'Rate Lookup'],
                    'settings'  => ['fas fa-cog',            'Settings'],
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

            <?php if (!$vx_connected && $tab !== 'settings'): ?>
            <div class="bg-cyan-50 border border-cyan-200 rounded-lg p-8 text-center">
                <i class="fas fa-phone-alt text-cyan-300 text-5xl mb-4"></i>
                <h3 class="text-base font-semibold text-cyan-800 mb-2">VarPhonex Credentials Required</h3>
                <p class="text-sm text-cyan-600 mb-4">Add your VarPhonex partner API username and password to manage phone numbers, customers, call records, and rates.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fas fa-key mr-2"></i>Configure Credentials
                </a>
            </div>
            <?php else: ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- OVERVIEW TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'overview'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Account info -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-building text-cyan-500 mr-2"></i>Account Details</h3>
                    <?php if (!empty($account) && !isset($account['_error'])): ?>
                    <div class="space-y-3">
                        <?php foreach ($account as $k => $v):
                            if (str_starts_with((string)$k, '_') || !is_scalar($v) || $v === '') continue;
                            $label = ucwords(str_replace(['_','-'],' ',(string)$k));
                        ?>
                        <div class="flex items-center justify-between py-2 border-b border-gray-50 text-sm">
                            <span class="text-gray-500"><?= htmlspecialchars($label) ?></span>
                            <span class="font-semibold text-gray-800 font-mono"><?= htmlspecialchars((string)$v) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-6 text-gray-400">
                        <i class="fas fa-info-circle text-3xl mb-2"></i>
                        <p class="text-sm">No account data returned. Verify credentials in Settings.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent DIDs -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-hashtag text-blue-500 mr-2"></i>Phone Numbers</h3>
                        <a href="?tab=dids" class="text-xs text-cyan-500 hover:underline">View all →</a>
                    </div>
                    <?php if (empty($dids)): ?>
                    <p class="text-sm text-gray-400 text-center py-4">No DIDs found.</p>
                    <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($dids, 0, 8) as $d):
                            $dnum    = $d['did'] ?? $d['number'] ?? $d['phone'] ?? '—';
                            $dstatus = $d['status'] ?? 'active';
                            $dcust   = $d['customer'] ?? $d['customer_id'] ?? '';
                        ?>
                        <div class="flex items-center justify-between px-3 py-2 bg-gray-50 rounded-lg">
                            <span class="text-sm font-mono font-semibold text-gray-800"><?= htmlspecialchars($dnum) ?></span>
                            <div class="flex items-center gap-2">
                                <?php if ($dcust): ?><span class="text-xs text-gray-400"><?= htmlspecialchars((string)$dcust) ?></span><?php endif; ?>
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= strtolower($dstatus)==='active'?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500' ?>"><?= ucfirst($dstatus) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($dids) > 8): ?><p class="text-xs text-center text-gray-400 mt-2">+ <?= count($dids)-8 ?> more</p><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent customers + quick actions -->
                <div class="space-y-5">
                    <div class="bg-white rounded-lg border border-gray-200 p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-users text-indigo-500 mr-2"></i>Customers</h3>
                            <a href="?tab=customers" class="text-xs text-cyan-500 hover:underline">View all →</a>
                        </div>
                        <?php if (empty($customers)): ?>
                        <p class="text-xs text-gray-400">No customers found.</p>
                        <?php else: ?>
                        <div class="space-y-1.5">
                            <?php foreach (array_slice($customers, 0, 5) as $c):
                                $cname = trim(($c['first_name']??'') . ' ' . ($c['last_name']??'')) ?: ($c['name']??$c['company']??'—');
                                $cstat = $c['status'] ?? 'active';
                            ?>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-circle text-[8px] <?= strtolower($cstat)==='active'?'text-green-500':'text-gray-300' ?>"></i>
                                <span class="text-sm text-gray-700 flex-1 truncate"><?= htmlspecialchars($cname) ?></span>
                                <span class="text-xs text-gray-400"><?= htmlspecialchars($c['email'] ?? '') ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick actions -->
                    <div class="bg-white rounded-lg border border-gray-200 p-5">
                        <h3 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-bolt text-yellow-500 mr-2"></i>Quick Actions</h3>
                        <div class="space-y-2">
                            <a href="?tab=dids" class="flex items-center gap-3 px-3 py-2 bg-blue-50 hover:bg-blue-100 rounded-lg transition text-sm text-blue-700 font-medium">
                                <i class="fas fa-search w-4 text-center"></i>Search Available Numbers
                            </a>
                            <a href="?tab=customers" class="flex items-center gap-3 px-3 py-2 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition text-sm text-indigo-700 font-medium">
                                <i class="fas fa-user-plus w-4 text-center"></i>Create New Customer
                            </a>
                            <a href="?tab=cdr" class="flex items-center gap-3 px-3 py-2 bg-gray-50 hover:bg-gray-100 rounded-lg transition text-sm text-gray-700 font-medium">
                                <i class="fas fa-list-alt w-4 text-center"></i>View Call Records
                            </a>
                            <a href="?tab=rates" class="flex items-center gap-3 px-3 py-2 bg-green-50 hover:bg-green-100 rounded-lg transition text-sm text-green-700 font-medium">
                                <i class="fas fa-dollar-sign w-4 text-center"></i>Rate Lookup
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- PHONE NUMBERS (DIDs) TAB                                   -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'dids'): ?>
            <?php $area_code = $_GET['area_code'] ?? ''; ?>

            <!-- Search / Purchase panel -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Search available -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-search text-cyan-500 mr-2"></i>Search Available Numbers</h3>
                    <form method="GET" class="space-y-3">
                        <input type="hidden" name="tab" value="dids">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Area Code</label>
                            <input type="text" name="area_code" value="<?= htmlspecialchars($area_code) ?>"
                                maxlength="3" placeholder="e.g. 346"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-cyan-500" data-testid="input-area-code">
                        </div>
                        <button type="submit" class="w-full px-3 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-search-dids">
                            <i class="fas fa-search mr-1"></i>Search Numbers
                        </button>
                    </form>

                    <?php if (!empty($avail_dids)): ?>
                    <div class="mt-4 space-y-2 max-h-64 overflow-y-auto">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-2"><?= count($avail_dids) ?> Available</p>
                        <?php foreach ($avail_dids as $ad):
                            $anum  = $ad['did'] ?? $ad['number'] ?? $ad['phone'] ?? '—';
                            $amrc  = $ad['monthly_cost'] ?? $ad['mrc'] ?? $ad['rate'] ?? '';
                            $asetup = $ad['setup_cost'] ?? $ad['nrc'] ?? '';
                        ?>
                        <div class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <span class="text-sm font-mono font-semibold text-gray-800 flex-1"><?= htmlspecialchars($anum) ?></span>
                            <?php if ($amrc): ?><span class="text-xs text-green-600">$<?= htmlspecialchars($amrc) ?>/mo</span><?php endif; ?>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="purchase_did">
                                <input type="hidden" name="did" value="<?= htmlspecialchars($anum) ?>">
                                <button type="submit" class="text-xs text-cyan-600 hover:text-cyan-800 font-semibold" onclick="return confirm('Purchase <?= htmlspecialchars(addslashes($anum)) ?>?')">
                                    <i class="fas fa-cart-plus mr-1"></i>Buy
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ($area_code && empty($avail_dids)): ?>
                    <p class="mt-4 text-xs text-gray-400 text-center">No numbers found for area code <?= htmlspecialchars($area_code) ?>.</p>
                    <?php endif; ?>
                </div>

                <!-- Your DIDs table -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-hashtag text-blue-500 mr-2"></i>Your Phone Numbers</h3>
                        <div class="flex items-center gap-3">
                            <input type="search" placeholder="Filter numbers…" onkeyup="filterDIDs()"
                                class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 w-44" data-testid="input-did-search">
                            <span class="text-xs text-gray-400"><?= $total_dids ?> DIDs</span>
                        </div>
                    </div>
                    <?php if (empty($dids) && !$api_error): ?>
                    <div class="p-8 text-center text-gray-400">
                        <i class="fas fa-hashtag text-3xl mb-2"></i>
                        <p class="text-sm font-medium">No phone numbers in your account.</p>
                        <p class="text-xs mt-1">Search and purchase numbers using the panel on the left.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="table-dids">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Number</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Customer</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="did-tbody">
                                <?php foreach ($dids as $d):
                                    $dnum  = $d['did'] ?? $d['number'] ?? $d['phone'] ?? '—';
                                    $ddesc = $d['description'] ?? $d['label'] ?? '';
                                    $dcust = $d['customer'] ?? $d['customer_id'] ?? $d['assigned_to'] ?? '';
                                    $dstat = $d['status'] ?? 'active';
                                    $dmrc  = $d['monthly_cost'] ?? $d['mrc'] ?? '';
                                ?>
                                <tr class="hover:bg-gray-50 did-row" data-testid="row-did-<?= htmlspecialchars($dnum) ?>">
                                    <td class="px-4 py-3">
                                        <p class="font-mono font-semibold text-gray-900 did-num"><?= htmlspecialchars($dnum) ?></p>
                                        <?php if ($dmrc): ?><p class="text-xs text-gray-400">$<?= htmlspecialchars($dmrc) ?>/mo</p><?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($ddesc ?: '—') ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($dcust ?: '—') ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= strtolower($dstat)==='active'?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500' ?>">
                                            <?= ucfirst($dstat) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3 flex-wrap">
                                            <a href="?tab=cdr&did=<?= urlencode($dnum) ?>" class="text-xs text-blue-500 hover:text-blue-700 font-medium">
                                                <i class="fas fa-list-alt mr-1"></i>CDR
                                            </a>
                                            <form method="POST" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="release_did">
                                                <input type="hidden" name="did" value="<?= htmlspecialchars($dnum) ?>">
                                                <button type="submit" class="text-xs text-red-400 hover:text-red-600 font-medium"
                                                    onclick="return confirm('Release <?= htmlspecialchars(addslashes($dnum)) ?>? This cannot be undone.')"
                                                    data-testid="button-release-<?= htmlspecialchars($dnum) ?>">
                                                    <i class="fas fa-trash mr-1"></i>Release
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- CUSTOMERS TAB                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'customers'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Create customer form -->
                <div class="bg-white rounded-lg border border-gray-200 p-5 self-start">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-user-plus text-indigo-500 mr-2"></i>Create Customer</h3>
                    <p class="text-xs text-gray-400 mb-4">Provision a new sub-account under your VarPhonex reseller account.</p>
                    <form method="POST" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_customer">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">First Name *</label>
                                <input type="text" name="first_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-first-name">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Last Name *</label>
                                <input type="text" name="last_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-last-name">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Email Address *</label>
                            <input type="email" name="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-cust-email">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Phone Number</label>
                            <input type="tel" name="phone" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-cust-phone">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Company</label>
                            <input type="text" name="company" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-cust-company">
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-create-customer">
                            <i class="fas fa-user-plus mr-2"></i>Create Customer
                        </button>
                    </form>
                </div>

                <!-- Customer list -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-users text-indigo-500 mr-2"></i>Customer Accounts</h3>
                        <div class="flex items-center gap-3">
                            <input type="search" placeholder="Filter…" onkeyup="filterTable('cust-tbody','cust-name')"
                                class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 w-44">
                            <span class="text-xs text-gray-400"><?= $total_cust ?> customers</span>
                        </div>
                    </div>
                    <?php if (empty($customers) && !$api_error): ?>
                    <div class="p-10 text-center text-gray-400">
                        <i class="fas fa-users text-4xl mb-3"></i>
                        <p class="font-medium">No customer accounts yet.</p>
                        <p class="text-sm mt-1">Use the form on the left to provision your first customer.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="table-customers">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Customer</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Contact</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Balance</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="cust-tbody">
                                <?php foreach ($customers as $c):
                                    $cid    = $c['customer_id'] ?? $c['id'] ?? '';
                                    $cname  = trim(($c['first_name']??'') . ' ' . ($c['last_name']??'')) ?: ($c['name']??$c['company']??'—');
                                    $ccomp  = $c['company'] ?? '';
                                    $cemail = $c['email'] ?? '';
                                    $cphone = $c['phone'] ?? '';
                                    $cbal   = $c['balance'] ?? $c['credit'] ?? null;
                                    $cstat  = $c['status'] ?? 'active';
                                    $statcol = strtolower($cstat)==='active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500';
                                ?>
                                <tr class="hover:bg-gray-50" data-testid="row-cust-<?= htmlspecialchars((string)$cid) ?>">
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-gray-900 cust-name"><?= htmlspecialchars($cname) ?></p>
                                        <?php if ($ccomp): ?><p class="text-xs text-gray-400"><?= htmlspecialchars($ccomp) ?></p><?php endif; ?>
                                        <?php if ($cid): ?><p class="text-xs font-mono text-gray-300">ID: <?= htmlspecialchars((string)$cid) ?></p><?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($cemail): ?><p class="text-sm text-gray-600"><?= htmlspecialchars($cemail) ?></p><?php endif; ?>
                                        <?php if ($cphone): ?><p class="text-xs font-mono text-gray-400"><?= htmlspecialchars($cphone) ?></p><?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">
                                        <?= $cbal !== null ? '$' . number_format((float)$cbal, 2) : '—' ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statcol ?>"><?= ucfirst($cstat) ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3 flex-wrap">
                                            <!-- Add credit modal -->
                                            <button type="button" onclick="openCreditModal('<?= htmlspecialchars(addslashes((string)$cid)) ?>','<?= htmlspecialchars(addslashes($cname)) ?>')"
                                                class="text-xs text-green-600 hover:text-green-800 font-medium" data-testid="button-credit-<?= htmlspecialchars((string)$cid) ?>">
                                                <i class="fas fa-plus-circle mr-1"></i>Credit
                                            </button>
                                            <!-- Toggle active/inactive -->
                                            <form method="POST" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_customer_status">
                                                <input type="hidden" name="customer_id" value="<?= htmlspecialchars((string)$cid) ?>">
                                                <input type="hidden" name="status" value="<?= strtolower($cstat)==='active'?'inactive':'active' ?>">
                                                <button type="submit" class="text-xs <?= strtolower($cstat)==='active'?'text-orange-400 hover:text-orange-600':'text-green-500 hover:text-green-700' ?> font-medium"
                                                    onclick="return confirm('<?= strtolower($cstat)==='active'?'Deactivate':'Activate' ?> this customer?')">
                                                    <i class="fas <?= strtolower($cstat)==='active'?'fa-ban':'fa-check' ?> mr-1"></i><?= strtolower($cstat)==='active'?'Suspend':'Activate' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add credit modal -->
            <div id="credit-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-plus-circle text-green-500 mr-2"></i>Add Credit</h3>
                    <p class="text-sm text-gray-500 mb-4">Add credit to customer account: <strong id="credit-cust-name"></strong></p>
                    <form method="POST" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_credit">
                        <input type="hidden" name="customer_id" id="credit-cust-id">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount (USD)</label>
                            <input type="number" name="amount" min="1" step="0.01" placeholder="25.00"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" data-testid="input-credit-amount">
                        </div>
                        <div class="flex gap-3 justify-end mt-2">
                            <button type="button" onclick="closeCreditModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-credit">
                                <i class="fas fa-plus-circle mr-1"></i>Add Credit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- CDR TAB                                                    -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'cdr'): ?>
            <?php
            $cdr_from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
            $cdr_to   = $_GET['to']   ?? date('Y-m-d');
            $cdr_did  = $_GET['did']  ?? '';
            ?>
            <!-- Filter bar -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-filter text-gray-400 mr-2"></i>Filter CDR Records</h3>
                <form method="GET" class="flex gap-3 flex-wrap items-end">
                    <input type="hidden" name="tab" value="cdr">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">From Date</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($cdr_from) ?>"
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500" data-testid="input-cdr-from">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">To Date</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($cdr_to) ?>"
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500" data-testid="input-cdr-to">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Phone Number (optional)</label>
                        <input type="text" name="did" value="<?= htmlspecialchars($cdr_did) ?>" placeholder="All numbers"
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono w-44 focus:outline-none focus:ring-2 focus:ring-cyan-500" data-testid="input-cdr-did">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-load-cdr">
                        <i class="fas fa-search mr-1"></i>Load Records
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-list-alt text-indigo-500 mr-2"></i>Call Detail Records</h2>
                    <span class="text-xs text-gray-400"><?= count($cdrs) ?> records · <?= $cdr_from ?> → <?= $cdr_to ?></span>
                </div>
                <?php if (empty($cdrs) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-list-alt text-4xl mb-3"></i>
                    <p class="font-medium">No call records for this date range.</p>
                    <p class="text-sm mt-1">Adjust the dates or phone number filter and search again.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-cdr">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date / Time</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">From</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">To</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Duration</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Direction</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($cdrs as $r):
                                $rdate  = $r['call_date'] ?? $r['date'] ?? $r['start_time'] ?? '—';
                                $rfrom  = $r['caller_id'] ?? $r['from'] ?? $r['callerid'] ?? '—';
                                $rto    = $r['destination'] ?? $r['to'] ?? $r['called'] ?? '—';
                                $rdur   = (int)($r['duration'] ?? $r['billsec'] ?? 0);
                                $rdir   = strtolower($r['direction'] ?? $r['call_type'] ?? '');
                                $rcost  = $r['cost'] ?? $r['charge'] ?? $r['amount'] ?? null;
                                $dircol = $rdir === 'inbound' ? 'text-blue-500' : 'text-green-500';
                                $diricon = $rdir === 'inbound' ? 'fa-phone-incoming' : 'fa-phone-alt';
                                $durstr = $rdur > 0 ? floor($rdur/60).'m '.($rdur%60).'s' : '—';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-cdr">
                                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars($rdate) ?></td>
                                <td class="px-4 py-3 font-mono text-gray-700"><?= htmlspecialchars($rfrom) ?></td>
                                <td class="px-4 py-3 font-mono text-gray-700"><?= htmlspecialchars($rto) ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= $durstr ?></td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($rdir): ?>
                                    <span class="flex items-center justify-center gap-1 <?= $dircol ?> text-xs font-medium">
                                        <i class="fas <?= $diricon ?>"></i><?= ucfirst($rdir) ?>
                                    </span>
                                    <?php else: ?><span class="text-gray-400">—</span><?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900">
                                    <?= $rcost !== null ? '$'.number_format((float)$rcost,4) : '—' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- RATES TAB                                                  -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'rates'): ?>
            <?php $dest = $_GET['dest'] ?? ''; ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Rate lookup form -->
                <div class="bg-white rounded-lg border border-gray-200 p-5 self-start">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-dollar-sign text-green-500 mr-2"></i>Rate Lookup</h3>
                    <p class="text-sm text-gray-500 mb-4">Look up calling rates for a specific destination prefix or country.</p>
                    <form method="GET" class="space-y-3">
                        <input type="hidden" name="tab" value="rates">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Destination / Prefix</label>
                            <input type="text" name="dest" value="<?= htmlspecialchars($dest) ?>" placeholder="e.g. 1, 44, UK, US"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" data-testid="input-dest">
                        </div>
                        <button type="submit" class="w-full px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-lookup">
                            <i class="fas fa-search mr-1"></i>Look Up Rates
                        </button>
                    </form>
                </div>

                <!-- Rates table -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-table text-gray-500 mr-2"></i>
                            <?= $dest ? 'Rates for "'.htmlspecialchars($dest).'"' : 'All Rates' ?>
                        </h3>
                    </div>
                    <?php if (empty($rates) && !$api_error): ?>
                    <div class="p-10 text-center text-gray-400">
                        <i class="fas fa-dollar-sign text-4xl mb-3"></i>
                        <p class="font-medium">Enter a destination above to look up calling rates.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="table-rates">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Destination</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Prefix</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Per Minute</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Connection</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Billing</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($rates as $r):
                                    $rdest   = $r['destination'] ?? $r['country'] ?? '—';
                                    $rprefix = $r['prefix'] ?? $r['code'] ?? '—';
                                    $rrate   = $r['rate'] ?? $r['per_minute'] ?? null;
                                    $rconn   = $r['connection_fee'] ?? $r['setup_fee'] ?? null;
                                    $rbill   = $r['billing_increment'] ?? $r['increment'] ?? '—';
                                ?>
                                <tr class="hover:bg-gray-50" data-testid="row-rate">
                                    <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($rdest) ?></td>
                                    <td class="px-4 py-3 font-mono text-gray-600">+<?= htmlspecialchars($rprefix) ?></td>
                                    <td class="px-4 py-3 text-right font-semibold <?= (float)($rrate??0) < 0.02 ? 'text-green-600' : ((float)($rrate??0) < 0.10 ? 'text-gray-800' : 'text-orange-600') ?>">
                                        <?= $rrate !== null ? '$'.number_format((float)$rrate,4) : '—' ?>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500">
                                        <?= $rconn !== null ? '$'.number_format((float)$rconn,4) : '—' ?>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string)$rbill) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SETTINGS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'settings'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Credentials form -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-key text-yellow-500 mr-2"></i>API Credentials</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Your VarPhonex partner API username and password. Log in at
                        <a href="http://partners.varphonex.com" target="_blank" class="text-cyan-500 hover:underline">partners.varphonex.com</a>
                        to obtain your API credentials. The API endpoint used is:
                        <code class="bg-gray-100 px-1 rounded text-xs">partners.varphonex.com/services/api.php</code>
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_credentials">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Username</label>
                            <input type="text" name="vx_user" value="<?= htmlspecialchars($vx_user) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                                placeholder="your_varphonex_username" autocomplete="off" data-testid="input-vx-user">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Password</label>
                            <input type="password" name="vx_pass" value="<?= htmlspecialchars($vx_pass) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                                placeholder="••••••••" autocomplete="off" data-testid="input-vx-pass">
                            <p class="text-xs text-gray-400 mt-1"><?= $vx_connected ? '✓ Credentials stored.' : 'No credentials saved yet.' ?></p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="flex-1 px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-creds">
                                <i class="fas fa-save mr-2"></i>Save Credentials
                            </button>
                            <?php if ($vx_connected): ?>
                            <form method="POST" class="flex-1 m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test_connection">
                                <button type="submit" class="w-full px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition" data-testid="button-test-creds">
                                    <i class="fas fa-plug mr-2"></i>Test Connection
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-400">Alternatively, set <code>VARPHONEX_USERNAME</code> and <code>VARPHONEX_PASSWORD</code> as Replit environment secrets — they take priority over credentials saved here.</p>
                    </form>
                </div>

                <!-- About + links -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About VarPhonex Partner API</h3>
                    <p class="text-sm text-gray-600 mb-4">
                        VarPhonex is a VoIP services provider offering SIP trunking, DID phone numbers, and hosted PBX solutions. The partner API allows resellers to manage customer accounts, provision phone numbers, retrieve call records, and access billing data programmatically.
                    </p>
                    <div class="space-y-2 mb-4">
                        <?php foreach ([
                            ['fas fa-globe',         'Partner Portal',       'http://partners.varphonex.com'],
                            ['fas fa-book',          'API Documentation',    'http://partners.varphonex.com/services/api.php'],
                            ['fas fa-envelope',      'VarPhonex Support',    'mailto:support@varphonex.com'],
                        ] as [$ico,$lbl,$href]): ?>
                        <a href="<?= htmlspecialchars($href) ?>" target="_blank" class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="<?= $ico ?> text-cyan-500 w-4"></i><span><?= $lbl ?></span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-3 bg-cyan-50 border border-cyan-200 rounded-lg">
                        <p class="text-xs text-cyan-700"><strong>Authentication:</strong> Every API call requires <code class="bg-cyan-100 px-1 rounded">api_username</code> and <code class="bg-cyan-100 px-1 rounded">api_password</code> in the POST body. Requests are sent to <code class="bg-cyan-100 px-1 rounded">partners.varphonex.com/services/api.php</code> using HTTP POST with form-encoded parameters.</p>
                    </div>
                </div>

                <!-- Feature grid -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-cyan-500 mr-2"></i>Integrated API Features</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php foreach ([
                            ['fas fa-tachometer-alt','cyan',   'Account Overview',     'Live account balance and summary of all managed resources'],
                            ['fas fa-hashtag',       'blue',   'DID Management',       'List, search, purchase, and release phone numbers (DIDs)'],
                            ['fas fa-search',        'indigo', 'Number Search',        'Search available numbers by area code and purchase instantly'],
                            ['fas fa-users',         'violet', 'Customer Accounts',    'Create, list, activate, and suspend customer sub-accounts'],
                            ['fas fa-plus-circle',   'green',  'Credit Management',    'Add account credit to any customer directly from the portal'],
                            ['fas fa-ban',           'orange', 'Account Control',      'Suspend or re-activate customer accounts with one click'],
                            ['fas fa-list-alt',      'indigo', 'CDR Records',          'Retrieve call detail records with date range and DID filter'],
                            ['fas fa-dollar-sign',   'green',  'Rate Lookup',          'Look up per-minute calling rates by destination or prefix'],
                            ['fas fa-phone-alt',     'cyan',   'Inbound / Outbound',   'Full call direction tracking (inbound, outbound) in CDR view'],
                        ] as [$icon,$color,$title,$desc]):
                            $bg=['cyan'=>'bg-cyan-50 border-cyan-200','blue'=>'bg-blue-50 border-blue-200','indigo'=>'bg-indigo-50 border-indigo-200','violet'=>'bg-violet-50 border-violet-200','green'=>'bg-green-50 border-green-200','orange'=>'bg-orange-50 border-orange-200'];
                            $ic=['cyan'=>'text-cyan-600','blue'=>'text-blue-600','indigo'=>'text-indigo-600','violet'=>'text-violet-600','green'=>'text-green-600','orange'=>'text-orange-500'];
                        ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg border <?= $bg[$color] ?>">
                            <i class="<?= $icon ?> <?= $ic[$color] ?> mt-0.5 w-4 text-center shrink-0"></i>
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

            <?php endif; // end $vx_connected ?>
        </div>
    </div>
</div>

<script>
function filterDIDs() {
    const q = document.querySelector('[data-testid="input-did-search"]').value.toLowerCase();
    document.querySelectorAll('#did-tbody .did-row').forEach(row => {
        const n = row.querySelector('.did-num')?.textContent.toLowerCase() || '';
        row.style.display = n.includes(q) ? '' : 'none';
    });
}
function filterTable(tbodyId, cls) {
    const input = document.querySelector(`#${tbodyId}`).closest('.bg-white').querySelector('input[type="search"]');
    if (!input) return;
    const q = input.value.toLowerCase();
    document.querySelectorAll(`#${tbodyId} tr`).forEach(row => {
        const cell = row.querySelector('.' + cls);
        row.style.display = !cell || cell.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
function openCreditModal(id, name) {
    document.getElementById('credit-cust-id').value = id;
    document.getElementById('credit-cust-name').textContent = name;
    document.getElementById('credit-modal').classList.remove('hidden');
}
function closeCreditModal() {
    document.getElementById('credit-modal').classList.add('hidden');
}
document.getElementById('credit-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCreditModal();
});
</script>
</body>
</html>
