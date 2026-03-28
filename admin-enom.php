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
$en_uid = ENOM_UID;
$en_pw  = ENOM_PW;
if (empty($en_uid)) {
    try {
        $r = $pdo->prepare("SELECT key_name, key_value FROM provider_settings WHERE provider='enom'");
        $r->execute();
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key_name'] === 'uid') $en_uid = $row['key_value'];
            if ($row['key_name'] === 'pw')  $en_pw  = $row['key_value'];
        }
    } catch (Exception $e) {}
}

$en_connected = !empty($en_uid) && !empty($en_pw);
$en_base      = rtrim(ENOM_API_URL, '/');

$success_msg = '';
$error_msg   = '';
$tab         = $_GET['tab'] ?? 'domains';

// ── Core API helper ────────────────────────────────────────────────────────────
function enom_api(array $params): array {
    global $en_uid, $en_pw, $en_base;
    $params = array_merge([
        'UID'          => $en_uid,
        'PW'           => $en_pw,
        'responsetype' => 'JSON',
    ], $params);
    $url = $en_base . '?' . http_build_query($params);
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => $err];
    $d = json_decode($resp, true);
    if ($d === null) {
        // Enom sometimes returns plain-text; parse it
        $lines = [];
        foreach (explode("\n", $resp) as $line) {
            $kv = explode('=', trim($line), 2);
            if (count($kv) === 2) $lines[strtolower($kv[0])] = $kv[1];
        }
        if (!empty($lines)) return array_merge(['http_code' => $code], $lines);
        return ['error' => "Unexpected response (HTTP $code): " . substr($resp,0,200)];
    }
    $errcode = $d['ErrCount'] ?? 0;
    if ($errcode > 0) {
        $msg = $d['errors']['Err1'] ?? $d['Err1'] ?? $d['RRPCode'] ?? "Enom error";
        return ['error' => $msg, 'raw' => $d];
    }
    return array_merge(['http_code' => $code], $d);
}

// ── POST action handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // Save credentials
        if ($action === 'save_credentials') {
            $uid = trim($_POST['enom_uid'] ?? '');
            $pw  = trim($_POST['enom_pw']  ?? '');
            try {
                foreach (['uid' => $uid, 'pw' => $pw] as $k => $v) {
                    $pdo->prepare("INSERT INTO provider_settings (provider,key_name,key_value) VALUES ('enom',?,?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")
                        ->execute([$k, $v]);
                }
                $en_uid = $uid; $en_pw = $pw;
                $en_connected = !empty($uid) && !empty($pw);
                $success_msg = 'Enom credentials saved.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
            $tab = 'settings';

        // Test connection
        } elseif ($action === 'test_connection') {
            $res = enom_api(['command' => 'GetAccountBalance']);
            if (isset($res['error'])) {
                $error_msg = 'Connection failed: ' . $res['error'];
            } else {
                $bal = $res['Balance'] ?? $res['balance'] ?? 'unknown';
                $success_msg = "Connected! Account balance: \$$bal";
            }

        // Check domain availability
        } elseif ($action === 'check_domain') {
            $domain = trim($_POST['domain'] ?? '');
            if (!$domain) {
                $error_msg = 'Please enter a domain name.';
            } else {
                $parts = explode('.', $domain, 2);
                $sld = $parts[0];
                $tld = $parts[1] ?? 'com';
                $res = enom_api(['command' => 'Check', 'SLD' => $sld, 'TLD' => $tld]);
                if (isset($res['error'])) {
                    $error_msg = 'Check failed: ' . $res['error'];
                } else {
                    $avail = $res['RRPCode'] ?? $res['rrpcode'] ?? '';
                    if ($avail == '210') {
                        $success_msg = "✓ $domain is AVAILABLE! You can register it below.";
                    } elseif ($avail == '211') {
                        $error_msg = "✗ $domain is already TAKEN.";
                    } else {
                        $success_msg = "Domain check result for $domain: Code $avail";
                    }
                }
            }
            $tab = 'register';

        // Register domain
        } elseif ($action === 'register_domain') {
            $sld   = trim($_POST['sld'] ?? '');
            $tld   = trim($_POST['tld'] ?? 'com');
            $years = (int)($_POST['num_years'] ?? 1);
            if (!$sld) {
                $error_msg = 'Domain name (SLD) is required.';
            } else {
                $res = enom_api([
                    'command'  => 'Purchase',
                    'SLD'      => $sld,
                    'TLD'      => $tld,
                    'NumYears' => $years,
                    'UseDNS'   => 'default',
                ]);
                if (isset($res['error'])) {
                    $error_msg = "Registration failed: " . $res['error'];
                } else {
                    $success_msg = "$sld.$tld registered for $years year(s)! Check your domain list.";
                }
            }
            $tab = 'register';

        // Renew domain
        } elseif ($action === 'renew_domain') {
            $sld   = trim($_POST['sld'] ?? '');
            $tld   = trim($_POST['tld'] ?? '');
            $years = (int)($_POST['num_years'] ?? 1);
            $res   = enom_api(['command' => 'Renew', 'SLD' => $sld, 'TLD' => $tld, 'NumYears' => $years]);
            if (isset($res['error'])) {
                $error_msg = "Renewal failed for $sld.$tld: " . $res['error'];
            } else {
                $success_msg = "$sld.$tld renewed for $years year(s).";
            }
            $tab = 'domains';

        // Toggle domain lock
        } elseif ($action === 'toggle_lock') {
            $sld    = trim($_POST['sld'] ?? '');
            $tld    = trim($_POST['tld'] ?? '');
            $unlock = (int)($_POST['unlock'] ?? 0);
            $res    = enom_api(['command' => 'SetDomainLocking', 'SLD' => $sld, 'TLD' => $tld, 'UnlockDomain' => $unlock]);
            if (isset($res['error'])) {
                $error_msg = "Lock toggle failed: " . $res['error'];
            } else {
                $success_msg = "$sld.$tld " . ($unlock ? 'unlocked' : 'locked') . " successfully.";
            }
            $tab = 'domains';

        // Toggle auto-renew
        } elseif ($action === 'toggle_autorenew') {
            $sld  = trim($_POST['sld'] ?? '');
            $tld  = trim($_POST['tld'] ?? '');
            $auto = (int)($_POST['auto'] ?? 0);
            $res  = enom_api(['command' => 'SetAutoRenew', 'SLD' => $sld, 'TLD' => $tld, 'AutoRenew' => $auto]);
            if (isset($res['error'])) {
                $error_msg = "Auto-renew toggle failed: " . $res['error'];
            } else {
                $success_msg = "Auto-renew " . ($auto ? 'enabled' : 'disabled') . " for $sld.$tld.";
            }
            $tab = 'domains';

        // Set nameservers
        } elseif ($action === 'set_nameservers') {
            $sld = trim($_POST['sld'] ?? '');
            $tld = trim($_POST['tld'] ?? '');
            $ns  = array_filter(array_map('trim', [
                $_POST['ns1'] ?? '', $_POST['ns2'] ?? '',
                $_POST['ns3'] ?? '', $_POST['ns4'] ?? '',
            ]));
            if (!$sld || empty($ns)) {
                $error_msg = 'Domain and at least one nameserver are required.';
            } else {
                $params = ['command' => 'ModifyNS', 'SLD' => $sld, 'TLD' => $tld];
                foreach (array_values($ns) as $i => $n) { $params['NS' . ($i+1)] = $n; }
                $res = enom_api($params);
                if (isset($res['error'])) {
                    $error_msg = "Nameserver update failed: " . $res['error'];
                } else {
                    $success_msg = "Nameservers updated for $sld.$tld.";
                }
            }
            $tab = 'dns';

        // Initiate transfer in
        } elseif ($action === 'transfer_domain') {
            $sld  = trim($_POST['sld'] ?? '');
            $tld  = trim($_POST['tld'] ?? '');
            $auth = trim($_POST['auth_code'] ?? '');
            if (!$sld || !$auth) {
                $error_msg = 'Domain and authorization code are required.';
            } else {
                $res = enom_api(['command' => 'TP_CreateOrder', 'SLD' => $sld, 'TLD' => $tld, 'AuthInfo' => $auth, 'NumYears' => 1]);
                if (isset($res['error'])) {
                    $error_msg = "Transfer failed: " . $res['error'];
                } else {
                    $success_msg = "Transfer order submitted for $sld.$tld. Check transfer status below.";
                }
            }
            $tab = 'transfer';
        }
    }
}

// ── Live data per tab ──────────────────────────────────────────────────────────
$domains       = [];
$balance       = null;
$expiring_soon = [];
$ns_info       = [];
$contacts      = [];
$transfers     = [];
$api_error     = '';

if ($en_connected) {

    // Balance always loaded for stat cards
    $bres = enom_api(['command' => 'GetAccountBalance']);
    if (!isset($bres['error'])) {
        $balance = $bres['Balance'] ?? $bres['balance'] ?? null;
    }

    if ($tab === 'domains') {
        $res = enom_api(['command' => 'GetDomains', 'MaxCount' => 100, 'PageSize' => 100]);
        if (isset($res['error'])) { $api_error = $res['error']; }
        else {
            $raw = $res['domains'] ?? $res['GetDomainsResult'] ?? [];
            if (isset($raw['Domain'])) $raw = is_array($raw['Domain'][0] ?? null) ? $raw['Domain'] : [$raw['Domain']];
            $domains = $raw;
        }

        // Expiring in next 30 days
        $now = time();
        foreach ($domains as $d) {
            $exp = strtotime($d['expdate'] ?? $d['ExpirationDate'] ?? '');
            if ($exp && $exp > $now && ($exp - $now) < 30 * 86400) {
                $expiring_soon[] = $d;
            }
        }

    } elseif ($tab === 'dns') {
        $sld = $_GET['sld'] ?? '';
        $tld = $_GET['tld'] ?? '';
        if ($sld && $tld) {
            $ns_res = enom_api(['command' => 'GetDNS', 'SLD' => $sld, 'TLD' => $tld]);
            if (!isset($ns_res['error'])) $ns_info = $ns_res;

            $ct_res = enom_api(['command' => 'GetContacts', 'SLD' => $sld, 'TLD' => $tld]);
            if (!isset($ct_res['error'])) $contacts = $ct_res;
        }

    } elseif ($tab === 'transfer') {
        $res = enom_api(['command' => 'TP_GetOrder', 'MaxItems' => 20]);
        if (!isset($res['error'])) {
            $raw = $res['transferorders'] ?? $res['TransferOrders'] ?? [];
            $transfers = is_array($raw) ? $raw : [];
        }
    }
}

$domain_count    = count($domains);
$expiring_count  = count($expiring_soon);
$locked_count    = 0;
$autorenew_count = 0;
foreach ($domains as $d) {
    if (($d['islocked'] ?? $d['IsLocked'] ?? '') == '1') $locked_count++;
    if (($d['AutoRenew'] ?? $d['autorenew'] ?? '') == '1') $autorenew_count++;
}

// Popular TLDs for registration
$tlds = ['com','net','org','io','co','info','biz','us','me','online','store','app','tech','cloud','dev'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enom Domains - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
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
                        <i class="fas fa-globe text-violet-500 mr-2"></i>Enom Domain Reseller
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">Domain registration, DNS management, renewals, transfers &amp; WHOIS</p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($en_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test-connection">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <a href="?tab=domains" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-refresh">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <a href="?tab=register" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-register">
                            <i class="fas fa-plus mr-1"></i>Register Domain
                        </a>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition">
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
                    <span>Enom API error: <strong><?= htmlspecialchars($api_error) ?></strong></span>
                </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($en_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">UID: <?= htmlspecialchars(substr($en_uid,0,6)).'…' ?></p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Credentials not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-balance">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Account Balance</p>
                    <p class="text-2xl font-bold text-violet-700" data-testid="text-balance"><?= $balance !== null ? '$'.number_format((float)$balance,2) : '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Reseller account funds</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-domains">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Domains</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-domain-count"><?= $domain_count ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $locked_count ?> locked · <?= $autorenew_count ?> auto-renew</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4 <?= $expiring_count > 0 ? 'border-orange-200 bg-orange-50' : '' ?>" data-testid="card-expiring">
                    <p class="text-xs font-semibold <?= $expiring_count > 0 ? 'text-orange-600' : 'text-gray-500' ?> uppercase mb-1">Expiring ≤30 Days</p>
                    <p class="text-2xl font-bold <?= $expiring_count > 0 ? 'text-orange-600' : 'text-gray-900' ?>" data-testid="text-expiring"><?= $expiring_count ?: '0' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Require renewal action</p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'domains'  => ['fas fa-list',           'Domains'],
                    'register' => ['fas fa-plus-circle',     'Check & Register'],
                    'dns'      => ['fas fa-server',          'DNS & Nameservers'],
                    'transfer' => ['fas fa-exchange-alt',    'Transfers'],
                    'settings' => ['fas fa-cog',             'Settings'],
                ];
                foreach ($tabs as $t => [$icon, $label]):
                    $active = $tab === $t;
                ?>
                <a href="?tab=<?= $t ?>"
                   class="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition <?= $active ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                   data-testid="tab-<?= $t ?>">
                    <i class="<?= $icon ?> text-xs"></i><?= $label ?>
                    <?php if ($t === 'domains' && $expiring_count > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-orange-500 text-white rounded-full text-[10px] font-bold"><?= $expiring_count ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$en_connected && $tab !== 'settings'): ?>
            <div class="bg-violet-50 border border-violet-200 rounded-lg p-6 text-center">
                <i class="fas fa-globe text-violet-300 text-4xl mb-3"></i>
                <h3 class="text-base font-semibold text-violet-800 mb-2">Enom Credentials Required</h3>
                <p class="text-sm text-violet-600 mb-4">Add your Enom reseller UID and API password to manage domains, DNS, and transfers directly from this dashboard.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fas fa-key mr-2"></i>Configure Credentials
                </a>
            </div>
            <?php else: ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- DOMAINS TAB                                                -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'domains'): ?>

            <?php if (!empty($expiring_soon)): ?>
            <div class="mb-4 bg-orange-50 border border-orange-200 rounded-lg p-4">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-exclamation-triangle text-orange-500"></i>
                    <span class="text-sm font-semibold text-orange-700"><?= count($expiring_soon) ?> domain(s) expiring within 30 days</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($expiring_soon as $d):
                        $sld = $d['SLD'] ?? $d['sld'] ?? '';
                        $tld = $d['TLD'] ?? $d['tld'] ?? '';
                        $exp = $d['expdate'] ?? $d['ExpirationDate'] ?? '';
                    ?>
                    <form method="POST" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="renew_domain">
                        <input type="hidden" name="sld" value="<?= htmlspecialchars($sld) ?>">
                        <input type="hidden" name="tld" value="<?= htmlspecialchars($tld) ?>">
                        <input type="hidden" name="num_years" value="1">
                        <button type="submit" class="px-3 py-1.5 bg-orange-100 hover:bg-orange-200 border border-orange-300 text-orange-800 rounded text-xs font-medium transition"
                            data-testid="button-quickrenew-<?= htmlspecialchars($sld) ?>"
                            onclick="return confirm('Renew <?= htmlspecialchars(addslashes("$sld.$tld")) ?> for 1 year?')">
                            <i class="fas fa-sync-alt mr-1"></i><?= htmlspecialchars("$sld.$tld") ?>
                            <span class="text-orange-400">(<?= $exp ? date('M d Y', strtotime($exp)) : '?' ?>)</span>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-list text-violet-500 mr-2"></i>All Domains</h2>
                    <div class="flex items-center gap-3">
                        <input type="search" id="domain-search" placeholder="Filter domains…" onkeyup="filterDomains()"
                            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500 w-48" data-testid="input-domain-search">
                        <span class="text-xs text-gray-400"><?= $domain_count ?> total</span>
                    </div>
                </div>

                <?php if (empty($domains) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-globe text-4xl mb-3"></i>
                    <p class="font-medium">No domains found in this account.</p>
                    <p class="text-sm mt-1">Register your first domain using the <a href="?tab=register" class="text-violet-500 hover:underline">Check &amp; Register</a> tab.</p>
                </div>
                <?php elseif (!empty($domains)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" id="domain-table" data-testid="table-domains">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Domain</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Expires</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Locked</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Auto-Renew</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="domain-tbody">
                            <?php foreach ($domains as $idx => $d):
                                $sld     = $d['SLD']     ?? $d['sld']     ?? '';
                                $tld     = $d['TLD']     ?? $d['tld']     ?? '';
                                $domain  = "$sld.$tld";
                                $status  = strtolower($d['status'] ?? $d['Status'] ?? 'active');
                                $expdate = $d['expdate']  ?? $d['ExpirationDate'] ?? '';
                                $locked  = ($d['islocked'] ?? $d['IsLocked'] ?? '0') == '1';
                                $autorenew = ($d['AutoRenew'] ?? $d['autorenew'] ?? '0') == '1';
                                $exp_ts  = $expdate ? strtotime($expdate) : 0;
                                $days_left = $exp_ts ? ceil(($exp_ts - time()) / 86400) : null;
                                $exp_class = ($days_left !== null && $days_left <= 30 && $days_left > 0) ? 'text-orange-600 font-medium' : 'text-gray-600';
                            ?>
                            <tr class="hover:bg-gray-50 domain-row" data-testid="row-domain-<?= htmlspecialchars($domain) ?>">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900 domain-name"><?= htmlspecialchars($domain) ?></p>
                                    <a href="?tab=dns&sld=<?= urlencode($sld) ?>&tld=<?= urlencode($tld) ?>" class="text-xs text-violet-500 hover:underline">Manage DNS →</a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 <?= $exp_class ?>">
                                    <?= $expdate ? date('M d Y', strtotime($expdate)) : '—' ?>
                                    <?php if ($days_left !== null && $days_left <= 30 && $days_left > 0): ?>
                                        <span class="block text-xs text-orange-500"><?= $days_left ?> days left</span>
                                    <?php elseif ($days_left !== null && $days_left <= 0): ?>
                                        <span class="block text-xs text-red-500">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_lock">
                                        <input type="hidden" name="sld" value="<?= htmlspecialchars($sld) ?>">
                                        <input type="hidden" name="tld" value="<?= htmlspecialchars($tld) ?>">
                                        <input type="hidden" name="unlock" value="<?= $locked ? 1 : 0 ?>">
                                        <button type="submit" class="text-xs font-medium <?= $locked ? 'text-green-600 hover:text-green-800' : 'text-gray-400 hover:text-gray-600' ?>"
                                            title="<?= $locked ? 'Click to unlock' : 'Click to lock' ?>"
                                            data-testid="button-lock-<?= htmlspecialchars($domain) ?>">
                                            <i class="fas <?= $locked ? 'fa-lock' : 'fa-lock-open' ?> text-base"></i>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_autorenew">
                                        <input type="hidden" name="sld" value="<?= htmlspecialchars($sld) ?>">
                                        <input type="hidden" name="tld" value="<?= htmlspecialchars($tld) ?>">
                                        <input type="hidden" name="auto" value="<?= $autorenew ? 0 : 1 ?>">
                                        <button type="submit" class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors <?= $autorenew ? 'bg-violet-600' : 'bg-gray-200' ?>"
                                            title="<?= $autorenew ? 'Disable auto-renew' : 'Enable auto-renew' ?>"
                                            data-testid="button-autorenew-<?= htmlspecialchars($domain) ?>">
                                            <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform <?= $autorenew ? 'translate-x-4' : 'translate-x-0.5' ?>"></span>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <!-- Renew -->
                                        <form method="POST" class="inline" onsubmit="return confirm('Renew <?= htmlspecialchars(addslashes($domain)) ?> for 1 year?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="renew_domain">
                                            <input type="hidden" name="sld" value="<?= htmlspecialchars($sld) ?>">
                                            <input type="hidden" name="tld" value="<?= htmlspecialchars($tld) ?>">
                                            <input type="hidden" name="num_years" value="1">
                                            <button type="submit" class="text-xs text-violet-600 hover:text-violet-800 font-medium" data-testid="button-renew-<?= htmlspecialchars($domain) ?>">
                                                <i class="fas fa-redo mr-1"></i>Renew
                                            </button>
                                        </form>
                                        <!-- DNS -->
                                        <a href="?tab=dns&sld=<?= urlencode($sld) ?>&tld=<?= urlencode($tld) ?>" class="text-xs text-blue-500 hover:text-blue-700 font-medium" data-testid="link-dns-<?= htmlspecialchars($domain) ?>">
                                            <i class="fas fa-server mr-1"></i>DNS
                                        </a>
                                        <!-- WHOIS -->
                                        <a href="https://www.enom.com/whois/<?= urlencode($domain) ?>" target="_blank" class="text-xs text-gray-400 hover:text-gray-600 font-medium">
                                            <i class="fas fa-search mr-1"></i>WHOIS
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- CHECK & REGISTER TAB                                       -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'register'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Domain availability checker -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-search text-violet-500 mr-2"></i>Check Availability</h3>
                    <p class="text-sm text-gray-500 mb-4">Enter a domain name (e.g. <code class="bg-gray-100 px-1 rounded text-xs">example.com</code>) to check if it is available for registration.</p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="check_domain">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Domain Name</label>
                            <div class="flex gap-2">
                                <input type="text" name="domain" required placeholder="example.com"
                                    class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                                    data-testid="input-check-domain">
                                <button type="submit" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-check-domain">
                                    <i class="fas fa-search mr-1"></i>Check
                                </button>
                            </div>
                        </div>
                    </form>

                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-400 mb-2 font-semibold uppercase">Popular TLDs</p>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($tlds as $t): ?>
                            <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-mono">.<?= $t ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Registration form -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-plus-circle text-green-500 mr-2"></i>Register Domain</h3>
                    <p class="text-sm text-gray-500 mb-4">Register a new domain using your Enom reseller account. The domain will be deducted from your account balance.</p>
                    <form method="POST" class="space-y-4" onsubmit="return confirm('Register this domain? This will charge your Enom account balance.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register_domain">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Second-Level Domain (SLD)</label>
                                <input type="text" name="sld" required placeholder="example"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                                    data-testid="input-register-sld">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">TLD</label>
                                <select name="tld" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500" data-testid="select-register-tld">
                                    <?php foreach ($tlds as $t): ?>
                                    <option value="<?= $t ?>" <?= $t === 'com' ? 'selected' : '' ?>>.<?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Registration Period</label>
                            <select name="num_years" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500" data-testid="select-register-years">
                                <?php for ($y = 1; $y <= 10; $y++): ?>
                                <option value="<?= $y ?>"><?= $y ?> year<?= $y > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-register-domain">
                            <i class="fas fa-cart-plus mr-2"></i>Register Domain
                        </button>
                        <p class="text-xs text-gray-400 text-center">Registration costs will be charged to your Enom account balance (currently <?= $balance !== null ? '$'.number_format((float)$balance,2) : 'unknown' ?>).</p>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- DNS & NAMESERVERS TAB                                      -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'dns'): ?>
            <?php
            $sel_sld = $_GET['sld'] ?? '';
            $sel_tld = $_GET['tld'] ?? '';
            ?>

            <!-- Domain selector -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-search text-gray-400 mr-2"></i>Select a Domain to Manage</h3>
                <form method="GET" class="flex gap-3 flex-wrap">
                    <input type="hidden" name="tab" value="dns">
                    <select name="sld" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500" data-testid="select-dns-domain">
                        <option value="">— Select domain —</option>
                        <?php
                        // Load domain list for selector (from cache or re-fetch)
                        $all_domains = $domains;
                        if (empty($all_domains)) {
                            $dr = enom_api(['command' => 'GetDomains', 'MaxCount' => 100]);
                            if (!isset($dr['error'])) {
                                $all_domains = $dr['domains'] ?? [];
                            }
                        }
                        foreach ($all_domains as $d2):
                            $d2sld = $d2['SLD'] ?? $d2['sld'] ?? '';
                            $d2tld = $d2['TLD'] ?? $d2['tld'] ?? '';
                            $d2dom = "$d2sld.$d2tld";
                            $sel   = ($sel_sld === $d2sld) ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($d2sld) ?>" data-tld="<?= htmlspecialchars($d2tld) ?>" <?= $sel ?>><?= htmlspecialchars($d2dom) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="tld" id="dns-tld" value="<?= htmlspecialchars($sel_tld) ?>">
                    <button type="submit" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-load-dns">
                        <i class="fas fa-server mr-1"></i>Load DNS Info
                    </button>
                </form>
            </div>

            <?php if ($sel_sld && $sel_tld): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Current nameservers -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-globe text-violet-500 mr-2"></i>Nameservers for <?= htmlspecialchars("$sel_sld.$sel_tld") ?></h3>
                    <?php
                    $ns_entries = [];
                    for ($n = 1; $n <= 4; $n++) {
                        $key = "NS$n";
                        $val = $ns_info[$key] ?? $ns_info['dns'][$key] ?? '';
                        if ($val) $ns_entries[] = $val;
                    }
                    ?>
                    <?php if (!empty($ns_entries)): ?>
                    <div class="space-y-2 mb-4">
                        <?php foreach ($ns_entries as $i => $ns): ?>
                        <div class="flex items-center gap-2 p-2 bg-gray-50 rounded">
                            <span class="text-xs text-gray-400 font-semibold w-8">NS<?= $i+1 ?></span>
                            <span class="text-sm font-mono text-gray-800"><?= htmlspecialchars($ns) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400 mb-4">No nameserver data returned from API.</p>
                    <?php endif; ?>

                    <h4 class="text-sm font-semibold text-gray-700 mb-3 mt-4 pt-4 border-t border-gray-100"><i class="fas fa-edit mr-2 text-gray-400"></i>Update Nameservers</h4>
                    <form method="POST" class="space-y-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_nameservers">
                        <input type="hidden" name="sld" value="<?= htmlspecialchars($sel_sld) ?>">
                        <input type="hidden" name="tld" value="<?= htmlspecialchars($sel_tld) ?>">
                        <?php for ($n = 1; $n <= 4; $n++): ?>
                        <input type="text" name="ns<?= $n ?>" value="<?= htmlspecialchars($ns_entries[$n-1] ?? '') ?>"
                            placeholder="NS<?= $n ?> (e.g. ns<?= $n ?>.provider.com)"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-violet-500"
                            data-testid="input-ns<?= $n ?>">
                        <?php endfor; ?>
                        <button type="submit" class="w-full px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-set-ns">
                            <i class="fas fa-save mr-2"></i>Update Nameservers
                        </button>
                    </form>
                </div>

                <!-- WHOIS / Contact info -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-address-card text-blue-500 mr-2"></i>WHOIS / Registrant Contact</h3>
                    <?php
                    $reg = $contacts['Registrant'] ?? $contacts['registrant'] ?? [];
                    $admin_c = $contacts['Admin'] ?? $contacts['admin'] ?? [];
                    $fields = ['Name'=>'name','Organisation'=>'org','Email'=>'email','Phone'=>'phone','Address1'=>'address1','City'=>'city','StateProvince'=>'state','PostalCode'=>'zip','Country'=>'country'];
                    ?>
                    <?php if (!empty($reg)): ?>
                    <div class="space-y-2">
                        <?php foreach ($fields as $label => $key):
                            $val = $reg[$label] ?? $reg[strtolower($label)] ?? '';
                            if (!$val) continue;
                        ?>
                        <div class="flex gap-2 text-sm">
                            <span class="text-gray-400 w-28 shrink-0"><?= $label ?></span>
                            <span class="text-gray-800"><?= htmlspecialchars($val) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400">No contact data returned. WHOIS privacy may be enabled.</p>
                    <?php endif; ?>
                    <div class="mt-4 pt-3 border-t border-gray-100">
                        <a href="https://cp.enom.com/myaccount/domains/domain.aspx?<?= urlencode("domain=$sel_sld.$sel_tld") ?>" target="_blank"
                            class="flex items-center gap-2 text-sm text-violet-500 hover:text-violet-700">
                            <i class="fas fa-external-link-alt text-xs"></i>Edit contacts in Enom panel
                        </a>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg border border-gray-200 p-10 text-center text-gray-400">
                <i class="fas fa-server text-4xl mb-3"></i>
                <p class="font-medium">Select a domain above to view and manage its DNS settings.</p>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- TRANSFERS TAB                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'transfer'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Transfer form -->
                <div class="bg-white rounded-lg border border-gray-200 p-6 self-start">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-exchange-alt text-orange-500 mr-2"></i>Transfer Domain In</h3>
                    <p class="text-sm text-gray-500 mb-4">Move an existing domain from another registrar into your Enom account. You'll need the domain's authorization (EPP) code.</p>
                    <form method="POST" class="space-y-4" onsubmit="return confirm('Submit transfer order? This will charge your Enom account.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="transfer_domain">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">SLD</label>
                                <input type="text" name="sld" required placeholder="example"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500" data-testid="input-transfer-sld">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">TLD</label>
                                <select name="tld" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500" data-testid="select-transfer-tld">
                                    <?php foreach ($tlds as $t): ?>
                                    <option value="<?= $t ?>" <?= $t === 'com' ? 'selected' : '' ?>>.<?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Authorization / EPP Code</label>
                            <input type="text" name="auth_code" required placeholder="Auth code from current registrar"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-violet-500" data-testid="input-transfer-auth">
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-transfer-submit">
                            <i class="fas fa-exchange-alt mr-2"></i>Initiate Transfer
                        </button>
                    </form>

                    <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <p class="text-xs text-yellow-700"><strong>Before transferring:</strong> Ensure the domain is unlocked at the current registrar, WHOIS privacy is disabled, and you have the EPP/auth code ready.</p>
                    </div>
                </div>

                <!-- Transfer status list -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-history text-blue-500 mr-2"></i>Transfer History</h3>
                    </div>
                    <?php if (empty($transfers)): ?>
                    <div class="p-10 text-center text-gray-400">
                        <i class="fas fa-exchange-alt text-4xl mb-3"></i>
                        <p class="font-medium">No pending or recent transfers.</p>
                        <p class="text-sm mt-1">Submitted transfers will appear here for tracking.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-testid="table-transfers">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Domain</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Submitted</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($transfers as $t):
                                    $tsld = $t['SLD'] ?? $t['sld'] ?? '';
                                    $ttld = $t['TLD'] ?? $t['tld'] ?? '';
                                    $tst  = $t['Status'] ?? $t['status'] ?? '—';
                                    $tdat = $t['OrderDate'] ?? $t['created_at'] ?? null;
                                ?>
                                <tr class="hover:bg-gray-50" data-testid="row-transfer-<?= htmlspecialchars("$tsld.$ttld") ?>">
                                    <td class="px-4 py-3 font-semibold text-gray-900"><?= htmlspecialchars("$tsld.$ttld") ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700"><?= htmlspecialchars($tst) ?></span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-400 text-xs"><?= $tdat ? date('M d Y', strtotime($tdat)) : '—' ?></td>
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
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-key text-yellow-500 mr-2"></i>Enom API Credentials</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Your reseller UID and API password. Find them at
                        <a href="https://cp.enom.com//resellers/api-reseller.aspx" target="_blank" class="text-violet-500 hover:underline">cp.enom.com → Resellers → API</a>.
                        You can also create a dedicated API user under your reseller account.
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_credentials">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reseller UID (Username)</label>
                            <input type="text" name="enom_uid" value="<?= htmlspecialchars($en_uid) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                                placeholder="YourEnomUID" autocomplete="off" data-testid="input-enom-uid">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Password (PW)</label>
                            <input type="password" name="enom_pw" value="<?= htmlspecialchars($en_pw) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                                placeholder="••••••••" autocomplete="off" data-testid="input-enom-pw">
                            <p class="text-xs text-gray-400 mt-1"><?= $en_connected ? '✓ Credentials currently stored.' : 'No credentials saved yet.' ?></p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="flex-1 px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-credentials">
                                <i class="fas fa-save mr-2"></i>Save Credentials
                            </button>
                            <?php if ($en_connected): ?>
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

                <!-- About + links -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About the Enom Reseller API</h3>
                    <p class="text-sm text-gray-600 mb-4">The Enom Reseller API allows you to manage domains, DNS, contacts, renewals, and transfers programmatically. All requests are sent to <code class="bg-gray-100 px-1 rounded text-xs">reseller.enom.com/interface.asp</code> using URL query parameters.</p>
                    <div class="space-y-2 mb-4">
                        <a href="https://cp.enom.com//resellers/api-reseller.aspx" target="_blank" class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-book text-violet-500 w-4"></i><span>Enom Reseller API Docs</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://cp.enom.com" target="_blank" class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-globe text-violet-500 w-4"></i><span>Enom Control Panel</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://cp.enom.com/myaccount/domains/domains.aspx" target="_blank" class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-list text-violet-500 w-4"></i><span>My Domain Portfolio</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                    </div>
                    <div class="p-3 bg-violet-50 rounded-lg border border-violet-200">
                        <p class="text-xs text-violet-700 font-semibold mb-1">Env variables (optional)</p>
                        <p class="text-xs text-violet-600">Set <code class="bg-violet-100 px-1 rounded">ENOM_UID</code> and <code class="bg-violet-100 px-1 rounded">ENOM_PW</code> as Replit environment secrets to avoid storing credentials in the database. Env vars take priority over the saved credentials above.</p>
                    </div>
                </div>

                <!-- Feature grid -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-purple-500 mr-2"></i>Supported API Features</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php
                        $features = [
                            ['fas fa-list',          'violet','List All Domains',        'View your full domain portfolio with status, expiry, and settings'],
                            ['fas fa-search',        'blue',  'Check Availability',       'Instantly check if any domain name is available to register'],
                            ['fas fa-cart-plus',     'green', 'Register Domains',          'Purchase new domains across all TLDs from within the portal'],
                            ['fas fa-redo',          'violet','Renew Domains',             'Renew any domain in your account for 1–10 years'],
                            ['fas fa-lock',          'yellow','Domain Locking',            'Lock or unlock a domain to prevent unauthorized transfers'],
                            ['fas fa-sync-alt',      'green', 'Auto-Renew Toggle',         'Enable or disable automatic renewal per-domain with a single click'],
                            ['fas fa-server',        'blue',  'Nameserver Management',     'View and update nameservers for any domain in your account'],
                            ['fas fa-address-card',  'gray',  'WHOIS / Contact Info',      'View registrant and admin contact details for each domain'],
                            ['fas fa-exchange-alt',  'orange','Domain Transfers',          'Initiate inbound domain transfers using EPP auth codes'],
                        ];
                        foreach ($features as [$icon, $color, $title, $desc]):
                            $bg = ['violet'=>'bg-violet-50 border-violet-200','blue'=>'bg-blue-50 border-blue-200','green'=>'bg-green-50 border-green-200','yellow'=>'bg-yellow-50 border-yellow-200','orange'=>'bg-orange-50 border-orange-200','gray'=>'bg-gray-50 border-gray-200'];
                            $ic = ['violet'=>'text-violet-600','blue'=>'text-blue-600','green'=>'text-green-600','yellow'=>'text-yellow-600','orange'=>'text-orange-500','gray'=>'text-gray-500'];
                        ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg border <?= $bg[$color] ?>">
                            <i class="<?= $icon ?> <?= $ic[$color] ?> mt-0.5 w-4 text-center"></i>
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

            <?php endif; // end $en_connected ?>
        </div><!-- /p-6 -->
    </div><!-- /flex-1 -->
</div><!-- /flex -->

<script>
// Domain table live filter
function filterDomains() {
    const q = document.getElementById('domain-search').value.toLowerCase();
    document.querySelectorAll('#domain-tbody .domain-row').forEach(row => {
        const name = row.querySelector('.domain-name')?.textContent.toLowerCase() || '';
        row.style.display = name.includes(q) ? '' : 'none';
    });
}

// DNS tab: auto-populate TLD from domain select
const domSel = document.querySelector('[data-testid="select-dns-domain"]');
if (domSel) {
    domSel.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const tld = opt.getAttribute('data-tld') || '';
        document.getElementById('dns-tld').value = tld;
    });
}
</script>
</body>
</html>
