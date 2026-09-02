<?php
/**
 * D&H Distributing - Full Integration Dashboard
 * Customer Order Management REST API v2
 *
 * Tabs: Overview, Price & Availability, Item Inquiry,
 *       Catalog Search, Order Tracking, Settings
 */
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();

// ── Load credentials from DB ────────────────────────────────────────
$creds = ['dh_client_id'=>'','dh_client_secret'=>'','dh_account'=>DH_ACCOUNT,'dh_env'=>DH_ENV];
$allKeys = array_keys($creds);
foreach ($allKeys as $k) {
    try {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='$k' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $creds[$k] = $row['setting_value'];
    } catch (PDOException $e) {}
}
$dh_connected = !empty($creds['dh_client_id']) && !empty($creds['dh_client_secret']);
$dh_env_label = $creds['dh_env'] === 'PRODUCTION' ? 'Production' : 'TEST';
$dh_api_base  = $creds['dh_env'] === 'PRODUCTION' ? DH_API_URL_PROD : DH_API_URL_TEST;
$dh_api_key   = getenv('DH_API_KEY') ?: '';

// ── Helpers ─────────────────────────────────────────────────────────
function dh_curl_token($creds) {
    $body = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $creds['dh_client_id'],
        'client_secret' => $creds['dh_client_secret'],
    ]);
    $authUrl = ($creds['dh_env'] ?? 'TEST') === 'PRODUCTION' ? DH_AUTH_URL : DH_AUTH_URL_TEST;
    $ch = curl_init($authUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return ['error' => "Token HTTP $code", 'raw' => substr($r, 0, 300)];
    $j = json_decode($r, true);
    if (empty($j['access_token'])) return ['error' => 'No access_token in response'];
    return ['token' => $j['access_token'], 'expires_in' => $j['expires_in'] ?? 3600];
}

function dh_curl($creds, $path, $method = 'GET', $body = null) {
    $tok = dh_curl_token($creds);
    if (!empty($tok['error'])) return $tok;
    $base = $creds['dh_env'] === 'PRODUCTION' ? DH_API_URL_PROD : DH_API_URL_TEST;
    $url  = $base . $path;
    $headers = ['Authorization: Bearer ' . $tok['token'], 'Accept: application/json'];
    if ($body) $headers[] = 'Content-Type: application/json';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => "cURL: $err"];
    if ($code < 200 || $code >= 300) return ['error' => "HTTP $code", 'raw' => substr($r, 0, 500)];
    $j = json_decode($r, true);
    return $j ?: ['raw' => substr($r, 0, 300)];
}

// ── Tab state ───────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';

// ── POST handlers ───────────────────────────────────────────────────
$api_error   = '';
$api_success = '';
$lookup_result  = null;
$avail_result   = null;
$item_result    = null;
$search_results = null;
$tracking_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save credentials
    if ($action === 'save_creds') {
        require_csrf();
        $fields = [
            'dh_client_id'     => trim($_POST['dh_client_id'] ?? ''),
            'dh_client_secret' => trim($_POST['dh_client_secret'] ?? ''),
            'dh_account'       => trim($_POST['dh_account'] ?? DH_ACCOUNT),
            'dh_env'           => trim($_POST['dh_env'] ?? DH_ENV),
        ];
        foreach ($fields as $k => $v) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value");
            $stmt->execute([$k, $v]);
        }
        $api_success = 'Credentials saved.';
        // Reload
        foreach ($fields as $k => $v) $creds[$k] = $v;
        $dh_connected = !empty($creds['dh_client_id']) && !empty($creds['dh_client_secret']);
        $dh_env_label = $creds['dh_env'] === 'PRODUCTION' ? 'Production' : 'TEST';
        $dh_api_base  = $creds['dh_env'] === 'PRODUCTION' ? DH_API_URL_PROD : DH_API_URL_TEST;
    }

    // Price lookup
    if ($action === 'price_lookup') {
        $item = trim($_POST['dh_item'] ?? '');
        $man  = trim($_POST['dh_manufacturer'] ?: '');
        if (!$item) { $api_error = 'Please enter an item number.'; }
        else {
            $man = $man ?: $item; // treat as both if manufacturer not given
            $res = dh_curl($creds, '/customers/' . rawurlencode($creds['dh_account']) . '/items/' . rawurlencode($man) . '/' . rawurlencode($item) . '/priceAndAvailability');
            if (!empty($res['error'])) { $api_error = $res['error'] . ' ' . ($res['raw'] ?? ''); }
            else { $lookup_result = $res; }
        }
    }

    // Availability lookup (same endpoint, different display)
    if ($action === 'avail_lookup') {
        $item = trim($_POST['dh_item'] ?? '');
        $man  = trim($_POST['dh_manufacturer'] ?: '');
        if (!$item) { $api_error = 'Please enter an item number.'; }
        else {
            $man = $man ?: $item;
            $res = dh_curl($creds, '/customers/' . rawurlencode($creds['dh_account']) . '/items/' . rawurlencode($man) . '/' . rawurlencode($item) . '/priceAndAvailability');
            if (!empty($res['error'])) { $api_error = $res['error'] . ' ' . ($res['raw'] ?? ''); }
            else { $avail_result = $res; }
        }
    }

    // Item inquiry
    if ($action === 'item_inquiry') {
        $item = trim($_POST['dh_item'] ?? '');
        $man  = trim($_POST['dh_manufacturer'] ?: '');
        if (!$item) { $api_error = 'Please enter an item number.'; }
        else {
            $man = $man ?: $item;
            $res = dh_curl($creds, '/customers/' . rawurlencode($creds['dh_account']) . '/items/' . rawurlencode($man) . '/' . rawurlencode($item));
            if (!empty($res['error'])) { $api_error = $res['error'] . ' ' . ($res['raw'] ?? ''); }
            else { $item_result = $res; }
        }
    }

    // Catalog search
    if ($action === 'search_catalog') {
        $q = trim($_POST['dh_search'] ?? '');
        if (!$q) { $api_error = 'Please enter a product name to search for.'; }
        else {
            $loadMore  = !empty($_POST['dh_load_more']);
            $scrollId  = trim($_POST['dh_scroll'] ?? '');
            $scannedSoFar = (int)($_POST['dh_scanned'] ?? 0);
            $maxPages  = 6;
            $pageSize  = 200;
            $matches   = [];
            $scroll    = $scrollId;
            $pagesDone = 0;
            $hasMore   = true;
            $qLower    = strtolower($q);

            while ($pagesDone < $maxPages && $hasMore) {
                $path = '/customers/' . rawurlencode($creds['dh_account']) . '/items?pageSize=' . $pageSize;
                if ($scroll) $path .= '&scrollId=' . rawurlencode($scroll);
                $res = dh_curl($creds, $path);
                if (!empty($res['error'])) { $api_error = $res['error']; break; }
                $pagesDone++;
                $items = $res['items'] ?? $res['data'] ?? [];
                if (!is_array($items) || !count($items)) { $hasMore = false; break; }
                foreach ($items as $item) {
                    $haystack = ($item['description'] ?? $item['itemDescription'] ?? '') . ' '
                              . ($item['vendorName'] ?? $item['manufacturer'] ?? '') . ' '
                              . ($item['itemId'] ?? $item['itemNumber'] ?? '') . ' '
                              . ($item['vendorItemId'] ?? '') . ' '
                              . ($item['manufacturerItemId'] ?? '') . ' '
                              . ($item['universalProductCode'] ?? '');
                    if (stripos($haystack, $qLower) !== false) $matches[] = $item;
                }
                $scroll = $res['scrollId'] ?? $res['nextScrollId'] ?? '';
                if (!$scroll) $hasMore = false;
            }
            $search_results = [
                'matches'      => $matches,
                'scrollId'     => $scroll,
                'pagesScanned' => $pagesDone,
                'hasMore'      => $hasMore,
                'totalScanned' => $scannedSoFar + ($pagesDone * $pageSize),
                'query'        => $q,
            ];
        }
    }

    // Order tracking
    if ($action === 'order_tracking') {
        $res = dh_curl($creds, '/customers/' . rawurlencode($creds['dh_account']) . '/salesOrders/tracking');
        if (!empty($res['error'])) { $api_error = $res['error']; }
        else { $tracking_result = $res; }
    }

    // Test connection
    if ($action === 'test_connection') {
        $tok = dh_curl_token($creds);
        if (!empty($tok['error'])) { $api_error = 'Token failed: ' . $tok['error'] . ' ' . ($tok['raw'] ?? ''); }
        else {
            $carriers = dh_curl($creds, '/customers/' . rawurlencode($creds['dh_account']) . '/carriers');
            if (!empty($carriers['error'])) { $api_error = 'Token OK but carriers API error: ' . $carriers['error']; }
            else { $api_success = 'Connected — OAuth access token acquired. Environment: ' . $dh_env_label . ' · Account: ' . $creds['dh_account']; }
        }
    }
}

// ── GET deep-link from search results (price/avail) ─────────────────
$deep_lookup_item = trim($_GET['dh_item_id'] ?? '');
$deep_lookup_mode = trim($_GET['dh_lookup'] ?? '');
if ($deep_lookup_item !== '' && $dh_connected && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $man = $deep_lookup_item;
    if ($deep_lookup_mode === 'avail') {
        $avail_result = dh_curl($creds, '/customers/' . rawurlencode($creds['dh_account']) . '/items/' . rawurlencode($man) . '/' . rawurlencode($deep_lookup_item) . '/priceAndAvailability');
        $tab = 'price';
    } else {
        $lookup_result = dh_curl($creds, '/customers/' . rawurlencode($creds['dh_account']) . '/items/' . rawurlencode($man) . '/' . rawurlencode($deep_lookup_item) . '/priceAndAvailability');
        $tab = 'price';
    }
    if (!empty($lookup_result['error']) && $deep_lookup_mode !== 'avail') {
        $api_error = $lookup_result['error'];
        $lookup_result = null;
    }
    if (!empty($avail_result['error']) && $deep_lookup_mode === 'avail') {
        $api_error = $avail_result['error'];
        $avail_result = null;
    }
}

include 'includes/admin-header.php';
?>
<div class="flex h-screen bg-gray-50">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/admin-topbar.php'; ?>
        <main class="flex-1 overflow-y-auto p-6">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">D&amp;H Distributing</h1>
                    <p class="text-gray-500 text-sm mt-1">Customer Order Management REST API v2</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($dh_connected): ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">
                        <span class="h-2 w-2 bg-green-500 rounded-full"></span>
                        Connected · <?= $dh_env_label ?>
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">
                        <span class="h-2 w-2 bg-red-500 rounded-full"></span>
                        Not Configured
                    </span>
                    <?php endif; ?>
                    <span class="text-xs text-gray-400 bg-gray-100 px-2.5 py-1 rounded-md font-mono">Acct: <?= htmlspecialchars($creds['dh_account']) ?></span>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($api_success): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm"><?= htmlspecialchars($api_success) ?></div>
            <?php endif; ?>
            <?php if ($api_error): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm"><?= htmlspecialchars($api_error) ?></div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="border-b border-gray-200 mb-6">
                <nav class="flex space-x-6 -mb-px">
                    <?php $tabs = [
                        'overview' => ['Overview', 'fa-info-circle'],
                        'price'    => ['Price & Availability', 'fa-tag'],
                        'inquiry'  => ['Item Inquiry', 'fa-search'],
                        'catalog'  => ['Catalog Search', 'fa-list'],
                        'tracking' => ['Order Tracking', 'fa-truck'],
                        'settings' => ['Settings', 'fa-cog'],
                    ];
                    foreach ($tabs as $key => [$label, $icon]):
                        $active = $tab === $key;
                    ?>
                    <a href="?tab=<?= $key ?>" class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition <?= $active ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                        <i class="fas <?= $icon ?> text-xs"></i>
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- ── TAB: Overview ──────────────────────────────────────── -->
            <?php if ($tab === 'overview'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Connection Status</h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">Status</span>
                            <span class="font-medium <?= $dh_connected ? 'text-green-600' : 'text-red-600' ?>"><?= $dh_connected ? 'Connected' : 'Not Configured' ?></span>
                        </div>
                        <div class="flex justify-between"><span class="text-gray-500">Environment</span>
                            <span class="font-medium"><?= $dh_env_label ?></span>
                        </div>
                        <div class="flex justify-between"><span class="text-gray-500">Account</span>
                            <span class="font-mono font-medium"><?= htmlspecialchars($creds['dh_account']) ?></span>
                        </div>
                        <div class="flex justify-between"><span class="text-gray-500">API Base URL</span>
                            <span class="font-mono text-xs text-gray-600"><?= htmlspecialchars($dh_api_base) ?></span>
                        </div>
                        <div class="flex justify-between"><span class="text-gray-500">Tenant</span>
                            <span class="font-medium"><?= htmlspecialchars(DH_TENANT) ?></span>
                        </div>
                    </div>
                    <?php if ($dh_connected): ?>
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="action" value="test_connection">
                        <?= csrf_field() ?>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Test API Connection</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Quick Actions</h2>
                    <div class="space-y-3">
                        <a href="?tab=price" class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                            <i class="fas fa-tag w-8 h-8 flex items-center justify-center rounded-lg bg-blue-50 text-blue-600"></i>
                            <div><div class="text-sm font-medium text-gray-800">Price &amp; Availability</div><div class="text-xs text-gray-400">Look up pricing and stock by item number</div></div>
                        </a>
                        <a href="?tab=catalog" class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                            <i class="fas fa-list w-8 h-8 flex items-center justify-center rounded-lg bg-purple-50 text-purple-600"></i>
                            <div><div class="text-sm font-medium text-gray-800">Catalog Search</div><div class="text-xs text-gray-400">Search products by name or description</div></div>
                        </a>
                        <a href="?tab=inquiry" class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                            <i class="fas fa-search w-8 h-8 flex items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"></i>
                            <div><div class="text-sm font-medium text-gray-800">Item Inquiry</div><div class="text-xs text-gray-400">Get detailed specs on any item</div></div>
                        </a>
                        <a href="?tab=tracking" class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                            <i class="fas fa-truck w-8 h-8 flex items-center justify-center rounded-lg bg-amber-50 text-amber-600"></i>
                            <div><div class="text-sm font-medium text-gray-800">Order Tracking</div><div class="text-xs text-gray-400">Track sales order status</div></div>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── TAB: Price & Availability ──────────────────────────── -->
            <?php if ($tab === 'price'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl mb-6">
                <h2 class="text-base font-semibold text-gray-800 mb-4">Price &amp; Availability</h2>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="price_lookup">
                    <?= csrf_field() ?>
                    <div class="flex gap-3">
                        <input type="text" name="dh_item" value="<?= htmlspecialchars($deep_lookup_item ?: ($_POST['dh_item'] ?? '')) ?>" placeholder="Item number (e.g. TI83PLUS)" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500 font-mono">
                        <input type="text" name="dh_manufacturer" value="<?= htmlspecialchars($_POST['dh_manufacturer'] ?? '') ?>" placeholder="Manufacturer (optional)" class="w-44 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Get Price</button>
                        <button type="submit" formaction="" formmethod="POST" name="action" value="avail_lookup" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">Get Availability</button>
                    </div>
                </form>
            </div>

            <?php if ($lookup_result): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Price Result</h3>
                <?php
                $price = $lookup_result['salesPrice'] ?? $lookup_result['price'] ?? $lookup_result['unitPrice'] ?? 'N/A';
                $desc  = $lookup_result['description'] ?? $lookup_result['itemDescription'] ?? '';
                $itemId = $lookup_result['itemId'] ?? $lookup_result['itemNumber'] ?? '';
                $vendor = $lookup_result['vendorName'] ?? $lookup_result['manufacturer'] ?? '';
                ?>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div><span class="text-gray-500">Item</span><div class="font-medium font-mono"><?= htmlspecialchars($itemId) ?></div></div>
                    <div><span class="text-gray-500">Price</span><div class="font-bold text-lg text-green-600">$<?= is_numeric($price) ? number_format((float)$price, 2) : htmlspecialchars($price) ?></div></div>
                    <?php if ($desc): ?><div class="col-span-2"><span class="text-gray-500">Description</span><div class="font-medium"><?= htmlspecialchars($desc) ?></div></div><?php endif; ?>
                    <?php if ($vendor): ?><div><span class="text-gray-500">Vendor</span><div class="font-medium"><?= htmlspecialchars($vendor) ?></div></div><?php endif; ?>
                </div>
                <?php if (!empty($lookup_result['availability'])): ?>
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Branch Availability</h4>
                    <div class="space-y-1">
                    <?php foreach ((array)$lookup_result['availability'] as $br): ?>
                        <div class="flex justify-between text-sm py-1 px-2 rounded bg-gray-50">
                            <span class="font-mono text-xs"><?= htmlspecialchars($br['branch'] ?? $br['warehouse'] ?? '?') ?></span>
                            <span class="font-medium"><?= (int)($br['quantity'] ?? $br['qty'] ?? $br['availableQuantity'] ?? 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($avail_result): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl mt-4">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Availability Result</h3>
                <?php
                $itemId = $avail_result['itemId'] ?? $avail_result['itemNumber'] ?? '';
                $desc   = $avail_result['description'] ?? $avail_result['itemDescription'] ?? '';
                ?>
                <div class="text-sm mb-3">
                    <span class="text-gray-500">Item:</span>
                    <span class="font-mono font-medium"><?= htmlspecialchars($itemId) ?></span>
                    <?php if ($desc): ?> — <span><?= htmlspecialchars($desc) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($avail_result['availability'])): ?>
                <div class="space-y-1">
                    <?php $totalAvail = 0; foreach ((array)$avail_result['availability'] as $br):
                        $qty = (int)($br['quantity'] ?? $br['qty'] ?? $br['availableQuantity'] ?? 0);
                        $totalAvail += $qty;
                    ?>
                    <div class="flex justify-between text-sm py-1.5 px-3 rounded bg-gray-50">
                        <span class="font-mono text-xs"><?= htmlspecialchars($br['branch'] ?? $br['warehouse'] ?? '?') ?></span>
                        <span class="font-medium"><?= $qty ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="flex justify-between text-sm py-1.5 px-3 rounded bg-blue-50 font-semibold mt-1">
                        <span>Total Available</span>
                        <span><?= $totalAvail ?></span>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-sm text-gray-400">No availability data returned.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ── TAB: Item Inquiry ────────���─────────────────────────── -->
            <?php if ($tab === 'inquiry'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl mb-6">
                <h2 class="text-base font-semibold text-gray-800 mb-4">Item Inquiry</h2>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="item_inquiry">
                    <?= csrf_field() ?>
                    <div class="flex gap-3">
                        <input type="text" name="dh_item" value="<?= htmlspecialchars($_POST['dh_item'] ?? '') ?>" placeholder="Item number (e.g. TI83PLUS)" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500 font-mono">
                        <input type="text" name="dh_manufacturer" value="<?= htmlspecialchars($_POST['dh_manufacturer'] ?? '') ?>" placeholder="Manufacturer" class="w-44 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Lookup Item</button>
                </form>
            </div>

            <?php if ($item_result): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Item Details</h3>
                <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <?php foreach ([
                    ['Item ID', $item_result['itemId'] ?? $item_result['itemNumber'] ?? ''],
                    ['Description', $item_result['description'] ?? $item_result['itemDescription'] ?? ''],
                    ['Vendor', $item_result['vendorName'] ?? $item_result['manufacturer'] ?? ''],
                    ['Vendor Item ID', $item_result['vendorItemId'] ?? ''],
                    ['Manufacturer', $item_result['manufacturer'] ?? ''],
                    ['UPC', $item_result['universalProductCode'] ?? ''],
                    ['Unit of Measure', $item_result['unitOfMeasure'] ?? $item_result['uom'] ?? ''],
                    ['Item Type', $item_result['itemType'] ?? ''],
                    ['Category', $item_result['category'] ?? $item_result['categoryDescription'] ?? ''],
                    ['Weight', isset($item_result['weight']) ? $item_result['weight'] . ' lbs' : ''],
                    ['Estimated Retail', isset($item_result['estimatedRetailPrice']) ? '$' . number_format((float)$item_result['estimatedRetailPrice'], 2) : ''],
                    ['Status', $item_result['status'] ?? $item_result['itemStatus'] ?? ''],
                ] as [$label, $val]): if (!$val) continue; ?>
                    <div>
                        <span class="text-gray-500 text-xs block"><?= $label ?></span>
                        <span class="font-medium"><?= htmlspecialchars((string)$val) ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ── TAB: Catalog Search ────────────────────────────────── -->
            <?php if ($tab === 'catalog'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                <h2 class="text-base font-semibold text-gray-800 mb-4">Catalog Search</h2>
                <p class="text-xs text-gray-400 mb-4">Search by product name, description, vendor, or SKU. The catalog is searched progressively (up to 1,200 items per request).</p>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="search_catalog">
                    <?= csrf_field() ?>
                    <div class="flex gap-3">
                        <input type="text" name="dh_search" value="<?= htmlspecialchars($_POST['dh_search'] ?? $search_results['query'] ?? '') ?>" placeholder="Search products by name, description, or SKU..." class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition"><i class="fas fa-search mr-1"></i> Search</button>
                    </div>
                </form>
            </div>

            <?php if ($search_results): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-gray-800">Results</h3>
                    <span class="text-xs text-gray-400">Scanned <?= $search_results['totalScanned'] ?> items · <?= count($search_results['matches']) ?> matches</span>
                </div>

                <?php if (count($search_results['matches']) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wider">
                                <th class="pb-2 pr-3 font-medium">Item #</th>
                                <th class="pb-2 pr-3 font-medium">Description</th>
                                <th class="pb-2 pr-3 font-medium">Vendor</th>
                                <th class="pb-2 pr-3 font-medium">Vendor Item ID</th>
                                <th class="pb-2 pr-3 font-medium text-right">Retail</th>
                                <th class="pb-2 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($search_results['matches'] as $match):
                            $id    = $match['itemId'] ?? $match['itemNumber'] ?? '';
                            $desc  = $match['description'] ?? $match['itemDescription'] ?? '';
                            $vend  = $match['vendorName'] ?? $match['manufacturer'] ?? '';
                            $vid   = $match['vendorItemId'] ?? '';
                            $retail = $match['estimatedRetailPrice'] ?? null;
                        ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50">
                                <td class="py-2 pr-3 font-mono text-xs font-medium"><?= htmlspecialchars($id) ?></td>
                                <td class="py-2 pr-3 text-gray-700 max-w-xs truncate"><?= htmlspecialchars($desc) ?></td>
                                <td class="py-2 pr-3 text-gray-500"><?= htmlspecialchars($vend) ?></td>
                                <td class="py-2 pr-3 font-mono text-xs text-gray-400"><?= htmlspecialchars($vid) ?></td>
                                <td class="py-2 pr-3 text-right font-medium"><?= $retail ? '$' . number_format((float)$retail, 2) : '-' ?></td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <a href="?tab=price&dh_item_id=<?= urlencode($id) ?>&dh_lookup=price" class="text-blue-600 hover:text-blue-800 text-xs font-medium mr-2">Price</a>
                                    <a href="?tab=price&dh_item_id=<?= urlencode($id) ?>&dh_lookup=avail" class="text-emerald-600 hover:text-emerald-800 text-xs font-medium">Avail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($search_results['hasMore']): ?>
                <form method="POST" class="mt-4 text-center">
                    <input type="hidden" name="action" value="search_catalog">
                    <?= csrf_field() ?>
                    <input type="hidden" name="dh_search" value="<?= htmlspecialchars($search_results['query']) ?>">
                    <input type="hidden" name="dh_scroll" value="<?= htmlspecialchars($search_results['scrollId']) ?>">
                    <input type="hidden" name="dh_scanned" value="<?= $search_results['totalScanned'] ?>">
                    <input type="hidden" name="dh_load_more" value="1">
                    <button type="submit" class="px-6 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">Load More Results</button>
                </form>
                <?php endif; ?>

                <?php else: ?>
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-search text-3xl mb-2 block"></i>
                    <p class="text-sm">No items found matching "<strong><?= htmlspecialchars($search_results['query']) ?></strong>"</p>
                    <p class="text-xs mt-1">Scanned <?= $search_results['totalScanned'] ?> items. Try a different search term or click Load More.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ── TAB: Order Tracking ────────────────────────────────── -->
            <?php if ($tab === 'tracking'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl mb-6">
                <h2 class="text-base font-semibold text-gray-800 mb-4">Order Tracking</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="order_tracking">
                    <?= csrf_field() ?>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition"><i class="fas fa-sync-alt mr-1"></i> Fetch Order Status</button>
                </form>
            </div>

            <?php if ($tracking_result): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Sales Orders</h3>
                <?php
                $orders = $tracking_result['salesOrders'] ?? $tracking_result['orders'] ?? $tracking_result['data'] ?? [];
                if (is_array($orders) && count($orders) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wider">
                                <th class="pb-2 pr-3 font-medium">Order #</th>
                                <th class="pb-2 pr-3 font-medium">Status</th>
                                <th class="pb-2 pr-3 font-medium">Date</th>
                                <th class="pb-2 pr-3 font-medium text-right">Total</th>
                                <th class="pb-2 font-medium">Carrier</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $o):
                            $onum = $o['salesOrderNumber'] ?? $o['orderNumber'] ?? $o['orderId'] ?? '-';
                            $stat = $o['status'] ?? $o['orderStatus'] ?? '-';
                            $date = $o['orderDate'] ?? $o['createdDate'] ?? '';
                            $total = $o['totalAmount'] ?? $o['orderTotal'] ?? null;
                            $carrier = $o['carrier'] ?? $o['carrierName'] ?? '-';
                        ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50">
                                <td class="py-2 pr-3 font-mono text-xs font-medium"><?= htmlspecialchars($onum) ?></td>
                                <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700"><?= htmlspecialchars($stat) ?></span></td>
                                <td class="py-2 pr-3 text-gray-500"><?= htmlspecialchars($date) ?></td>
                                <td class="py-2 pr-3 text-right font-medium"><?= $total ? '$' . number_format((float)$total, 2) : '-' ?></td>
                                <td class="py-2 text-gray-500"><?= htmlspecialchars($carrier) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-box-open text-3xl mb-2 block"></i>
                    <p class="text-sm">No sales orders found, or the API returned an empty result.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ── TAB: Settings ──────────────────────────────────────── -->
            <?php if ($tab === 'settings'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <h2 class="text-base font-semibold text-gray-800 mb-4">API Credentials</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="save_creds">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client ID</label>
                        <input type="text" name="dh_client_id" value="<?= htmlspecialchars($creds['dh_client_id'] ?? '') ?>" placeholder="OAuth Client ID" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client Secret</label>
                        <input type="password" name="dh_client_secret" value="<?= htmlspecialchars($creds['dh_client_secret'] ?? '') ?>" placeholder="OAuth Client Secret" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Account Number</label>
                        <input type="text" name="dh_account" value="<?= htmlspecialchars($creds['dh_account'] ?? DH_ACCOUNT) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500 font-mono">
                        <p class="text-xs text-gray-400 mt-1">Blue Mogul Enterprise, LLC — 3054540000</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
                        <select name="dh_env" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                            <option value="TEST" <?= ($creds['dh_env'] ?? 'TEST') === 'TEST' ? 'selected' : '' ?>>TEST (test.api.dandh.com)</option>
                            <option value="PRODUCTION" <?= ($creds['dh_env'] ?? '') === 'PRODUCTION' ? 'selected' : '' ?>>PRODUCTION (api.dandh.com)</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Save Credentials</button>
                        <?php if ($dh_connected): ?>
                        <a href="?tab=overview" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">Test Connection</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>
<?php include 'includes/admin-footer.php'; ?>
