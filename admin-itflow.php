<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$itflow_connected = !empty(ITFLOW_API_KEY);
$itflow_url = ITFLOW_URL;
$itflow_api_url = rtrim($itflow_url, '/') . '/api/v1';

$db = getDB();
$success_msg = '';
$error_msg = '';
$sync_results = null;
$test_result = null;
$sync_logs = [];

function itflow_api_request($endpoint, $params = []) {
    $params['api_key'] = ITFLOW_API_KEY;
    $url = rtrim(ITFLOW_URL, '/') . '/api/v1/' . ltrim($endpoint, '/');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url . '?' . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => 'cURL error: ' . $curl_error, 'http_code' => 0];
    }

    $data = json_decode($response, true);
    if ($data === null && !empty($response)) {
        return ['success' => false, 'error' => 'Invalid JSON response from API', 'http_code' => $http_code, 'data' => null, 'raw' => substr($response, 0, 500)];
    }
    return [
        'success' => $http_code >= 200 && $http_code < 300,
        'http_code' => $http_code,
        'data' => $data,
        'raw' => $response,
    ];
}

function log_sync_action($db, $action, $entity_type, $status, $details, $count = 0) {
    $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([
           $_SESSION['user_id'],
           $action,
           $entity_type,
           $count,
           json_encode(['message' => $details, 'source' => 'itflow_sync']),
           $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
       ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $itflow_connected) {
    $action = $_POST['action'] ?? '';

    if ($action === 'test_connection') {
        $result = itflow_api_request('clients', ['page' => 1, 'per_page' => 1]);
        if ($result['success']) {
            $test_result = ['status' => 'success', 'message' => 'Connection successful! API responded with HTTP ' . $result['http_code']];
            log_sync_action($db, 'itflow_test', 'integration', 'success', 'ITFlow API connection test passed');
            $success_msg = 'Connection test passed successfully.';
        } else {
            $error_detail = $result['error'] ?? ('HTTP ' . $result['http_code']);
            $test_result = ['status' => 'error', 'message' => 'Connection failed: ' . $error_detail];
            log_sync_action($db, 'itflow_test', 'integration', 'failed', 'ITFlow API connection test failed: ' . $error_detail);
            $error_msg = 'Connection test failed: ' . $error_detail;
        }
    }

    if ($action === 'sync_clients') {
        $result = itflow_api_request('clients');
        if ($result['success'] && isset($result['data'])) {
            $clients_data = $result['data']['data'] ?? $result['data'];
            if (!is_array($clients_data)) $clients_data = [];

            $synced = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($clients_data as $itf_client) {
                $name = $itf_client['client_name'] ?? $itf_client['name'] ?? '';
                $email = $itf_client['client_email'] ?? $itf_client['email'] ?? '';
                $phone = $itf_client['client_phone'] ?? $itf_client['phone'] ?? '';
                $company = $itf_client['client_name'] ?? $itf_client['company'] ?? '';

                if (empty($email)) { $skipped++; continue; }

                try {
                    $existing = $db->prepare("SELECT id FROM clients WHERE email = ?")->execute([$email]);
                    $exists = $existing->fetch();

                    if ($exists) {
                        $db->prepare("UPDATE clients SET name = COALESCE(NULLIF(?, ''), name), phone = COALESCE(NULLIF(?, ''), phone), company = COALESCE(NULLIF(?, ''), company) WHERE email = ?")
                           ->execute([$name, $phone, $company, $email]);
                        $synced++;
                    } else {
                        $temp_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                        $db->prepare("INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, false)")
                           ->execute([$name ?: $email, $email, $temp_pass]);
                        $user_id = $db->lastInsertId();

                        $db->prepare("INSERT INTO clients (user_id, name, email, phone, company, status) VALUES (?, ?, ?, ?, ?, 'active')")
                           ->execute([$user_id, $name, $email, $phone, $company]);
                        $synced++;
                    }
                } catch (Exception $e) {
                    $errors++;
                }
            }

            $total = count($clients_data);
            $sync_results = ['type' => 'clients', 'total' => $total, 'synced' => $synced, 'skipped' => $skipped, 'errors' => $errors];
            log_sync_action($db, 'itflow_sync_clients', 'client', 'completed', "Synced $synced/$total clients ($skipped skipped, $errors errors)", $synced);
            $success_msg = "Client sync complete: $synced synced, $skipped skipped, $errors errors out of $total total.";
        } else {
            $error_msg = 'Failed to fetch clients from ITFlow: ' . ($result['error'] ?? 'HTTP ' . $result['http_code']);
            log_sync_action($db, 'itflow_sync_clients', 'client', 'failed', $error_msg);
        }
    }

    if ($action === 'sync_tickets') {
        $result = itflow_api_request('tickets');
        if ($result['success'] && isset($result['data'])) {
            $tickets_data = $result['data']['data'] ?? $result['data'];
            if (!is_array($tickets_data)) $tickets_data = [];

            $synced = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($tickets_data as $itf_ticket) {
                $subject = $itf_ticket['ticket_subject'] ?? $itf_ticket['subject'] ?? '';
                $body = $itf_ticket['ticket_details'] ?? $itf_ticket['body'] ?? $itf_ticket['description'] ?? '';
                $status_raw = $itf_ticket['ticket_status'] ?? $itf_ticket['status'] ?? 'open';
                $priority_raw = $itf_ticket['ticket_priority'] ?? $itf_ticket['priority'] ?? 'medium';
                $client_email = $itf_ticket['client_email'] ?? $itf_ticket['contact_email'] ?? '';
                $itf_id = $itf_ticket['ticket_id'] ?? $itf_ticket['id'] ?? '';

                if (empty($subject)) { $skipped++; continue; }

                $status_map = ['Open' => 'open', 'Working' => 'in_progress', 'In Progress' => 'in_progress', 'Closed' => 'closed', 'Resolved' => 'closed'];
                $status = $status_map[$status_raw] ?? strtolower($status_raw);
                if (!in_array($status, ['open', 'in_progress', 'closed'])) $status = 'open';

                $priority_map = ['Low' => 'low', 'Medium' => 'medium', 'High' => 'high', 'Critical' => 'urgent', 'Urgent' => 'urgent'];
                $priority = $priority_map[$priority_raw] ?? strtolower($priority_raw);
                if (!in_array($priority, ['low', 'medium', 'high', 'urgent'])) $priority = 'medium';

                try {
                    $client_id = null;
                    if ($client_email) {
                        $cl = $db->prepare("SELECT id FROM clients WHERE email = ?")->execute([$client_email]);
                        $client_row = $cl->fetch();
                        if ($client_row) $client_id = $client_row['id'];
                    }

                    if (!$client_id) { $skipped++; continue; }

                    $dup = $db->prepare("SELECT id FROM tickets WHERE subject = ? AND client_id = ?")->execute([$subject, $client_id]);
                    if ($dup->fetch()) {
                        $skipped++;
                        continue;
                    }

                    $db->prepare("INSERT INTO tickets (client_id, subject, description, status, priority) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$client_id, $subject, $body, $status, $priority]);
                    $synced++;
                } catch (Exception $e) {
                    $errors++;
                }
            }

            $total = count($tickets_data);
            $sync_results = ['type' => 'tickets', 'total' => $total, 'synced' => $synced, 'skipped' => $skipped, 'errors' => $errors];
            log_sync_action($db, 'itflow_sync_tickets', 'ticket', 'completed', "Synced $synced/$total tickets ($skipped skipped, $errors errors)", $synced);
            $success_msg = "Ticket sync complete: $synced synced, $skipped skipped, $errors errors out of $total total.";
        } else {
            $error_msg = 'Failed to fetch tickets from ITFlow: ' . ($result['error'] ?? 'HTTP ' . $result['http_code']);
            log_sync_action($db, 'itflow_sync_tickets', 'ticket', 'failed', $error_msg);
        }
    }

    if ($action === 'sync_assets') {
        $result = itflow_api_request('assets');
        if ($result['success'] && isset($result['data'])) {
            $assets_data = $result['data']['data'] ?? $result['data'];
            if (!is_array($assets_data)) $assets_data = [];

            $synced = 0;
            $skipped = 0;

            foreach ($assets_data as $asset) {
                $name = $asset['asset_name'] ?? $asset['name'] ?? '';
                $type = $asset['asset_type'] ?? $asset['type'] ?? 'other';
                $serial = $asset['asset_serial'] ?? $asset['serial'] ?? '';
                $client_name = $asset['client_name'] ?? '';

                if (empty($name)) { $skipped++; continue; }

                try {
                    $client_id = null;
                    if ($client_name) {
                        $cl = $db->prepare("SELECT id FROM clients WHERE name ILIKE ? OR company ILIKE ? LIMIT 1")->execute(['%'.$client_name.'%', '%'.$client_name.'%']);
                        $cr = $cl->fetch();
                        if ($cr) $client_id = $cr['id'];
                    }

                    $dup = $db->prepare("SELECT id FROM network_devices WHERE device_name = ? AND client_id = ?")->execute([$name, $client_id]);
                    if ($dup->fetch()) { $skipped++; continue; }

                    $db->prepare("INSERT INTO network_devices (client_id, device_name, device_type, ip_address, status, notes) VALUES (?, ?, ?, ?, 'online', ?)")
                       ->execute([$client_id, $name, $type, '', 'ITFlow asset - Serial: ' . $serial]);
                    $synced++;
                } catch (Exception $e) {
                    $skipped++;
                }
            }

            $total = count($assets_data);
            $sync_results = ['type' => 'assets', 'total' => $total, 'synced' => $synced, 'skipped' => $skipped, 'errors' => 0];
            log_sync_action($db, 'itflow_sync_assets', 'asset', 'completed', "Synced $synced/$total assets ($skipped skipped)", $synced);
            $success_msg = "Asset sync complete: $synced synced, $skipped skipped out of $total total.";
        } else {
            $error_msg = 'Failed to fetch assets from ITFlow: ' . ($result['error'] ?? 'HTTP ' . $result['http_code']);
            log_sync_action($db, 'itflow_sync_assets', 'asset', 'failed', $error_msg);
        }
    }

    if ($action === 'sync_automation') {
        $workflows = [];
        $endpoints_to_check = [
            ['endpoint' => 'recurring_tickets', 'label' => 'Recurring Tickets'],
            ['endpoint' => 'scheduled_tasks', 'label' => 'Scheduled Tasks'],
            ['endpoint' => 'automations', 'label' => 'Automations'],
        ];

        $total_rules = 0;
        foreach ($endpoints_to_check as $ep) {
            $result = itflow_api_request($ep['endpoint']);
            if ($result['success'] && isset($result['data'])) {
                $items = $result['data']['data'] ?? $result['data'];
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $workflows[] = [
                            'type' => $ep['label'],
                            'name' => $item['name'] ?? $item['subject'] ?? $item['ticket_subject'] ?? 'Unnamed',
                            'frequency' => $item['frequency'] ?? $item['recurrence'] ?? $item['schedule'] ?? 'N/A',
                            'status' => $item['status'] ?? $item['active'] ?? 'active',
                            'last_run' => $item['last_run'] ?? $item['updated_at'] ?? 'N/A',
                        ];
                        $total_rules++;
                    }
                }
            }
        }

        $sync_results = ['type' => 'automation', 'total' => $total_rules, 'synced' => $total_rules, 'skipped' => 0, 'errors' => 0, 'workflows' => $workflows];
        log_sync_action($db, 'itflow_sync_automation', 'automation', 'completed', "Fetched $total_rules automation rules from ITFlow", $total_rules);
        $success_msg = "Automation sync complete: Found $total_rules automation rules.";
    }
}

$logs_stmt = $db->prepare("SELECT al.*, u.name AS username FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE al.details::text ILIKE '%itflow%' ORDER BY al.created_at DESC LIMIT 20");
$logs_stmt->execute();
$sync_logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

$client_count = $db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$ticket_count = $db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$device_count = $db->query("SELECT COUNT(*) FROM network_devices")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITFlow Integration - Blue Mogul Admin</title>
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
        <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
            <div class="px-6 py-4 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-ticket-alt text-primary mr-2"></i>ITFlow Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Professional Services Automation &mdash; Client & ticket management</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($itflow_connected): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">

            <?php if ($success_msg): ?>
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm flex items-start gap-2" data-testid="alert-success">
                    <i class="fas fa-check-circle mt-0.5"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm flex items-start gap-2" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($sync_results): ?>
                <div class="mb-6 bg-white rounded-lg border border-gray-200 overflow-hidden" data-testid="section-sync-results">
                    <div class="px-6 py-4 border-b border-gray-100 bg-blue-50">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-chart-bar text-primary mr-2"></i>Sync Results &mdash; <?php echo ucfirst($sync_results['type']); ?></h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                            <div class="text-center p-3 bg-gray-50 rounded-lg">
                                <p class="text-2xl font-bold text-gray-900"><?php echo $sync_results['total']; ?></p>
                                <p class="text-xs text-gray-500 mt-1">Total Found</p>
                            </div>
                            <div class="text-center p-3 bg-green-50 rounded-lg">
                                <p class="text-2xl font-bold text-green-600"><?php echo $sync_results['synced']; ?></p>
                                <p class="text-xs text-gray-500 mt-1">Synced</p>
                            </div>
                            <div class="text-center p-3 bg-yellow-50 rounded-lg">
                                <p class="text-2xl font-bold text-yellow-600"><?php echo $sync_results['skipped']; ?></p>
                                <p class="text-xs text-gray-500 mt-1">Skipped</p>
                            </div>
                            <div class="text-center p-3 bg-red-50 rounded-lg">
                                <p class="text-2xl font-bold text-red-600"><?php echo $sync_results['errors']; ?></p>
                                <p class="text-xs text-gray-500 mt-1">Errors</p>
                            </div>
                        </div>

                        <?php if ($sync_results['type'] === 'automation' && !empty($sync_results['workflows'])): ?>
                            <div class="mt-4 border border-gray-200 rounded-lg overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Frequency</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Last Run</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($sync_results['workflows'] as $wf): ?>
                                            <tr>
                                                <td class="px-4 py-2.5"><span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs font-medium"><?php echo htmlspecialchars($wf['type']); ?></span></td>
                                                <td class="px-4 py-2.5 font-medium text-gray-900"><?php echo htmlspecialchars($wf['name']); ?></td>
                                                <td class="px-4 py-2.5 text-gray-600"><?php echo htmlspecialchars($wf['frequency']); ?></td>
                                                <td class="px-4 py-2.5">
                                                    <?php $st = strtolower($wf['status']); ?>
                                                    <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo ($st === 'active' || $st === '1') ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                                                        <?php echo ($st === 'active' || $st === '1') ? 'Active' : ucfirst($wf['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2.5 text-gray-500 text-xs"><?php echo htmlspecialchars($wf['last_run']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($itflow_connected): ?>
                        <p class="text-lg font-bold text-green-600"><span class="inline-block h-2.5 w-2.5 bg-green-500 rounded-full mr-1.5"></span>Active</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><span class="inline-block h-2.5 w-2.5 bg-red-500 rounded-full mr-1.5"></span>Inactive</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-clients">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Portal Clients</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $client_count; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-tickets">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Portal Tickets</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $ticket_count; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-devices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Network Devices</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $device_count; ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-api-endpoint">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-link text-primary mr-2"></i>API Endpoint</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase mb-1">ITFlow URL</p>
                            <p class="text-sm font-medium text-gray-900 break-all" data-testid="text-itflow-url"><?php echo htmlspecialchars($itflow_url); ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Endpoint</p>
                            <p class="text-sm font-medium text-gray-900 break-all"><?php echo htmlspecialchars($itflow_api_url); ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Key</p>
                            <?php if ($itflow_connected): ?>
                                <p class="text-sm font-medium text-gray-900" data-testid="text-api-key"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs"><?php echo substr(ITFLOW_API_KEY, 0, 8); ?>...****</code></p>
                            <?php else: ?>
                                <p class="text-sm text-gray-500" data-testid="text-api-key">Not configured</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-quick-actions">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-bolt text-primary mr-2"></i>Quick Actions</h2>
                </div>
                <div class="p-6">
                    <?php if (!$itflow_connected): ?>
                        <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-yellow-700 text-sm mb-4">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Quick actions are disabled because the ITFlow API key is not configured. Set <code>ITFLOW_API_KEY</code> in your environment secrets.
                        </div>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-3">
                        <form method="POST" class="inline" data-testid="form-sync-clients">
                            <input type="hidden" name="action" value="sync_clients">
                            <button type="submit" <?php echo !$itflow_connected ? 'disabled' : ''; ?> class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed" data-testid="button-sync-clients" onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Syncing...'; this.disabled=true; this.form.submit();">
                                <i class="fas fa-users"></i> Sync Clients
                            </button>
                        </form>
                        <form method="POST" class="inline" data-testid="form-sync-tickets">
                            <input type="hidden" name="action" value="sync_tickets">
                            <button type="submit" <?php echo !$itflow_connected ? 'disabled' : ''; ?> class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed" data-testid="button-sync-tickets" onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Syncing...'; this.disabled=true; this.form.submit();">
                                <i class="fas fa-ticket-alt"></i> Sync Tickets
                            </button>
                        </form>
                        <form method="POST" class="inline" data-testid="form-sync-assets">
                            <input type="hidden" name="action" value="sync_assets">
                            <button type="submit" <?php echo !$itflow_connected ? 'disabled' : ''; ?> class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed" data-testid="button-sync-assets" onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Syncing...'; this.disabled=true; this.form.submit();">
                                <i class="fas fa-laptop"></i> Sync Assets
                            </button>
                        </form>
                        <form method="POST" class="inline" data-testid="form-sync-automation">
                            <input type="hidden" name="action" value="sync_automation">
                            <button type="submit" <?php echo !$itflow_connected ? 'disabled' : ''; ?> class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700 transition disabled:opacity-50 disabled:cursor-not-allowed" data-testid="button-sync-automation" onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Syncing...'; this.disabled=true; this.form.submit();">
                                <i class="fas fa-robot"></i> Sync Automation
                            </button>
                        </form>
                        <form method="POST" class="inline" data-testid="form-test-connection">
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" <?php echo !$itflow_connected ? 'disabled' : ''; ?> class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition disabled:opacity-50 disabled:cursor-not-allowed" data-testid="button-test-connection" onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Testing...'; this.disabled=true; this.form.submit();">
                                <i class="fas fa-plug"></i> Test Connection
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-capabilities">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-sync-alt text-primary mr-2"></i>Sync Capabilities</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div class="flex items-start gap-4" data-testid="capability-client-sync">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-users text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Client Sync</h3>
                                <p class="text-xs text-gray-500">Import and sync client records. Creates users and client profiles for new contacts, updates existing records.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-ticket-sync">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-ticket-alt text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Ticket Sync</h3>
                                <p class="text-xs text-gray-500">Pull support tickets with status and priority mapping. Duplicates detected by subject + client.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-asset-sync">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-laptop text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Asset Sync</h3>
                                <p class="text-xs text-gray-500">Import hardware/software assets into network devices. Maps to client records by company name.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-automation-sync">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-robot text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Automation Sync</h3>
                                <p class="text-xs text-gray-500">Fetch recurring tickets, scheduled tasks, and automation rules from ITFlow for review.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-contact-management">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-address-book text-yellow-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Contact Management</h3>
                                <p class="text-xs text-gray-500">Pull contact records and communication history for a unified view of client interactions.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-invoicing">
                            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-file-invoice-dollar text-red-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Invoice Data</h3>
                                <p class="text-xs text-gray-500">Access invoice data from ITFlow for billing reconciliation and financial reporting.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-sync-log">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-file-alt text-gray-500 mr-2"></i>Sync Log</h2>
                    <span class="text-xs text-gray-400"><?php echo count($sync_logs); ?> entries</span>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($sync_logs)): ?>
                        <div class="p-8 text-center text-gray-400">
                            <i class="fas fa-clipboard-list text-3xl mb-2"></i>
                            <p class="text-sm">No sync activity yet. Run a sync action to see logs here.</p>
                        </div>
                    <?php else: ?>
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Time</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">User</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Action</th>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Details</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($sync_logs as $log):
                                    $details = json_decode($log['details'] ?? '{}', true);
                                    $msg = $details['message'] ?? ($log['details'] ?? '');
                                    $action_color = 'bg-gray-100 text-gray-700';
                                    if (strpos($log['action'], 'sync') !== false) $action_color = 'bg-blue-100 text-blue-700';
                                    if (strpos($log['action'], 'test') !== false) $action_color = 'bg-yellow-100 text-yellow-700';
                                    if (strpos($msg, 'failed') !== false) $action_color = 'bg-red-100 text-red-700';
                                ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2.5 text-gray-500 text-xs whitespace-nowrap"><?php echo date('M j, g:i A', strtotime($log['created_at'])); ?></td>
                                        <td class="px-4 py-2.5 text-gray-700"><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                                        <td class="px-4 py-2.5"><span class="px-2 py-0.5 rounded text-xs font-medium <?php echo $action_color; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                        <td class="px-4 py-2.5 text-gray-600 text-xs"><?php echo htmlspecialchars(is_string($msg) ? $msg : json_encode($msg)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200" data-testid="section-configuration">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between flex-wrap gap-3 py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">ITFLOW_API_KEY</p>
                                <p class="text-xs text-gray-500">Set in Replit Secrets</p>
                            </div>
                            <?php if ($itflow_connected): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-itflow-api-key"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-itflow-api-key"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between flex-wrap gap-3 py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">ITFLOW_URL</p>
                                <p class="text-xs text-gray-500">ITFlow instance URL</p>
                            </div>
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium" data-testid="env-itflow-url"><?php echo htmlspecialchars($itflow_url); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>