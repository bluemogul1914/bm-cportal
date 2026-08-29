<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin   = true;
$pdo        = getDB();

// ── Ensure settings table exists (self-bootstrapping) ────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key   VARCHAR(255) PRIMARY KEY,
        setting_value TEXT
    )");
} catch (Exception $e) {}

// ── Load credentials: env constant → system_settings fallback ────────────────
$dh_client_id     = DANDH_CLIENT_ID;
$dh_client_secret = DANDH_CLIENT_SECRET;
$dh_account       = DANDH_ACCOUNT_NUMBER;
$dh_env           = 'test'; // test | live
$dh_tenant        = DANDH_TENANT;

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('dandh_client_id','dandh_client_secret','dandh_account','dandh_env','dandh_tenant')");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['setting_key']] = $r['setting_value'];
    if (!empty($map['dandh_client_id']))     $dh_client_id     = $map['dandh_client_id'];
    if (!empty($map['dandh_client_secret'])) $dh_client_secret = $map['dandh_client_secret'];
    if (!empty($map['dandh_account']))       $dh_account       = $map['dandh_account'];
    if (!empty($map['dandh_env']))           $dh_env           = $map['dandh_env'];
    if (!empty($map['dandh_tenant']))        $dh_tenant        = $map['dandh_tenant'];
} catch (Exception $e) {}

$dh_connected = !empty($dh_client_id) && !empty($dh_client_secret);
$dh_api_base  = $dh_env === 'live' ? 'https://api.dandh.com' : 'https://test.api.dandh.com';
// D&H runs a separate auth domain for the test environment.
$dh_auth_base = $dh_env === 'live' ? 'https://auth.dandh.com' : 'https://test.auth.dandh.com';

$success_msg = '';
$error_msg   = '';
$api_error   = '';
$tab         = $_GET['tab'] ?? 'overview';

// Catalog search render state (set by the search_items POST handler)
$search_results = [];
$search_q       = '';
$search_scanned = 0;
$search_has_more = false;
$search_next_scroll = '';
$search_err     = '';

// Deep-link from catalog search results: ?tab=price&dh_item_id=...&dh_lookup=avail
$deep_lookup_item = strtoupper(trim($_GET['dh_item_id'] ?? ''));
$deep_lookup_mode = $_GET['dh_lookup'] ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? '' : '');

// ── OAuth2 token cache ───────────────────────────────────────────────────────
function dh_get_token(): array {
    global $pdo, $dh_client_id, $dh_client_secret, $dh_auth_base;
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('dandh_token','dandh_token_expires')");
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['setting_key']] = $r['setting_value'];
        if (!empty($map['dandh_token']) && !empty($map['dandh_token_expires']) && (int)$map['dandh_token_expires'] > time() + 60) {
            return ['token' => $map['dandh_token'], 'expires' => (int)$map['dandh_token_expires']];
        }
    } catch (Exception $e) {}

    $ch = curl_init($dh_auth_base . '/api/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $dh_client_id,
            'client_secret' => $dh_client_secret,
            'scope'         => 'resource.READ resource.WRITE',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => "Token request failed: $err"];
    $d = json_decode($resp, true);
    if (!is_array($d) || !isset($d['access_token'])) {
        $msg = $d['error_description'] ?? $d['error'] ?? "HTTP $code";
        return ['error' => "OAuth token error: $msg"];
    }
    $expires = time() + (int)($d['expires_in'] ?? 3600) - 60;
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('dandh_token', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value")
            ->execute([$d['access_token']]);
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('dandh_token_expires', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value")
            ->execute([(string)$expires]);
    } catch (Exception $e) {}
    return ['token' => $d['access_token'], 'expires' => $expires];
}

// ── Core API helper ────────────────────────────────────────────────────────────
function dh_api(string $endpoint, array $query = [], string $method = 'GET', ?array $body = null): array {
    global $dh_api_base, $dh_account, $dh_tenant;
    $tok = dh_get_token();
    if (isset($tok['error'])) return ['error' => $tok['error']];
    $token = $tok['token'];

    $url = $dh_api_base . '/customerOrderManagement/v2' . $endpoint;
    if (strpos($url, '{accountNumber}') !== false) {
        $url = str_replace('{accountNumber}', urlencode($dh_account), $url);
    }
    if ($query) $url .= '?' . http_build_query($query);

    $headers = [
        "Authorization: Bearer $token",
        "dandh-tenant: $dh_tenant",
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST]          = true;
        $opts[CURLOPT_POSTFIELDS]    = $body === null ? '' : json_encode($body);
        $opts[CURLOPT_HTTPHEADER][]  = 'Content-Type: application/json';
    } elseif (strtoupper($method) === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($cerr) return ['error' => "API request failed: $cerr"];
    $d = json_decode($resp, true);
    if ($d === null) return ['error' => "Invalid JSON (HTTP $code): " . substr($resp, 0, 300), 'raw' => substr($resp, 0, 3000)];
    if ($code >= 400) {
        $msg = $d['message'] ?? $d['errorName'] ?? "HTTP $code";
        return ['error' => $msg, 'raw' => $d];
    }
    return array_merge(['_http' => $code], is_array($d) ? $d : ['data' => $d]);
}

// ── Catalog search helper ─────────────────────────────────────────────────────
// D&H has no server-side free-text search, so we page through the item catalog
// and filter on description / vendor / itemId / vendorItemId / UPC.
// Scans a bounded number of pages per request (~1.5s/page) and returns the scroll
// cursor + "has_more" so the UI can continue paginating with "Load more".
function dh_search_items(string $query, string $scrollId = '', int $maxPages = 6): array {
    global $dh_account, $dh_tenant;
    $q = strtolower(trim($query));
    if ($q === '') return ['error' => 'Search query is empty'];

    $matches    = [];
    $scanned    = 0;
    $pageSize   = 200;
    $scroll     = $scrollId;
    $has_more   = false;

    for ($page = 0; $page < $maxPages; $page++) {
        $qs = ['pageSize' => $pageSize];
        if ($scroll !== '') $qs['scrollId'] = $scroll;
        $res = dh_api("/customers/{accountNumber}/items", $qs);
        if (isset($res['error'])) {
            // If we already collected matches, return them with an error flag
            if ($matches) return ['matches' => $matches, 'scanned' => $scanned, 'has_more' => false, 'next_scroll' => '', 'error' => $res['error']];
            return ['error' => $res['error']];
        }

        $els = $res['elements'] ?? [];
        $scanned += count($els);
        foreach ($els as $el) {
            $hay = strtolower(implode(' ', [
                $el['itemId']         ?? '',
                $el['description']    ?? '',
                $el['vendorName']     ?? '',
                $el['vendorItemId']   ?? '',
                $el['universalProductCode'] ?? '',
            ]));
            if (strpos($hay, $q) !== false) {
                $matches[] = $el;
            }
        }

        if (!empty($res['hasNext']) && !empty($res['scrollId'])) {
            $scroll = $res['scrollId'];
        } else {
            $has_more = false;
            break;
        }

        // Stop early if we already have a full page of matches to show
        if (count($matches) >= $pageSize) {
            $has_more = true;
            break;
        }
        // Always leave the cursor at the *next* page so "Load more" continues.
        $has_more = true;
    }

    return [
        'matches'    => $matches,
        'scanned'    => $scanned,
        'has_more'   => $has_more,
        'next_scroll' => $scroll,
    ];
}

// ── POST action handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // Save credentials
        if ($action === 'save_credentials') {
            $dh_client_id     = trim($_POST['dh_client_id']     ?? '');
            $dh_client_secret = trim($_POST['dh_client_secret'] ?? '');
            $dh_account       = trim($_POST['dh_account']       ?? '');
            $dh_env           = ($_POST['dh_env'] ?? 'test') === 'live' ? 'live' : 'test';
            $dh_tenant        = trim($_POST['dh_tenant']        ?? 'dhus');
            try {
                $vals = [
                    'dandh_client_id'     => $dh_client_id,
                    'dandh_client_secret' => $dh_client_secret,
                    'dandh_account'       => $dh_account,
                    'dandh_env'           => $dh_env,
                    'dandh_tenant'        => $dh_tenant,
                ];
                foreach ($vals as $k => $v) {
                    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value")
                        ->execute([$k, $v]);
                }
                // Invalidate cached token on credential change
                $pdo->exec("DELETE FROM system_settings WHERE setting_key IN ('dandh_token','dandh_token_expires')");
                $dh_connected = !empty($dh_client_id) && !empty($dh_client_secret);
                $dh_api_base  = $dh_env === 'live' ? 'https://api.dandh.com' : 'https://test.api.dandh.com';
                $success_msg  = 'D&H credentials saved.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
            $tab = 'settings';

        // Test connection
        } elseif ($action === 'test_connection') {
            $tok = dh_get_token();
            if (isset($tok['error'])) {
                $error_msg = 'Connection failed: ' . $tok['error'];
            } else {
                $success_msg = 'Connected — OAuth access token acquired.';
                $tab = 'overview';
            }

        // Price lookup
        } elseif ($action === 'lookup_price') {
            $item = strtoupper(trim($_POST['dh_item_id'] ?? ''));
            if ($item === '') {
                $error_msg = 'Please enter a D&H item number.';
            } else {
                $res = dh_api("/customers/{accountNumber}/items/$item/price", ['quantity' => (int)($_POST['dh_qty'] ?? 1) ?: 1]);
                if (isset($res['error'])) {
                    $api_error = $res['error'];
                } else {
                    $lookup_result = $res;
                }
            }
            $tab = 'price';

        // Availability lookup
        } elseif ($action === 'lookup_avail') {
            $item = strtoupper(trim($_POST['dh_item_id'] ?? ''));
            if ($item === '') {
                $error_msg = 'Please enter a D&H item number.';
            } else {
                $res = dh_api("/customers/{accountNumber}/items/$item/availability");
                if (isset($res['error'])) {
                    $api_error = $res['error'];
                } else {
                    $avail_result = $res;
                }
            }
            $tab = 'price';

        // Item inquiry
        } elseif ($action === 'lookup_item') {
            $item = strtoupper(trim($_POST['dh_item_id'] ?? ''));
            if ($item === '') {
                $error_msg = 'Please enter a D&H item number.';
            } else {
                $res = dh_api("/customers/{accountNumber}/items/$item");
                if (isset($res['error'])) {
                    $api_error = $res['error'];
                } else {
                    $item_result = $res;
                }
            }
            $tab = 'items';

        // Product catalog search (free-text: description / vendor / SKU)
        } elseif ($action === 'search_items') {
            $q        = trim($_POST['dh_search'] ?? '');
            $loadMore = !empty($_POST['dh_load_more']);
            $prevScroll   = trim($_POST['dh_scroll'] ?? '');
            $prevScanned  = (int)($_POST['dh_scanned'] ?? 0);
            if ($q === '' && !$loadMore) {
                $error_msg = 'Please enter a product name, vendor, or SKU to search.';
                $tab = 'items';
            } else {
                if ($loadMore && $q === '') {
                    $q = trim($_POST['dh_prev_q'] ?? '');
                }
                $search = dh_search_items($q, $loadMore ? $prevScroll : '');
                if (isset($search['error'])) {
                    $api_error = $search['error'];
                } else {
                    $search_q         = $q;
                    $search_results   = $search['matches'];
                    $search_scanned   = $prevScanned + $search['scanned'];
                    $search_has_more  = $search['has_more'];
                    $search_next_scroll = $search['next_scroll'];
                }
            }
            $tab = 'items';

        // Order tracking
        } elseif ($action === 'track_order') {
            $orderNum = trim($_POST['dh_order_number'] ?? '');
            if ($orderNum === '') {
                $error_msg = 'Please enter an order number or PO number.';
            } else {
                $res = dh_api("/customers/{accountNumber}/salesOrders/tracking", ['orderNumber' => (int)$orderNum]);
                if (isset($res['error'])) {
                    $api_error = $res['error'];
                } else {
                    $order_result = $res;
                }
            }
            $tab = 'orders';
        }
    }
}

// GET deep-link lookup from catalog search (only when not a POST).
// Note: the Node PHP wrapper sets REQUEST_METHOD='POST' for POST requests but leaves
// it unset on GET, so check "not POST" rather than "is GET".
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && $deep_lookup_item !== '' && $dh_connected) {
    if ($deep_lookup_mode === 'avail') {
        $res = dh_api("/customers/{accountNumber}/items/$deep_lookup_item/availability");
        if (isset($res['error'])) { $api_error = $res['error']; } else { $avail_result = $res; }
    } else {
        $res = dh_api("/customers/{accountNumber}/items/$deep_lookup_item/price", ['quantity' => 1]);
        if (isset($res['error'])) { $api_error = $res['error']; } else { $lookup_result = $res; }
    }
    $tab = 'price';
}

// ── Live data per tab ─────────────────────────────────────────────────────────
$overview_stats = [];
if ($tab === 'overview' && $dh_connected) {
    // Lightweight connectivity probe: fetch carriers list (cheap GET)
    $probe = dh_api("/customers/{accountNumber}/carriers", ['pageSize' => 5]);
    if (!isset($probe['error'])) {
        $overview_stats['carriers'] = $probe['carriers'] ?? $probe['data'] ?? [];
    } else {
        $overview_stats['error'] = $probe['error'];
    }
}

// ── Render ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D&amp;H Distributing - Blue Mogul Admin</title>
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
                        <i class="fas fa-truck text-orange-500 mr-2"></i>D&amp;H Distributing
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">IT distribution — price, availability, item inquiry &amp; sales orders</p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($dh_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test-connection">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <a href="?tab=<?= htmlspecialchars($tab) ?>" class="px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected · <?= htmlspecialchars(strtoupper($dh_env)) ?>
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition">
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
                <span>D&amp;H API error: <strong><?= htmlspecialchars($api_error) ?></strong></span>
            </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($dh_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(strtoupper($dh_env)) ?> · Acct <?= htmlspecialchars($dh_account !== '' ? $dh_account : '—') ?></p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Credentials not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Environment</p>
                    <p class="text-lg font-bold text-gray-900"><?= htmlspecialchars(strtoupper($dh_env)) ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($dh_api_base) ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Account</p>
                    <p class="text-lg font-bold text-gray-900"><?= htmlspecialchars($dh_account !== '' ? $dh_account : '—') ?></p>
                    <p class="text-xs text-gray-400 mt-1">D&amp;H customer account number</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Carriers</p>
                    <p class="text-lg font-bold text-gray-900"><?= isset($overview_stats['carriers']) ? count($overview_stats['carriers']) : '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Available via API</p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'overview' => ['fas fa-tachometer-alt', 'Overview'],
                    'price'    => ['fas fa-tags',          'Price & Availability'],
                    'items'    => ['fas fa-box',           'Item Inquiry'],
                    'orders'   => ['fas fa-receipt',       'Order Tracking'],
                    'settings' => ['fas fa-cog',           'Settings'],
                ];
                foreach ($tabs as $t => [$icon, $label]):
                    $active = $tab === $t;
                ?>
                <a href="?tab=<?= htmlspecialchars($t) ?>"
                   class="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition <?= $active ? 'bg-white text-orange-700 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                   data-testid="tab-<?= htmlspecialchars($t) ?>">
                    <i class="<?= htmlspecialchars($icon) ?> text-xs"></i><?= htmlspecialchars($label) ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$dh_connected && $tab !== 'settings'): ?>
            <div class="bg-orange-50 border border-orange-200 rounded-lg p-6 text-center">
                <i class="fas fa-truck text-orange-300 text-4xl mb-3"></i>
                <h3 class="text-xl font-semibold text-orange-800 mb-2">D&amp;H Credentials Required</h3>
                <p class="text-sm text-orange-600 mb-4">Add your D&amp;H Client ID, Secret, and Account Number to look up pricing, availability, and orders.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fas fa-key mr-2"></i>Configure Credentials
                </a>
            </div>
            <?php else: ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- OVERVIEW TAB                                                -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'overview'): ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                        <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-search text-orange-500 mr-2"></i>Quick Lookup</h3>
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="lookup_price">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">D&amp;H Item Number</label>
                                <input type="text" name="dh_item_id" placeholder="e.g. TI83PLUS" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500" data-testid="input-quick-item">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Qty</label>
                                <input type="number" name="dh_qty" value="1" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="w-full px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-quick-price">
                                    <i class="fas fa-tags mr-1"></i>Get Price
                                </button>
                            </div>
                        </form>
                        <?php if (isset($lookup_result)): ?>
                            <div class="mt-4 bg-gray-50 rounded-lg border border-gray-200 p-4">
                                <p class="text-sm font-semibold text-gray-700"><?= htmlspecialchars($lookup_result['itemId'] ?? 'Item') ?></p>
                                <p class="text-2xl font-bold text-orange-600 mt-1">$<?= htmlspecialchars($lookup_result['salesPrice'] ?? '—') ?></p>
                                <?php if (!empty($lookup_result['rebate']['amount'])): ?>
                                    <p class="text-sm text-gray-600 mt-1">Rebate: $<?= htmlspecialchars($lookup_result['rebate']['amount']) ?><?= !empty($lookup_result['rebate']['endDate']) ? ' until ' . htmlspecialchars($lookup_result['rebate']['endDate']) : '' ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About D&amp;H</h3>
                        <p class="text-sm text-gray-600 mb-4">D&amp;H Distributing is a leading technology distributor. The Customer Order Management API provides real-time item pricing, inventory availability, and order entry.</p>
                        <div class="space-y-2">
                            <?php foreach ([
                                ['fas fa-book', 'sky', 'D&H API Documentation', 'https://www.dandh.com/docs/apidocs/'],
                                ['fas fa-globe', 'sky', 'D&H Website', 'https://www.dandh.com'],
                            ] as [$icon, $color, $label, $href]): ?>
                                <a href="<?= htmlspecialchars($href) ?>" target="_blank" class="flex items-center gap-2 text-sm text-sky-500 hover:underline">
                                    <i class="fas <?= htmlspecialchars($icon) ?> text-<?= htmlspecialchars($color) ?>-500 w-4"></i><?= htmlspecialchars($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- PRICE & AVAILABILITY TAB                                     -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'price'): ?>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-tags text-orange-500 mr-2"></i>Price &amp; Availability</h3>
                        <form method="POST" class="space-y-4">
                            <?= csrf_field() ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">D&amp;H Item Number</label>
                                <input type="text" name="dh_item_id" value="<?= htmlspecialchars($deep_lookup_item ?: ($_POST['dh_item_id'] ?? '')) ?>" placeholder="e.g. TI83PLUS" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500" data-testid="input-price-item">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                                <input type="number" name="dh_qty" value="1" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500">
                            </div>
                            <div class="flex gap-3">
                                <button type="submit" name="action" value="lookup_price" class="flex-1 px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-price">
                                    <i class="fas fa-tags mr-2"></i>Get Price
                                </button>
                                <button type="submit" name="action" value="lookup_avail" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition" data-testid="button-avail">
                                    <i class="fas fa-box-open mr-2"></i>Get Availability
                                </button>
                            </div>
                        </form>
                        <?php if (isset($lookup_result)): ?>
                            <div class="mt-4 bg-gray-50 rounded-lg border border-gray-200 p-4">
                                <p class="text-sm font-semibold text-gray-700"><?= htmlspecialchars($lookup_result['itemId'] ?? 'Item') ?></p>
                                <p class="text-2xl font-bold text-orange-600 mt-1">$<?= htmlspecialchars($lookup_result['salesPrice'] ?? '—') ?></p>
                                <?php if (!empty($lookup_result['rebate']['amount'])): ?>
                                    <p class="text-sm text-gray-600 mt-1">Rebate: $<?= htmlspecialchars($lookup_result['rebate']['amount']) ?><?= !empty($lookup_result['rebate']['endDate']) ? ' until ' . htmlspecialchars($lookup_result['rebate']['endDate']) : '' ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($avail_result)): ?>
                            <div class="mt-4 bg-gray-50 rounded-lg border border-gray-200 p-4">
                                <p class="text-sm font-semibold text-gray-700"><?= htmlspecialchars($avail_result['itemId'] ?? 'Item') ?> — Availability</p>
                                <p class="text-lg font-bold text-gray-900 mt-1">Total Available: <?= htmlspecialchars((string)($avail_result['totalAvailableQuantity'] ?? '—')) ?></p>
                                <?php if (!empty($avail_result['branchInventory'])): ?>
                                    <table class="mt-3 w-full text-sm">
                                        <thead><tr class="text-left text-gray-500 border-b"><th class="py-1">Branch</th><th class="py-1">Qty</th><th class="py-1">Replenish</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($avail_result['branchInventory'] as $bi): ?>
                                            <tr class="border-b border-gray-100">
                                                <td class="py-1 font-medium"><?= htmlspecialchars($bi['branch'] ?? '—') ?></td>
                                                <td class="py-1"><?= htmlspecialchars((string)($bi['availableQuantity'] ?? '—')) ?></td>
                                                <td class="py-1 text-gray-500"><?= htmlspecialchars($bi['stockReplenishDate'] ?? '—') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About Price &amp; Availability</h3>
                        <p class="text-sm text-gray-600 mb-4">Look up real-time D&amp;H item pricing and inventory availability across distribution centers.</p>
                        <ul class="text-sm text-gray-600 space-y-2">
                            <li><i class="fas fa-check text-green-500 mr-2"></i>Real-time sales price</li>
                            <li><i class="fas fa-check text-green-500 mr-2"></i>Rebate info when available</li>
                            <li><i class="fas fa-check text-green-500 mr-2"></i>Per-branch stock levels</li>
                        </ul>
                    </div>
                </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ITEM INQUIRY TAB                                             -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'items'): ?>
                <div class="space-y-6">
                    <!-- Exact item lookup -->
                    <div class="bg-white rounded-lg border border-gray-200 p-6 max-w-2xl">
                        <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-box text-orange-500 mr-2"></i>Item Inquiry</h3>
                        <p class="text-sm text-gray-500 mb-4">Look up a single item by its exact D&amp;H item number.</p>
                        <form method="POST" class="space-y-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="lookup_item">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">D&amp;H Item Number</label>
                                <input type="text" name="dh_item_id" placeholder="e.g. TI83PLUS" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500" data-testid="input-item-inquiry">
                            </div>
                            <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-item-inquiry">
                                <i class="fas fa-search mr-2"></i>Look Up Item
                            </button>
                        </form>
                        <?php if (isset($item_result)): ?>
                            <div class="mt-4 bg-gray-50 rounded-lg border border-gray-200 p-4">
                                <pre class="text-xs text-gray-700 whitespace-pre-wrap overflow-x-auto"><?= htmlspecialchars(json_encode($item_result, JSON_PRETTY_PRINT)) ?></pre>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Product catalog search -->
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-boxes text-orange-500 mr-2"></i>Search Catalog</h3>
                        <p class="text-sm text-gray-500 mb-4">Find items by product name, vendor, SKU, or UPC. D&amp;H has no server-side text search, so the portal scans the item catalog a page at a time.</p>
                        <form method="POST" class="flex flex-col sm:flex-row gap-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="search_items">
                            <input type="text" name="dh_search" value="<?= htmlspecialchars($search_q ?? ($_POST['dh_search'] ?? '')) ?>"
                                placeholder="e.g. graphics calculator, Cisco, 033317198658"
                                class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500"
                                data-testid="input-catalog-search">
                            <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition whitespace-nowrap" data-testid="button-catalog-search">
                                <i class="fas fa-search mr-2"></i>Search
                            </button>
                        </form>

                        <?php if ($search_q !== ''): ?>
                            <div class="mt-6">
                                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                                    <p class="text-sm text-gray-500">
                                        <span class="font-semibold text-gray-700"><?= count($search_results) ?></span> match(es) for
                                        "<span class="font-semibold text-gray-700"><?= htmlspecialchars($search_q) ?></span>"
                                        <span class="text-gray-400">· scanned <?= number_format($search_scanned) ?> items</span>
                                        <?php if (!empty($search_err)): ?>
                                            <span class="text-red-500">· <?= htmlspecialchars($search_err) ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <?php if (!$search_results): ?>
                                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center text-sm text-gray-500">
                                        No matching products found<?= $search_has_more ? ' in the scanned portion of the catalog — try "Load more".' : '.' ?>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto border border-gray-200 rounded-lg">
                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Item #</th>
                                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Description</th>
                                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Vendor</th>
                                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Retail</th>
                                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
                                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">UPC</th>
                                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <?php foreach ($search_results as $r): ?>
                                                <tr class="hover:bg-orange-50/40">
                                                    <td class="px-3 py-2 font-mono text-xs text-gray-800 whitespace-nowrap"><?= htmlspecialchars($r['itemId'] ?? '') ?></td>
                                                    <td class="px-3 py-2 text-gray-700 max-w-xs"><?= htmlspecialchars($r['description'] ?? '') ?></td>
                                                    <td class="px-3 py-2 text-gray-700 whitespace-nowrap"><?= htmlspecialchars($r['vendorName'] ?? '') ?></td>
                                                    <td class="px-3 py-2 text-right text-gray-700 whitespace-nowrap">$<?= htmlspecialchars(number_format((float)($r['estimatedRetailPrice'] ?? 0), 2)) ?></td>
                                                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap"><?= htmlspecialchars($r['itemType'] ?? '') ?></td>
                                                    <td class="px-3 py-2 font-mono text-xs text-gray-500 whitespace-nowrap"><?= htmlspecialchars($r['universalProductCode'] ?? '') ?></td>
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        <a href="?tab=price&dh_item_id=<?= urlencode($r['itemId'] ?? '') ?>" class="text-orange-600 hover:text-orange-800 text-xs font-medium">Price</a>
                                                        <span class="text-gray-300 mx-1">|</span>
                                                        <a href="?tab=price&dh_item_id=<?= urlencode($r['itemId'] ?? '') ?>&dh_lookup=avail" class="text-orange-600 hover:text-orange-800 text-xs font-medium">Avail</a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($search_has_more)): ?>
                                    <form method="POST" class="mt-4">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="search_items">
                                        <input type="hidden" name="dh_load_more" value="1">
                                        <input type="hidden" name="dh_prev_q" value="<?= htmlspecialchars($search_q ?? '') ?>">
                                        <input type="hidden" name="dh_scroll" value="<?= htmlspecialchars($search_next_scroll ?? '') ?>">
                                        <input type="hidden" name="dh_scanned" value="<?= (int)($search_scanned ?? 0) ?>">
                                        <button type="submit" class="px-4 py-2 border border-orange-300 text-orange-700 hover:bg-orange-50 rounded-lg text-sm font-medium transition" data-testid="button-load-more">
                                            <i class="fas fa-chevron-down mr-2"></i>Load more (scan next 1,200 items)
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ORDER TRACKING TAB                                            -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'orders'): ?>
                <div class="bg-white rounded-lg border border-gray-200 p-6 max-w-2xl">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-receipt text-orange-500 mr-2"></i>Order Tracking</h3>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="track_order">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">D&amp;H Order Number</label>
                            <input type="number" name="dh_order_number" placeholder="e.g. 123456" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500" data-testid="input-order-number">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-track-order">
                            <i class="fas fa-truck mr-2"></i>Track Order
                        </button>
                    </form>
                    <?php if (isset($order_result)): ?>
                        <div class="mt-4 bg-gray-50 rounded-lg border border-gray-200 p-4">
                            <pre class="text-xs text-gray-700 whitespace-pre-wrap overflow-x-auto"><?= htmlspecialchars(json_encode($order_result, JSON_PRETTY_PRINT)) ?></pre>
                        </div>
                    <?php endif; ?>
                </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SETTINGS TAB                                                  -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'settings'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Credentials -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-key text-yellow-500 mr-2"></i>API Credentials</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        D&amp;H provides OAuth2 client-credentials for the Customer Order Management API. Get your credentials from D&amp;H API support.
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_credentials">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Client ID</label>
                            <input type="text" name="dh_client_id" value="<?= htmlspecialchars($dh_client_id) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500"
                                placeholder="UUID client ID" data-testid="input-dh-client-id">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Secret Key</label>
                            <input type="password" name="dh_client_secret" value="<?= htmlspecialchars($dh_client_secret) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500"
                                placeholder="••••••••" autocomplete="off" data-testid="input-dh-secret">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Account Number</label>
                            <input type="text" name="dh_account" value="<?= htmlspecialchars($dh_account) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500"
                                placeholder="Your D&H customer account number" data-testid="input-dh-account">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
                            <select name="dh_env" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500" data-testid="input-dh-env">
                                <option value="test" <?= $dh_env === 'test' ? 'selected' : '' ?>>Test (test.api.dandh.com / test.auth.dandh.com)</option>
                                <option value="live" <?= $dh_env === 'live' ? 'selected' : '' ?>>Live (api.dandh.com / auth.dandh.com)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tenant Header</label>
                            <input type="text" name="dh_tenant" value="<?= htmlspecialchars($dh_tenant) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500"
                                placeholder="dhus" data-testid="input-dh-tenant">
                            <p class="text-xs text-gray-400 mt-1">D&H company code sent as the <code class="bg-gray-100 px-1 rounded text-xs">dandh-tenant</code> header — <code class="bg-gray-100 px-1 rounded text-xs">dhus</code> (US), <code class="bg-gray-100 px-1 rounded text-xs">dhca</code> (Canada), <code class="bg-gray-100 px-1 rounded text-xs">dsc</code> (SCALE).</p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="flex-1 px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-creds">
                                <i class="fas fa-save mr-2"></i>Save Credentials
                            </button>
                            <?php if ($dh_connected): ?>
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
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About D&amp;H API</h3>
                    <p class="text-sm text-gray-600 mb-4">D&amp;H Distributing provides an OAuth2-secured Customer Order Management API for real-time item pricing, availability, item inquiry, and sales order entry/tracking.</p>
                    <div class="space-y-2 mb-4">
                        <?php foreach ([
                            ['fas fa-book', 'sky', 'D&H API Documentation', 'https://www.dandh.com/docs/apidocs/'],
                            ['fas fa-envelope', 'sky', 'D&H API Support', 'mailto:apisupport@dandh.com'],
                        ] as [$icon, $color, $label, $href]): ?>
                        <a href="<?= htmlspecialchars($href) ?>" target="_blank" class="flex items-center gap-2 text-sm text-sky-500 hover:underline">
                            <i class="fas <?= htmlspecialchars($icon) ?> text-<?= htmlspecialchars($color) ?>-500 w-4"></i><?= htmlspecialchars($label) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4 text-xs text-gray-500">
                        <p class="font-semibold text-gray-700 mb-1">Auth Flow</p>
                        <code class="block whitespace-pre-wrap">POST /api/oauth/token
grant_type=client_credentials
client_id=…&amp;client_secret=…
→ Bearer access_token</code>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>