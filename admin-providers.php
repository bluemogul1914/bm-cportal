<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$pdo = getDB();

// ── Ensure provider_settings table ───────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_settings (
        id         SERIAL PRIMARY KEY,
        provider   VARCHAR(50) NOT NULL,
        key_name   VARCHAR(100) NOT NULL,
        key_value  TEXT DEFAULT '',
        updated_at TIMESTAMP DEFAULT NOW(),
        UNIQUE(provider, key_name)
    )");
} catch(Exception $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_fund_log (
        id         SERIAL PRIMARY KEY,
        provider   VARCHAR(50),
        amount     NUMERIC(10,2),
        note       TEXT,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT NOW()
    )");
} catch(Exception $e) {}

// ── Helper: get/set provider settings ────────────────────────────────────────
function providerGet($pdo, $provider, $key) {
    try {
        $s = $pdo->prepare("SELECT key_value FROM provider_settings WHERE provider=? AND key_name=?");
        $s->execute([$provider, $key]);
        return $s->fetchColumn() ?: '';
    } catch(Exception $e) { return ''; }
}

function providerSet($pdo, $provider, $key, $value) {
    try {
        $pdo->prepare("INSERT INTO provider_settings (provider, key_name, key_value, updated_at)
            VALUES (?,?,?,NOW()) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value, updated_at=NOW()")
            ->execute([$provider, $key, $value]);
    } catch(Exception $e) {}
}

function providerGetAll($pdo, $provider) {
    $out = [];
    try {
        $s = $pdo->prepare("SELECT key_name, key_value FROM provider_settings WHERE provider=?");
        $s->execute([$provider]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[$r['key_name']] = $r['key_value']; }
    } catch(Exception $e) {}
    return $out;
}

// ── API helpers ───────────────────────────────────────────────────────────────
function httpGet($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body   = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'error' => $err];
}

function httpPost($url, $data, $headers = [], $json = false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => $json ? json_encode($data) : http_build_query($data),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'error' => $err];
}

// ── Live balance checkers ─────────────────────────────────────────────────────
function getVultrBalance($api_key) {
    if (!$api_key) return null;
    $r = httpGet('https://api.vultr.com/v2/account', ["Authorization: Bearer $api_key", "Content-Type: application/json"]);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        return $d['account']['balance'] ?? null;
    }
    return null;
}

function getVoipBalance($username, $password) {
    if (!$username || !$password) return null;
    $url = "https://voip.ms/api/v1/rest.php?api_username=" . urlencode($username) . "&api_password=" . urlencode($password) . "&method=getBalance";
    $r   = httpGet($url);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        if (($d['status'] ?? '') === 'success') return $d['balance'] ?? null;
    }
    return null;
}

function getEnomBalance($uid, $pw) {
    if (!$uid || !$pw) return null;
    $url = "https://reseller.enom.com/interface.asp?command=GetAccountBalance&UID=" . urlencode($uid) . "&PW=" . urlencode($pw) . "&responsetype=JSON";
    $r   = httpGet($url);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        return $d['accountbalance'] ?? $d['AccountBalance'] ?? null;
    }
    return null;
}

function getHostwindsBalance($api_key) {
    if (!$api_key) return null;
    $r = httpGet('https://api.hostwinds.com/v1/account', ["Authorization: Bearer $api_key", "Content-Type: application/json"]);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        return $d['account']['credit_balance'] ?? $d['credit_balance'] ?? null;
    }
    return null;
}

function getVidapayBalance($username, $api_key) {
    if (!$username || !$api_key) return null;
    $url = "https://api.vidapay.com/v1/account/balance";
    $r   = httpGet($url, ["Authorization: Bearer $api_key", "X-Username: $username", "Content-Type: application/json"]);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        return $d['balance'] ?? null;
    }
    return null;
}

// ── Tab & current tab ─────────────────────────────────────────────────────────
$tab    = $_GET['tab'] ?? 'overview';
$msg    = '';
$errmsg = '';

// ── Handle POST saves ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_vultr') {
        providerSet($pdo, 'vultr', 'api_key', trim($_POST['api_key'] ?? ''));
        $msg = 'Vultr credentials saved.';
        $tab = 'vultr';
    } elseif ($action === 'save_voip') {
        providerSet($pdo, 'voip', 'api_username', trim($_POST['api_username'] ?? ''));
        providerSet($pdo, 'voip', 'api_password', trim($_POST['api_password'] ?? ''));
        providerSet($pdo, 'voip', 'account_username', trim($_POST['account_username'] ?? ''));
        $msg = 'VoIP.ms credentials saved.';
        $tab = 'voip';
    } elseif ($action === 'save_enom') {
        providerSet($pdo, 'enom', 'uid', trim($_POST['uid'] ?? ''));
        providerSet($pdo, 'enom', 'pw',  trim($_POST['pw']  ?? ''));
        $msg = 'Enom credentials saved.';
        $tab = 'enom';
    } elseif ($action === 'save_hostwinds') {
        providerSet($pdo, 'hostwinds', 'api_key',  trim($_POST['api_key']  ?? ''));
        providerSet($pdo, 'hostwinds', 'client_id', trim($_POST['client_id'] ?? ''));
        $msg = 'Hostwinds credentials saved.';
        $tab = 'hostwinds';
    } elseif ($action === 'save_vidapay') {
        providerSet($pdo, 'vidapay', 'username',  trim($_POST['username']  ?? ''));
        providerSet($pdo, 'vidapay', 'api_key',   trim($_POST['api_key']   ?? ''));
        providerSet($pdo, 'vidapay', 'agent_id',  trim($_POST['agent_id']  ?? ''));
        providerSet($pdo, 'vidapay', 'dealer_id', trim($_POST['dealer_id'] ?? ''));
        $msg = 'VidaPay credentials saved.';
        $tab = 'vidapay';
    } elseif ($action === 'log_fund') {
        $provider = $_POST['provider'] ?? '';
        $amount   = floatval($_POST['amount'] ?? 0);
        $note     = trim($_POST['note'] ?? '');
        if ($provider && $amount > 0) {
            try {
                $pdo->prepare("INSERT INTO provider_fund_log (provider, amount, note, created_by) VALUES (?,?,?,?)")
                    ->execute([$provider, $amount, $note, $_SESSION['user_id']]);
                $msg = "Fund addition of $$amount logged for $provider.";
            } catch(Exception $e) { $errmsg = $e->getMessage(); }
        }
        $tab = $_POST['from_tab'] ?? $tab;
    } elseif ($action === 'enom_register') {
        $c   = providerGetAll($pdo, 'enom');
        $dom = trim($_POST['domain_name'] ?? '');
        $tld = trim($_POST['tld'] ?? 'com');
        $yrs = intval($_POST['years'] ?? 1);
        if ($c['uid'] && $c['pw'] && $dom) {
            $url = "https://reseller.enom.com/interface.asp?command=Purchase&UID=" . urlencode($c['uid']) . "&PW=" . urlencode($c['pw'])
                 . "&SLD=" . urlencode($dom) . "&TLD=" . urlencode($tld) . "&NumYears=" . $yrs
                 . "&responsetype=JSON";
            $r = httpGet($url);
            $d = json_decode($r['body'], true);
            if ($r['code'] === 200 && ($d['RRPCode'] ?? '') === '200') {
                $msg = "Domain $dom.$tld registered successfully!";
            } else {
                $errmsg = "Enom error: " . ($d['RRPText'] ?? $r['body']);
            }
        } else { $errmsg = "Missing Enom credentials or domain name."; }
        $tab = 'enom';
    } elseif ($action === 'vidapay_reload') {
        $c    = providerGetAll($pdo, 'vidapay');
        $mdn  = trim($_POST['mdn'] ?? '');
        $plan = trim($_POST['plan_id'] ?? '');
        if ($c['api_key'] && $mdn && $plan) {
            $r = httpPost("https://api.vidapay.com/v1/reload", [
                'mdn' => $mdn, 'plan_id' => $plan, 'agent_id' => $c['agent_id'] ?? ''
            ], ["Authorization: Bearer " . $c['api_key'], "Content-Type: application/json"], true);
            $d = json_decode($r['body'], true);
            if ($r['code'] === 200 && ($d['success'] ?? false)) {
                $msg = "Reload successful for $mdn!";
            } else {
                $errmsg = "VidaPay error: " . ($d['message'] ?? $r['body']);
            }
        } else { $errmsg = "Missing credentials, MDN, or plan."; }
        $tab = 'vidapay';
    } elseif ($action === 'vidapay_activate') {
        $c     = providerGetAll($pdo, 'vidapay');
        $mdn   = trim($_POST['mdn'] ?? '');
        $iccid = trim($_POST['iccid'] ?? '');
        $plan  = trim($_POST['plan_id'] ?? '');
        $zip   = trim($_POST['zip'] ?? '');
        if ($c['api_key'] && $mdn && $iccid && $plan) {
            $r = httpPost("https://api.vidapay.com/v1/activate", [
                'mdn' => $mdn, 'iccid' => $iccid, 'plan_id' => $plan,
                'zip' => $zip, 'agent_id' => $c['agent_id'] ?? '', 'dealer_id' => $c['dealer_id'] ?? ''
            ], ["Authorization: Bearer " . $c['api_key'], "Content-Type: application/json"], true);
            $d = json_decode($r['body'], true);
            if ($r['code'] === 200 && ($d['success'] ?? false)) {
                $msg = "Activation successful for $mdn!";
            } else {
                $errmsg = "VidaPay error: " . ($d['message'] ?? $r['body']);
            }
        } else { $errmsg = "Missing fields for activation."; }
        $tab = 'vidapay';
    }
}

// ── Load all provider credentials ─────────────────────────────────────────────
$vultr_key    = providerGet($pdo, 'vultr', 'api_key')    ?: (defined('VULTR_API_KEY') ? VULTR_API_KEY : '');
$voip_user    = providerGet($pdo, 'voip', 'api_username');
$voip_pass    = providerGet($pdo, 'voip', 'api_password');
$voip_account = providerGet($pdo, 'voip', 'account_username');
$enom_uid     = providerGet($pdo, 'enom', 'uid');
$enom_pw      = providerGet($pdo, 'enom', 'pw');
$hw_key       = providerGet($pdo, 'hostwinds', 'api_key');
$hw_cid       = providerGet($pdo, 'hostwinds', 'client_id');
$vp_user      = providerGet($pdo, 'vidapay', 'username');
$vp_key       = providerGet($pdo, 'vidapay', 'api_key');
$vp_agent     = providerGet($pdo, 'vidapay', 'agent_id');
$vp_dealer    = providerGet($pdo, 'vidapay', 'dealer_id');

// ── Connection status (quick check) ───────────────────────────────────────────
$vultr_ok  = !empty($vultr_key);
$voip_ok   = !empty($voip_user) && !empty($voip_pass);
$enom_ok   = !empty($enom_uid) && !empty($enom_pw);
$hw_ok     = !empty($hw_key);
$vp_ok     = !empty($vp_user) && !empty($vp_key);

// Live balance (only fetch if viewing that tab)
$vultr_balance  = null;
$voip_balance   = null;
$enom_balance   = null;
$hw_balance     = null;
$vp_balance     = null;

if ($tab === 'vultr'     && $vultr_ok) $vultr_balance = getVultrBalance($vultr_key);
if ($tab === 'voip'      && $voip_ok)  $voip_balance  = getVoipBalance($voip_user, $voip_pass);
if ($tab === 'enom'      && $enom_ok)  $enom_balance  = getEnomBalance($enom_uid, $enom_pw);
if ($tab === 'hostwinds' && $hw_ok)    $hw_balance    = getHostwindsBalance($hw_key);
if ($tab === 'vidapay'   && $vp_ok)    $vp_balance    = getVidapayBalance($vp_user, $vp_key);

// Fund logs
$fund_logs = [];
try {
    $fund_logs = $pdo->query("SELECT f.*, u.name as admin_name FROM provider_fund_log f LEFT JOIN users u ON f.created_by = u.id ORDER BY f.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Enom domain list (if on enom tab)
$enom_domains = [];
if ($tab === 'enom' && $enom_ok) {
    $url = "https://reseller.enom.com/interface.asp?command=GetDomains&UID=" . urlencode($enom_uid) . "&PW=" . urlencode($enom_pw) . "&responsetype=JSON&MaxCount=25";
    $r = httpGet($url);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        $enom_domains = $d['domains'] ?? [];
    }
}

// Hostwinds servers/services
$hw_services = [];
if ($tab === 'hostwinds' && $hw_ok) {
    $r = httpGet('https://api.hostwinds.com/v1/invoices?limit=10', ["Authorization: Bearer $hw_key"]);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        $hw_services = $d['data'] ?? $d['invoices'] ?? [];
    }
}

// VidaPay recent orders
$vp_orders = [];
if ($tab === 'vidapay' && $vp_ok) {
    $r = httpGet('https://api.vidapay.com/v1/orders?limit=10', ["Authorization: Bearer $vp_key"]);
    if ($r['code'] === 200) {
        $d = json_decode($r['body'], true);
        $vp_orders = $d['data'] ?? $d['orders'] ?? [];
    }
}

function statusBadge($ok) {
    return $ok ? '<span class="badge-conn connected">● Connected</span>' : '<span class="badge-conn disconnected">○ Not Connected</span>';
}
function fmtBalance($b, $currency = '$') {
    if ($b === null) return '<span style="color:#888">—</span>';
    return '<strong style="color:' . ($b >= 0 ? '#10b981' : '#ef4444') . '">' . $currency . number_format((float)$b, 2) . '</strong>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Provider Accounts – Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;}
.sidebar{width:240px;background:#1e293b;position:fixed;top:0;left:0;height:100%;overflow-y:auto;z-index:50;}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:#94a3b8;text-decoration:none;font-size:13px;border-radius:6px;margin:2px 8px;transition:all .15s;}
.sidebar a:hover,.sidebar a.active{background:#334155;color:#f1f5f9;}
.sidebar .section-label{padding:16px 20px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#475569;font-weight:600;}
.main{margin-left:240px;padding:28px 32px;min-height:100vh;}
.page-header{margin-bottom:24px;}
.page-header h1{font-size:22px;font-weight:700;color:#f1f5f9;}
.page-header p{color:#94a3b8;font-size:14px;margin-top:4px;}
.tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid #334155;padding-bottom:0;}
.tab-btn{padding:10px 18px;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:13px;font-weight:500;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .15s;}
.tab-btn:hover{color:#f1f5f9;}
.tab-btn.active{color:#3b82f6;border-bottom-color:#3b82f6;}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:20px;}
.card-title{font-size:15px;font-weight:600;color:#f1f5f9;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;}
.form-group input,.form-group select{background:#0f172a;border:1px solid #334155;border-radius:6px;padding:8px 12px;color:#f1f5f9;font-size:13px;outline:none;transition:border .15s;}
.form-group input:focus,.form-group select:focus{border-color:#3b82f6;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:500;transition:all .15s;}
.btn-primary{background:#3b82f6;color:#fff;} .btn-primary:hover{background:#2563eb;}
.btn-success{background:#10b981;color:#fff;} .btn-success:hover{background:#059669;}
.btn-warning{background:#f59e0b;color:#fff;} .btn-warning:hover{background:#d97706;}
.btn-sm{padding:5px 10px;font-size:12px;}
.badge-conn{display:inline-flex;align-items:center;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;}
.badge-conn.connected{background:#052e16;color:#10b981;}
.badge-conn.disconnected{background:#1c1917;color:#6b7280;}
.balance-card{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:6px;}
.balance-amount{font-size:28px;font-weight:700;}
.overview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;}
.provider-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:20px;cursor:pointer;transition:all .2s;}
.provider-card:hover{border-color:#3b82f6;transform:translateY(-2px);}
.provider-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:12px;}
.provider-name{font-size:15px;font-weight:600;color:#f1f5f9;margin-bottom:4px;}
.provider-desc{font-size:12px;color:#64748b;margin-bottom:12px;}
.msg-success{background:#052e16;border:1px solid #10b981;color:#10b981;padding:10px 16px;border-radius:6px;margin-bottom:16px;font-size:13px;}
.msg-error{background:#450a0a;border:1px solid #ef4444;color:#ef4444;padding:10px 16px;border-radius:6px;margin-bottom:16px;font-size:13px;}
.table{width:100%;border-collapse:collapse;}
.table th{background:#0f172a;color:#64748b;font-size:11px;text-transform:uppercase;padding:8px 12px;text-align:left;font-weight:600;}
.table td{padding:10px 12px;border-bottom:1px solid #1e293b;color:#e2e8f0;font-size:13px;}
.table tr:hover td{background:#1e293b;}
.section-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media(max-width:768px){.section-grid{grid-template-columns:1fr;}.form-row{grid-template-columns:1fr;}}
.fund-panel{background:#0f172a;border:1px solid #22c55e33;border-radius:8px;padding:16px;}
.fund-panel h4{font-size:13px;font-weight:600;color:#22c55e;margin-bottom:12px;}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
  <div style="padding:20px 20px 12px;">
    <div style="font-size:16px;font-weight:700;color:#3b82f6;">Blue Mogul</div>
    <div style="font-size:11px;color:#64748b;">Admin Portal</div>
  </div>
  <div class="section-label">Main</div>
  <a href="/portal/admin-dashboard.php">🏠 Dashboard</a>
  <a href="/portal/admin-clients.php">👥 Clients</a>
  <a href="/portal/admin-tickets.php">🎫 Tickets</a>
  <a href="/portal/admin-invoices.php">💳 Invoices</a>
  <div class="section-label">Services</div>
  <a href="/portal/admin-crm.php">📊 CRM</a>
  <a href="/portal/admin-voip.php">📞 VoIP</a>
  <a href="/portal/admin-frontier.php">🌐 Frontier ASR</a>
  <a href="/portal/admin-providers.php" class="active">🔌 Provider Accounts</a>
  <a href="/portal/admin-vultr.php">☁️ Vultr Cloud</a>
  <a href="/portal/admin-network.php">🖧 Network</a>
  <div class="section-label">Admin</div>
  <a href="/portal/admin-settings.php">⚙️ Settings</a>
  <a href="/portal/admin-reports.php">📈 Reports</a>
  <a href="/portal/logout.php">🚪 Logout</a>
</div>

<div class="main">
  <div class="page-header">
    <h1>🔌 Provider Accounts</h1>
    <p>Manage API credentials, check balances, and perform actions across all service providers.</p>
  </div>

  <?php if ($msg): ?><div class="msg-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($errmsg): ?><div class="msg-error">✗ <?= htmlspecialchars($errmsg) ?></div><?php endif; ?>

  <!-- Tabs -->
  <div class="tabs">
    <?php $tabs = ['overview'=>'Overview','vultr'=>'Vultr','voip'=>'VoIP.ms','enom'=>'Enom','hostwinds'=>'Hostwinds','vidapay'=>'VidaPay','funds'=>'Fund Log']; ?>
    <?php foreach ($tabs as $t => $label): ?>
      <button class="tab-btn <?= $tab===$t?'active':'' ?>" onclick="window.location='?tab=<?= $t ?>'"><?= $label ?></button>
    <?php endforeach; ?>
  </div>

  <!-- ── OVERVIEW TAB ─────────────────────────────────────────────────────── -->
  <?php if ($tab === 'overview'): ?>
  <div class="overview-grid" style="margin-bottom:24px;">
    <?php
    $providers_info = [
      'vultr'     => ['icon'=>'☁️','color'=>'#0073e6','name'=>'Vultr','desc'=>'Cloud compute & storage','ok'=>$vultr_ok,'tab'=>'vultr','url'=>'https://my.vultr.com'],
      'voip'      => ['icon'=>'📞','color'=>'#f59e0b','name'=>'VoIP.ms','desc'=>'VoIP reseller services','ok'=>$voip_ok,'tab'=>'voip','url'=>'https://voip.ms/m/billing.php'],
      'enom'      => ['icon'=>'🌐','color'=>'#8b5cf6','name'=>'Enom','desc'=>'Domain registration & DNS','ok'=>$enom_ok,'tab'=>'enom','url'=>'https://www.enom.com/myaccount'],
      'hostwinds' => ['icon'=>'🏢','color'=>'#10b981','name'=>'Hostwinds','desc'=>'Web hosting & email','ok'=>$hw_ok,'tab'=>'hostwinds','url'=>'https://www.hostwinds.com/manage'],
      'vidapay'   => ['icon'=>'📱','color'=>'#ef4444','name'=>'VidaPay','desc'=>'Phone activations & reloads','ok'=>$vp_ok,'tab'=>'vidapay','url'=>'https://vidapay.com'],
    ];
    foreach ($providers_info as $k => $p): ?>
    <div class="provider-card" onclick="window.location='?tab=<?= $p['tab'] ?>'">
      <div class="provider-icon" style="background:<?= $p['color'] ?>22;color:<?= $p['color'] ?>"><?= $p['icon'] ?></div>
      <div class="provider-name"><?= $p['name'] ?></div>
      <div class="provider-desc"><?= $p['desc'] ?></div>
      <?= statusBadge($p['ok']) ?>
      <?php if ($p['ok']): ?>
      <div style="margin-top:10px;">
        <a href="<?= $p['url'] ?>" target="_blank" class="btn btn-sm" style="background:#1e293b;border:1px solid #334155;color:#94a3b8;text-decoration:none;">↗ Open Portal</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-title">📋 Recent Fund Additions</div>
    <?php if (empty($fund_logs)): ?>
      <p style="color:#64748b;font-size:13px;">No fund additions logged yet.</p>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Provider</th><th>Amount</th><th>Note</th><th>Admin</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($fund_logs, 0, 10) as $fl): ?>
        <tr>
          <td><span style="text-transform:capitalize;"><?= htmlspecialchars($fl['provider']) ?></span></td>
          <td style="color:#10b981;font-weight:600;">$<?= number_format($fl['amount'], 2) ?></td>
          <td><?= htmlspecialchars($fl['note']) ?></td>
          <td><?= htmlspecialchars($fl['admin_name'] ?? 'Admin') ?></td>
          <td style="color:#64748b;"><?= date('M j, Y g:i a', strtotime($fl['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── VULTR TAB ─────────────────────────────────────────────────────────── -->
  <?php elseif ($tab === 'vultr'): ?>
  <div class="section-grid">
    <div class="card">
      <div class="card-title">☁️ Vultr Credentials <?= statusBadge($vultr_ok) ?></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_vultr">
        <div class="form-group" style="margin-bottom:12px;">
          <label>API Key</label>
          <input type="password" name="api_key" value="<?= htmlspecialchars($vultr_key) ?>" placeholder="Vultr API Key" autocomplete="off">
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary" type="submit">💾 Save</button>
          <a href="/portal/admin-vultr.php" class="btn" style="background:#334155;color:#f1f5f9;text-decoration:none;">☁️ Full Vultr Dashboard →</a>
        </div>
      </form>
    </div>
    <div>
      <div class="balance-card" style="margin-bottom:16px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">ACCOUNT BALANCE</div>
        <div class="balance-amount"><?= fmtBalance($vultr_balance) ?></div>
        <div style="font-size:12px;color:#64748b;">Live from Vultr API<?= $vultr_balance === null ? ' (configure credentials)' : '' ?></div>
      </div>
      <div class="fund-panel">
        <h4>💳 Log Fund Addition</h4>
        <form method="POST">
          <input type="hidden" name="action" value="log_fund">
          <input type="hidden" name="provider" value="vultr">
          <input type="hidden" name="from_tab" value="vultr">
          <div class="form-row">
            <div class="form-group"><label>Amount ($)</label><input type="number" name="amount" step="0.01" min="0" placeholder="25.00"></div>
            <div class="form-group"><label>Note</label><input type="text" name="note" placeholder="Payment ref…"></div>
          </div>
          <div style="display:flex;gap:8px;margin-top:4px;">
            <button class="btn btn-success" type="submit">Log Addition</button>
            <a href="https://my.vultr.com/billing/addfunds" target="_blank" class="btn btn-warning">↗ Add Funds on Vultr</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── VOIP TAB ──────────────────────────────────────────────────────────── -->
  <?php elseif ($tab === 'voip'): ?>
  <div class="section-grid">
    <div class="card">
      <div class="card-title">📞 VoIP.ms Credentials <?= statusBadge($voip_ok) ?></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_voip">
        <div class="form-row">
          <div class="form-group"><label>Account Username</label><input type="text" name="account_username" value="<?= htmlspecialchars($voip_account) ?>" placeholder="voip.ms login email"></div>
          <div class="form-group"><label>API Username</label><input type="text" name="api_username" value="<?= htmlspecialchars($voip_user) ?>" placeholder="API-enabled username"></div>
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label>API Password</label>
          <input type="password" name="api_password" value="<?= htmlspecialchars($voip_pass) ?>" placeholder="API Password (set in VoIP.ms account settings)">
        </div>
        <div style="font-size:11px;color:#64748b;margin-bottom:12px;">Enable API access in your VoIP.ms account under Settings → General Settings → Enable API</div>
        <button class="btn btn-primary" type="submit">💾 Save</button>
      </form>
    </div>
    <div>
      <div class="balance-card" style="margin-bottom:16px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">ACCOUNT BALANCE</div>
        <div class="balance-amount"><?= fmtBalance($voip_balance) ?></div>
        <div style="font-size:12px;color:#64748b;">Live from VoIP.ms API<?= $voip_balance === null ? ' (configure credentials)' : '' ?></div>
      </div>
      <div class="fund-panel">
        <h4>💳 Log Fund Addition</h4>
        <form method="POST">
          <input type="hidden" name="action" value="log_fund">
          <input type="hidden" name="provider" value="voip">
          <input type="hidden" name="from_tab" value="voip">
          <div class="form-row">
            <div class="form-group"><label>Amount ($)</label><input type="number" name="amount" step="0.01" min="0" placeholder="25.00"></div>
            <div class="form-group"><label>Note</label><input type="text" name="note" placeholder="Payment ref…"></div>
          </div>
          <div style="display:flex;gap:8px;margin-top:4px;">
            <button class="btn btn-success" type="submit">Log Addition</button>
            <a href="https://voip.ms/m/billing.php" target="_blank" class="btn btn-warning">↗ Add Funds on VoIP.ms</a>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php if ($voip_ok): ?>
  <div class="card" style="margin-top:20px;">
    <div class="card-title">📋 Quick API Tester</div>
    <p style="font-size:13px;color:#94a3b8;">Use the <a href="/portal/admin-voip.php" style="color:#3b82f6;">VoIP Admin panel</a> to manage sub-accounts, DIDs, and call routing.</p>
  </div>
  <?php endif; ?>

  <!-- ── ENOM TAB ──────────────────────────────────────────────────────────── -->
  <?php elseif ($tab === 'enom'): ?>
  <div class="section-grid">
    <div class="card">
      <div class="card-title">🌐 Enom Credentials <?= statusBadge($enom_ok) ?></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_enom">
        <div class="form-row">
          <div class="form-group"><label>UID (Reseller Username)</label><input type="text" name="uid" value="<?= htmlspecialchars($enom_uid) ?>" placeholder="YourEnomUID"></div>
          <div class="form-group"><label>API Password (PW)</label><input type="password" name="pw" value="<?= htmlspecialchars($enom_pw) ?>" placeholder="Enom API password"></div>
        </div>
        <div style="font-size:11px;color:#64748b;margin-bottom:12px;">Get credentials from your eNom reseller account → Account → API Settings</div>
        <button class="btn btn-primary" type="submit">💾 Save</button>
      </form>
    </div>
    <div>
      <div class="balance-card" style="margin-bottom:16px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">ACCOUNT BALANCE</div>
        <div class="balance-amount"><?= fmtBalance($enom_balance) ?></div>
        <div style="font-size:12px;color:#64748b;">Live from Enom API<?= $enom_balance === null ? ' (configure credentials)' : '' ?></div>
      </div>
      <div class="fund-panel">
        <h4>💳 Log Fund Addition</h4>
        <form method="POST">
          <input type="hidden" name="action" value="log_fund">
          <input type="hidden" name="provider" value="enom">
          <input type="hidden" name="from_tab" value="enom">
          <div class="form-row">
            <div class="form-group"><label>Amount ($)</label><input type="number" name="amount" step="0.01" min="0" placeholder="25.00"></div>
            <div class="form-group"><label>Note</label><input type="text" name="note" placeholder="Payment ref…"></div>
          </div>
          <div style="display:flex;gap:8px;margin-top:4px;">
            <button class="btn btn-success" type="submit">Log Addition</button>
            <a href="https://www.enom.com/myaccount/creditcard.aspx" target="_blank" class="btn btn-warning">↗ Add Funds on Enom</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="section-grid" style="margin-top:20px;">
    <!-- Register Domain -->
    <div class="card">
      <div class="card-title">➕ Register Domain</div>
      <?php if (!$enom_ok): ?>
        <p style="color:#64748b;font-size:13px;">Configure Enom credentials above to register domains.</p>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="action" value="enom_register">
        <div class="form-row">
          <div class="form-group"><label>Domain Name (without TLD)</label><input type="text" name="domain_name" placeholder="example" required></div>
          <div class="form-group">
            <label>TLD</label>
            <select name="tld">
              <option value="com">.com</option><option value="net">.net</option><option value="org">.org</option>
              <option value="io">.io</option><option value="co">.co</option><option value="biz">.biz</option>
              <option value="info">.info</option><option value="us">.us</option><option value="app">.app</option>
            </select>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label>Registration Period (years)</label>
          <select name="years">
            <?php for($i=1;$i<=5;$i++): ?><option value="<?=$i?>"><?=$i?> year<?=$i>1?'s':''?></option><?php endfor; ?>
          </select>
        </div>
        <button class="btn btn-primary" type="submit">🌐 Register Domain</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Domain List -->
    <div class="card">
      <div class="card-title">📋 Your Domains</div>
      <?php if (empty($enom_domains)): ?>
        <p style="color:#64748b;font-size:13px;"><?= $enom_ok ? 'No domains found or API did not return results.' : 'Configure credentials to view domains.' ?></p>
      <?php else: ?>
      <table class="table">
        <thead><tr><th>Domain</th><th>Expires</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($enom_domains as $d): ?>
          <tr>
            <td><?= htmlspecialchars($d['DomainName'] ?? $d['domain'] ?? '—') ?></td>
            <td><?= htmlspecialchars($d['ExpirationDate'] ?? $d['expiry'] ?? '—') ?></td>
            <td><span style="color:#10b981;font-size:12px;"><?= htmlspecialchars($d['Status'] ?? 'Active') ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── HOSTWINDS TAB ─────────────────────────────────────────────────────── -->
  <?php elseif ($tab === 'hostwinds'): ?>
  <div class="section-grid">
    <div class="card">
      <div class="card-title">🏢 Hostwinds Credentials <?= statusBadge($hw_ok) ?></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_hostwinds">
        <div class="form-row">
          <div class="form-group"><label>API Key</label><input type="password" name="api_key" value="<?= htmlspecialchars($hw_key) ?>" placeholder="Hostwinds API Key" autocomplete="off"></div>
          <div class="form-group"><label>Client ID (optional)</label><input type="text" name="client_id" value="<?= htmlspecialchars($hw_cid) ?>" placeholder="Your Hostwinds Client ID"></div>
        </div>
        <div style="font-size:11px;color:#64748b;margin-bottom:12px;">Generate API key in Hostwinds Client Area → API → Manage API Credentials</div>
        <button class="btn btn-primary" type="submit">💾 Save</button>
      </form>
      <div style="margin-top:16px;border-top:1px solid #334155;padding-top:16px;">
        <div style="font-size:13px;font-weight:600;color:#f1f5f9;margin-bottom:10px;">Quick Links</div>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <a href="https://www.hostwinds.com/manage" target="_blank" class="btn btn-sm" style="background:#334155;color:#f1f5f9;text-decoration:none;justify-content:flex-start;">🌐 Client Portal</a>
          <a href="https://www.hostwinds.com/manage/hosting" target="_blank" class="btn btn-sm" style="background:#334155;color:#f1f5f9;text-decoration:none;justify-content:flex-start;">🖥️ Web Hosting</a>
          <a href="https://www.hostwinds.com/manage/email" target="_blank" class="btn btn-sm" style="background:#334155;color:#f1f5f9;text-decoration:none;justify-content:flex-start;">📧 Email Services</a>
          <a href="https://www.hostwinds.com/manage/cloud" target="_blank" class="btn btn-sm" style="background:#334155;color:#f1f5f9;text-decoration:none;justify-content:flex-start;">☁️ Cloud Servers</a>
        </div>
      </div>
    </div>
    <div>
      <div class="balance-card" style="margin-bottom:16px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">ACCOUNT CREDIT</div>
        <div class="balance-amount"><?= fmtBalance($hw_balance) ?></div>
        <div style="font-size:12px;color:#64748b;">Live from Hostwinds API<?= $hw_balance === null ? ' (configure credentials)' : '' ?></div>
      </div>
      <div class="fund-panel">
        <h4>💳 Log Fund Addition</h4>
        <form method="POST">
          <input type="hidden" name="action" value="log_fund">
          <input type="hidden" name="provider" value="hostwinds">
          <input type="hidden" name="from_tab" value="hostwinds">
          <div class="form-row">
            <div class="form-group"><label>Amount ($)</label><input type="number" name="amount" step="0.01" min="0" placeholder="25.00"></div>
            <div class="form-group"><label>Note</label><input type="text" name="note" placeholder="Invoice #…"></div>
          </div>
          <div style="display:flex;gap:8px;margin-top:4px;">
            <button class="btn btn-success" type="submit">Log Addition</button>
            <a href="https://www.hostwinds.com/manage/billing/addfunds" target="_blank" class="btn btn-warning">↗ Add Funds</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if (!empty($hw_services)): ?>
  <div class="card" style="margin-top:20px;">
    <div class="card-title">📋 Recent Invoices</div>
    <table class="table">
      <thead><tr><th>ID</th><th>Total</th><th>Status</th><th>Due Date</th></tr></thead>
      <tbody>
        <?php foreach ($hw_services as $inv): ?>
        <tr>
          <td><?= htmlspecialchars($inv['id'] ?? '—') ?></td>
          <td>$<?= number_format($inv['total'] ?? 0, 2) ?></td>
          <td><?= htmlspecialchars($inv['status'] ?? '—') ?></td>
          <td><?= htmlspecialchars($inv['due_date'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── VIDAPAY TAB ───────────────────────────────────────────────────────── -->
  <?php elseif ($tab === 'vidapay'): ?>
  <div class="section-grid">
    <div class="card">
      <div class="card-title">📱 VidaPay Credentials <?= statusBadge($vp_ok) ?></div>
      <form method="POST">
        <input type="hidden" name="action" value="save_vidapay">
        <div class="form-row">
          <div class="form-group"><label>Username</label><input type="text" name="username" value="<?= htmlspecialchars($vp_user) ?>" placeholder="VidaPay username"></div>
          <div class="form-group"><label>API Key</label><input type="password" name="api_key" value="<?= htmlspecialchars($vp_key) ?>" placeholder="API Key" autocomplete="off"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Agent ID</label><input type="text" name="agent_id" value="<?= htmlspecialchars($vp_agent) ?>" placeholder="Agent/Retailer ID"></div>
          <div class="form-group"><label>Dealer ID</label><input type="text" name="dealer_id" value="<?= htmlspecialchars($vp_dealer) ?>" placeholder="Dealer ID (if applicable)"></div>
        </div>
        <button class="btn btn-primary" type="submit">💾 Save</button>
      </form>
    </div>
    <div>
      <div class="balance-card" style="margin-bottom:16px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">ACCOUNT BALANCE</div>
        <div class="balance-amount"><?= fmtBalance($vp_balance) ?></div>
        <div style="font-size:12px;color:#64748b;">Live from VidaPay API<?= $vp_balance === null ? ' (configure credentials)' : '' ?></div>
      </div>
      <div class="fund-panel">
        <h4>💳 Log Fund Addition</h4>
        <form method="POST">
          <input type="hidden" name="action" value="log_fund">
          <input type="hidden" name="provider" value="vidapay">
          <input type="hidden" name="from_tab" value="vidapay">
          <div class="form-row">
            <div class="form-group"><label>Amount ($)</label><input type="number" name="amount" step="0.01" min="0" placeholder="100.00"></div>
            <div class="form-group"><label>Note</label><input type="text" name="note" placeholder="Top-up ref…"></div>
          </div>
          <div style="display:flex;gap:8px;margin-top:4px;">
            <button class="btn btn-success" type="submit">Log Addition</button>
            <a href="https://vidapay.com/portal" target="_blank" class="btn btn-warning">↗ VidaPay Portal</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="section-grid" style="margin-top:20px;">
    <!-- Phone Reload -->
    <div class="card">
      <div class="card-title">🔄 Phone Reload</div>
      <?php if (!$vp_ok): ?>
        <p style="color:#64748b;font-size:13px;">Configure VidaPay credentials above to process reloads.</p>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="action" value="vidapay_reload">
        <div class="form-group" style="margin-bottom:10px;"><label>Phone Number (MDN)</label><input type="text" name="mdn" placeholder="5551234567" required></div>
        <div class="form-group" style="margin-bottom:10px;"><label>Plan ID / Amount</label><input type="text" name="plan_id" placeholder="e.g. TF30 or plan code" required></div>
        <button class="btn btn-primary" type="submit">🔄 Process Reload</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Activation -->
    <div class="card">
      <div class="card-title">📲 New Activation</div>
      <?php if (!$vp_ok): ?>
        <p style="color:#64748b;font-size:13px;">Configure VidaPay credentials above to process activations.</p>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="action" value="vidapay_activate">
        <div class="form-row">
          <div class="form-group"><label>Phone Number (MDN)</label><input type="text" name="mdn" placeholder="5551234567" required></div>
          <div class="form-group"><label>SIM/ICCID</label><input type="text" name="iccid" placeholder="89010..." required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Plan ID</label><input type="text" name="plan_id" placeholder="Plan code" required></div>
          <div class="form-group"><label>ZIP Code</label><input type="text" name="zip" placeholder="70001"></div>
        </div>
        <button class="btn btn-primary" type="submit">📲 Activate</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($vp_orders)): ?>
  <div class="card" style="margin-top:20px;">
    <div class="card-title">📋 Recent Orders</div>
    <table class="table">
      <thead><tr><th>Order ID</th><th>MDN</th><th>Plan</th><th>Type</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($vp_orders as $o): ?>
        <tr>
          <td><?= htmlspecialchars($o['id'] ?? '—') ?></td>
          <td><?= htmlspecialchars($o['mdn'] ?? '—') ?></td>
          <td><?= htmlspecialchars($o['plan_id'] ?? $o['plan'] ?? '—') ?></td>
          <td><?= htmlspecialchars($o['type'] ?? '—') ?></td>
          <td><?= htmlspecialchars($o['status'] ?? '—') ?></td>
          <td style="color:#64748b;"><?= htmlspecialchars($o['created_at'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── FUND LOG TAB ──────────────────────────────────────────────────────── -->
  <?php elseif ($tab === 'funds'): ?>
  <div class="card">
    <div class="card-title">📋 Complete Fund Addition Log</div>
    <?php if (empty($fund_logs)): ?>
      <p style="color:#64748b;font-size:13px;">No fund additions logged yet. Add funds from each provider tab.</p>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Provider</th><th>Amount</th><th>Note</th><th>Admin</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($fund_logs as $fl): ?>
        <tr>
          <td><span style="text-transform:capitalize;font-weight:500;"><?= htmlspecialchars($fl['provider']) ?></span></td>
          <td style="color:#10b981;font-weight:700;">$<?= number_format($fl['amount'], 2) ?></td>
          <td><?= htmlspecialchars($fl['note']) ?></td>
          <td><?= htmlspecialchars($fl['admin_name'] ?? 'Admin') ?></td>
          <td style="color:#64748b;"><?= date('M j, Y g:i a', strtotime($fl['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /main -->
</body>
</html>
