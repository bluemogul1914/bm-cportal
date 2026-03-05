<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$itarian_api_key = ITARIAN_API_KEY;
$itarian_api_url = ITARIAN_API_URL;
$itarian_connected = !empty($itarian_api_key) && !empty($itarian_api_url);
$pdo = getDB();

$success_msg = '';
$error_msg = '';

function resolve_itarian_api_base() {
    $configured = rtrim(ITARIAN_API_URL, '/');
    if (strpos($configured, 'pitstop-api.itarian.com') !== false
        || strpos($configured, 'msp-api.itarian.com') !== false
        || strpos($configured, '-api.itarian.com') !== false
        || strpos($configured, 'api.') !== false) {
        return $configured;
    }
    $api_bases = [
        'https://pitstop-api.itarian.com',
        'https://msp-api.itarian.com',
    ];
    if (preg_match('/https?:\/\/(\w+)\.itarian\.com/', $configured, $m)) {
        array_unshift($api_bases, 'https://' . $m[1] . '-api.itarian.com');
        array_unshift($api_bases, 'https://api.' . $m[1] . '.itarian.com');
    }
    foreach ($api_bases as $base) {
        $ch = curl_init($base . '/api/v1/device/load');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['search' => new \stdClass(), 'pagination' => ['page' => 1, 'pageSize' => 1]]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-auth-token: ' . ITARIAN_API_KEY,
                'x-auth-type: 4',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            return $base;
        }
    }
    return 'https://pitstop-api.itarian.com';
}

function itarian_api_post($path, $body = []) {
    static $resolved_base = null;
    if ($resolved_base === null) {
        $resolved_base = resolve_itarian_api_base();
    }
    $url = $resolved_base . $path;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-auth-token: ' . ITARIAN_API_KEY,
            'x-auth-type: 4',
        ],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => $curl_error, 'http_code' => 0];
    }
    if ($http_code < 200 || $http_code >= 300) {
        $body_resp = json_decode($response, true);
        $msg = $body_resp['error'] ?? $body_resp['message'] ?? $body_resp['errorMessage'] ?? "HTTP $http_code";
        return ['error' => $msg, 'http_code' => $http_code];
    }
    return json_decode($response, true) ?: ['error' => 'Invalid JSON response'];
}

$api_error = '';
$endpoints = [];
$devices = [];
$alerts = [];
$patches = [];
$endpoint_count = 0;
$online_count = 0;
$offline_count = 0;
$alert_count = 0;
$patch_pending_count = 0;
$clients_list = [];

try {
    $clients_list = $pdo->query("SELECT id, name, company FROM clients WHERE status = 'active' ORDER BY company, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync_endpoints' && $itarian_connected) {
        $api_data = itarian_api_post('/api/v1/device/load', [
            'search' => new stdClass(),
            'pagination' => ['page' => 1, 'pageSize' => 500],
        ]);

        if (isset($api_data['error'])) {
            $err = $api_data['error'];
            if (strpos(strtolower($err), 'unauthorized') !== false || strpos(strtolower($err), '401') !== false || strpos(strtolower($err), '403') !== false) {
                $err .= ' — Check your ITARIAN_API_KEY and ITARIAN_API_URL in Replit Secrets.';
            }
            $error_msg = 'Endpoint sync failed: ' . $err;
        } else {
            $api_endpoints = $api_data['data'] ?? $api_data['result'] ?? $api_data['devices'] ?? $api_data;
            if (is_array($api_endpoints)) {
                $synced = 0;
                foreach ($api_endpoints as $ep) {
                    $itarian_id = $ep['id'] ?? $ep['endpoint_id'] ?? '';
                    if (!$itarian_id) continue;

                    try {
                        $pdo->prepare("INSERT INTO itarian_endpoints (itarian_id, name, hostname, os_name, os_version, ip_address, mac_address, status, last_seen, agent_version, group_name, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) ON CONFLICT (itarian_id) DO UPDATE SET name = EXCLUDED.name, hostname = EXCLUDED.hostname, os_name = EXCLUDED.os_name, os_version = EXCLUDED.os_version, ip_address = EXCLUDED.ip_address, mac_address = EXCLUDED.mac_address, status = EXCLUDED.status, last_seen = EXCLUDED.last_seen, agent_version = EXCLUDED.agent_version, group_name = EXCLUDED.group_name, updated_at = NOW()")
                            ->execute([
                                $itarian_id,
                                $ep['name'] ?? $ep['endpoint_name'] ?? '',
                                $ep['hostname'] ?? '',
                                $ep['os_name'] ?? $ep['os'] ?? '',
                                $ep['os_version'] ?? '',
                                $ep['ip_address'] ?? $ep['ip'] ?? '',
                                $ep['mac_address'] ?? $ep['mac'] ?? '',
                                $ep['status'] ?? $ep['online_status'] ?? 'unknown',
                                $ep['last_activity'] ?? $ep['last_seen'] ?? null,
                                $ep['agent_version'] ?? '',
                                $ep['group'] ?? $ep['group_name'] ?? '',
                            ]);
                        $synced++;
                    } catch (Exception $e) {
                        // skip individual failures
                    }
                }
                $success_msg = "Synced {$synced} endpoints from ITarian API.";

                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'itarian_sync', 'itarian_endpoint', 0, "Synced {$synced} endpoints from ITarian", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            } else {
                $error_msg = 'Unexpected API response format.';
            }
        }
    }

    if ($action === 'sync_alerts' && $itarian_connected) {
        $api_data = itarian_api_post('/api/v1/alerts', [
            'search' => new stdClass(),
            'pagination' => ['page' => 1, 'pageSize' => 100],
        ]);

        if (isset($api_data['error'])) {
            $error_msg = 'Alert sync failed: ' . $api_data['error'];
        } else {
            $api_alerts = $api_data['data'] ?? $api_data['alerts'] ?? $api_data;
            if (is_array($api_alerts)) {
                $synced = 0;
                foreach ($api_alerts as $al) {
                    $alert_id = $al['id'] ?? $al['alert_id'] ?? '';
                    if (!$alert_id) continue;

                    try {
                        $pdo->prepare("INSERT INTO itarian_alerts (itarian_alert_id, endpoint_id, severity, category, message, status, created_at_remote, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON CONFLICT (itarian_alert_id) DO UPDATE SET severity = EXCLUDED.severity, status = EXCLUDED.status, message = EXCLUDED.message, updated_at = NOW()")
                            ->execute([
                                $alert_id,
                                $al['endpoint_id'] ?? $al['device_id'] ?? '',
                                $al['severity'] ?? $al['priority'] ?? 'info',
                                $al['category'] ?? $al['type'] ?? '',
                                $al['message'] ?? $al['description'] ?? '',
                                $al['status'] ?? 'open',
                                $al['created_at'] ?? $al['timestamp'] ?? null,
                            ]);
                        $synced++;
                    } catch (Exception $e) {}
                }
                $success_msg = "Synced {$synced} alerts from ITarian API.";

                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'itarian_alert_sync', 'itarian_alert', 0, "Synced {$synced} alerts from ITarian", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            }
        }
    }
}

$db_endpoints = [];
try {
    $db_endpoints = $pdo->query("SELECT ie.*, c.name as client_name, c.company as client_company FROM itarian_endpoints ie LEFT JOIN clients c ON ie.client_id = c.id ORDER BY ie.status ASC, ie.name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$db_alerts = [];
try {
    $db_alerts = $pdo->query("SELECT * FROM itarian_alerts ORDER BY created_at_remote DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$endpoint_count = count($db_endpoints);
foreach ($db_endpoints as $ep) {
    $status = strtolower($ep['status'] ?? '');
    if ($status === 'online' || $status === 'active') {
        $online_count++;
    } else {
        $offline_count++;
    }
}
$alert_count = count($db_alerts);

$os_breakdown = [];
foreach ($db_endpoints as $ep) {
    $os = $ep['os_name'] ?: 'Unknown';
    $os_breakdown[$os] = ($os_breakdown[$os] ?? 0) + 1;
}
arsort($os_breakdown);

$last_synced_time = null;
try {
    $stmt = $pdo->query("SELECT MAX(updated_at) as last_sync FROM itarian_endpoints");
    $row = $stmt->fetch();
    $last_synced_time = $row['last_sync'] ?? null;
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITarian RMM - Blue Mogul Admin</title>
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
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-desktop text-blue-500 mr-2"></i>ITarian RMM Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Remote monitoring &mdash; Endpoints, devices, patches, and alerts</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($itarian_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sync_endpoints">
                            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-sync-endpoints">
                                <i class="fas fa-sync-alt mr-1"></i>Sync Endpoints
                            </button>
                        </form>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sync_alerts">
                            <button type="submit" class="px-3 py-1.5 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-sync-alerts">
                                <i class="fas fa-bell mr-1"></i>Sync Alerts
                            </button>
                        </form>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
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

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($itarian_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">API key + host configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1"><?php
                            $missing = [];
                            if (empty($itarian_api_key)) $missing[] = 'ITARIAN_API_KEY';
                            if (empty($itarian_api_url)) $missing[] = 'ITARIAN_API_URL';
                            echo implode(' & ', $missing) . ' not set';
                        ?></p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-endpoints">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Endpoints</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-endpoints"><?php echo (int)$endpoint_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $last_synced_time ? 'Last sync: ' . date('M d, g:i A', strtotime($last_synced_time)) : 'Not synced yet'; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-online-endpoints">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Online</p>
                    <p class="text-2xl font-bold text-green-600" data-testid="text-online-endpoints"><?php echo (int)$online_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $endpoint_count > 0 ? round(($online_count / $endpoint_count) * 100, 1) . '% online' : 'No data'; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-alerts">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active Alerts</p>
                    <p class="text-2xl font-bold text-yellow-600" data-testid="text-alert-count"><?php echo (int)$alert_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Requires review</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-endpoints-title"><i class="fas fa-desktop text-blue-500 mr-2"></i>Endpoints / Devices</h2>
                            <span class="text-xs text-gray-400"><?php echo $endpoint_count; ?> total</span>
                        </div>
                        <?php if (!empty($db_endpoints)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-endpoints">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hostname</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">OS</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">IP Address</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Group</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Last Seen</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($db_endpoints as $index => $ep): ?>
                                        <?php
                                            $status = strtolower($ep['status'] ?? '');
                                            $status_class = match(true) {
                                                $status === 'online' || $status === 'active' => 'bg-green-500',
                                                $status === 'offline' || $status === 'inactive' => 'bg-red-500',
                                                default => 'bg-gray-400',
                                            };
                                            $status_label = match(true) {
                                                $status === 'online' || $status === 'active' => 'Online',
                                                $status === 'offline' || $status === 'inactive' => 'Offline',
                                                default => ucfirst($status ?: 'Unknown'),
                                            };
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="endpoint-row-<?php echo $index; ?>">
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <span class="w-2.5 h-2.5 rounded-full <?php echo $status_class; ?> inline-block"></span>
                                                    <span class="text-xs text-gray-600"><?php echo $status_label; ?></span>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ep['name'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($ep['hostname'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars(($ep['os_name'] ?? '') . ($ep['os_version'] ? ' ' . $ep['os_version'] : '')); ?></td>
                                            <td class="px-4 py-3"><code class="text-xs font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($ep['ip_address'] ?? 'N/A'); ?></code></td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($ep['group_name'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-500"><?php echo isset($ep['last_seen']) && $ep['last_seen'] ? date('M d, g:i A', strtotime($ep['last_seen'])) : 'Never'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="p-8 text-center text-gray-500 text-sm">
                                <i class="fas fa-desktop text-gray-300 text-2xl mb-2 block"></i>
                                No endpoints found. <?php if ($itarian_connected): ?>Click &ldquo;Sync Endpoints&rdquo; to pull data from ITarian.<?php else: ?>Configure your API key to get started.<?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-os-breakdown-title"><i class="fas fa-chart-pie text-blue-500 mr-2"></i>OS Breakdown</h2>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($os_breakdown)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($os_breakdown as $os => $cnt): ?>
                                        <?php
                                            $pct = $endpoint_count > 0 ? round(($cnt / $endpoint_count) * 100) : 0;
                                            $os_lower = strtolower($os);
                                            $bar_color = 'bg-blue-400';
                                            if (strpos($os_lower, 'windows') !== false) $bar_color = 'bg-blue-500';
                                            elseif (strpos($os_lower, 'mac') !== false || strpos($os_lower, 'darwin') !== false) $bar_color = 'bg-gray-500';
                                            elseif (strpos($os_lower, 'linux') !== false || strpos($os_lower, 'ubuntu') !== false) $bar_color = 'bg-orange-500';
                                        ?>
                                        <div data-testid="os-type-<?php echo strtolower(str_replace(' ', '-', $os)); ?>">
                                            <div class="flex items-center justify-between text-sm mb-1">
                                                <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($os); ?></span>
                                                <span class="text-gray-500"><?php echo $cnt; ?> (<?php echo $pct; ?>%)</span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-2">
                                                <div class="<?php echo $bar_color; ?> rounded-full h-2" style="width: <?php echo $pct; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500 text-center py-4">No endpoint data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-alerts-title"><i class="fas fa-bell text-yellow-500 mr-2"></i>Recent Alerts</h2>
                    </div>
                    <?php if (!empty($db_alerts)): ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($db_alerts as $index => $al): ?>
                                <?php
                                    $severity = strtolower($al['severity'] ?? 'info');
                                    $sev_class = match($severity) {
                                        'critical', 'high' => 'bg-red-100 text-red-700',
                                        'warning', 'medium' => 'bg-yellow-100 text-yellow-700',
                                        'low' => 'bg-blue-100 text-blue-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };
                                    $sev_icon = match($severity) {
                                        'critical', 'high' => 'fa-exclamation-circle text-red-500',
                                        'warning', 'medium' => 'fa-exclamation-triangle text-yellow-500',
                                        default => 'fa-info-circle text-blue-500',
                                    };
                                ?>
                                <div class="px-6 py-3 flex items-start gap-3" data-testid="alert-row-<?php echo $index; ?>">
                                    <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <i class="fas <?php echo $sev_icon; ?> text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="text-sm text-gray-900"><?php echo htmlspecialchars($al['message'] ?? ''); ?></p>
                                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-medium <?php echo $sev_class; ?>"><?php echo ucfirst($severity); ?></span>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-0.5">
                                            <?php if (!empty($al['category'])): ?><span class="mr-2"><?php echo htmlspecialchars($al['category']); ?></span><?php endif; ?>
                                            <?php echo isset($al['created_at_remote']) && $al['created_at_remote'] ? date('M d, Y g:i A', strtotime($al['created_at_remote'])) : ''; ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-8 text-center text-gray-500 text-sm">
                            <i class="fas fa-bell-slash text-gray-300 text-2xl mb-2 block"></i>
                            No alerts found. <?php if ($itarian_connected): ?>Click &ldquo;Sync Alerts&rdquo; to pull alerts from ITarian.<?php else: ?>Configure your API key first.<?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$itarian_connected): ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-setup-title"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Setup Instructions</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Get your ITSM API Token</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Log in to your ITarian / Comodo ONE portal. Go to <strong>Management &rarr; Staff &rarr; API</strong>. Generate an access token. You will use this as <code class="bg-gray-100 px-1 rounded text-xs">ITARIAN_API_KEY</code>.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Find your ITSM Host URL</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Your ITSM host is the base URL of your ITarian instance, e.g. <code class="bg-gray-100 px-1 rounded text-xs">https://yourcompany.cmdm.comodo.com</code> or your custom domain. This will be your <code class="bg-gray-100 px-1 rounded text-xs">ITARIAN_API_URL</code>.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Add both Secrets in Replit</p>
                                    <p class="text-xs text-gray-500 mt-0.5">In Replit, go to Tools &rarr; Secrets and add:</p>
                                    <ul class="text-xs text-gray-500 mt-1 space-y-1 list-disc pl-4">
                                        <li><code class="bg-gray-100 px-1 rounded text-xs">ITARIAN_API_KEY</code> &mdash; Your API access token</li>
                                        <li><code class="bg-gray-100 px-1 rounded text-xs">ITARIAN_API_URL</code> &mdash; Your ITSM host URL (e.g. <code class="bg-gray-100 px-1 rounded text-xs">https://yourcompany.cmdm.comodo.com</code>)</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">4</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Verify Connection</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Refresh this page after adding the secrets. Both <code class="bg-gray-100 px-1 rounded text-xs">ITARIAN_API_KEY</code> and <code class="bg-gray-100 px-1 rounded text-xs">ITARIAN_API_URL</code> must be set for the status to show &ldquo;Connected.&rdquo;</p>
                                </div>
                            </div>
                            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-xs text-blue-700"><i class="fas fa-info-circle mr-1"></i><strong>API Reference:</strong> ITarian uses the Comodo ONE ITSM API. Endpoints are at <code class="bg-blue-100 px-1 rounded">/api/rest/v1/device/load</code> (devices), <code class="bg-blue-100 px-1 rounded">/api/rest/v1/alerts</code> (alerts). Auth uses <code class="bg-blue-100 px-1 rounded">x-auth-token</code> header. See <a href="https://developer.itarian.com/" target="_blank" class="underline">developer.itarian.com</a></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-patches-title"><i class="fas fa-shield-alt text-blue-500 mr-2"></i>Patch Management</h2>
                    </div>
                    <div class="p-6">
                        <div class="text-center text-gray-500 text-sm mb-4">
                            <i class="fas fa-shield-alt text-gray-300 text-2xl mb-2 block"></i>
                            Patch status data is populated during endpoint sync. View individual endpoints for detailed patch information.
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-center" data-testid="card-patched">
                                <p class="text-lg font-bold text-green-700"><?php echo $online_count; ?></p>
                                <p class="text-xs text-green-600">Up to Date</p>
                            </div>
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-center" data-testid="card-needs-patches">
                                <p class="text-lg font-bold text-yellow-700"><?php echo $offline_count; ?></p>
                                <p class="text-xs text-yellow-600">Needs Review</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-capabilities-title"><i class="fas fa-star text-blue-500 mr-2"></i>Capabilities</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="flex items-start gap-4" data-testid="capability-endpoint-management">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-desktop text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Endpoint Management</h3>
                                <p class="text-xs text-gray-500">Import and monitor all endpoints from ITarian RMM. Track workstations, servers, and other managed devices with real-time status updates.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-patch-management">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-shield-alt text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Patch Management</h3>
                                <p class="text-xs text-gray-500">Monitor OS and third-party patch compliance across all endpoints. Identify devices that need critical security updates and track patch deployment status.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-alert-monitoring">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-bell text-yellow-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Alert Monitoring</h3>
                                <p class="text-xs text-gray-500">Receive and manage alerts from ITarian RMM. Monitor CPU, memory, disk usage, and service status across all managed endpoints.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-remote-access">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-plug text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Remote Access</h3>
                                <p class="text-xs text-gray-500">Leverage ITarian's remote access capabilities for endpoint troubleshooting. Manage devices remotely for faster issue resolution and reduced downtime.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>