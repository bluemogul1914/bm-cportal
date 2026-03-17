<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';

$pdo = getDB();

// ── Create tables ─────────────────────────────────────────────────────────────
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
        $client_id = (int)($_POST['client_id'] ?? 0) ?: null;
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

        $result = $client->sendOrder($orderData);

        if (isset($result['error'])) {
            $pdo->prepare("UPDATE frontier_orders SET status='ERROR', remarks=?, updated_at=NOW() WHERE pon=?")
                ->execute([$result['error'], $pon]);
            $error_msg = "Order submitted (PON: {$pon}) but Frontier returned error: " . $result['error'];
        } else {
            $orderManager->updateFromFrontierResponse($pon, $result);
            $success_msg = "Order submitted successfully. PON: {$pon} | Status: " . ($result['status'] ?? 'RECEIVED');
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

// ── Data for display ──────────────────────────────────────────────────────────
$orders  = $orderManager->recent(100);
$counts  = $orderManager->counts();
$clients = $pdo->query("SELECT id, name, company FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$logs    = $pdo->query("SELECT * FROM frontier_logs ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

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

<?php include 'includes/admin-sidebar.php'; ?>

<div class="ml-64 min-h-screen flex flex-col">
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
            <?php foreach (['dashboard'=>'Dashboard','prequalify'=>'Pre-Qualify','orders'=>'New Order','logs'=>'Logs','settings'=>'Settings'] as $t => $label): ?>
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
                               class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                    </div>
                    <div class="grid grid-cols-3 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">City</label>
                            <input type="text" name="city" required placeholder="Houston" data-testid="input-preq-city"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">State</label>
                            <input type="text" name="state" required placeholder="TX" maxlength="2" data-testid="input-preq-state"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">ZIP</label>
                            <input type="text" name="zip" required placeholder="77001" data-testid="input-preq-zip"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
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

                    <!-- Client & Order type -->
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Client</label>
                            <select name="client_id" data-testid="select-client" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                                <option value="">— Select client —</option>
                                <?php foreach ($clients as $cl): ?>
                                    <option value="<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['company'] ?: $cl['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Order Type</label>
                            <select name="activity_code" data-testid="select-activity" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
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
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Desired Due Date</label>
                            <input type="date" name="desired_due_date" data-testid="input-due-date"
                                   min="<?php echo date('Y-m-d', strtotime('+5 days')); ?>"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                        </div>
                    </div>

                    <!-- Service address -->
                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Service Address</label>
                        <input type="text" name="address_line1" required placeholder="Street address" data-testid="input-address"
                               class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                    </div>
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div>
                            <input type="text" name="city" required placeholder="City" data-testid="input-city"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                        </div>
                        <div>
                            <input type="text" name="state" required placeholder="TX" maxlength="2" data-testid="input-state"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
                        </div>
                        <div>
                            <input type="text" name="zip" required placeholder="ZIP" data-testid="input-zip"
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 focus:outline-none">
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
</div><!-- /ml-64 -->

</body>
</html>
