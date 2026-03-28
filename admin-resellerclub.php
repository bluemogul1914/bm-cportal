<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin   = true;
$pdo        = getDB();

// ── Load credentials: env constant → provider_settings fallback ───────────────
$rc_uid = RC_AUTH_USERID;
$rc_key = RC_API_KEY;
if (empty($rc_uid)) {
    try {
        $rows = $pdo->prepare("SELECT key_name, key_value FROM provider_settings WHERE provider='resellerclub'");
        $rows->execute();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key_name'] === 'auth_userid') $rc_uid = $row['key_value'];
            if ($row['key_name'] === 'api_key')     $rc_key = $row['key_value'];
        }
    } catch (Exception $e) {}
}
$rc_connected = !empty($rc_uid) && !empty($rc_key);
$rc_base      = rtrim(RC_API_URL, '/');

$success_msg = '';
$error_msg   = '';
$tab         = $_GET['tab'] ?? 'domains';

// ── Core API helper ────────────────────────────────────────────────────────────
function rc_api(string $endpoint, array $params = [], string $method = 'GET'): array {
    global $rc_uid, $rc_key, $rc_base;
    $base_params = ['auth-userid' => $rc_uid, 'api-key' => $rc_key];
    $all_params  = array_merge($base_params, $params);

    // ResellerClub API uses array params like domain-name[]
    $query_parts = [];
    foreach ($all_params as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $item) {
                $query_parts[] = urlencode($k) . '=' . urlencode($item);
            }
        } else {
            $query_parts[] = urlencode($k) . '=' . urlencode((string)$v);
        }
    }
    $query_string = implode('&', $query_parts);

    // Endpoint must include .json
    $url = $rc_base . $endpoint;
    if (strtoupper($method) === 'GET') {
        $url .= '?' . $query_string;
    }

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $query_string;
        $opts[CURLOPT_URL]        = $rc_base . $endpoint;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => $err];
    $d = json_decode($resp, true);
    if ($d === null) return ['error' => "Invalid JSON (HTTP $code): " . substr($resp, 0, 200)];
    if (isset($d['status']) && $d['status'] === 'ERROR') {
        return ['error' => $d['message'] ?? $d['error'] ?? 'Unknown ResellerClub error', 'raw' => $d];
    }
    return array_merge(['_http' => $code], is_array($d) ? $d : ['data' => $d]);
}

// ── POST action handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // Save credentials
        if ($action === 'save_credentials') {
            $uid = trim($_POST['rc_auth_userid'] ?? '');
            $key = trim($_POST['rc_api_key']     ?? '');
            try {
                foreach (['auth_userid' => $uid, 'api_key' => $key] as $k => $v) {
                    $pdo->prepare("INSERT INTO provider_settings (provider,key_name,key_value) VALUES ('resellerclub',?,?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")
                        ->execute([$k, $v]);
                }
                $rc_uid = $uid; $rc_key = $key;
                $rc_connected = !empty($uid) && !empty($key);
                $success_msg  = 'ResellerClub credentials saved.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
            $tab = 'settings';

        // Test connection
        } elseif ($action === 'test_connection') {
            $res = rc_api('/resellers/details.json');
            if (isset($res['error'])) {
                $error_msg = 'Connection failed: ' . $res['error'];
            } else {
                $name = $res['username'] ?? $res['name'] ?? 'Reseller';
                $bal  = $res['sellingcurrencysymbol'] ?? '$';
                $succ = $res['currentamount'] ?? null;
                $success_msg = "Connected as $name." . ($succ !== null ? " Balance: $bal" . number_format((float)$succ, 2) : '');
            }

        // Check domain availability
        } elseif ($action === 'check_domain') {
            $domain = trim($_POST['domain'] ?? '');
            if (!$domain) {
                $error_msg = 'Please enter a domain name.';
            } else {
                $parts = explode('.', $domain, 2);
                $sld   = $parts[0];
                $tld   = $parts[1] ?? 'com';
                $res   = rc_api('/domains/available.json', [
                    'domain-name' => [$sld],
                    'tlds'        => [$tld],
                    'suggest-alternative' => 0,
                ]);
                if (isset($res['error'])) {
                    $error_msg = 'Check failed: ' . $res['error'];
                } else {
                    $domRes = $res[$domain] ?? $res[strtolower($domain)] ?? [];
                    $status = $domRes['status'] ?? '';
                    if (strtolower($status) === 'available') {
                        $price = $domRes['domainrecategory'][0]['sellingprice'] ?? null;
                        $success_msg = "✓ $domain is AVAILABLE!" . ($price ? " Price: $$price" : '');
                    } elseif (strtolower($status) === 'regthroughothers') {
                        $error_msg = "✗ $domain is already registered elsewhere.";
                    } elseif (strtolower($status) === 'regthroughus') {
                        $error_msg = "✗ $domain is already registered in your ResellerClub account.";
                    } else {
                        $success_msg = "$domain status: " . ucfirst($status ?: 'unknown');
                    }
                }
            }
            $tab = 'register';

        // Register domain
        } elseif ($action === 'register_domain') {
            $domain  = trim($_POST['domain_name'] ?? '');
            $years   = (int)($_POST['num_years'] ?? 1);
            $ns      = array_filter([
                trim($_POST['ns1'] ?? 'ns1.resellerclubhosting.com'),
                trim($_POST['ns2'] ?? 'ns2.resellerclubhosting.com'),
            ]);
            $cust_id = trim($_POST['customer_id'] ?? '');
            $reg_id  = trim($_POST['reg_contact_id'] ?? '');
            $adm_id  = trim($_POST['admin_contact_id'] ?? '');
            $tec_id  = trim($_POST['tech_contact_id'] ?? '');
            $bil_id  = trim($_POST['billing_contact_id'] ?? '');
            if (!$domain || !$cust_id || !$reg_id) {
                $error_msg = 'Domain, customer ID, and registrant contact ID are required.';
            } else {
                $params = [
                    'domain-name'        => $domain,
                    'years'              => $years,
                    'ns'                 => array_values($ns),
                    'customer-id'        => $cust_id,
                    'reg-contact-id'     => $reg_id,
                    'admin-contact-id'   => $adm_id ?: $reg_id,
                    'tech-contact-id'    => $tec_id  ?: $reg_id,
                    'billing-contact-id' => $bil_id  ?: $reg_id,
                    'invoice-option'     => 'NoInvoice',
                    'purchase-privacy-protection' => 0,
                    'auto-renew'         => 0,
                ];
                $res = rc_api('/domains/register.json', $params, 'POST');
                if (isset($res['error'])) {
                    $error_msg = "Registration failed: " . $res['error'];
                } else {
                    $success_msg = "$domain registered! Order ID: " . ($res['entityid'] ?? $res['orderid'] ?? 'N/A');
                }
            }
            $tab = 'register';

        // Renew domain
        } elseif ($action === 'renew_domain') {
            $order_id = (int)($_POST['order_id'] ?? 0);
            $years    = (int)($_POST['num_years'] ?? 1);
            $exp_date = trim($_POST['exp_date'] ?? '');
            $res = rc_api('/domains/renew.json', [
                'order-id'       => $order_id,
                'years'          => $years,
                'exp-date'       => $exp_date,
                'invoice-option' => 'NoInvoice',
            ], 'POST');
            if (isset($res['error'])) {
                $error_msg = "Renewal failed: " . $res['error'];
            } else {
                $success_msg = "Domain order #$order_id renewed for $years year(s).";
            }
            $tab = 'domains';

        // Toggle theft protection (lock/unlock)
        } elseif ($action === 'toggle_lock') {
            $order_id = (int)($_POST['order_id'] ?? 0);
            $lock     = (int)($_POST['lock'] ?? 1);
            $endpoint = $lock ? '/domains/enable-theft-protection.json' : '/domains/disable-theft-protection.json';
            $res = rc_api($endpoint, ['order-id' => $order_id], 'POST');
            if (isset($res['error'])) {
                $error_msg = "Lock toggle failed: " . $res['error'];
            } else {
                $success_msg = "Domain #$order_id " . ($lock ? 'locked' : 'unlocked') . " successfully.";
            }
            $tab = 'domains';

        // Modify nameservers
        } elseif ($action === 'modify_ns') {
            $order_id = (int)($_POST['order_id'] ?? 0);
            $ns = array_filter(array_map('trim', [
                $_POST['ns1'] ?? '', $_POST['ns2'] ?? '',
                $_POST['ns3'] ?? '', $_POST['ns4'] ?? '',
            ]));
            if (!$order_id || empty($ns)) {
                $error_msg = 'Order ID and at least one nameserver are required.';
            } else {
                $res = rc_api('/domains/modify-ns.json', ['order-id' => $order_id, 'ns' => array_values($ns)], 'POST');
                if (isset($res['error'])) {
                    $error_msg = "NS update failed: " . $res['error'];
                } else {
                    $success_msg = "Nameservers updated for order #$order_id.";
                }
            }
            $tab = 'dns';

        // Add DNS record
        } elseif ($action === 'add_dns_record') {
            $order_id  = (int)($_POST['order_id'] ?? 0);
            $rec_type  = $_POST['rec_type'] ?? 'A';
            $host      = trim($_POST['host'] ?? '');
            $value     = trim($_POST['value'] ?? '');
            $ttl       = (int)($_POST['ttl'] ?? 14400);
            $endpt_map = ['A'=>'/dns/manage/add-ipv4-record.json','AAAA'=>'/dns/manage/add-ipv6-record.json','CNAME'=>'/dns/manage/add-cname-record.json','MX'=>'/dns/manage/add-mx-record.json','TXT'=>'/dns/manage/add-txt-record.json'];
            $ep = $endpt_map[$rec_type] ?? '/dns/manage/add-ipv4-record.json';
            $params = ['order-id' => $order_id, 'host' => $host, 'value' => $value, 'ttl' => $ttl];
            if ($rec_type === 'MX') $params['priority'] = (int)($_POST['priority'] ?? 10);
            $res = rc_api($ep, $params, 'POST');
            if (isset($res['error'])) {
                $error_msg = "DNS record add failed: " . $res['error'];
            } else {
                $success_msg = "$rec_type record added for order #$order_id.";
            }
            $tab = 'dns';

        // Initiate transfer
        } elseif ($action === 'transfer_domain') {
            $domain  = trim($_POST['domain_name'] ?? '');
            $auth    = trim($_POST['auth_code']   ?? '');
            $cust_id = trim($_POST['customer_id'] ?? '');
            $reg_id  = trim($_POST['reg_contact_id'] ?? '');
            if (!$domain || !$auth || !$cust_id || !$reg_id) {
                $error_msg = 'Domain, auth code, customer ID, and contact ID are required.';
            } else {
                $res = rc_api('/domains/transfer.json', [
                    'domain-name'        => $domain,
                    'auth-code'          => $auth,
                    'customer-id'        => $cust_id,
                    'reg-contact-id'     => $reg_id,
                    'admin-contact-id'   => $reg_id,
                    'tech-contact-id'    => $reg_id,
                    'billing-contact-id' => $reg_id,
                    'invoice-option'     => 'NoInvoice',
                    'purchase-privacy-protection' => 0,
                    'auto-renew'         => 0,
                ], 'POST');
                if (isset($res['error'])) {
                    $error_msg = "Transfer failed: " . $res['error'];
                } else {
                    $success_msg = "Transfer initiated for $domain! Order ID: " . ($res['entityid'] ?? $res['orderid'] ?? 'N/A');
                }
            }
            $tab = 'transfer';
        }
    }
}

// ── Live data per tab ──────────────────────────────────────────────────────────
$rc_details   = [];
$domains      = [];
$contacts     = [];
$orders       = [];
$dns_records  = [];
$api_error    = '';

if ($rc_connected) {
    // Reseller details (balance etc.) — loaded on every tab for stat cards
    $dr = rc_api('/resellers/details.json');
    if (!isset($dr['error'])) $rc_details = $dr;

    if ($tab === 'domains') {
        $res = rc_api('/domains/search.json', [
            'no-of-records' => 50,
            'page-no'       => 1,
        ]);
        if (isset($res['error'])) { $api_error = $res['error']; }
        else {
            // RC returns recsindb + recsonpage + domain order objects keyed by orderid
            unset($res['_http'], $res['recsonpage'], $res['recsindb']);
            $domains = array_values($res);
        }

    } elseif ($tab === 'contacts') {
        $res = rc_api('/contacts/search.json', [
            'no-of-records' => 50,
            'page-no'       => 1,
        ]);
        if (isset($res['error'])) { $api_error = $res['error']; }
        else {
            unset($res['_http'], $res['recsonpage'], $res['recsindb']);
            $contacts = array_values($res);
        }

    } elseif ($tab === 'orders') {
        $res = rc_api('/orders/search.json', [
            'no-of-records' => 30,
            'page-no'       => 1,
        ]);
        if (isset($res['error'])) { $api_error = $res['error']; }
        else {
            unset($res['_http'], $res['recsonpage'], $res['recsindb']);
            $orders = array_values($res);
        }
    }
}

// Stats
$balance       = $rc_details['currentamount'] ?? null;
$currency_sym  = $rc_details['sellingcurrencysymbol'] ?? '$';
$domain_count  = count($domains);
$reseller_name = $rc_details['username'] ?? ($rc_details['name'] ?? '');

$expiring_soon = [];
$locked_count  = 0;
foreach ($domains as $d) {
    $exp = $d['endtime'] ?? $d['expirytime'] ?? null;
    if ($exp) {
        $exp_ts = (int)$exp;
        if ($exp_ts > time() && ($exp_ts - time()) < 30 * 86400) $expiring_soon[] = $d;
    }
    if (($d['domainstatus']['theftprotection'] ?? '') === 'enabled') $locked_count++;
}

$tlds = ['com','net','org','io','co','info','biz','us','me','online','store','app','tech','cloud','dev','in','co.uk'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResellerClub - Blue Mogul Admin</title>
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
                        <i class="fas fa-store text-sky-500 mr-2"></i>ResellerClub Integration
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">Domains, contacts, DNS, hosting orders, transfers &amp; reseller billing</p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($rc_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test-connection">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <a href="?tab=<?= $tab ?>" class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <a href="?tab=register" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-plus mr-1"></i>Register Domain
                        </a>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected<?= $reseller_name ? " · $reseller_name" : '' ?>
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium transition">
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
                <span>ResellerClub API error: <strong><?= htmlspecialchars($api_error) ?></strong></span>
            </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($rc_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">Auth ID: <?= htmlspecialchars(substr($rc_uid,0,6)).'…' ?></p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Credentials not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-balance">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Reseller Balance</p>
                    <p class="text-2xl font-bold text-sky-700" data-testid="text-balance">
                        <?= $balance !== null ? $currency_sym.number_format((float)$balance, 2) : '—' ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Available reseller funds</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-domains">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Domains (Page)</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-domain-count"><?= $domain_count ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $locked_count ?> theft-protected</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4 <?= count($expiring_soon) > 0 ? 'border-orange-200 bg-orange-50' : '' ?>" data-testid="card-expiring">
                    <p class="text-xs font-semibold <?= count($expiring_soon) > 0 ? 'text-orange-600' : 'text-gray-500' ?> uppercase mb-1">Expiring ≤30 Days</p>
                    <p class="text-2xl font-bold <?= count($expiring_soon) > 0 ? 'text-orange-600' : 'text-gray-900' ?>"><?= count($expiring_soon) ?: '0' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Need renewal action</p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'domains'  => ['fas fa-list',         'Domains'],
                    'register' => ['fas fa-plus-circle',   'Check & Register'],
                    'dns'      => ['fas fa-server',        'DNS & Nameservers'],
                    'contacts' => ['fas fa-address-book',  'Contacts'],
                    'orders'   => ['fas fa-receipt',       'All Orders'],
                    'transfer' => ['fas fa-exchange-alt',  'Transfers'],
                    'settings' => ['fas fa-cog',           'Settings'],
                ];
                foreach ($tabs as $t => [$icon, $label]):
                    $active = $tab === $t;
                ?>
                <a href="?tab=<?= $t ?>"
                   class="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition <?= $active ? 'bg-white text-sky-700 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                   data-testid="tab-<?= $t ?>">
                    <i class="<?= $icon ?> text-xs"></i><?= $label ?>
                    <?php if ($t === 'domains' && count($expiring_soon) > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-orange-500 text-white rounded-full text-[10px] font-bold"><?= count($expiring_soon) ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$rc_connected && $tab !== 'settings'): ?>
            <div class="bg-sky-50 border border-sky-200 rounded-lg p-6 text-center">
                <i class="fas fa-store text-sky-300 text-4xl mb-3"></i>
                <h3 class="text-base font-semibold text-sky-800 mb-2">ResellerClub Credentials Required</h3>
                <p class="text-sm text-sky-600 mb-4">Add your ResellerClub Reseller ID and API key to manage domains, contacts, DNS, and billing from this dashboard.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium transition">
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
                        $dname = $d['domainname'] ?? '—';
                        $oid   = $d['entityid']   ?? $d['orderid'] ?? '';
                        $exp   = isset($d['endtime']) ? date('M d Y', (int)$d['endtime']) : '?';
                    ?>
                    <span class="px-3 py-1.5 bg-orange-100 border border-orange-300 text-orange-800 rounded text-xs font-medium">
                        <?= htmlspecialchars($dname) ?> <span class="text-orange-400">(<?= $exp ?>)</span>
                        <?php if ($oid): ?>
                        <a href="#renew-modal" onclick="openRenewModal('<?= htmlspecialchars(addslashes($oid)) ?>','<?= htmlspecialchars(addslashes($dname)) ?>')"
                           class="ml-1 text-orange-600 underline">Renew</a>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-list text-sky-500 mr-2"></i>Domain Portfolio</h2>
                    <div class="flex items-center gap-3">
                        <input type="search" id="domain-search" placeholder="Filter domains…" onkeyup="filterDomains()"
                            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 w-48" data-testid="input-domain-search">
                        <span class="text-xs text-gray-400"><?= $domain_count ?> loaded</span>
                    </div>
                </div>
                <?php if (empty($domains) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-globe text-4xl mb-3"></i>
                    <p class="font-medium">No domains found.</p>
                    <p class="text-sm mt-1">Register your first domain on the <a href="?tab=register" class="text-sky-500 hover:underline">Check &amp; Register</a> tab.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" id="domain-table" data-testid="table-domains">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Domain</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Expires</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order ID</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Theft Prot.</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="domain-tbody">
                            <?php foreach ($domains as $d):
                                if (!is_array($d)) continue;
                                $dname  = $d['domainname'] ?? '—';
                                $oid    = $d['entityid']   ?? $d['orderid'] ?? '—';
                                $status = strtolower($d['currentstatus'] ?? 'unknown');
                                $exp_ts = isset($d['endtime']) ? (int)$d['endtime'] : 0;
                                $locked = ($d['domainstatus']['theftprotection'] ?? '') === 'enabled';
                                $days_left = $exp_ts ? ceil(($exp_ts - time()) / 86400) : null;
                                $exp_class = ($days_left !== null && $days_left <= 30 && $days_left > 0) ? 'text-orange-600 font-medium' : 'text-gray-600';
                            ?>
                            <tr class="hover:bg-gray-50 domain-row" data-testid="row-domain-<?= htmlspecialchars($dname) ?>">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900 domain-name"><?= htmlspecialchars($dname) ?></p>
                                    <a href="?tab=dns&order_id=<?= urlencode((string)$oid) ?>&domain=<?= urlencode($dname) ?>" class="text-xs text-sky-500 hover:underline">Manage DNS →</a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $status==='active'?'bg-green-100 text-green-700':($status==='inactive'?'bg-red-100 text-red-700':'bg-gray-100 text-gray-600') ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 <?= $exp_class ?>">
                                    <?= $exp_ts ? date('M d Y', $exp_ts) : '—' ?>
                                    <?php if ($days_left !== null && $days_left <= 30 && $days_left > 0): ?>
                                        <span class="block text-xs text-orange-500"><?= $days_left ?> days left</span>
                                    <?php elseif ($days_left !== null && $days_left <= 0): ?>
                                        <span class="block text-xs text-red-500">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars((string)$oid) ?></td>
                                <td class="px-4 py-3 text-center">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_lock">
                                        <input type="hidden" name="order_id" value="<?= $oid ?>">
                                        <input type="hidden" name="lock" value="<?= $locked ? 0 : 1 ?>">
                                        <button type="submit"
                                            class="text-base <?= $locked ? 'text-green-600 hover:text-green-800' : 'text-gray-300 hover:text-gray-500' ?>"
                                            title="<?= $locked ? 'Disable theft protection' : 'Enable theft protection' ?>"
                                            data-testid="button-lock-<?= htmlspecialchars($dname) ?>">
                                            <i class="fas <?= $locked ? 'fa-shield-alt' : 'fa-shield' ?>"></i>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <button type="button"
                                            onclick="openRenewModal('<?= htmlspecialchars(addslashes((string)$oid)) ?>','<?= htmlspecialchars(addslashes($dname)) ?>', '<?= $exp_ts ?>')"
                                            class="text-xs text-sky-600 hover:text-sky-800 font-medium" data-testid="button-renew-<?= htmlspecialchars($dname) ?>">
                                            <i class="fas fa-redo mr-1"></i>Renew
                                        </button>
                                        <a href="?tab=dns&order_id=<?= urlencode((string)$oid) ?>&domain=<?= urlencode($dname) ?>"
                                           class="text-xs text-blue-500 hover:text-blue-700 font-medium" data-testid="link-dns-<?= htmlspecialchars($dname) ?>">
                                            <i class="fas fa-server mr-1"></i>DNS
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

            <!-- Renew modal -->
            <div id="renew-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-redo text-sky-500 mr-2"></i>Renew Domain</h3>
                    <form method="POST" id="renew-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="renew_domain">
                        <input type="hidden" name="order_id" id="renew-order-id">
                        <input type="hidden" name="exp_date" id="renew-exp-date">
                        <p class="text-sm text-gray-600 mb-4">Renewing: <strong id="renew-domain-name"></strong></p>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Renewal Period</label>
                            <select name="num_years" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-renew-years">
                                <?php for ($y = 1; $y <= 10; $y++): ?>
                                <option value="<?= $y ?>"><?= $y ?> year<?= $y > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="flex gap-3 justify-end">
                            <button type="button" onclick="closeRenewModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-confirm-renew">
                                <i class="fas fa-redo mr-1"></i>Renew
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- CHECK & REGISTER TAB                                       -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'register'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Availability checker -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-search text-sky-500 mr-2"></i>Check Availability</h3>
                    <p class="text-sm text-gray-500 mb-4">Enter a full domain name (e.g. <code class="bg-gray-100 px-1 rounded text-xs">mybusiness.com</code>) to check if it's available.</p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="check_domain">
                        <div class="flex gap-2">
                            <input type="text" name="domain" required placeholder="example.com"
                                class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                                data-testid="input-check-domain">
                            <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-check-domain">
                                <i class="fas fa-search mr-1"></i>Check
                            </button>
                        </div>
                    </form>
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-400 mb-2 font-semibold uppercase">Supported TLDs</p>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($tlds as $t): ?>
                            <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-mono">.<?= $t ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Register form -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-plus-circle text-green-500 mr-2"></i>Register Domain</h3>
                    <p class="text-sm text-gray-500 mb-4">Requires a Customer ID and Contact ID already created in your ResellerClub account.</p>
                    <form method="POST" class="space-y-3" onsubmit="return confirm('Register this domain? This will charge your reseller balance.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register_domain">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Domain Name</label>
                            <input type="text" name="domain_name" required placeholder="example.com"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                                data-testid="input-reg-domain">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Years</label>
                                <select name="num_years" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-reg-years">
                                    <?php for ($y = 1; $y <= 10; $y++): ?>
                                    <option value="<?= $y ?>"><?= $y ?> year<?= $y > 1 ? 's' : '' ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Customer ID</label>
                                <input type="text" name="customer_id" placeholder="RC Customer ID"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-reg-customer-id">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Registrant Contact ID</label>
                            <input type="text" name="reg_contact_id" placeholder="Contact ID from ResellerClub"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-reg-contact-id">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">NS1</label>
                                <input type="text" name="ns1" value="ns1.resellerclubhosting.com"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono text-xs">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">NS2</label>
                                <input type="text" name="ns2" value="ns2.resellerclubhosting.com"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono text-xs">
                            </div>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-register">
                            <i class="fas fa-cart-plus mr-2"></i>Register Domain
                        </button>
                        <p class="text-xs text-gray-400 text-center">Charges apply to your reseller balance (<?= $balance !== null ? $currency_sym.number_format((float)$balance,2) : 'unknown' ?>).</p>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- DNS & NAMESERVERS TAB                                      -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'dns'): ?>
            <?php
            $sel_oid    = $_GET['order_id'] ?? '';
            $sel_domain = $_GET['domain'] ?? '';
            ?>

            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-search text-gray-400 mr-2"></i>Select a Domain</h3>
                <form method="GET" class="flex gap-3 flex-wrap">
                    <input type="hidden" name="tab" value="dns">
                    <select name="order_id" id="dns-order-select"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                        data-testid="select-dns-order" onchange="this.form.submit()">
                        <option value="">— Select domain —</option>
                        <?php
                        // Re-fetch domain list for selector
                        $dom_list = $domains;
                        if (empty($dom_list)) {
                            $dr2 = rc_api('/domains/search.json', ['no-of-records'=>50,'page-no'=>1]);
                            if (!isset($dr2['error'])) {
                                unset($dr2['_http'],$dr2['recsonpage'],$dr2['recsindb']);
                                $dom_list = array_values($dr2);
                            }
                        }
                        foreach ($dom_list as $d2):
                            if (!is_array($d2)) continue;
                            $d2n  = $d2['domainname'] ?? '';
                            $d2oi = $d2['entityid']   ?? $d2['orderid'] ?? '';
                        ?>
                        <option value="<?= htmlspecialchars((string)$d2oi) ?>"
                            data-domain="<?= htmlspecialchars($d2n) ?>"
                            <?= ($sel_oid == $d2oi) ? 'selected' : '' ?>><?= htmlspecialchars($d2n) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="domain" id="dns-domain-hidden" value="<?= htmlspecialchars($sel_domain) ?>">
                </form>
            </div>

            <?php if ($sel_oid): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Modify Nameservers -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-globe text-sky-500 mr-2"></i>Nameservers — <?= htmlspecialchars($sel_domain) ?></h3>
                    <form method="POST" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="modify_ns">
                        <input type="hidden" name="order_id" value="<?= htmlspecialchars($sel_oid) ?>">
                        <?php for ($n = 1; $n <= 4; $n++): ?>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 uppercase">NS<?= $n ?></label>
                            <input type="text" name="ns<?= $n ?>" placeholder="ns<?= $n ?>.provider.com"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-sky-500"
                                data-testid="input-ns<?= $n ?>">
                        </div>
                        <?php endfor; ?>
                        <button type="submit" class="w-full px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-set-ns">
                            <i class="fas fa-save mr-2"></i>Update Nameservers
                        </button>
                    </form>
                </div>

                <!-- Add DNS Record -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plus-circle text-green-500 mr-2"></i>Add DNS Record</h3>
                    <form method="POST" class="space-y-3" id="dns-add-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_dns_record">
                        <input type="hidden" name="order_id" value="<?= htmlspecialchars($sel_oid) ?>">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Record Type</label>
                            <select name="rec_type" id="dns-rec-type" onchange="toggleDNSFields()"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-dns-type">
                                <option value="A">A (IPv4 Address)</option>
                                <option value="AAAA">AAAA (IPv6 Address)</option>
                                <option value="CNAME">CNAME (Alias)</option>
                                <option value="MX">MX (Mail Exchange)</option>
                                <option value="TXT">TXT (Text Record)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Host</label>
                            <input type="text" name="host" required placeholder="@ or subdomain"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" data-testid="input-dns-host">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Value</label>
                            <input type="text" name="value" required placeholder="IP address, hostname, or text value"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" data-testid="input-dns-value">
                        </div>
                        <div id="dns-priority-field" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Priority (MX)</label>
                            <input type="number" name="priority" value="10" min="1" max="100"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-dns-priority">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">TTL (seconds)</label>
                            <select name="ttl" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="select-dns-ttl">
                                <option value="300">300 (5 min)</option>
                                <option value="3600">3600 (1 hour)</option>
                                <option value="14400" selected>14400 (4 hours)</option>
                                <option value="86400">86400 (1 day)</option>
                            </select>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-dns">
                            <i class="fas fa-plus mr-2"></i>Add Record
                        </button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg border border-gray-200 p-10 text-center text-gray-400">
                <i class="fas fa-server text-4xl mb-3"></i>
                <p class="font-medium">Select a domain above to manage its DNS and nameservers.</p>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- CONTACTS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'contacts'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-address-book text-indigo-500 mr-2"></i>Contacts</h2>
                    <span class="text-xs text-gray-400"><?= count($contacts) ?> loaded</span>
                </div>
                <?php if (empty($contacts) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-address-book text-4xl mb-3"></i>
                    <p class="font-medium">No contacts found.</p>
                    <p class="text-sm mt-1">Create contacts via the <a href="https://manage.resellerclub.com" target="_blank" class="text-sky-500 hover:underline">ResellerClub panel</a>.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-contacts">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Contact ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Phone</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Country</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($contacts as $c):
                                if (!is_array($c)) continue;
                                $cid    = $c['entity.entityid'] ?? $c['entityid'] ?? '—';
                                $cname  = ($c['contact.name'] ?? $c['name'] ?? '—');
                                $cemail = $c['contact.emailaddr'] ?? $c['emailaddr'] ?? '—';
                                $cphone = $c['contact.telno'] ?? $c['telno'] ?? '—';
                                $cctry  = $c['contact.country'] ?? $c['country'] ?? '—';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-contact-<?= htmlspecialchars((string)$cid) ?>">
                                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars((string)$cid) ?></td>
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($cname) ?></td>
                                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($cemail) ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($cphone) ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars(strtoupper($cctry)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ALL ORDERS TAB                                             -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'orders'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-receipt text-green-500 mr-2"></i>All Orders</h2>
                    <span class="text-xs text-gray-400"><?= count($orders) ?> loaded</span>
                </div>
                <?php if (empty($orders) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-receipt text-4xl mb-3"></i>
                    <p class="font-medium">No orders found.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-orders">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($orders as $o):
                                if (!is_array($o)) continue;
                                $oid   = $o['entityid'] ?? $o['orderid'] ?? '—';
                                $odesc = $o['description'] ?? $o['domainname'] ?? '—';
                                $otype = $o['productcategory'] ?? $o['type'] ?? '—';
                                $ost   = strtolower($o['currentstatus'] ?? '—');
                                $ocr   = isset($o['creationtime']) ? date('M d Y', (int)$o['creationtime']) : '—';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-order-<?= htmlspecialchars((string)$oid) ?>">
                                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars((string)$oid) ?></td>
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($odesc) ?></td>
                                <td class="px-4 py-3 text-gray-500 capitalize"><?= htmlspecialchars($otype) ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $ost==='active'?'bg-green-100 text-green-700':($ost==='inactive'?'bg-red-100 text-red-700':'bg-gray-100 text-gray-600') ?>">
                                        <?= ucfirst($ost) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs"><?= $ocr ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- TRANSFERS TAB                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'transfer'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg border border-gray-200 p-6 self-start">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-exchange-alt text-orange-500 mr-2"></i>Transfer Domain In</h3>
                    <p class="text-sm text-gray-500 mb-4">Move a domain from another registrar into your ResellerClub account. Requires a Customer ID and Contact ID.</p>
                    <form method="POST" class="space-y-3" onsubmit="return confirm('Submit transfer? This will charge your reseller balance.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="transfer_domain">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Domain Name</label>
                            <input type="text" name="domain_name" required placeholder="example.com"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-transfer-domain">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Authorization / EPP Code</label>
                            <input type="text" name="auth_code" required placeholder="Auth code from current registrar"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" data-testid="input-transfer-auth">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Customer ID</label>
                                <input type="text" name="customer_id" placeholder="RC Customer ID"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-transfer-customer">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Contact ID</label>
                                <input type="text" name="reg_contact_id" placeholder="RC Contact ID"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" data-testid="input-transfer-contact">
                            </div>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-transfer">
                            <i class="fas fa-exchange-alt mr-2"></i>Initiate Transfer
                        </button>
                    </form>
                    <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <p class="text-xs text-yellow-700"><strong>Before transferring:</strong> Unlock the domain at the current registrar, disable WHOIS privacy, and obtain the EPP/auth code.</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Transfer Process</h3>
                    <ol class="space-y-3">
                        <?php foreach ([
                            ['Unlock domain at current registrar', 'Log in to your current registrar and disable the domain transfer lock.'],
                            ['Disable WHOIS privacy', 'Temporarily disable WHOIS privacy so the registrant email is accessible for confirmation.'],
                            ['Request EPP / auth code', 'Get the authorization code from your current registrar (also called transfer auth, EPP code, or secret code).'],
                            ['Submit transfer here', 'Fill in the form with the domain, auth code, and ResellerClub customer/contact IDs.'],
                            ['Approve email confirmation', 'Watch for a confirmation email sent to the domain\'s registrant address and approve the transfer.'],
                        ] as $i => [$step, $desc]): ?>
                        <li class="flex items-start gap-3">
                            <span class="flex-shrink-0 w-6 h-6 bg-sky-100 text-sky-700 rounded-full text-xs font-bold flex items-center justify-center"><?= $i+1 ?></span>
                            <div>
                                <p class="text-sm font-semibold text-gray-800"><?= $step ?></p>
                                <p class="text-xs text-gray-500 mt-0.5"><?= $desc ?></p>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SETTINGS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'settings'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Credentials -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-key text-yellow-500 mr-2"></i>API Credentials</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Find your Reseller ID and API key in your ResellerClub panel under
                        <a href="https://manage.resellerclub.com/kb/answer/744" target="_blank" class="text-sky-500 hover:underline">Settings → API</a>.
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_credentials">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reseller Auth User ID</label>
                            <input type="text" name="rc_auth_userid" value="<?= htmlspecialchars($rc_uid) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                                placeholder="Your numeric reseller ID" data-testid="input-rc-uid">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                            <input type="password" name="rc_api_key" value="<?= htmlspecialchars($rc_key) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                                placeholder="••••••••" autocomplete="off" data-testid="input-rc-key">
                            <p class="text-xs text-gray-400 mt-1"><?= $rc_connected ? '✓ Credentials stored.' : 'No credentials saved yet.' ?></p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="flex-1 px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-creds">
                                <i class="fas fa-save mr-2"></i>Save Credentials
                            </button>
                            <?php if ($rc_connected): ?>
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

                <!-- About + links -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About ResellerClub API</h3>
                    <p class="text-sm text-gray-600 mb-4">ResellerClub provides a RESTful HTTP API (httpapi.com) for managing domain registration, hosting, contacts, DNS, and billing. Requests use <code class="bg-gray-100 px-1 rounded text-xs">auth-userid</code> + <code class="bg-gray-100 px-1 rounded text-xs">api-key</code> as query parameters.</p>
                    <div class="space-y-2 mb-4">
                        <?php foreach ([
                            ['fas fa-book','sky','ResellerClub API Documentation','https://manage.resellerclub.com/kb/answer/744'],
                            ['fas fa-store','sky','ResellerClub Panel','https://manage.resellerclub.com'],
                            ['fas fa-list','sky','My Orders','https://manage.resellerclub.com/servlet/com.customerprofile.web.customer.list.AllOrders'],
                        ] as [$ico,$col,$lbl,$href]): ?>
                        <a href="<?= $href ?>" target="_blank" class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="<?= $ico ?> text-sky-500 w-4"></i><span><?= $lbl ?></span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-3 bg-sky-50 rounded-lg border border-sky-200">
                        <p class="text-xs text-sky-700 font-semibold mb-1">Env variables (optional)</p>
                        <p class="text-xs text-sky-600">Set <code class="bg-sky-100 px-1 rounded">RC_AUTH_USERID</code> and <code class="bg-sky-100 px-1 rounded">RC_API_KEY</code> as Replit secrets. They take priority over the saved credentials.</p>
                    </div>
                </div>

                <!-- Feature grid -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-purple-500 mr-2"></i>Supported API Features</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php
                        $features = [
                            ['fas fa-list',         'sky',    'Domain Portfolio',       'List all registered domains with status, expiry, and order IDs'],
                            ['fas fa-search',       'blue',   'Availability Check',     'Check domain availability across all supported TLDs instantly'],
                            ['fas fa-cart-plus',    'green',  'Domain Registration',    'Register new domains with custom nameservers and contacts'],
                            ['fas fa-redo',         'sky',    'Domain Renewal',         'Renew any domain for 1–10 years via the renewal modal'],
                            ['fas fa-shield-alt',   'yellow', 'Theft Protection',       'Enable/disable theft protection (transfer lock) per domain'],
                            ['fas fa-server',       'blue',   'Nameserver Management',  'Update nameservers for any domain in your portfolio'],
                            ['fas fa-database',     'green',  'DNS Records',            'Add A, AAAA, CNAME, MX, TXT records to DNS zone'],
                            ['fas fa-address-book', 'indigo', 'Contact Management',     'View registrant, admin, and tech contacts in your account'],
                            ['fas fa-receipt',      'green',  'Order Browser',          'Browse all product orders (domains, hosting, SSL) by status'],
                            ['fas fa-exchange-alt', 'orange', 'Domain Transfers',       'Initiate inbound transfers with EPP auth codes'],
                            ['fas fa-wallet',       'sky',    'Reseller Balance',       'View your live reseller account balance in real time'],
                        ];
                        foreach ($features as [$icon,$color,$title,$desc]):
                            $bg = ['sky'=>'bg-sky-50 border-sky-200','blue'=>'bg-blue-50 border-blue-200','green'=>'bg-green-50 border-green-200','yellow'=>'bg-yellow-50 border-yellow-200','orange'=>'bg-orange-50 border-orange-200','indigo'=>'bg-indigo-50 border-indigo-200'];
                            $ic = ['sky'=>'text-sky-600','blue'=>'text-blue-600','green'=>'text-green-600','yellow'=>'text-yellow-600','orange'=>'text-orange-500','indigo'=>'text-indigo-600'];
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

            <?php endif; // end $rc_connected ?>
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

// Renew modal
function openRenewModal(orderId, domainName, expTs) {
    document.getElementById('renew-order-id').value  = orderId;
    document.getElementById('renew-domain-name').textContent = domainName;
    if (expTs) document.getElementById('renew-exp-date').value = expTs;
    document.getElementById('renew-modal').classList.remove('hidden');
}
function closeRenewModal() {
    document.getElementById('renew-modal').classList.add('hidden');
}
document.getElementById('renew-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeRenewModal();
});

// Show/hide MX priority field based on record type
function toggleDNSFields() {
    const t = document.getElementById('dns-rec-type').value;
    document.getElementById('dns-priority-field').classList.toggle('hidden', t !== 'MX');
}

// DNS domain selector: sync hidden tld field
const dnsSel = document.getElementById('dns-order-select');
if (dnsSel) {
    dnsSel.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        document.getElementById('dns-domain-hidden').value = opt.getAttribute('data-domain') || '';
    });
}
</script>
</body>
</html>
