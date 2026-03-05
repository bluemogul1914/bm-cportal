<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$vultr_connected = !empty(VULTR_API_KEY);
$vultr_api_url = VULTR_API_URL;
$pdo = getDB();

$success_msg = '';
$error_msg = '';

function vultr_api_get($endpoint) {
    $url = VULTR_API_URL . $endpoint;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . VULTR_API_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => $curl_error, 'http_code' => 0];
    }
    if ($http_code !== 200) {
        $body = json_decode($response, true);
        $msg = $body['error'] ?? "HTTP $http_code";
        return ['error' => $msg, 'http_code' => $http_code];
    }
    return json_decode($response, true) ?: ['error' => 'Invalid JSON response'];
}

$api_account = null;
$api_error = '';
$sync_count = 0;
$total_monthly_cost = 0;
$total_bandwidth_allowed = 0;
$total_bandwidth_used = 0;
$active_count = 0;
$stopped_count = 0;

function vultr_sync_instances($pdo) {
    $instances_data = vultr_api_get('/instances');
    if (isset($instances_data['error'])) {
        return ['error' => $instances_data['error'], 'count' => 0];
    }

    $api_instances = $instances_data['instances'] ?? [];
    $synced = 0;
    $errors = [];

    foreach ($api_instances as $inst) {
        $vultr_id = $inst['id'] ?? '';
        if (!$vultr_id) continue;

        try {
            $pdo->prepare("INSERT INTO vultr_instances (vultr_id, label, hostname, os, ram, disk, vcpu_count, region, plan, main_ip, v6_main_ip, status, power_status, allowed_bandwidth, cost_per_month, date_created, last_synced, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON CONFLICT (vultr_id) DO UPDATE SET label = EXCLUDED.label, hostname = EXCLUDED.hostname, os = EXCLUDED.os, ram = EXCLUDED.ram, disk = EXCLUDED.disk, vcpu_count = EXCLUDED.vcpu_count, region = EXCLUDED.region, plan = EXCLUDED.plan, main_ip = EXCLUDED.main_ip, v6_main_ip = EXCLUDED.v6_main_ip, status = EXCLUDED.status, power_status = EXCLUDED.power_status, allowed_bandwidth = EXCLUDED.allowed_bandwidth, cost_per_month = EXCLUDED.cost_per_month, last_synced = NOW(), updated_at = NOW()")
                ->execute([
                    $vultr_id,
                    $inst['label'] ?? '',
                    $inst['hostname'] ?? '',
                    $inst['os'] ?? '',
                    intval($inst['ram'] ?? 0),
                    intval($inst['disk'] ?? 0),
                    intval($inst['vcpu_count'] ?? 0),
                    $inst['region'] ?? '',
                    $inst['plan'] ?? '',
                    $inst['main_ip'] ?? '',
                    $inst['v6_main_ip'] ?? '',
                    $inst['status'] ?? '',
                    $inst['power_status'] ?? '',
                    floatval($inst['allowed_bandwidth'] ?? 0),
                    floatval($inst['monthly_cost'] ?? $inst['cost'] ?? 0),
                    $inst['date_created'] ?? null,
                ]);
            $synced++;
        } catch (Exception $e) {
            $errors[] = $vultr_id . ': ' . $e->getMessage();
        }
    }

    foreach ($api_instances as $idx => $inst) {
        $vultr_id = $inst['id'] ?? '';
        if (!$vultr_id) continue;
        if ($idx > 0) usleep(350000);

        $bw_data = vultr_api_get("/instances/{$vultr_id}/bandwidth");
        if (!isset($bw_data['error']) && isset($bw_data['bandwidth'])) {
            $bw_entries = array_values($bw_data['bandwidth']);
            if (!empty($bw_entries)) {
                $last = end($bw_entries);
                $incoming_gb = floatval($last['incoming_bytes'] ?? 0) / (1024*1024*1024);
                $outgoing_gb = floatval($last['outgoing_bytes'] ?? 0) / (1024*1024*1024);
                $inst_bw = round($incoming_gb + $outgoing_gb, 2);
                try {
                    $pdo->prepare("UPDATE vultr_instances SET current_bandwidth = ? WHERE vultr_id = ?")
                        ->execute([$inst_bw, $vultr_id]);
                } catch (Exception $e) {
                    $errors[] = "BW {$vultr_id}: " . $e->getMessage();
                }
            }
        }
    }

    return ['error' => null, 'count' => $synced, 'errors' => $errors];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_client') {
        $instance_id = intval($_POST['instance_id'] ?? 0);
        $client_id = intval($_POST['client_id'] ?? 0);

        if ($instance_id > 0) {
            $stmt = $pdo->prepare("UPDATE vultr_instances SET client_id = ? WHERE id = ?");
            $stmt->execute([$client_id > 0 ? $client_id : null, $instance_id]);
            $success_msg = $client_id > 0 ? 'Instance assigned to client successfully.' : 'Instance unassigned from client.';

            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$_SESSION['user_id'], 'vultr_instance_assigned', 'vultr_instance', $instance_id, "Assigned Vultr instance to client ID: " . ($client_id ?: 'none'), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
        }
    }

    if ($action === 'sync_instances' && $vultr_connected) {
        $sync_result = vultr_sync_instances($pdo);
        if ($sync_result['error']) {
            $err = $sync_result['error'];
            if (strpos(strtolower($err), 'unauthorized') !== false) {
                $err .= ' — Go to my.vultr.com → Account → API → Access Control and add this IP, or select "Allow All IPv4".';
            }
            $error_msg = 'Sync failed: ' . $err;
        } else {
            $success_msg = "Synced {$sync_result['count']} instances from Vultr API (including bandwidth data).";
            if (!empty($sync_result['errors'])) {
                $success_msg .= ' Some warnings: ' . implode('; ', array_slice($sync_result['errors'], 0, 3));
            }
        }

        $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'], 'vultr_sync', 'vultr_instance', 0, "Synced {$sync_result['count']} instances from Vultr API", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    }
}

if ($vultr_connected) {
    $account_data = vultr_api_get('/account');
    if (!isset($account_data['error'])) {
        $api_account = $account_data['account'] ?? null;
    } else {
        $err = $account_data['error'];
        if (strpos(strtolower($err), 'unauthorized') !== false) {
            $api_error = $err . ' — Go to my.vultr.com → Account → API → Access Control and add this IP, or select "Allow All IPv4".';
        } else {
            $api_error = $err;
        }
    }
}

$db_instances = [];
try {
    $db_instances = $pdo->query("SELECT vi.*, c.name as client_name, c.company as client_company FROM vultr_instances vi LEFT JOIN clients c ON vi.client_id = c.id ORDER BY vi.cost_per_month DESC, vi.label")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$clients_list = [];
try {
    $clients_list = $pdo->query("SELECT id, name, company FROM clients WHERE status = 'active' ORDER BY company, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$assigned_count = 0;
$unassigned_count = 0;
foreach ($db_instances as $di) {
    if (!empty($di['client_id'])) {
        $assigned_count++;
    } else {
        $unassigned_count++;
    }
    $total_monthly_cost += floatval($di['cost_per_month'] ?? 0);
    $total_bandwidth_allowed += floatval($di['allowed_bandwidth'] ?? 0);
    $total_bandwidth_used += floatval($di['current_bandwidth'] ?? 0);
    $power = strtolower($di['power_status'] ?? '');
    if ($power === 'running') {
        $active_count++;
    } else {
        $stopped_count++;
    }
}

$last_synced_time = null;
try {
    $stmt = $pdo->query("SELECT MAX(last_synced) as last_sync FROM vultr_instances");
    $row = $stmt->fetch();
    $last_synced_time = $row['last_sync'] ?? null;
} catch (Exception $e) {}

$cost_by_client = [];
foreach ($db_instances as $di) {
    $client_label = !empty($di['client_name']) ? ($di['client_company'] ?: $di['client_name']) : 'Unassigned';
    $cost_by_client[$client_label] = ($cost_by_client[$client_label] ?? 0) + floatval($di['cost_per_month'] ?? 0);
}
arsort($cost_by_client);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vultr Cloud - Blue Mogul Admin</title>
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
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-cloud text-blue-500 mr-2"></i>Vultr Cloud Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Cloud infrastructure &mdash; Instances, bandwidth, and billing management</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($vultr_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sync_instances">
                            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-sync">
                                <i class="fas fa-sync-alt mr-1"></i>Sync Now
                            </button>
                        </form>
                        <?php if (empty($api_error)): ?>
                            <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                        <?php else: ?>
                            <span class="px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium" data-testid="status-error"><i class="fas fa-exclamation-triangle text-[10px] mr-1"></i>API Error</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-3"></i><span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-3"></i><span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($api_error): ?>
                <div class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-api-error">
                    <i class="fas fa-exclamation-triangle mr-3"></i><span>Vultr API Error: <?php echo htmlspecialchars($api_error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($api_account): ?>
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6" data-testid="card-account-info">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-100 rounded-lg p-3">
                            <i class="fas fa-user-circle text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900" data-testid="text-account-name"><?php echo htmlspecialchars($api_account['name'] ?? $api_account['email'] ?? 'Vultr Account'); ?></h3>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($api_account['email'] ?? ''); ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-6 text-sm">
                        <div class="text-center">
                            <p class="text-xs text-gray-500 uppercase font-semibold">Balance</p>
                            <p class="text-lg font-bold <?php echo floatval($api_account['balance'] ?? 0) < 0 ? 'text-green-600' : 'text-gray-900'; ?>" data-testid="text-balance">
                                $<?php echo number_format(abs(floatval($api_account['balance'] ?? 0)), 2); ?>
                                <?php if (floatval($api_account['balance'] ?? 0) < 0): ?><span class="text-xs text-green-600">credit</span><?php endif; ?>
                            </p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs text-gray-500 uppercase font-semibold">Pending Charges</p>
                            <p class="text-lg font-bold text-orange-600" data-testid="text-pending-charges">$<?php echo number_format(floatval($api_account['pending_charges'] ?? 0), 2); ?></p>
                        </div>
                        <div class="text-center">
                            <p class="text-xs text-gray-500 uppercase font-semibold">Last Payment</p>
                            <p class="text-lg font-bold text-gray-900" data-testid="text-last-payment">$<?php echo number_format(abs(floatval($api_account['last_payment_amount'] ?? 0)), 2); ?></p>
                            <p class="text-xs text-gray-400"><?php echo $api_account['last_payment_date'] ? date('M d, Y', strtotime($api_account['last_payment_date'])) : 'N/A'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-instances">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Instances</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($db_instances); ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $last_synced_time ? 'Synced ' . date('M d g:ia', strtotime($last_synced_time)) : 'Not synced yet'; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-active-instances">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Running</p>
                    <p class="text-2xl font-bold text-green-600"><?php echo $active_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Powered on</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-stopped-instances">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Stopped</p>
                    <p class="text-2xl font-bold text-red-600"><?php echo $stopped_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Powered off</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-monthly-cost">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Monthly Cost</p>
                    <p class="text-2xl font-bold text-blue-600">$<?php echo number_format($total_monthly_cost, 2); ?></p>
                    <p class="text-xs text-gray-400 mt-1">All instances</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-assigned">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Assigned</p>
                    <p class="text-2xl font-bold text-indigo-600"><?php echo $assigned_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Linked to clients</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-unassigned">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Unassigned</p>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $unassigned_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Not linked yet</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-instances-title"><i class="fas fa-server text-blue-500 mr-2"></i>Cloud Instances</h2>
                            <span class="text-sm text-gray-500"><?php echo count($db_instances); ?> servers synced</span>
                        </div>
                        <?php if (!empty($db_instances)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-instances">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Label</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">IP Address</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Specs</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Region</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Cost/Mo</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Bandwidth</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($db_instances as $idx => $inst): ?>
                                        <?php
                                            $power = strtolower($inst['power_status'] ?? '');
                                            $status_dot = match($power) {
                                                'running' => 'bg-green-500',
                                                'stopped' => 'bg-red-500',
                                                default => 'bg-gray-400',
                                            };
                                            $ram_gb = round(intval($inst['ram'] ?? 0) / 1024, 1);
                                            if ($ram_gb < 1) $ram_gb = intval($inst['ram'] ?? 0) . 'MB';
                                            else $ram_gb = $ram_gb . 'GB';
                                            $bw_used = floatval($inst['current_bandwidth'] ?? 0);
                                            $bw_allowed = floatval($inst['allowed_bandwidth'] ?? 0);
                                            $bw_pct = $bw_allowed > 0 ? round(($bw_used / ($bw_allowed * 1024)) * 100, 1) : 0;
                                        ?>
                                        <tr class="hover:bg-gray-50" data-testid="row-instance-<?php echo $inst['id']; ?>">
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <span class="h-2.5 w-2.5 rounded-full <?php echo $status_dot; ?>"></span>
                                                    <span class="text-xs font-medium <?php echo $power === 'running' ? 'text-green-700' : 'text-red-700'; ?>"><?php echo ucfirst($power ?: 'unknown'); ?></span>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inst['label'] ?: $inst['hostname'] ?: 'Unnamed'); ?></p>
                                                    <p class="text-xs text-gray-400"><?php echo htmlspecialchars($inst['os'] ?? ''); ?></p>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <p class="text-sm text-gray-900 font-mono"><?php echo htmlspecialchars($inst['main_ip'] ?? ''); ?></p>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <p class="text-sm text-gray-700"><?php echo intval($inst['vcpu_count'] ?? 0); ?> vCPU / <?php echo $ram_gb; ?> / <?php echo intval($inst['disk'] ?? 0); ?>GB</p>
                                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($inst['plan'] ?? ''); ?></p>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <span class="text-sm text-gray-700"><?php echo strtoupper($inst['region'] ?? ''); ?></span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <span class="text-sm font-semibold text-gray-900">$<?php echo number_format(floatval($inst['cost_per_month'] ?? 0), 2); ?></span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="w-24">
                                                    <div class="flex justify-between text-xs mb-1">
                                                        <span class="text-gray-500"><?php echo number_format($bw_used, 1); ?> GB</span>
                                                        <span class="text-gray-400"><?php echo $bw_pct; ?>%</span>
                                                    </div>
                                                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                                                        <div class="<?php echo $bw_pct > 80 ? 'bg-red-500' : ($bw_pct > 50 ? 'bg-yellow-500' : 'bg-blue-500'); ?> h-1.5 rounded-full" style="width: <?php echo min($bw_pct, 100); ?>%"></div>
                                                    </div>
                                                    <p class="text-xs text-gray-400 mt-0.5"><?php echo number_format($bw_allowed, 0); ?> TB allowed</p>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <form method="POST" class="flex items-center gap-2" data-testid="form-assign-<?php echo $inst['id']; ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="assign_client">
                                                    <input type="hidden" name="instance_id" value="<?php echo $inst['id']; ?>">
                                                    <select name="client_id" class="text-xs border border-gray-300 rounded-md px-2 py-1 w-32 focus:ring-blue-500 focus:border-blue-500" data-testid="select-client-<?php echo $inst['id']; ?>">
                                                        <option value="0">-- None --</option>
                                                        <?php foreach ($clients_list as $cl): ?>
                                                            <option value="<?php echo $cl['id']; ?>" <?php echo (intval($inst['client_id'] ?? 0) === intval($cl['id'])) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($cl['company'] ?: $cl['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-xs font-medium transition" data-testid="button-assign-<?php echo $inst['id']; ?>">
                                                        <i class="fas fa-link"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php elseif ($vultr_connected): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class="fas fa-server text-4xl text-gray-300 mb-3"></i>
                                <p class="text-sm">No instances found. Click "Sync Now" to pull from Vultr API.</p>
                            </div>
                        <?php else: ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class="fas fa-plug text-4xl text-gray-300 mb-3"></i>
                                <p class="text-sm">Connect your Vultr API key to view instances.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white rounded-lg border border-gray-200" data-testid="card-cost-breakdown">
                        <div class="px-5 py-4 border-b border-gray-100">
                            <h3 class="font-semibold text-gray-900"><i class="fas fa-chart-pie text-blue-500 mr-2"></i>Cost by Client</h3>
                        </div>
                        <div class="p-5">
                            <?php if (!empty($cost_by_client)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($cost_by_client as $client_label => $cost): ?>
                                        <?php $cost_pct = $total_monthly_cost > 0 ? round(($cost / $total_monthly_cost) * 100, 1) : 0; ?>
                                        <div>
                                            <div class="flex justify-between text-sm mb-1">
                                                <span class="text-gray-700 font-medium truncate mr-2"><?php echo htmlspecialchars($client_label); ?></span>
                                                <span class="text-gray-900 font-semibold whitespace-nowrap">$<?php echo number_format($cost, 2); ?></span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2">
                                                <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $cost_pct; ?>%"></div>
                                            </div>
                                            <p class="text-xs text-gray-400 mt-0.5"><?php echo $cost_pct; ?>% of total</p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4 pt-4 border-t border-gray-200">
                                    <div class="flex justify-between text-sm font-bold">
                                        <span class="text-gray-700">Total Monthly</span>
                                        <span class="text-blue-600">$<?php echo number_format($total_monthly_cost, 2); ?></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500 text-center py-4">No cost data available</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200" data-testid="card-bandwidth-overview">
                        <div class="px-5 py-4 border-b border-gray-100">
                            <h3 class="font-semibold text-gray-900"><i class="fas fa-tachometer-alt text-green-500 mr-2"></i>Bandwidth Overview</h3>
                        </div>
                        <div class="p-5">
                            <div class="text-center mb-4">
                                <p class="text-3xl font-bold text-gray-900"><?php echo number_format($total_bandwidth_used, 1); ?> <span class="text-sm font-normal text-gray-500">GB used</span></p>
                                <p class="text-sm text-gray-400 mt-1">of <?php echo number_format($total_bandwidth_allowed, 0); ?> TB allowed total</p>
                            </div>
                            <?php
                                $total_bw_pct = $total_bandwidth_allowed > 0 ? round(($total_bandwidth_used / ($total_bandwidth_allowed * 1024)) * 100, 1) : 0;
                            ?>
                            <div class="w-full bg-gray-200 rounded-full h-3 mb-2">
                                <div class="<?php echo $total_bw_pct > 80 ? 'bg-red-500' : ($total_bw_pct > 50 ? 'bg-yellow-500' : 'bg-green-500'); ?> h-3 rounded-full transition-all" style="width: <?php echo min($total_bw_pct, 100); ?>%"></div>
                            </div>
                            <p class="text-xs text-gray-400 text-center"><?php echo $total_bw_pct; ?>% utilized this period</p>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200" data-testid="card-capabilities">
                        <div class="px-5 py-4 border-b border-gray-100">
                            <h3 class="font-semibold text-gray-900"><i class="fas fa-puzzle-piece text-purple-500 mr-2"></i>Capabilities</h3>
                        </div>
                        <div class="p-5 space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="bg-blue-100 rounded-lg p-2 flex-shrink-0"><i class="fas fa-server text-blue-600 text-sm"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Instance Management</p>
                                    <p class="text-xs text-gray-500">Monitor all VPS instances, power status, and specs</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="bg-green-100 rounded-lg p-2 flex-shrink-0"><i class="fas fa-chart-line text-green-600 text-sm"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Bandwidth Monitoring</p>
                                    <p class="text-xs text-gray-500">Track usage vs allowance per instance per billing period</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="bg-purple-100 rounded-lg p-2 flex-shrink-0"><i class="fas fa-users text-purple-600 text-sm"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Client Assignment</p>
                                    <p class="text-xs text-gray-500">Assign instances to clients for billing and cost tracking</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="bg-orange-100 rounded-lg p-2 flex-shrink-0"><i class="fas fa-dollar-sign text-orange-600 text-sm"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Cost Breakdown</p>
                                    <p class="text-xs text-gray-500">Per-client cost allocation and monthly totals</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="bg-indigo-100 rounded-lg p-2 flex-shrink-0"><i class="fas fa-sync text-indigo-600 text-sm"></i></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Auto Sync</p>
                                    <p class="text-xs text-gray-500">Data synced from Vultr API on each page load</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$vultr_connected): ?>
            <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-setup">
                <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-cog text-gray-500 mr-2"></i>Setup Instructions</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="font-medium text-gray-800 mb-3">Connect Your Vultr Account</h3>
                        <ol class="list-decimal list-inside space-y-2 text-sm text-gray-600">
                            <li>Log into your Vultr account at <a href="https://my.vultr.com" target="_blank" class="text-blue-600 hover:underline">my.vultr.com</a></li>
                            <li>Navigate to <strong>Account &rarr; API</strong> in the sidebar</li>
                            <li>Click <strong>Enable API</strong> if not already enabled</li>
                            <li>Copy your <strong>Personal Access Token</strong></li>
                            <li>Under Access Control, add your server IP or select "Allow All IPv4"</li>
                            <li>Set the <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">VULTR_API_KEY</code> secret in your Replit environment</li>
                            <li>Restart the application to apply changes</li>
                        </ol>
                    </div>
                    <div>
                        <h3 class="font-medium text-gray-800 mb-3">API Information</h3>
                        <div class="bg-gray-50 rounded-lg p-4 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">API Version</span>
                                <span class="font-medium text-gray-900">v2</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Base URL</span>
                                <span class="font-mono text-xs text-gray-900">https://api.vultr.com/v2</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Auth Method</span>
                                <span class="font-medium text-gray-900">Bearer Token</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Rate Limit</span>
                                <span class="font-medium text-gray-900">3 req/sec general, 1 req/sec create</span>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h4 class="font-medium text-gray-700 text-sm mb-2">Endpoints Used</h4>
                            <ul class="text-xs font-mono text-gray-600 space-y-1">
                                <li><span class="text-green-600">GET</span> /v2/account</li>
                                <li><span class="text-green-600">GET</span> /v2/instances</li>
                                <li><span class="text-green-600">GET</span> /v2/instances/{id}/bandwidth</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($vultr_connected && !empty($db_instances)): ?>
            <div class="bg-white rounded-lg border border-gray-200" data-testid="card-instance-details">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-list-alt text-blue-500 mr-2"></i>Instance Details</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-6">
                    <?php foreach ($db_instances as $inst): ?>
                        <?php
                            $power = strtolower($inst['power_status'] ?? '');
                            $ram_gb = round(intval($inst['ram'] ?? 0) / 1024, 1);
                            if ($ram_gb < 1) $ram_gb = intval($inst['ram'] ?? 0) . ' MB';
                            else $ram_gb = $ram_gb . ' GB';
                        ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition" data-testid="detail-card-<?php echo $inst['id']; ?>">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 rounded-full <?php echo $power === 'running' ? 'bg-green-500' : 'bg-red-500'; ?>"></span>
                                    <h4 class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($inst['label'] ?: $inst['hostname'] ?: 'Unnamed'); ?></h4>
                                </div>
                                <span class="text-sm font-bold text-blue-600">$<?php echo number_format(floatval($inst['cost_per_month'] ?? 0), 2); ?>/mo</span>
                            </div>
                            <div class="space-y-1.5 text-xs text-gray-600">
                                <div class="flex justify-between">
                                    <span>IP</span>
                                    <span class="font-mono"><?php echo htmlspecialchars($inst['main_ip'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>OS</span>
                                    <span><?php echo htmlspecialchars($inst['os'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>CPU / RAM / Disk</span>
                                    <span><?php echo intval($inst['vcpu_count'] ?? 0); ?> vCPU / <?php echo $ram_gb; ?> / <?php echo intval($inst['disk'] ?? 0); ?> GB</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Region</span>
                                    <span><?php echo strtoupper($inst['region'] ?? ''); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Plan</span>
                                    <span><?php echo htmlspecialchars($inst['plan'] ?? 'N/A'); ?></span>
                                </div>
                                <?php if (!empty($inst['client_name'])): ?>
                                <div class="flex justify-between">
                                    <span>Client</span>
                                    <span class="text-blue-600 font-medium"><?php echo htmlspecialchars($inst['client_company'] ?: $inst['client_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex justify-between">
                                    <span>Created</span>
                                    <span><?php echo $inst['date_created'] ? date('M d, Y', strtotime($inst['date_created'])) : 'N/A'; ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Last Synced</span>
                                    <span><?php echo $inst['last_synced'] ? date('M d g:ia', strtotime($inst['last_synced'])) : 'Never'; ?></span>
                                </div>
                            </div>
                            <?php
                                $bw_used = floatval($inst['current_bandwidth'] ?? 0);
                                $bw_allowed = floatval($inst['allowed_bandwidth'] ?? 0);
                                $bw_pct = $bw_allowed > 0 ? round(($bw_used / ($bw_allowed * 1024)) * 100, 1) : 0;
                            ?>
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="text-gray-500">Bandwidth</span>
                                    <span class="text-gray-500"><?php echo number_format($bw_used, 1); ?> GB / <?php echo number_format($bw_allowed, 0); ?> TB</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-1.5">
                                    <div class="<?php echo $bw_pct > 80 ? 'bg-red-500' : ($bw_pct > 50 ? 'bg-yellow-500' : 'bg-blue-500'); ?> h-1.5 rounded-full" style="width: <?php echo min($bw_pct, 100); ?>%"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>
