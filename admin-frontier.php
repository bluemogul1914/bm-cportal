<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';

$pdo = getDB();

// ── Create / migrate tables ───────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE frontier_orders ADD COLUMN IF NOT EXISTS product_id INTEGER"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE frontier_orders ADD COLUMN IF NOT EXISTS monthly_price NUMERIC(10,2)"); } catch (Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS frontier_orders (
    id               SERIAL PRIMARY KEY,
    pon              VARCHAR(50) UNIQUE NOT NULL,
    client_id        INTEGER,
    activity_code    VARCHAR(5)  DEFAULT 'N',
    address_line1    VARCHAR(255) DEFAULT '',
    city             VARCHAR(100) DEFAULT '',
    state            VARCHAR(50)  DEFAULT '',
    zip              VARCHAR(20)  DEFAULT '',
    account_number   VARCHAR(100) DEFAULT '',
    desired_due_date DATE,
    contact_name     VARCHAR(255) DEFAULT '',
    contact_phone    VARCHAR(50)  DEFAULT '',
    contact_email    VARCHAR(255) DEFAULT '',
    status           VARCHAR(50)  DEFAULT 'PENDING',
    type             VARCHAR(20)  DEFAULT 'ORDER',
    circuit_id       VARCHAR(100) DEFAULT '',
    errors           TEXT         DEFAULT '[]',
    remarks          TEXT         DEFAULT '',
    raw_request      TEXT,
    raw_response     TEXT,
    billing_result   TEXT,
    invoice_id       INTEGER,
    created_at       TIMESTAMP    DEFAULT NOW(),
    updated_at       TIMESTAMP    DEFAULT NOW()
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS frontier_logs (
    id         SERIAL PRIMARY KEY,
    level      VARCHAR(20) DEFAULT 'info',
    message    TEXT,
    created_at TIMESTAMP DEFAULT NOW()
)");

require_once 'includes/frontier/FrontierASRClient.php';
require_once 'includes/frontier/PortalOrderManager.php';

// Ensure settings table exists before querying it
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key VARCHAR(255) PRIMARY KEY, value TEXT)");
} catch (Exception $e) {}

// ── Load/save config ──────────────────────────────────────────────────────────
$configKey = 'frontier_asr_config';
try {
    $configRow = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
    $configRow->execute([$configKey]);
    $row = $configRow->fetch(PDO::FETCH_ASSOC);
    $config = $row ? (json_decode($row['value'], true) ?: []) : [];
} catch (Exception $e) {
    $config = [];
}
$config = array_merge([
    'environment'   => 'TEST',
    'ccna'          => 'BMR',
    'source_ip'     => '149.28.124.240',
    'contact_name'  => 'Tracy Williams',
    'contact_phone' => '3463095514',
    'contact_email' => 'tracy.williams@bluemogul.biz',
], $config);

$orderManager = new PortalOrderManager($pdo);

$success_msg = '';
$error_msg   = '';
$tab = $_GET['tab'] ?? 'dashboard';

// ── Actions ───────────────────────────────────────────────────────────────────
$action = $_POST['_action'] ?? $_GET['action'] ?? '';

if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['environment']   = $_POST['environment']   ?? 'TEST';
    $config['ccna']          = strtoupper(trim($_POST['ccna']          ?? 'BMR'));
    $config['source_ip']     = trim($_POST['source_ip']     ?? '');
    $config['contact_name']  = trim($_POST['contact_name']  ?? '');
    $config['contact_phone'] = trim($_POST['contact_phone'] ?? '');
    $config['contact_email'] = trim($_POST['contact_email'] ?? '');
    $pdo->prepare("INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value")
        ->execute([$configKey, json_encode($config)]);
    $success_msg = 'Settings saved.';
    $tab = 'settings';
}

if ($action === 'submit_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = 'orders';
    try {
        $client_id   = (int)($_POST['client_id']   ?? 0) ?: null;
        $product_id  = (int)($_POST['product_id']  ?? 0) ?: null;
        $monthly_price = (float)($_POST['monthly_price'] ?? 0);
        $product_name  = trim($_POST['product_name'] ?? 'Broadband Service');

        $orderData = [
            'activity_code'   => $_POST['activity_code']   ?? 'N',
            'address_line1'   => $_POST['address_line1']   ?? '',
            'city'            => $_POST['city']            ?? '',
            'state'           => $_POST['state']           ?? '',
            'zip'             => $_POST['zip']             ?? '',
            'account_number'  => $_POST['account_number']  ?? '',
            'desired_due_date'=> $_POST['desired_due_date'] ?? '',
            'contact_name'    => $_POST['contact_name']    ?? $config['contact_name'],
            'contact_phone'   => $_POST['contact_phone']   ?? $config['contact_phone'],
            'contact_email'   => $_POST['contact_email']   ?? $config['contact_email'],
            'type'            => 'ORDER',
        ];

        $client = new FrontierASRClient($config, new class {
            public function info($m) {} public function error($m) {} public function debug($m) {}
        });

        $pon = $orderManager->create($orderData, $client_id);
        $orderData['pon'] = $pon;

        // ── Save product & price to order ────────────────────────────────────
        if ($product_id || $monthly_price > 0) {
            $pdo->prepare("UPDATE frontier_orders SET product_id=?, monthly_price=?, updated_at=NOW() WHERE pon=?")
                ->execute([$product_id, $monthly_price ?: null, $pon]);
        }

        // ── Create invoice immediately (payment required before activation) ──
        $invoiceId = null;
        if ($client_id && $monthly_price > 0) {
            try {
                $addr = trim("{$orderData['address_line1']}, {$orderData['city']}, {$orderData['state']} {$orderData['zip']}");
                $invoiceId = createBroadbandInvoice($pdo, $client_id, $pon, $product_name, $monthly_price, $addr);
                $pdo->prepare("UPDATE frontier_orders SET invoice_id=?, updated_at=NOW() WHERE pon=?")
                    ->execute([$invoiceId, $pon]);
                $pdo->prepare("INSERT INTO frontier_logs (level, message) VALUES ('info', ?)")
                    ->execute(["Invoice #{$invoiceId} created for PON {$pon} — {$product_name} \${$monthly_price}/mo"]);
            } catch (Exception $ie) {
                $pdo->prepare("INSERT INTO frontier_logs (level, message) VALUES ('warn', ?)")
                    ->execute(["Invoice creation failed for {$pon}: " . $ie->getMessage()]);
            }
        }

        $result = $client->sendOrder($orderData);

        if (isset($result['error'])) {
            $pdo->prepare("UPDATE frontier_orders SET status='ERROR', remarks=?, updated_at=NOW() WHERE pon=?")
                ->execute([$result['error'], $pon]);
            $error_msg = "Order submitted (PON: {$pon}) but Frontier returned error: " . $result['error'];
            if ($invoiceId) $error_msg .= " Invoice #{$invoiceId} created — collect payment then retry.";
        } else {
            $orderManager->updateFromFrontierResponse($pon, $result);
            $success_msg = "✅ Order submitted. PON: <strong>{$pon}</strong> | Status: " . ($result['status'] ?? 'RECEIVED');
            if ($invoiceId) $success_msg .= " | <a href=\"admin-invoice-detail.php?id={$invoiceId}\" class=\"underline font-semibold\">Invoice #{$invoiceId}</a> created — send to client for payment.";
        }

        $pdo->prepare("INSERT INTO frontier_logs (level, message) VALUES ('info', ?)")
            ->execute(["Order submitted: {$pon} — " . json_encode($result)]);

    } catch (Exception $e) {
        $error_msg = 'Order error: ' . $e->getMessage();
    }
}

if ($action === 'prequalify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = 'prequalify';
    try {
        $orderData = [
            'address_line1' => $_POST['address_line1'] ?? '',
            'city'          => $_POST['city']          ?? '',
            'state'         => $_POST['state']         ?? '',
            'zip'           => $_POST['zip']           ?? '',
            'pon'           => 'PREQ-' . strtoupper(substr(md5(uniqid()), 0, 8)),
        ];

        $client = new FrontierASRClient($config, new class {
            public function info($m) {} public function error($m) {} public function debug($m) {}
        });

        $result = $client->sendPreOrder($orderData);

        $pdo->prepare("INSERT INTO frontier_logs (level, message) VALUES ('info', ?)")
            ->execute(["Pre-qualify check: " . json_encode($orderData) . ' => ' . json_encode($result)]);

        if (isset($result['error'])) {
            $error_msg = 'Pre-qualify error: ' . $result['error'];
        } else {
            $available = $result['available'] ?? ($result['status'] === 'AVAILABLE');
            $success_msg = $available
                ? '✅ Service is available at this address!'
                : '❌ Service is not available at this address.';
        }
    } catch (Exception $e) {
        $error_msg = 'Pre-qualify error: ' . $e->getMessage();
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function createBroadbandInvoice(PDO $pdo, int $clientId, string $pon, string $productName, float $price, string $address): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(REPLACE(invoice_number,'INV-','') AS INTEGER)),0)+1 as n FROM invoices WHERE invoice_number ~ '^INV-[0-9]{3,5}$'");
    $stmt->execute();
    $next = (int)$stmt->fetch(PDO::FETCH_ASSOC)['n'];
    $invoiceNum = 'INV-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    $due   = date('Y-m-d', strtotime('+30 days'));
    $notes = "Broadband order PON: {$pon}\nPayment required before service activation.\nService address: {$address}";
    $items = json_encode([[
        'description' => $productName . ' — ' . $address,
        'qty'         => 1,
        'unit_price'  => $price,
        'tax_rate'    => 0,
        'amount'      => $price,
        'tax_amount'  => 0,
    ]]);
    $stmt = $pdo->prepare("INSERT INTO invoices (client_id, invoice_number, amount, tax, total, status, due_date, notes, items, created_at) VALUES (?,?,?,0,?,'unpaid',?,?,?::jsonb,NOW())");
    $stmt->execute([$clientId, $invoiceNum, $price, $price, $due, $notes, $items]);
    return (int)$pdo->lastInsertId();
}

// ── Data for display ──────────────────────────────────────────────────────────
$orders           = $orderManager->recent(100);
$counts           = $orderManager->counts();
$clients          = $pdo->query("SELECT id, name, company FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$logs             = $pdo->query("SELECT * FROM frontier_logs ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$internetProducts = $pdo->query("SELECT id, name, price, description FROM products WHERE category='Internet' ORDER BY CAST(price AS NUMERIC) ASC")->fetchAll(PDO::FETCH_ASSOC);

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'portal.bluemogul.biz';
$receiveUrl = "{$scheme}://{$host}/portal/frontier-receive.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frontier ASR Ordering — Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="bg-gray-50 font-sans">

<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>

<div class="flex-1 overflow-y-auto flex flex-col">
    <!-- Header -->
    <div class="bg-white border-b border-gray-200 px-8 py-5 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-network-wired text-orange-500"></i>
                Frontier ASR Ordering
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">Submit and track broadband service orders via Frontier Wholesale</p>
        </div>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold <?php echo $config['environment'] === 'PRODUCTION' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
            <span class="w-2 h-2 rounded-full <?php echo $config['environment'] === 'PRODUCTION' ? 'bg-green-500' : 'bg-yellow-500'; ?>"></span>
            <?php echo $config['environment']; ?> MODE
        </span>
    </div>

    <div class="flex-1 px-8 py-6">

        <!-- Messages -->
        <?php if ($success_msg): ?>
            <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg flex items-start gap-2">
                <i class="fas fa-check-circle mt-0.5 text-green-500"></i>
                <span><?php echo htmlspecialchars($success_msg); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg flex items-start gap-2">
                <i class="fas fa-exclamation-circle mt-0.5 text-red-500"></i>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="flex gap-1 mb-6 border-b border-gray-200">
            <?php foreach (['dashboard'=>'Dashboard','track'=>'Track Orders','prequalify'=>'Pre-Qualify','orders'=>'New Order','logs'=>'Logs','settings'=>'Settings'] as $t => $label): ?>
                <a href="?tab=<?php echo $t; ?>"
                   class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition <?php echo $tab === $t ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- DASHBOARD TAB -->
        <?php if ($tab === 'dashboard'): ?>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <?php
            $stats = [
                ['label'=>'Total Orders',    'value'=>$counts['total']        ?? 0, 'icon'=>'fa-file-alt',    'color'=>'blue'],
                ['label'=>'Completed',       'value'=>$counts['COMPLETED']    ?? 0, 'icon'=>'fa-check-circle','color'=>'green'],
                ['label'=>'Pending / Active','value'=>($counts['PENDING']??0)+($counts['RECEIVED']??0), 'icon'=>'fa-clock','color'=>'yellow'],
                ['label'=>'Errors',          'value'=>$counts['ERROR']        ?? 0, 'icon'=>'fa-times-circle','color'=>'red'],
            ];
            foreach ($stats as $s):
                $colors = [
                    'blue'  => 'bg-blue-50 text-blue-600 border-blue-200',
                    'green' => 'bg-green-50 text-green-600 border-green-200',
                    'yellow'=> 'bg-yellow-50 text-yellow-600 border-yellow-200',
                    'red'   => 'bg-red-50 text-red-600 border-red-200',
                ];
                $c = $colors[$s['color']];
            ?>
            <div class="bg-white border border-gray-200 rounded-xl p-5 flex items-center gap-4">
                <span class="w-12 h-12 rounded-xl flex items-center justify-center text-lg <?php echo $c; ?> border">
                    <i class="fas <?php echo $s['icon']; ?>"></i>
                </span>
                <div>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $s['value']; ?></p>
                    <p class="text-xs text-gray-500"><?php echo $s['label']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Orders table -->
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900"><i class="fas fa-list text-orange-500 mr-2"></i>Recent Orders</h2>
                <a href="?tab=orders" class="text-sm text-orange-600 hover:underline font-medium">+ New Order</a>
            </div>
            <?php if (empty($orders)): ?>
                <div class="p-10 text-center text-gray-400 text-sm">
                    <i class="fas fa-inbox text-3xl mb-3 block text-gray-200"></i>
                    No orders yet. Use <strong>New Order</strong> to submit your first ASR.
                </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">PON</th>
                            <th class="px-4 py-3 text-left">Client</th>
                            <th class="px-4 py-3 text-left">Address</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Circuit ID</th>
                            <th class="px-4 py-3 text-left">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($orders as $ord):
                            $sc = match($ord['status']) {
                                'COMPLETED'  => 'bg-green-100 text-green-700',
                                'ERROR'      => 'bg-red-100 text-red-700',
                                'CANCELLED'  => 'bg-gray-100 text-gray-600',
                                'RECEIVED'   => 'bg-blue-100 text-blue-700',
                                default      => 'bg-yellow-100 text-yellow-700',
                            };
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-gray-900"><?php echo htmlspecialchars($ord['pon']); ?></td>
                            <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($ord['client_name'] ?? '—'); ?></td>
                            <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars(trim("{$ord['address_line1']}, {$ord['city']}, {$ord['state']} {$ord['zip']}")); ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                    <?php echo match($ord['activity_code']) {'N'=>'New','C'=>'Change','D'=>'Disconnect',default=>$ord['activity_code']}; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $sc; ?>"><?php echo htmlspecialchars($ord['status']); ?></span></td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($ord['circuit_id'] ?: '—'); ?></td>
                            <td class="px-4 py-3 text-xs text-gray-500"><?php echo $ord['created_at'] ? date('M d, Y', strtotime($ord['created_at'])) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- TRACK ORDERS TAB -->
        <?php elseif ($tab === 'track'): ?>

        <?php
        $filterStatus = $_GET['status'] ?? '';
        $filterSearch = trim($_GET['q'] ?? '');
        $trackOrders  = $orders; // already fetched (recent 100)

        // If we need more than 100 for tracking, re-query with filter
        $whereClause = "1=1";
        $params = [];
        if ($filterStatus) { $whereClause .= " AND fo.status = ?"; $params[] = $filterStatus; }
        if ($filterSearch) { $whereClause .= " AND (fo.pon ILIKE ? OR fo.address_line1 ILIKE ? OR fo.city ILIKE ? OR c.name ILIKE ?)"; $params[] = "%{$filterSearch}%"; $params[] = "%{$filterSearch}%"; $params[] = "%{$filterSearch}%"; $params[] = "%{$filterSearch}%"; }

        $trackQ = $pdo->prepare("SELECT fo.*, c.name as client_name, c.company as client_company FROM frontier_orders fo LEFT JOIN clients c ON fo.client_id = c.id WHERE {$whereClause} ORDER BY fo.created_at DESC LIMIT 200");
        $trackQ->execute($params);
        $trackOrders = $trackQ->fetchAll(PDO::FETCH_ASSOC);

        $allStatuses = ['PENDING','RECEIVED','COMPLETED','ERROR','CANCELLED'];
        $statusColors = [
            'COMPLETED' => ['bg'=>'bg-green-100','text'=>'text-green-700','icon'=>'fa-check-circle'],
            'ERROR'     => ['bg'=>'bg-red-100',  'text'=>'text-red-700',  'icon'=>'fa-times-circle'],
            'CANCELLED' => ['bg'=>'bg-gray-100', 'text'=>'text-gray-600', 'icon'=>'fa-ban'],
            'RECEIVED'  => ['bg'=>'bg-blue-100', 'text'=>'text-blue-700', 'icon'=>'fa-paper-plane'],
            'PENDING'   => ['bg'=>'bg-yellow-100','text'=>'text-yellow-700','icon'=>'fa-clock'],
        ];
        ?>

        <!-- Filter bar -->
        <div class="bg-white border border-gray-200 rounded-xl p-4 mb-5 flex flex-wrap gap-3 items-center">
            <form method="GET" action="" class="flex flex-wrap gap-3 items-center w-full">
                <input type="hidden" name="tab" value="track">
                <input type="text" name="q" value="<?php echo htmlspecialchars($filterSearch); ?>"
                    placeholder="Search PON, address, client…"
                    class="border border-gray-200 rounded-lg px-3 py-2 text-sm flex-1 min-w-[200px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-300" data-testid="input-track-search">
                <div class="flex gap-1 flex-wrap">
                    <a href="?tab=track<?php echo $filterSearch ? '&q='.urlencode($filterSearch) : ''; ?>"
                       class="px-3 py-1.5 text-xs font-medium rounded-lg <?php echo !$filterStatus ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                        All (<?php echo count($trackOrders); ?>)
                    </a>
                    <?php foreach ($allStatuses as $st):
                        $cnt = $pdo->query("SELECT COUNT(*) FROM frontier_orders WHERE status='".addslashes($st)."'")->fetchColumn();
                    ?>
                    <a href="?tab=track&status=<?php echo $st; ?><?php echo $filterSearch ? '&q='.urlencode($filterSearch) : ''; ?>"
                       class="px-3 py-1.5 text-xs font-medium rounded-lg <?php echo $filterStatus===$st ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                        <?php echo ucfirst(strtolower($st)); ?> (<?php echo $cnt; ?>)
                    </a>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="px-4 py-2 bg-orange-500 text-white text-xs font-semibold rounded-lg hover:bg-orange-600 transition" data-testid="button-track-search">Search</button>
            </form>
        </div>

        <!-- Orders list -->
        <?php if (empty($trackOrders)): ?>
        <div class="bg-white border border-gray-200 rounded-xl p-12 text-center text-gray-400">
            <i class="fas fa-search text-4xl mb-3 block text-gray-200"></i>
            <p class="text-sm">No orders match your criteria.</p>
        </div>
        <?php else: ?>
        <div class="space-y-3" id="track-orders-list">
        <?php foreach ($trackOrders as $idx => $ord):
            $sc     = $statusColors[$ord['status']] ?? ['bg'=>'bg-gray-100','text'=>'text-gray-600','icon'=>'fa-circle'];
            $errors = json_decode($ord['errors'] ?? '[]', true) ?: [];
            $resp   = $ord['raw_response'] ?? '';
            $req    = $ord['raw_request']  ?? '';

            // Parse key fields from raw SOAP response
            $respCircuit  = '';
            $respDueDate  = '';
            $respStatus   = '';
            $respErrors   = [];
            if ($resp) {
                if (preg_match('/<CircuitID[^>]*>([^<]+)<\/CircuitID>/i', $resp, $m)) $respCircuit = $m[1];
                if (preg_match('/<DueDate[^>]*>([^<]+)<\/DueDate>/i', $resp, $m))     $respDueDate = $m[1];
                if (preg_match('/<Status[^>]*>([^<]+)<\/Status>/i', $resp, $m))       $respStatus  = $m[1];
                preg_match_all('/<ErrorMessage[^>]*>([^<]+)<\/ErrorMessage>/i', $resp, $em);
                $respErrors = $em[1] ?? [];
                preg_match_all('/<ErrorDescription[^>]*>([^<]+)<\/ErrorDescription>/i', $resp, $ed);
                if (!empty($ed[1])) $respErrors = array_merge($respErrors, $ed[1]);
            }
            if (empty($respErrors)) $respErrors = $errors;

            $actLabel = match($ord['activity_code'] ?? 'N') {
                'N'=>'New Install', 'C'=>'Change', 'D'=>'Disconnect', 'T'=>'Transfer', default => $ord['activity_code'] ?? 'N'
            };
        ?>
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden" data-testid="order-card-<?php echo (int)$ord['id']; ?>">
            <!-- Collapsed header row -->
            <div class="px-5 py-4 flex items-center gap-4 cursor-pointer hover:bg-gray-50 transition order-toggle" onclick="toggleOrder(<?php echo (int)$ord['id']; ?>)" data-testid="button-expand-order-<?php echo (int)$ord['id']; ?>">
                <!-- Status icon -->
                <span class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?php echo $sc['bg'].' '.$sc['text']; ?>">
                    <i class="fas <?php echo $sc['icon']; ?>"></i>
                </span>
                <!-- PON + client -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-mono text-sm font-bold text-gray-900" data-testid="text-pon-<?php echo (int)$ord['id']; ?>"><?php echo htmlspecialchars($ord['pon']); ?></span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $sc['bg'].' '.$sc['text']; ?>"><?php echo htmlspecialchars($ord['status']); ?></span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600"><?php echo htmlspecialchars($actLabel); ?></span>
                        <?php if (!empty($respErrors)): ?>
                        <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-red-50 text-red-600"><i class="fas fa-exclamation-circle mr-1"></i><?php echo count($respErrors); ?> error(s)</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5 truncate">
                        <?php if ($ord['client_name']): ?><span class="font-medium text-gray-700"><?php echo htmlspecialchars($ord['client_name']); ?></span> &mdash; <?php endif; ?>
                        <?php echo htmlspecialchars(trim("{$ord['address_line1']}, {$ord['city']}, {$ord['state']} {$ord['zip']}")); ?>
                    </p>
                </div>
                <!-- Quick meta -->
                <div class="hidden sm:flex flex-col items-end gap-1 flex-shrink-0 text-right">
                    <?php if ($ord['circuit_id']): ?>
                    <span class="font-mono text-xs text-blue-700 font-semibold" data-testid="text-circuit-<?php echo (int)$ord['id']; ?>"><?php echo htmlspecialchars($ord['circuit_id']); ?></span>
                    <?php endif; ?>
                    <span class="text-xs text-gray-400"><?php echo $ord['created_at'] ? date('M d, Y', strtotime($ord['created_at'])) : '—'; ?></span>
                    <?php if ($ord['desired_due_date']): ?>
                    <span class="text-[10px] text-gray-400">Due <?php echo date('M d, Y', strtotime($ord['desired_due_date'])); ?></span>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform order-chevron-<?php echo (int)$ord['id']; ?>"></i>
            </div>

            <!-- Expanded detail panel (hidden by default) -->
            <div id="order-detail-<?php echo (int)$ord['id']; ?>" class="hidden border-t border-gray-100">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-0 divide-y md:divide-y-0 md:divide-x divide-gray-100">

                    <!-- Left: Order details -->
                    <div class="p-5">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-file-alt text-orange-400 mr-1"></i>Order Details</h3>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt class="text-gray-500">PON</dt>
                            <dd class="font-mono font-semibold text-gray-900"><?php echo htmlspecialchars($ord['pon']); ?></dd>

                            <dt class="text-gray-500">Activity</dt>
                            <dd class="text-gray-800"><?php echo htmlspecialchars($actLabel); ?></dd>

                            <dt class="text-gray-500">Address</dt>
                            <dd class="text-gray-800 col-span-1"><?php echo htmlspecialchars($ord['address_line1']); ?></dd>

                            <dt class="text-gray-500">City / State</dt>
                            <dd class="text-gray-800"><?php echo htmlspecialchars("{$ord['city']}, {$ord['state']} {$ord['zip']}"); ?></dd>

                            <?php if ($ord['account_number']): ?>
                            <dt class="text-gray-500">Account #</dt>
                            <dd class="font-mono text-gray-800"><?php echo htmlspecialchars($ord['account_number']); ?></dd>
                            <?php endif; ?>

                            <?php if ($ord['circuit_id']): ?>
                            <dt class="text-gray-500">Circuit ID</dt>
                            <dd class="font-mono text-blue-700 font-semibold"><?php echo htmlspecialchars($ord['circuit_id']); ?></dd>
                            <?php endif; ?>

                            <?php if ($ord['desired_due_date']): ?>
                            <dt class="text-gray-500">Desired Due</dt>
                            <dd class="text-gray-800"><?php echo date('M d, Y', strtotime($ord['desired_due_date'])); ?></dd>
                            <?php endif; ?>

                            <?php if ($ord['contact_name']): ?>
                            <dt class="text-gray-500">Contact</dt>
                            <dd class="text-gray-800"><?php echo htmlspecialchars($ord['contact_name']); ?></dd>
                            <?php endif; ?>

                            <?php if ($ord['contact_phone']): ?>
                            <dt class="text-gray-500">Phone</dt>
                            <dd class="text-gray-800"><?php echo htmlspecialchars($ord['contact_phone']); ?></dd>
                            <?php endif; ?>

                            <?php if ($ord['contact_email']): ?>
                            <dt class="text-gray-500">Email</dt>
                            <dd class="text-gray-800 break-all"><?php echo htmlspecialchars($ord['contact_email']); ?></dd>
                            <?php endif; ?>

                            <dt class="text-gray-500">Submitted</dt>
                            <dd class="text-gray-800"><?php echo $ord['created_at'] ? date('M d, Y g:ia', strtotime($ord['created_at'])) : '—'; ?></dd>

                            <?php if ($ord['updated_at'] && $ord['updated_at'] !== $ord['created_at']): ?>
                            <dt class="text-gray-500">Updated</dt>
                            <dd class="text-gray-800"><?php echo date('M d, Y g:ia', strtotime($ord['updated_at'])); ?></dd>
                            <?php endif; ?>
                        </dl>

                        <!-- Client link -->
                        <?php if ($ord['client_id']): ?>
                        <div class="mt-4 pt-3 border-t border-gray-100 flex gap-2 flex-wrap">
                            <a href="admin-client-detail.php?id=<?php echo (int)$ord['client_id']; ?>&tab=broadband"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition" data-testid="link-client-<?php echo (int)$ord['id']; ?>">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($ord['client_name']); ?>
                            </a>
                            <?php if ($ord['invoice_id']): ?>
                            <a href="admin-invoice-detail.php?id=<?php echo (int)$ord['invoice_id']; ?>"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition" data-testid="link-invoice-<?php echo (int)$ord['id']; ?>">
                                <i class="fas fa-file-invoice-dollar"></i> Invoice #<?php echo (int)$ord['invoice_id']; ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Frontier response -->
                    <div class="p-5">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-satellite-dish text-orange-400 mr-1"></i>Frontier Response</h3>

                        <?php if ($ord['status'] === 'PENDING' && !$resp): ?>
                        <div class="flex items-center gap-2 text-yellow-600 text-sm bg-yellow-50 rounded-lg px-4 py-3">
                            <i class="fas fa-clock"></i>
                            <span>Awaiting Frontier confirmation. No response received yet.</span>
                        </div>
                        <?php else: ?>

                        <!-- Status from Frontier -->
                        <div class="flex items-center gap-2 mb-4">
                            <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $sc['bg'].' '.$sc['text']; ?>">
                                <i class="fas <?php echo $sc['icon']; ?> mr-1"></i><?php echo htmlspecialchars($ord['status']); ?>
                            </span>
                            <?php if ($respStatus && $respStatus !== $ord['status']): ?>
                            <span class="text-xs text-gray-500">(Frontier: <?php echo htmlspecialchars($respStatus); ?>)</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($respCircuit): ?>
                        <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <p class="text-xs font-medium text-blue-600 uppercase tracking-wide mb-1">Circuit ID Assigned</p>
                            <p class="font-mono text-sm font-bold text-blue-800"><?php echo htmlspecialchars($respCircuit); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($respDueDate): ?>
                        <div class="mb-3 text-sm">
                            <span class="text-gray-500">Frontier Due Date: </span>
                            <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($respDueDate); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($respErrors)): ?>
                        <div class="mb-3">
                            <p class="text-xs font-semibold text-red-600 uppercase tracking-wide mb-2"><i class="fas fa-exclamation-triangle mr-1"></i>Errors from Frontier</p>
                            <ul class="space-y-1">
                                <?php foreach ($respErrors as $err): ?>
                                <li class="text-xs bg-red-50 border border-red-100 text-red-700 rounded px-3 py-2"><?php echo htmlspecialchars($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php if ($ord['remarks']): ?>
                        <div class="mb-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Remarks</p>
                            <p class="text-sm text-gray-700 bg-gray-50 rounded-lg px-3 py-2"><?php echo nl2br(htmlspecialchars($ord['remarks'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($ord['billing_result']): ?>
                        <div class="mb-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Billing Result</p>
                            <?php $br = json_decode($ord['billing_result'], true); ?>
                            <p class="text-xs text-gray-600 bg-gray-50 rounded px-3 py-2">
                                <?php echo htmlspecialchars(is_array($br) ? ($br['message'] ?? json_encode($br)) : $ord['billing_result']); ?>
                            </p>
                        </div>
                        <?php endif; ?>

                        <!-- Raw response toggle -->
                        <?php if ($resp): ?>
                        <div class="mt-3">
                            <button onclick="toggleRaw(<?php echo (int)$ord['id']; ?>)"
                                class="text-xs text-gray-400 hover:text-gray-600 underline" data-testid="button-raw-<?php echo (int)$ord['id']; ?>">
                                Show raw Frontier response
                            </button>
                            <pre id="raw-resp-<?php echo (int)$ord['id']; ?>" class="hidden mt-2 bg-gray-900 text-green-400 text-[10px] rounded-lg p-3 overflow-x-auto max-h-48 leading-relaxed"><?php echo htmlspecialchars($resp); ?></pre>
                        </div>
                        <?php endif; ?>

                        <?php endif; // end if PENDING ?>
                    </div>
                </div>

                <!-- Raw request toggle -->
                <?php if ($req): ?>
                <div class="border-t border-gray-100 px-5 py-3">
                    <button onclick="toggleRawReq(<?php echo (int)$ord['id']; ?>)"
                        class="text-xs text-gray-400 hover:text-gray-600 underline" data-testid="button-rawreq-<?php echo (int)$ord['id']; ?>">
                        Show raw request sent to Frontier
                    </button>
                    <pre id="raw-req-<?php echo (int)$ord['id']; ?>" class="hidden mt-2 bg-gray-900 text-cyan-400 text-[10px] rounded-lg p-3 overflow-x-auto max-h-40 leading-relaxed"><?php echo htmlspecialchars($req); ?></pre>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div><!-- /track-orders-list -->
        <?php endif; ?>

        <script>
        function toggleOrder(id) {
            var panel = document.getElementById('order-detail-' + id);
            var chevron = document.querySelector('.order-chevron-' + id);
            if (panel.classList.contains('hidden')) {
                panel.classList.remove('hidden');
                if (chevron) chevron.style.transform = 'rotate(180deg)';
            } else {
                panel.classList.add('hidden');
                if (chevron) chevron.style.transform = '';
            }
        }
        function toggleRaw(id) {
            var el = document.getElementById('raw-resp-' + id);
            el.classList.toggle('hidden');
        }
        function toggleRawReq(id) {
            var el = document.getElementById('raw-req-' + id);
            el.classList.toggle('hidden');
        }
        </script>

        <!-- PRE-QUALIFY TAB -->
        <?php elseif ($tab === 'prequalify'): ?>
        <div class="max-w-2xl">
            <div class="bg-white border border-gray-200 rounded-xl p-6 mb-6">
                <h2 class="font-semibold text-gray-900 mb-1 text-lg"><i class="fas fa-search-location text-orange-500 mr-2"></i>Address Pre-Qualification</h2>
                <p class="text-sm text-gray-500 mb-6">Check whether Frontier broadband service is available at a given address before submitting a full order.</p>

                <form method="POST" action="?tab=prequalify">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="prequalify">

                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Street Address</label>
                        <input type="text" name="address_line1" required placeholder="123 Main St" data-testid="input-preq-address"
                               class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                    </div>
                    <div class="grid grid-cols-3 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">City</label>
                            <input type="text" name="city" required placeholder="Houston" data-testid="input-preq-city"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">State</label>
                            <input type="text" name="state" required placeholder="TX" maxlength="2" data-testid="input-preq-state"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">ZIP</label>
                            <input type="text" name="zip" required placeholder="77001" data-testid="input-preq-zip"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                    </div>
                    <button type="submit" data-testid="button-prequalify"
                            class="w-full bg-orange-500 hover:bg-orange-600 text-white font-semibold py-3 rounded-xl transition">
                        <i class="fas fa-search mr-2"></i>Check Availability
                    </button>
                </form>
            </div>

            <div class="bg-blue-50 border border-blue-200 rounded-xl p-5 text-sm text-blue-800">
                <p class="font-semibold mb-1"><i class="fas fa-info-circle mr-1"></i>How this works</p>
                <p>A pre-order SOAP request is sent to Frontier's <strong><?php echo $config['environment']; ?></strong> endpoint. Frontier responds with availability for the address. This does not reserve or commit any service.</p>
            </div>
        </div>

        <!-- NEW ORDER TAB -->
        <?php elseif ($tab === 'orders'): ?>
        <div class="max-w-3xl">
            <div class="bg-white border border-gray-200 rounded-xl p-6">
                <h2 class="font-semibold text-gray-900 mb-1 text-lg"><i class="fas fa-plus-circle text-orange-500 mr-2"></i>Submit New ASR Order</h2>
                <p class="text-sm text-gray-500 mb-6">Submit an Access Service Request to Frontier Wholesale for a new, change, or disconnect.</p>

                <form method="POST" action="?tab=orders">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="submit_order">
                    <input type="hidden" name="product_id"    id="hidden-product-id"    value="">
                    <input type="hidden" name="product_name"  id="hidden-product-name"  value="">
                    <input type="hidden" name="monthly_price" id="hidden-monthly-price" value="">

                    <!-- Invoice notice -->
                    <div class="mb-5 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex gap-3 items-start text-sm text-amber-800">
                        <i class="fas fa-file-invoice-dollar text-amber-500 mt-0.5 flex-shrink-0"></i>
                        <div>
                            <span class="font-semibold">An invoice will be generated immediately on submission.</span>
                            Payment must be collected before Frontier activates the service. The invoice is sent as <em>unpaid</em> — send it to the client and confirm payment before the service due date.
                        </div>
                    </div>

                    <!-- Service Plan & Pricing -->
                    <div class="mb-5">
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><i class="fas fa-wifi text-orange-400 mr-1"></i>Service Plan</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 mb-3" id="product-grid">
                            <?php foreach ($internetProducts as $prod): ?>
                            <label class="product-card cursor-pointer flex items-center gap-3 border border-gray-200 rounded-xl px-4 py-3 hover:border-orange-400 hover:bg-orange-50 transition has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50 has-[:checked]:ring-2 has-[:checked]:ring-orange-300"
                                   data-testid="product-card-<?php echo $prod['id']; ?>">
                                <input type="radio" name="_product_radio" value="<?php echo $prod['id']; ?>"
                                    data-id="<?php echo $prod['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($prod['name'], ENT_QUOTES); ?>"
                                    data-price="<?php echo number_format((float)$prod['price'], 2, '.', ''); ?>"
                                    data-desc="<?php echo htmlspecialchars($prod['description'] ?? '', ENT_QUOTES); ?>"
                                    class="product-radio sr-only"
                                    onchange="selectProduct(this)">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 leading-tight"><?php echo htmlspecialchars($prod['name']); ?></p>
                                    <p class="text-xs text-gray-400 truncate mt-0.5"><?php echo htmlspecialchars(strtok($prod['description'] ?? '', ',') ?: ''); ?></p>
                                </div>
                                <span class="text-sm font-bold text-orange-600 flex-shrink-0">$<?php echo number_format((float)$prod['price'], 0); ?>/mo</span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Selected plan summary -->
                        <div id="plan-summary" class="hidden bg-green-50 border border-green-200 rounded-xl px-4 py-3 flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-green-800" id="plan-name-display"></p>
                                <p class="text-xs text-green-600 mt-0.5" id="plan-desc-display"></p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-lg font-bold text-green-700" id="plan-price-display"></p>
                                <p class="text-xs text-green-600">monthly</p>
                            </div>
                        </div>
                    </div>

                    <!-- Client & Order type -->
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Client</label>
                            <?php $preselectedClientId = (int)($_GET['client_id'] ?? 0); ?>
                            <select name="client_id" data-testid="select-client" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                                <option value="">— Select client —</option>
                                <?php foreach ($clients as $cl): ?>
                                    <option value="<?php echo $cl['id']; ?>" <?php echo $preselectedClientId === (int)$cl['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl['company'] ?: $cl['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Order Type</label>
                            <select name="activity_code" data-testid="select-activity" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                                <option value="N">New Service</option>
                                <option value="C">Change</option>
                                <option value="D">Disconnect</option>
                            </select>
                        </div>
                    </div>

                    <!-- Account & Due date -->
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Account Number</label>
                            <input type="text" name="account_number" placeholder="Frontier account #" data-testid="input-account-number"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Desired Due Date</label>
                            <input type="date" name="desired_due_date" data-testid="input-due-date"
                                   min="<?php echo date('Y-m-d', strtotime('+5 days')); ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                    </div>

                    <!-- Service address -->
                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Service Address</label>
                        <input type="text" name="address_line1" required placeholder="Street address" data-testid="input-address"
                               class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                    </div>
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div>
                            <input type="text" name="city" required placeholder="City" data-testid="input-city"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                        <div>
                            <input type="text" name="state" required placeholder="TX" maxlength="2" data-testid="input-state"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                        <div>
                            <input type="text" name="zip" required placeholder="ZIP" data-testid="input-zip"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:border-orange-400 focus-visible:outline-none">
                        </div>
                    </div>

                    <!-- Contact override -->
                    <details class="mb-6 border border-gray-200 rounded-lg">
                        <summary class="px-4 py-3 text-sm font-semibold text-gray-700 cursor-pointer">Override Contact Info (optional)</summary>
                        <div class="grid grid-cols-3 gap-4 p-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Name</label>
                                <input type="text" name="contact_name" value="<?php echo htmlspecialchars($config['contact_name']); ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Phone</label>
                                <input type="text" name="contact_phone" value="<?php echo htmlspecialchars($config['contact_phone']); ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Email</label>
                                <input type="email" name="contact_email" value="<?php echo htmlspecialchars($config['contact_email']); ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            </div>
                        </div>
                    </details>

                    <button type="submit" data-testid="button-submit-order"
                            class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 rounded-xl transition shadow">
                        <i class="fas fa-paper-plane mr-2"></i>Submit Order to Frontier
                    </button>
                </form>
            </div>
        </div>

        <script>
        function selectProduct(radio) {
            document.getElementById('hidden-product-id').value    = radio.dataset.id;
            document.getElementById('hidden-product-name').value  = radio.dataset.name;
            document.getElementById('hidden-monthly-price').value = radio.dataset.price;
            var summary = document.getElementById('plan-summary');
            document.getElementById('plan-name-display').textContent  = radio.dataset.name;
            document.getElementById('plan-desc-display').textContent  = radio.dataset.desc;
            document.getElementById('plan-price-display').textContent = '$' + parseFloat(radio.dataset.price).toFixed(2) + '/mo';
            summary.classList.remove('hidden');
            // Highlight selected card
            document.querySelectorAll('.product-card').forEach(function(lbl) {
                lbl.classList.remove('border-orange-500','ring-2','ring-orange-300','bg-orange-50');
                lbl.classList.add('border-gray-200');
            });
            radio.closest('.product-card').classList.remove('border-gray-200');
            radio.closest('.product-card').classList.add('border-orange-500','ring-2','ring-orange-300','bg-orange-50');
        }
        </script>

        <!-- LOGS TAB -->
        <?php elseif ($tab === 'logs'): ?>
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900"><i class="fas fa-terminal text-orange-500 mr-2"></i>Activity Log</h2>
                <span class="text-xs text-gray-400"><?php echo count($logs); ?> entries</span>
            </div>
            <?php if (empty($logs)): ?>
                <div class="p-8 text-center text-gray-400 text-sm">No log entries yet.</div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left w-40">Time</th>
                            <th class="px-4 py-2 text-left w-16">Level</th>
                            <th class="px-4 py-2 text-left">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-mono">
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-400"><?php echo date('M d H:i:s', strtotime($log['created_at'])); ?></td>
                            <td class="px-4 py-2">
                                <span class="px-1.5 py-0.5 rounded text-xs font-semibold <?php echo $log['level']==='error' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>">
                                    <?php echo strtoupper($log['level']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-gray-700 break-all"><?php echo htmlspecialchars($log['message']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- SETTINGS TAB -->
        <?php elseif ($tab === 'settings'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white border border-gray-200 rounded-xl p-6">
                <h2 class="font-semibold text-gray-900 mb-5 text-lg"><i class="fas fa-cog text-orange-500 mr-2"></i>API Configuration</h2>
                <form method="POST" action="?tab=settings">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="save_settings">

                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Environment</label>
                        <select name="environment" data-testid="select-environment"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm">
                            <option value="TEST" <?php echo $config['environment']==='TEST'?'selected':''; ?>>TEST — epclec.frontier.com</option>
                            <option value="PRODUCTION" <?php echo $config['environment']==='PRODUCTION'?'selected':''; ?>>PRODUCTION — ep.frontier.com</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Always start in TEST. Switch to PRODUCTION only when approved by Frontier.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">CCNA</label>
                            <input type="text" name="ccna" value="<?php echo htmlspecialchars($config['ccna']); ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-mono">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Source IP</label>
                            <input type="text" name="source_ip" value="<?php echo htmlspecialchars($config['source_ip']); ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-mono">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Default Contact Name</label>
                        <input type="text" name="contact_name" value="<?php echo htmlspecialchars($config['contact_name']); ?>"
                               class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Contact Phone</label>
                            <input type="text" name="contact_phone" value="<?php echo htmlspecialchars($config['contact_phone']); ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Contact Email</label>
                            <input type="email" name="contact_email" value="<?php echo htmlspecialchars($config['contact_email']); ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm">
                        </div>
                    </div>

                    <button type="submit" data-testid="button-save-settings"
                            class="w-full bg-orange-500 hover:bg-orange-600 text-white font-semibold py-3 rounded-xl transition">
                        <i class="fas fa-save mr-2"></i>Save Settings
                    </button>
                </form>
            </div>

            <div>
                <div class="bg-white border border-gray-200 rounded-xl p-6 mb-4">
                    <h2 class="font-semibold text-gray-900 mb-4"><i class="fas fa-link text-orange-500 mr-2"></i>Frontier Callback Endpoints</h2>
                    <p class="text-xs text-gray-500 mb-4">Provide these to Barb at Frontier Connectivity Management for the ASR UOM form:</p>
                    <div class="space-y-3">
                        <?php foreach ([
                            'ORDER Receive URL'    => $receiveUrl . '?action=receive',
                            'PRE-ORDER Receive URL'=> $receiveUrl . '?action=preorder',
                            'Certificate CN'       => $host,
                            'Source IP'            => $config['source_ip'],
                            'CCNA'                 => $config['ccna'],
                        ] as $label => $val): ?>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-0.5"><?php echo $label; ?></p>
                            <code class="block text-xs bg-gray-100 px-3 py-2 rounded text-gray-800 break-all"><?php echo htmlspecialchars($val); ?></code>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bg-orange-50 border border-orange-200 rounded-xl p-5 text-sm text-orange-800">
                    <p class="font-semibold mb-1"><i class="fas fa-exclamation-triangle mr-1"></i>Before going PRODUCTION</p>
                    <ul class="list-disc list-inside space-y-1 text-xs">
                        <li>Complete at least one successful TEST order</li>
                        <li>Confirm CCNA <strong><?php echo htmlspecialchars($config['ccna']); ?></strong> with Frontier</li>
                        <li>Notify Frontier CM to switch endpoint URLs</li>
                        <li>Change Environment to PRODUCTION here</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /px-8 -->
</div><!-- /flex-1 -->
</div><!-- /flex h-screen -->

</body>
</html>
