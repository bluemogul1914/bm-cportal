<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin   = true;
$pdo        = getDB();

// ── Ensure provider_settings table exists (self-bootstrapping) ────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_settings (
        id         SERIAL PRIMARY KEY,
        provider   VARCHAR(50) NOT NULL,
        key_name   VARCHAR(100) NOT NULL,
        key_value  TEXT DEFAULT '',
        updated_at TIMESTAMP DEFAULT NOW(),
        UNIQUE(provider, key_name)
    )");
} catch (Exception $e) {}

// ── Load credentials: env constant → provider_settings fallback ───────────────
$ts_uname = TRAVELSIM_UNAME;
$ts_upass = TRAVELSIM_UPASS;
if (empty($ts_uname)) {
    try {
        $rows = $pdo->prepare("SELECT key_name, key_value FROM provider_settings WHERE provider='travelsim'");
        $rows->execute();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key_name'] === 'uname') $ts_uname = $row['key_value'];
            if ($row['key_name'] === 'upass') $ts_upass = $row['key_value'];
        }
    } catch (Exception $e) {}
}
$ts_connected = !empty($ts_uname) && !empty($ts_upass);
$ts_base      = rtrim(TRAVELSIM_API_URL, '/');

$success_msg = '';
$error_msg   = '';
$tab         = $_GET['tab'] ?? 'cards';

// ── Core API helper: GET with XML response ────────────────────────────────────
function ts_api(array $params): array {
    global $ts_uname, $ts_upass, $ts_base;
    $params = array_merge([
        'uname' => $ts_uname,
        'upass' => $ts_upass,
        'plain' => 1,
    ], $params);

    $url = $ts_base . '?' . http_build_query($params);
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['error' => $err];
    if (empty($resp)) return ['error' => "Empty response (HTTP $code)"];

    // Parse XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($resp);
    if ($xml === false) {
        $msg = implode('; ', array_map(fn($e) => $e->message, libxml_get_errors()));
        return ['error' => "XML parse error: $msg", 'raw' => $resp];
    }
    $arr = json_decode(json_encode($xml), true);
    // Check for error code
    if (isset($arr['code']) && (int)$arr['code'] !== 0) {
        return ['error' => $arr['message'] ?? "Error code " . $arr['code'], 'raw' => $arr];
    }
    return array_merge(['_http' => $code], $arr);
}

// Helper: flatten XML children into a list
function ts_rows(array $data, string $key): array {
    $items = $data[$key] ?? [];
    if (empty($items)) return [];
    // If single item, wrap it
    if (isset($items[0]) && is_array($items[0])) return $items;
    return [$items];
}

// ── POST action handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // Save credentials
        if ($action === 'save_credentials') {
            $uname = trim($_POST['ts_uname'] ?? '');
            $upass = trim($_POST['ts_upass'] ?? '');
            try {
                foreach (['uname' => $uname, 'upass' => $upass] as $k => $v) {
                    $pdo->prepare("INSERT INTO provider_settings (provider,key_name,key_value) VALUES ('travelsim',?,?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")
                        ->execute([$k, $v]);
                }
                $ts_uname = $uname; $ts_upass = $upass;
                $ts_connected = !empty($uname) && !empty($upass);
                $success_msg  = 'TravelSim credentials saved.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
            $tab = 'settings';

        // Test connection
        } elseif ($action === 'test_connection') {
            $res = ts_api(['command' => 'gbalance']);
            if (isset($res['error'])) {
                $error_msg = 'Connection failed: ' . $res['error'];
            } else {
                $cards = ts_rows($res, 'card');
                $success_msg = 'Connected! Found ' . count($cards) . ' SIM card(s) in account.';
            }

        // Block / Unblock SIM
        } elseif ($action === 'block_sim') {
            $onum  = trim($_POST['onum'] ?? '');
            $block = (int)($_POST['block'] ?? 1);
            if (!$onum) { $error_msg = 'SIM number required.'; }
            else {
                $res = ts_api(['command' => 'SBLOCK', 'onum' => $onum, 'block' => $block]);
                if (isset($res['error'])) {
                    $error_msg = "Block/Unblock failed: " . $res['error'];
                } else {
                    $success_msg = "SIM $onum " . ($block ? 'blocked' : 'unblocked') . " successfully.";
                }
            }
            $tab = 'cards';

        // Send SMS
        } elseif ($action === 'send_sms') {
            $onum = trim($_POST['onum'] ?? '');
            $dest = trim($_POST['dest'] ?? '');
            $msg  = trim($_POST['message'] ?? '');
            if (!$onum || !$dest || !$msg) {
                $error_msg = 'SIM number, destination, and message are all required.';
            } else {
                $res = ts_api(['command' => 'SMS2', 'onum' => $onum, 'dnumber' => $dest, 'message' => $msg]);
                if (isset($res['error'])) {
                    $error_msg = "SMS send failed: " . $res['error'];
                } else {
                    $success_msg = "SMS sent from $onum to $dest.";
                }
            }
            $tab = 'cards';

        // Recharge SIM with PIN
        } elseif ($action === 'recharge_pin') {
            $onum    = trim($_POST['onum']    ?? '');
            $pin     = trim($_POST['pin']     ?? '');
            $orderid = trim($_POST['orderid'] ?? '1');
            if (!$onum || !$pin) {
                $error_msg = 'SIM number and PIN are required.';
            } else {
                $res = ts_api(['command' => 'RECHARGE', 'onum' => $onum, 'pin' => $pin, 'orderid' => $orderid]);
                if (isset($res['error'])) {
                    $error_msg = "Recharge failed: " . $res['error'];
                } else {
                    $success_msg = "SIM $onum recharged successfully!";
                }
            }
            $tab = 'recharge';

        // Transfer balance between accounts
        } elseif ($action === 'transfer_balance') {
            $amount   = trim($_POST['amount']   ?? '');
            $currency = trim($_POST['currency'] ?? 'USD');
            $orderid  = trim($_POST['orderid']  ?? '1');
            $dest_onum = trim($_POST['dest_onum'] ?? '');
            if (!$amount || !$dest_onum) {
                $error_msg = 'Amount and destination SIM are required.';
            } else {
                $res = ts_api([
                    'command'  => 'SBALANCE',
                    'amount'   => $amount,
                    'currency' => $currency,
                    'orderid'  => $orderid,
                    'onum'     => $dest_onum,
                ]);
                if (isset($res['error'])) {
                    $error_msg = "Transfer failed: " . $res['error'];
                } else {
                    $success_msg = "Transferred $currency $amount to SIM $dest_onum.";
                }
            }
            $tab = 'recharge';

        // Activate discount packet
        } elseif ($action === 'activate_packet') {
            $onum   = trim($_POST['onum']   ?? '');
            $packet = trim($_POST['packet'] ?? '');
            if (!$onum || !$packet) {
                $error_msg = 'SIM number and packet ID are required.';
            } else {
                $res = ts_api(['command' => 'DISCOUNT', 'onum' => $onum, 'activate' => $packet]);
                if (isset($res['error'])) {
                    $error_msg = "Packet activation failed: " . $res['error'];
                } else {
                    $success_msg = "Packet $packet activated on SIM $onum.";
                }
            }
            $tab = 'packets';

        // Deactivate discount packet
        } elseif ($action === 'deactivate_packet') {
            $onum   = trim($_POST['onum']   ?? '');
            $packet = trim($_POST['packet'] ?? '');
            if (!$onum || !$packet) {
                $error_msg = 'SIM number and packet ID are required.';
            } else {
                $res = ts_api(['command' => 'DISCOUNT', 'onum' => $onum, 'deactivate' => $packet]);
                if (isset($res['error'])) {
                    $error_msg = "Packet deactivation failed: " . $res['error'];
                } else {
                    $success_msg = "Packet $packet deactivated on SIM $onum.";
                }
            }
            $tab = 'packets';

        // Set call redirect
        } elseif ($action === 'set_redirect') {
            $onum   = trim($_POST['onum']   ?? '');
            $divnum = trim($_POST['divnum'] ?? '');
            $type   = trim($_POST['rtype']  ?? 'on');
            if (!$onum) { $error_msg = 'SIM number is required.'; }
            else {
                $params = ['command' => 'REDIRECT', 'onum' => $onum, $type => 1];
                if ($divnum) $params['divnum'] = $divnum;
                $res = ts_api($params);
                if (isset($res['error'])) {
                    $error_msg = "Redirect failed: " . $res['error'];
                } else {
                    $success_msg = "Call redirect " . ($type === 'off' ? 'disabled' : 'enabled') . " for SIM $onum.";
                }
            }
            $tab = 'cards';
        }
    }
}

// ── Live data per tab ──────────────────────────────────────────────────────────
$cards        = [];
$cdrs         = [];
$recharge_log = [];
$packets_active = [];
$available_packets = [];
$account_info = [];
$gprs_cdrs    = [];
$api_error    = '';

if ($ts_connected) {

    if ($tab === 'cards') {
        $res = ts_api(['command' => 'gbalance']);
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $cards = ts_rows($res, 'card'); }

    } elseif ($tab === 'cdr') {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $onum = $_GET['onum'] ?? '';
        $params = ['command' => 'gccdr', 'started' => $from, 'finished' => $to];
        if ($onum) $params['onum'] = $onum;
        $res = ts_api($params);
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $cdrs = ts_rows($res, 'cdr'); }

    } elseif ($tab === 'recharge') {
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $_GET['to']   ?? date('Y-m-d');
        $res = ts_api(['command' => 'RECHARGEREPORT', 'started' => $from, 'finished' => $to]);
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $recharge_log = ts_rows($res, 'recharge'); }

    } elseif ($tab === 'packets') {
        $onum = $_GET['onum'] ?? '';
        if ($onum) {
            $res = ts_api(['command' => 'DISCOUNT', 'onum' => $onum, 'status' => 1]);
            if (!isset($res['error'])) $packets_active = ts_rows($res, 'packet');

            $res2 = ts_api(['command' => 'DISCOUNT', 'onum' => $onum, 'getdiscount' => 1]);
            if (!isset($res2['error'])) $available_packets = ts_rows($res2, 'packet');
        }

    } elseif ($tab === 'account') {
        $res = ts_api(['command' => 'ACCOUNT']);
        if (!isset($res['error'])) $account_info = $res;

        $res2 = ts_api(['command' => 'GETPERMISSIONS']);
        $permissions = $res2;
    }
}

// Stats
$card_count   = count($cards);
$total_balance = array_sum(array_map(fn($c) => (float)($c['balance'] ?? 0), $cards));
$blocked_count = count(array_filter($cards, fn($c) => ($c['blocked'] ?? '') === 'true'));
$low_balance   = count(array_filter($cards, fn($c) => (float)($c['balance'] ?? 0) < 5));
$currency_sym  = $cards[0]['curr'] ?? 'USD';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TravelSim - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
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
                        <i class="fas fa-sim-card text-emerald-500 mr-2"></i>TravelSim Management
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">SIM cards, balances, CDRs, recharges, data packets &amp; call control</p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($ts_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test-connection">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <a href="?tab=<?= $tab ?>" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <a href="?tab=sms" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-sms mr-1"></i>Send SMS
                        </a>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected · <?= htmlspecialchars(substr($ts_uname,0,12)) ?>
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition">
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
                <span>TravelSim API error: <strong><?= htmlspecialchars($api_error) ?></strong></span>
            </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($ts_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">User: <?= htmlspecialchars($ts_uname) ?></p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Credentials not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-sims">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">SIM Cards</p>
                    <p class="text-2xl font-bold text-emerald-700" data-testid="text-card-count"><?= $card_count ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $blocked_count ?> blocked</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-balance">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Balance</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-balance">
                        <?= $card_count ? number_format($total_balance, 2) . ' ' . $currency_sym : '—' ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Across all SIMs</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4 <?= $low_balance > 0 ? 'border-orange-200 bg-orange-50' : '' ?>" data-testid="card-low">
                    <p class="text-xs font-semibold <?= $low_balance > 0 ? 'text-orange-600' : 'text-gray-500' ?> uppercase mb-1">Low Balance</p>
                    <p class="text-2xl font-bold <?= $low_balance > 0 ? 'text-orange-600' : 'text-gray-900' ?>"><?= $low_balance ?: '0' ?></p>
                    <p class="text-xs text-gray-400 mt-1">SIMs below 5 <?= $currency_sym ?></p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'cards'   => ['fas fa-sim-card',       'SIM Cards'],
                    'cdr'     => ['fas fa-list-alt',        'CDR Records'],
                    'sms'     => ['fas fa-sms',             'Send SMS'],
                    'recharge'=> ['fas fa-bolt',            'Recharge'],
                    'packets' => ['fas fa-box-open',        'Data Packets'],
                    'account' => ['fas fa-user-circle',     'Account'],
                    'settings'=> ['fas fa-cog',             'Settings'],
                ];
                foreach ($tabs as $t => [$icon, $label]):
                    $active = $tab === $t;
                ?>
                <a href="?tab=<?= $t ?>"
                   class="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition <?= $active ? 'bg-white text-emerald-700 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                   data-testid="tab-<?= $t ?>">
                    <i class="<?= $icon ?> text-xs"></i><?= $label ?>
                    <?php if ($t === 'cards' && $blocked_count > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-red-500 text-white rounded-full text-[10px] font-bold"><?= $blocked_count ?></span>
                    <?php elseif ($t === 'cards' && $low_balance > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-orange-500 text-white rounded-full text-[10px] font-bold"><?= $low_balance ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$ts_connected && $tab !== 'settings'): ?>
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-6 text-center">
                <i class="fas fa-sim-card text-emerald-300 text-4xl mb-3"></i>
                <h3 class="text-base font-semibold text-emerald-800 mb-2">TravelSim Credentials Required</h3>
                <p class="text-sm text-emerald-600 mb-4">Add your TravelSim XML API username and password to manage SIM cards, CDRs, recharges, and data packets.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fas fa-key mr-2"></i>Configure Credentials
                </a>
            </div>
            <?php else: ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SIM CARDS TAB                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'cards'): ?>

            <!-- Low balance alert -->
            <?php if ($low_balance > 0): ?>
            <div class="mb-4 bg-orange-50 border border-orange-200 rounded-lg p-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-orange-500"></i>
                    <span class="text-sm font-semibold text-orange-700"><?= $low_balance ?> SIM card(s) have balance below 5 <?= $currency_sym ?> — consider recharging.</span>
                    <a href="?tab=recharge" class="ml-auto text-xs text-orange-600 hover:underline font-medium">Go to Recharge →</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-sim-card text-emerald-500 mr-2"></i>SIM Card Portfolio</h2>
                    <div class="flex items-center gap-3">
                        <input type="search" id="sim-search" placeholder="Filter by number…" onkeyup="filterSims()"
                            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 w-48" data-testid="input-sim-search">
                        <span class="text-xs text-gray-400"><?= $card_count ?> SIMs</span>
                    </div>
                </div>

                <?php if (empty($cards) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-sim-card text-4xl mb-3"></i>
                    <p class="font-medium">No SIM cards found in this account.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-sims">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SMS / oNum</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Mobile / iNum</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SIM ID</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Balance</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="sim-tbody">
                            <?php foreach ($cards as $c):
                                $onum    = $c['onum']    ?? '—';
                                $inum    = $c['inum']    ?? '—';
                                $tsimid  = $c['tsimid']  ?? '—';
                                $balance = (float)($c['balance'] ?? 0);
                                $curr    = $c['curr']    ?? 'USD';
                                $blocked = ($c['blocked'] ?? '') === 'true';
                                $prepaid = ($c['prepayed'] ?? 'true') === 'true';
                                $bal_class = $balance < 5 ? 'text-orange-600 font-bold' : ($balance >= 20 ? 'text-green-600' : 'text-gray-900');
                            ?>
                            <tr class="hover:bg-gray-50 sim-row" data-testid="row-sim-<?= htmlspecialchars($onum) ?>">
                                <td class="px-4 py-3">
                                    <p class="font-mono font-semibold text-gray-900 sim-onum"><?= htmlspecialchars($onum) ?></p>
                                    <span class="text-xs text-gray-400"><?= $prepaid ? 'Prepaid' : 'Postpaid' ?></span>
                                </td>
                                <td class="px-4 py-3 font-mono text-gray-600 text-sm"><?= htmlspecialchars($inum) ?></td>
                                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars((string)$tsimid) ?></td>
                                <td class="px-4 py-3 text-right font-semibold <?= $bal_class ?>">
                                    <?= number_format($balance, 2) ?> <?= htmlspecialchars($curr) ?>
                                    <?php if ($balance < 5): ?>
                                    <span class="block text-xs text-orange-400">Low</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($blocked): ?>
                                    <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs font-medium">Blocked</span>
                                    <?php else: ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <!-- Block/Unblock -->
                                        <form method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="block_sim">
                                            <input type="hidden" name="onum" value="<?= htmlspecialchars($onum) ?>">
                                            <input type="hidden" name="block" value="<?= $blocked ? 0 : 1 ?>">
                                            <button type="submit"
                                                class="text-xs font-medium <?= $blocked ? 'text-green-600 hover:text-green-800' : 'text-red-400 hover:text-red-600' ?>"
                                                onclick="return confirm('<?= $blocked ? 'Unblock' : 'Block' ?> SIM <?= htmlspecialchars(addslashes($onum)) ?>?')"
                                                data-testid="button-block-<?= htmlspecialchars($onum) ?>">
                                                <i class="fas <?= $blocked ? 'fa-lock-open' : 'fa-lock' ?> mr-1"></i><?= $blocked ? 'Unblock' : 'Block' ?>
                                            </button>
                                        </form>
                                        <!-- CDR link -->
                                        <a href="?tab=cdr&onum=<?= urlencode($onum) ?>" class="text-xs text-blue-500 hover:text-blue-700 font-medium" data-testid="link-cdr-<?= htmlspecialchars($onum) ?>">
                                            <i class="fas fa-list-alt mr-1"></i>CDR
                                        </a>
                                        <!-- Packets link -->
                                        <a href="?tab=packets&onum=<?= urlencode($onum) ?>" class="text-xs text-purple-500 hover:text-purple-700 font-medium">
                                            <i class="fas fa-box-open mr-1"></i>Packets
                                        </a>
                                        <!-- Send SMS -->
                                        <button type="button" onclick="openSmsModal('<?= htmlspecialchars(addslashes($onum)) ?>')"
                                            class="text-xs text-emerald-500 hover:text-emerald-700 font-medium" data-testid="button-sms-<?= htmlspecialchars($onum) ?>">
                                            <i class="fas fa-sms mr-1"></i>SMS
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick SMS modal -->
            <div id="sms-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-sms text-emerald-500 mr-2"></i>Send SMS</h3>
                    <form method="POST" id="sms-quick-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_sms">
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">From SIM (oNum)</label>
                                <input type="text" name="onum" id="sms-from" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" readonly data-testid="input-sms-from">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">To Number</label>
                                <input type="text" name="dest" required placeholder="+1234567890"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" data-testid="input-sms-dest">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                                <textarea name="message" required rows="3" maxlength="160" placeholder="Type your SMS message…"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" data-testid="input-sms-message"></textarea>
                            </div>
                        </div>
                        <div class="flex gap-3 justify-end mt-4">
                            <button type="button" onclick="closeSmsModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-send-sms">
                                <i class="fas fa-paper-plane mr-1"></i>Send
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
            $cdr_onum = $_GET['onum'] ?? '';
            ?>
            <!-- Filter bar -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-filter text-gray-400 mr-2"></i>Filter CDR Records</h3>
                <form method="GET" class="flex gap-3 flex-wrap items-end">
                    <input type="hidden" name="tab" value="cdr">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">From</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($cdr_from) ?>"
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="input-cdr-from">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">To</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($cdr_to) ?>"
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="input-cdr-to">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">SIM (oNum) — optional</label>
                        <input type="text" name="onum" value="<?= htmlspecialchars($cdr_onum) ?>" placeholder="All SIMs"
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono w-44 focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="input-cdr-onum">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-load-cdr">
                        <i class="fas fa-search mr-1"></i>Load Records
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-list-alt text-blue-500 mr-2"></i>Call &amp; SMS Records</h2>
                    <span class="text-xs text-gray-400"><?= count($cdrs) ?> records · <?= $cdr_from ?> → <?= $cdr_to ?></span>
                </div>
                <?php if (empty($cdrs) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-list-alt text-4xl mb-3"></i>
                    <p class="font-medium">No CDR records found for this date range.</p>
                    <p class="text-sm mt-1">Try adjusting the date range or selecting a specific SIM.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-cdr">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date/Time</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SIM (oNum)</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Number</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Duration / Chars</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($cdrs as $cdr):
                                $dt    = $cdr['calldate'] ?? $cdr['date'] ?? '—';
                                $onum  = $cdr['onum']     ?? '—';
                                $type  = strtoupper($cdr['cdir'] ?? $cdr['type'] ?? '—');
                                $num   = $cdr['dialnumber'] ?? $cdr['number'] ?? '—';
                                $dur   = $cdr['duration']  ?? $cdr['smscount'] ?? '—';
                                $cost  = $cdr['cost']      ?? $cdr['amount']   ?? '—';
                                $curr  = $cdr['curr']      ?? '';
                                $type_icon  = $type === 'O' ? 'fa-phone-alt text-green-500' : ($type === 'I' ? 'fa-phone-incoming text-blue-500' : 'fa-sms text-purple-500');
                                $type_label = $type === 'O' ? 'Outbound' : ($type === 'I' ? 'Inbound' : $type);
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-cdr">
                                <td class="px-4 py-3 text-xs text-gray-500 font-mono"><?= htmlspecialchars($dt) ?></td>
                                <td class="px-4 py-3 font-mono text-gray-600"><?= htmlspecialchars($onum) ?></td>
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-1.5 text-xs font-medium">
                                        <i class="fas <?= $type_icon ?>"></i><?= htmlspecialchars($type_label) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-gray-700"><?= htmlspecialchars($num) ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars((string)$dur) ?><?= is_numeric($dur) && (int)$dur > 60 ? ' (' . floor((int)$dur/60) . 'm' . ((int)$dur%60) . 's)' : '' ?></td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900"><?= is_numeric($cost) ? number_format((float)$cost, 4) : htmlspecialchars((string)$cost) ?> <?= htmlspecialchars($curr) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SEND SMS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'sms'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- SMS2: From any SIM to any number -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-paper-plane text-emerald-500 mr-2"></i>Send SMS from TravelSim Number</h3>
                    <p class="text-sm text-gray-500 mb-4">Send an SMS from any TravelSim SIM (oNum) to any destination number. Uses the <code class="bg-gray-100 px-1 rounded text-xs">SMS2</code> command.</p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_sms">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From SIM (oNum — SMS number)</label>
                            <select name="onum" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="select-sms-from">
                                <option value="">— Select SIM —</option>
                                <?php foreach ($cards as $c): $on = $c['onum'] ?? ''; if (!$on) continue; ?>
                                <option value="<?= htmlspecialchars($on) ?>"><?= htmlspecialchars($on) ?> (<?= number_format((float)($c['balance']??0),2) ?> <?= $c['curr']??'' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="onum_manual" placeholder="Or enter manually: 37254515257"
                                class="mt-2 w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">To Number (international format)</label>
                            <input type="text" name="dest" required placeholder="+1 702 555 0199"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="input-sms-dest">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                            <textarea name="message" required rows="4" maxlength="160"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                placeholder="Your message (max 160 characters)…"
                                oninput="document.getElementById('sms-char-count').textContent=this.value.length"
                                data-testid="input-sms-message"></textarea>
                            <p class="text-xs text-gray-400 mt-1 text-right"><span id="sms-char-count">0</span> / 160</p>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-send">
                            <i class="fas fa-paper-plane mr-2"></i>Send SMS
                        </button>
                    </form>
                </div>

                <!-- Call redirect -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-exchange-alt text-blue-500 mr-2"></i>Unconditional Call Redirect</h3>
                    <p class="text-sm text-gray-500 mb-4">Forward all incoming calls on a SIM card to another number (ENUM diversion number). Uses the <code class="bg-gray-100 px-1 rounded text-xs">REDIRECT</code> command.</p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_redirect">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">SIM (oNum)</label>
                            <select name="onum" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="select-redirect-sim">
                                <option value="">— Select SIM —</option>
                                <?php foreach ($cards as $c): $on = $c['onum'] ?? ''; if (!$on) continue; ?>
                                <option value="<?= htmlspecialchars($on) ?>"><?= htmlspecialchars($on) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Redirect Type</label>
                            <select name="rtype" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-redirect-type" onchange="document.getElementById('divnum-row').classList.toggle('hidden',this.value==='off')">
                                <option value="on">Enable redirect</option>
                                <option value="off">Disable redirect</option>
                            </select>
                        </div>
                        <div id="divnum-row">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Forward-To Number (ENUM / divnum)</label>
                            <input type="text" name="divnum" placeholder="Destination number"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-divnum">
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-set-redirect">
                            <i class="fas fa-exchange-alt mr-2"></i>Apply Redirect
                        </button>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- RECHARGE TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'recharge'): ?>
            <?php
            $rch_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
            $rch_to   = $_GET['to']   ?? date('Y-m-d');
            ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Recharge form -->
                <div class="bg-white rounded-lg border border-gray-200 p-6 self-start">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-bolt text-yellow-500 mr-2"></i>Recharge with PIN</h3>
                    <p class="text-sm text-gray-500 mb-4">Enter a 16-digit recharge PIN to top up a SIM card balance. Uses the <code class="bg-gray-100 px-1 rounded text-xs">RECHARGE</code> command.</p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="recharge_pin">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">SIM (oNum)</label>
                            <select name="onum" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500" data-testid="select-recharge-sim">
                                <option value="">— Select SIM —</option>
                                <?php foreach ($cards as $c): $on = $c['onum'] ?? ''; if (!$on) continue; ?>
                                <option value="<?= htmlspecialchars($on) ?>"><?= htmlspecialchars($on) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Recharge PIN (16 digits)</label>
                            <input type="text" name="pin" required pattern="[0-9]{16}" maxlength="16" placeholder="1234567890123456"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                data-testid="input-recharge-pin">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Order ID (increment each time)</label>
                            <input type="number" name="orderid" value="<?= time() ?>" min="1"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500" data-testid="input-orderid">
                            <p class="text-xs text-gray-400 mt-1">Must be unique per transaction to prevent duplicate recharges.</p>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg text-sm font-medium transition" data-testid="button-recharge">
                            <i class="fas fa-bolt mr-2"></i>Recharge SIM
                        </button>
                    </form>

                    <div class="mt-5 pt-5 border-t border-gray-100">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-exchange-alt text-purple-500 mr-2"></i>Transfer Balance</h4>
                        <form method="POST" class="space-y-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="transfer_balance">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">To SIM (oNum)</label>
                                <input type="text" name="dest_onum" placeholder="Destination SIM number"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" data-testid="input-transfer-dest">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Amount</label>
                                    <input type="text" name="amount" placeholder="10.00"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-transfer-amount">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">Currency</label>
                                    <input type="text" name="currency" value="USD" maxlength="3"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" data-testid="input-transfer-currency">
                                </div>
                            </div>
                            <input type="hidden" name="orderid" value="<?= time() ?>">
                            <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-transfer">
                                <i class="fas fa-exchange-alt mr-2"></i>Transfer
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Recharge history -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-history text-gray-500 mr-2"></i>Recharge History</h3>
                        <form method="GET" class="flex gap-2 flex-wrap">
                            <input type="hidden" name="tab" value="recharge">
                            <input type="date" name="from" value="<?= htmlspecialchars($rch_from) ?>"
                                class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <input type="date" name="to" value="<?= htmlspecialchars($rch_to) ?>"
                                class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition">
                                <i class="fas fa-filter mr-1"></i>Filter
                            </button>
                        </form>
                    </div>
                    <?php if (empty($recharge_log) && !$api_error): ?>
                    <div class="p-10 text-center text-gray-400">
                        <i class="fas fa-bolt text-4xl mb-3"></i>
                        <p class="font-medium">No recharge records found.</p>
                        <p class="text-sm mt-1">Try a wider date range.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="table-recharges">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SIM</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PIN</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($recharge_log as $r):
                                    $rdate  = $r['date']    ?? $r['calldate'] ?? '—';
                                    $ronum  = $r['onum']    ?? '—';
                                    $rpin   = $r['pin']     ?? '—';
                                    $ramt   = $r['amount']  ?? '—';
                                    $rcurr  = $r['curr']    ?? '';
                                    $rst    = $r['status']  ?? 'success';
                                ?>
                                <tr class="hover:bg-gray-50" data-testid="row-recharge">
                                    <td class="px-4 py-3 text-xs font-mono text-gray-500"><?= htmlspecialchars($rdate) ?></td>
                                    <td class="px-4 py-3 font-mono text-gray-700"><?= htmlspecialchars($ronum) ?></td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= htmlspecialchars($rpin) ?></td>
                                    <td class="px-4 py-3 text-right font-semibold text-green-700"><?= is_numeric($ramt) ? number_format((float)$ramt, 2) : htmlspecialchars((string)$ramt) ?> <?= htmlspecialchars($rcurr) ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium"><?= htmlspecialchars(ucfirst($rst)) ?></span>
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
            <!-- DATA PACKETS TAB                                           -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'packets'): ?>
            <?php $pkt_onum = $_GET['onum'] ?? ''; ?>

            <!-- SIM selector -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-filter text-gray-400 mr-2"></i>Select SIM Card</h3>
                <form method="GET" class="flex gap-3 flex-wrap">
                    <input type="hidden" name="tab" value="packets">
                    <select name="onum" onchange="this.form.submit()"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" data-testid="select-packet-sim">
                        <option value="">— Select SIM to view packets —</option>
                        <?php foreach ($cards as $c): $on = $c['onum'] ?? ''; if (!$on) continue; ?>
                        <option value="<?= htmlspecialchars($on) ?>" <?= $pkt_onum === $on ? 'selected' : '' ?>><?= htmlspecialchars($on) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($pkt_onum): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Active packets -->
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-check-circle text-green-500 mr-2"></i>Active Packets — <?= htmlspecialchars($pkt_onum) ?></h3>
                    </div>
                    <?php if (empty($packets_active)): ?>
                    <div class="p-8 text-center text-gray-400">
                        <i class="fas fa-box-open text-3xl mb-2"></i>
                        <p class="text-sm font-medium">No active packets on this SIM.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($packets_active as $pkt):
                            $pid   = $pkt['id']   ?? $pkt['packetid'] ?? '—';
                            $pname = $pkt['name']  ?? $pkt['description'] ?? "Packet $pid";
                            $pexp  = $pkt['expiry'] ?? $pkt['enddate'] ?? '—';
                            $ptype = strtolower($pkt['type'] ?? '');
                            $col   = $ptype === 'gprs' ? 'blue' : ($ptype === 'voice' ? 'green' : ($ptype === 'sms' ? 'purple' : 'gray'));
                            $cols  = ['blue'=>'bg-blue-100 text-blue-700','green'=>'bg-green-100 text-green-700','purple'=>'bg-purple-100 text-purple-700','gray'=>'bg-gray-100 text-gray-600'];
                        ?>
                        <div class="px-5 py-3 flex items-center gap-4" data-testid="row-packet-<?= htmlspecialchars((string)$pid) ?>">
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($pname) ?></p>
                                <p class="text-xs text-gray-400 mt-0.5">ID: <?= htmlspecialchars((string)$pid) ?><?= $pexp !== '—' ? " · Expires: $pexp" : '' ?></p>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $cols[$col] ?>"><?= ucfirst($ptype ?: 'packet') ?></span>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deactivate_packet">
                                <input type="hidden" name="onum" value="<?= htmlspecialchars($pkt_onum) ?>">
                                <input type="hidden" name="packet" value="<?= htmlspecialchars((string)$pid) ?>">
                                <button type="submit" class="text-xs text-red-400 hover:text-red-600 font-medium" onclick="return confirm('Deactivate this packet?')"
                                    data-testid="button-deactivate-<?= htmlspecialchars((string)$pid) ?>">
                                    <i class="fas fa-times mr-1"></i>Deactivate
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Available packets -->
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-store text-purple-500 mr-2"></i>Available Packets</h3>
                    </div>
                    <?php if (empty($available_packets)): ?>
                    <div class="p-8 text-center text-gray-400">
                        <i class="fas fa-box text-3xl mb-2"></i>
                        <p class="text-sm font-medium">No packages available for this SIM.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($available_packets as $pkt):
                            $pid   = $pkt['id']   ?? $pkt['packetid'] ?? '—';
                            $pname = $pkt['name']  ?? $pkt['description'] ?? "Packet $pid";
                            $pprice = $pkt['price'] ?? $pkt['cost'] ?? null;
                            $ptype = strtolower($pkt['type'] ?? '');
                            $col   = $ptype === 'gprs' ? 'blue' : ($ptype === 'voice' ? 'green' : ($ptype === 'sms' ? 'purple' : 'gray'));
                            $cols  = ['blue'=>'bg-blue-100 text-blue-700','green'=>'bg-green-100 text-green-700','purple'=>'bg-purple-100 text-purple-700','gray'=>'bg-gray-100 text-gray-600'];
                        ?>
                        <div class="px-5 py-3 flex items-center gap-4" data-testid="row-avail-<?= htmlspecialchars((string)$pid) ?>">
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($pname) ?></p>
                                <p class="text-xs text-gray-400 mt-0.5">ID: <?= htmlspecialchars((string)$pid) ?><?= $pprice ? " · $pprice" : '' ?></p>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $cols[$col] ?>"><?= ucfirst($ptype ?: 'packet') ?></span>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="activate_packet">
                                <input type="hidden" name="onum" value="<?= htmlspecialchars($pkt_onum) ?>">
                                <input type="hidden" name="packet" value="<?= htmlspecialchars((string)$pid) ?>">
                                <button type="submit" class="text-xs text-purple-600 hover:text-purple-800 font-medium"
                                    data-testid="button-activate-<?= htmlspecialchars((string)$pid) ?>">
                                    <i class="fas fa-plus mr-1"></i>Activate
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg border border-gray-200 p-10 text-center text-gray-400">
                <i class="fas fa-box-open text-4xl mb-3"></i>
                <p class="font-medium">Select a SIM card above to view and manage its data packets.</p>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ACCOUNT TAB                                                -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'account'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Account info -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-user-circle text-emerald-500 mr-2"></i>Account Information</h3>
                    <?php if (!empty($account_info) && !isset($account_info['error'])): ?>
                    <div class="space-y-3">
                        <?php foreach ($account_info as $k => $v):
                            if (str_starts_with((string)$k, '_') || !is_scalar($v)) continue;
                        ?>
                        <div class="flex gap-3 text-sm border-b border-gray-50 pb-2">
                            <span class="text-gray-400 w-36 shrink-0 capitalize"><?= htmlspecialchars(str_replace('_',' ',(string)$k)) ?></span>
                            <span class="text-gray-800 font-medium"><?= htmlspecialchars((string)$v) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400">No account data returned. Check credentials or API permissions.</p>
                    <?php endif; ?>
                </div>

                <!-- API Permissions -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-shield-alt text-blue-500 mr-2"></i>API Permissions</h3>
                    <?php if (!empty($permissions) && !isset($permissions['error'])): ?>
                    <div class="space-y-2">
                        <?php foreach ($permissions as $k => $v):
                            if (str_starts_with((string)$k, '_') || !is_scalar($v)) continue;
                            $enabled = in_array(strtolower((string)$v), ['1','true','yes','allowed','enabled']);
                        ?>
                        <div class="flex items-center gap-3 px-3 py-2 bg-gray-50 rounded-lg">
                            <i class="fas <?= $enabled ? 'fa-check-circle text-green-500' : 'fa-times-circle text-red-400' ?>"></i>
                            <span class="text-sm text-gray-700 flex-1 capitalize"><?= htmlspecialchars(str_replace(['_','-'],' ',(string)$k)) ?></span>
                            <span class="text-xs <?= $enabled ? 'text-green-600' : 'text-red-500' ?> font-medium"><?= htmlspecialchars((string)$v) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400">No permission data returned.</p>
                    <?php endif; ?>
                    <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                        <p class="text-xs text-blue-700">Permissions are configured by TravelSim support for each API username. Contact TravelSim to enable additional XML commands.</p>
                    </div>
                </div>

                <!-- Quick command reference -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-code text-gray-500 mr-2"></i>XML Command Reference</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php foreach ([
                            ['gbalance',      'emerald', 'Get Balance',          'Returns balance and card info for all or one SIM'],
                            ['card_stat',     'blue',    'Card Status',          'Full card info including services, ICCID, MSISDN'],
                            ['gccdr',         'indigo',  'Call / SMS CDR',       'Call and SMS records between two dates'],
                            ['GPRSCDR',       'cyan',    'GPRS CDR',             'Data session records for a SIM card'],
                            ['SBLOCK',        'red',     'Block / Unblock',      'Block or unblock a SIM card immediately'],
                            ['SMS2',          'emerald', 'Send SMS',             'Send SMS from TravelSim number to any destination'],
                            ['REDIRECT',      'orange',  'Call Redirect',        'Set or clear unconditional call forwarding'],
                            ['DISCOUNT',      'purple',  'Data Packets',         'List, activate, and deactivate discount packets'],
                            ['RECHARGE',      'yellow',  'Recharge PIN',         'Top up a SIM balance using a 16-digit PIN'],
                            ['SBALANCE',      'violet',  'Transfer Balance',     'Move credit between your reseller account and a SIM'],
                            ['SVMAIL',        'gray',    'Voicemail',            'Activate, configure, and manage voicemail'],
                            ['RECHARGEREPORT','green',   'Recharge Report',      'View recharge history between two dates'],
                            ['ACCOUNT',       'blue',    'Account Info',         'Retrieve account-level details and balance'],
                            ['GETPERMISSIONS','slate',   'API Permissions',      'List which XML commands your user is authorized to use'],
                            ['SGPRS',         'cyan',    'GPRS Settings',        'Configure GPRS/APN settings for a SIM card'],
                        ] as [$cmd,$color,$title,$desc]):
                            $bg = ['emerald'=>'bg-emerald-50 border-emerald-200','blue'=>'bg-blue-50 border-blue-200','indigo'=>'bg-indigo-50 border-indigo-200','cyan'=>'bg-cyan-50 border-cyan-200','red'=>'bg-red-50 border-red-200','orange'=>'bg-orange-50 border-orange-200','purple'=>'bg-purple-50 border-purple-200','yellow'=>'bg-yellow-50 border-yellow-200','violet'=>'bg-violet-50 border-violet-200','gray'=>'bg-gray-50 border-gray-200','green'=>'bg-green-50 border-green-200','slate'=>'bg-slate-50 border-slate-200'];
                            $tc = ['emerald'=>'text-emerald-700','blue'=>'text-blue-700','indigo'=>'text-indigo-700','cyan'=>'text-cyan-700','red'=>'text-red-700','orange'=>'text-orange-700','purple'=>'text-purple-700','yellow'=>'text-yellow-700','violet'=>'text-violet-700','gray'=>'text-gray-600','green'=>'text-green-700','slate'=>'text-slate-600'];
                        ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg border <?= $bg[$color] ?>">
                            <code class="text-xs font-bold <?= $tc[$color] ?> bg-white/70 px-1.5 py-0.5 rounded shrink-0"><?= $cmd ?></code>
                            <div>
                                <p class="text-sm font-semibold text-gray-800"><?= $title ?></p>
                                <p class="text-xs text-gray-500 mt-0.5"><?= $desc ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
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
                        Your TravelSim XML API username and password. Contact TravelSim support (or log in at
                        <a href="https://www.travelsim.com" target="_blank" class="text-emerald-500 hover:underline">travelsim.com</a>) to obtain API access. Your IP address must be whitelisted (max 5 per distributor).
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_credentials">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Username (uname)</label>
                            <input type="text" name="ts_uname" value="<?= htmlspecialchars($ts_uname) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                placeholder="Your TravelSim API username" autocomplete="off" data-testid="input-ts-uname">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Password (upass)</label>
                            <input type="password" name="ts_upass" value="<?= htmlspecialchars($ts_upass) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                placeholder="••••••••" autocomplete="off" data-testid="input-ts-upass">
                            <p class="text-xs text-gray-400 mt-1"><?= $ts_connected ? '✓ Credentials stored.' : 'No credentials saved yet.' ?></p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="flex-1 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-creds">
                                <i class="fas fa-save mr-2"></i>Save Credentials
                            </button>
                            <?php if ($ts_connected): ?>
                            <form method="POST" class="flex-1 m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test_connection">
                                <button type="submit" class="w-full px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition" data-testid="button-test-creds">
                                    <i class="fas fa-plug mr-2"></i>Test Connection
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- About & links -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About TravelSim XML API</h3>
                    <p class="text-sm text-gray-600 mb-4">
                        The TravelSim Exchange Interface is an XML-based API accessed over SSL at <code class="bg-gray-100 px-1 rounded text-xs">xml2.travelsim.com/tsim_xml/service/xmlgate</code>.
                        All requests require <code class="bg-gray-100 px-1 rounded text-xs">uname</code>, <code class="bg-gray-100 px-1 rounded text-xs">upass</code>, and <code class="bg-gray-100 px-1 rounded text-xs">command</code> parameters.
                        Adding <code class="bg-gray-100 px-1 rounded text-xs">plain=1</code> returns plaintext values instead of Base64.
                    </p>
                    <div class="space-y-2 mb-4">
                        <a href="https://www.travelsim.com" target="_blank" class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-sim-card text-emerald-500 w-4"></i><span>TravelSim Website</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://xml2.travelsim.com/tsim_xml/service/xmlgate?plain=1&command=GETPERMISSIONS&uname=<?= urlencode($ts_uname) ?>&upass=<?= urlencode($ts_upass) ?>" target="_blank"
                            class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700 <?= !$ts_connected ? 'opacity-50 pointer-events-none' : '' ?>">
                            <i class="fas fa-shield-alt text-emerald-500 w-4"></i><span>Check API Permissions</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                    </div>
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg mb-3">
                        <p class="text-xs text-amber-700"><strong>IP Whitelist required:</strong> TravelSim restricts XML API access to up to 5 whitelisted IPs per distributor. Ensure your server's outbound IP is registered with TravelSim support before connecting.</p>
                    </div>
                    <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-lg">
                        <p class="text-xs text-emerald-700 font-semibold mb-1">Env variables (optional)</p>
                        <p class="text-xs text-emerald-600">Set <code class="bg-emerald-100 px-1 rounded">TRAVELSIM_UNAME</code> and <code class="bg-emerald-100 px-1 rounded">TRAVELSIM_UPASS</code> as Replit secrets. They take priority over credentials saved in the database.</p>
                    </div>
                </div>

                <!-- Feature grid -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-purple-500 mr-2"></i>Integrated API Features</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php foreach ([
                            ['fas fa-sim-card',    'emerald', 'SIM Portfolio',       'View all SIM cards with real-time balances and status'],
                            ['fas fa-lock',        'red',     'Block / Unblock SIM', 'Immediately block or unblock any SIM card in your account'],
                            ['fas fa-list-alt',    'indigo',  'Call & SMS CDR',      'Retrieve call and SMS records by date range and SIM'],
                            ['fas fa-sms',         'emerald', 'Send SMS',            'Send SMS messages from any TravelSim number (SMS2)'],
                            ['fas fa-exchange-alt','orange',  'Call Redirect',       'Enable or disable unconditional call forwarding per SIM'],
                            ['fas fa-bolt',        'yellow',  'Recharge with PIN',   'Top up SIM balances using 16-digit recharge PINs'],
                            ['fas fa-transfer',    'purple',  'Balance Transfer',    'Transfer credit between your account and a SIM card'],
                            ['fas fa-box-open',    'purple',  'Data Packets',        'View, activate, and deactivate voice/SMS/GPRS discount packets'],
                            ['fas fa-history',     'green',   'Recharge History',    'View PIN recharge log filtered by date range'],
                            ['fas fa-user-circle', 'blue',    'Account Info',        'View account-level details from the ACCOUNT command'],
                            ['fas fa-shield-alt',  'blue',    'API Permissions',     'Check which XML commands your API user is authorized for'],
                            ['fas fa-wifi',        'cyan',    'GPRS / Data CDR',     'View data session records via GPRSCDR command'],
                        ] as [$icon,$color,$title,$desc]):
                            $bg=['emerald'=>'bg-emerald-50 border-emerald-200','red'=>'bg-red-50 border-red-200','indigo'=>'bg-indigo-50 border-indigo-200','orange'=>'bg-orange-50 border-orange-200','yellow'=>'bg-yellow-50 border-yellow-200','purple'=>'bg-purple-50 border-purple-200','green'=>'bg-green-50 border-green-200','blue'=>'bg-blue-50 border-blue-200','cyan'=>'bg-cyan-50 border-cyan-200'];
                            $ic=['emerald'=>'text-emerald-600','red'=>'text-red-600','indigo'=>'text-indigo-600','orange'=>'text-orange-500','yellow'=>'text-yellow-600','purple'=>'text-purple-600','green'=>'text-green-600','blue'=>'text-blue-600','cyan'=>'text-cyan-600'];
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

            <?php endif; // end $ts_connected ?>
        </div><!-- /p-6 -->
    </div><!-- /flex-1 -->
</div><!-- /flex -->

<script>
// SIM table filter
function filterSims() {
    const q = document.getElementById('sim-search').value.toLowerCase();
    document.querySelectorAll('#sim-tbody .sim-row').forEach(row => {
        const n = row.querySelector('.sim-onum')?.textContent.toLowerCase() || '';
        row.style.display = n.includes(q) ? '' : 'none';
    });
}

// Quick SMS modal
function openSmsModal(onum) {
    document.getElementById('sms-from').value = onum;
    document.getElementById('sms-modal').classList.remove('hidden');
}
function closeSmsModal() {
    document.getElementById('sms-modal').classList.add('hidden');
}
document.getElementById('sms-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSmsModal();
});

// Redirect form: hide divnum when disabling
document.querySelector('[data-testid="select-redirect-type"]')?.addEventListener('change', function() {
    document.getElementById('divnum-row').classList.toggle('hidden', this.value === 'off');
});
</script>
</body>
</html>
