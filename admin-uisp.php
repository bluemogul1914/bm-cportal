<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$uisp_connected = !empty(getenv('UISP_API_KEY'));
$uisp_url = UISP_URL;

$device_count = 0;
$online_count = 0;
$offline_count = 0;
$warning_count = 0;
$recent_devices = [];
$device_types = [];
$recent_activity = [];

try {
    $db = getDB();

    $stmt = $db->query("SELECT COUNT(*) as count FROM network_devices");
    $result = $stmt->fetch();
    $device_count = $result['count'] ?? 0;

    $stmt = $db->query("SELECT COUNT(*) as count FROM network_devices WHERE status = 'online'");
    $result = $stmt->fetch();
    $online_count = $result['count'] ?? 0;

    $stmt = $db->query("SELECT COUNT(*) as count FROM network_devices WHERE status = 'offline'");
    $result = $stmt->fetch();
    $offline_count = $result['count'] ?? 0;

    $stmt = $db->query("SELECT COUNT(*) as count FROM network_devices WHERE status = 'warning'");
    $result = $stmt->fetch();
    $warning_count = $result['count'] ?? 0;

    $stmt = $db->query("SELECT device_type, COUNT(*) as cnt FROM network_devices GROUP BY device_type ORDER BY cnt DESC LIMIT 10");
    $device_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT nd.*, c.name as client_name, c.company as client_company FROM network_devices nd LEFT JOIN clients c ON nd.client_id = c.id ORDER BY nd.created_at DESC LIMIT 10");
    $recent_devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT * FROM activity_log WHERE entity_type = 'device' OR details ILIKE '%device%' OR details ILIKE '%uisp%' ORDER BY created_at DESC LIMIT 10");
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // tables may not exist yet
}

$uptime_pct = $device_count > 0 ? round(($online_count / $device_count) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UISP Integration - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-wifi text-blue-500 mr-2"></i>UISP Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Network management &mdash; Device monitoring and service plans</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($uisp_connected): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($uisp_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">API key configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">API key not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-devices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Devices</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-devices"><?php echo (int)$device_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Across all clients</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-online-devices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Online</p>
                    <p class="text-2xl font-bold text-green-600" data-testid="text-online-devices"><?php echo (int)$online_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $uptime_pct; ?>% uptime</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-offline-devices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Offline</p>
                    <p class="text-2xl font-bold text-red-600" data-testid="text-offline-devices"><?php echo (int)$offline_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Requires attention</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-warning-devices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Warning</p>
                    <p class="text-2xl font-bold text-yellow-600" data-testid="text-warning-devices"><?php echo (int)$warning_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Monitoring alerts</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-recent-devices-title"><i class="fas fa-server text-blue-500 mr-2"></i>Recent Devices</h2>
                            <a href="admin-network.php" class="text-sm text-primary hover:underline" data-testid="link-view-all-devices">View All <i class="fas fa-arrow-right text-xs ml-1"></i></a>
                        </div>
                        <?php if (!empty($recent_devices)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-recent-devices">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hostname</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">IP Address</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Last Seen</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($recent_devices as $index => $dev): ?>
                                        <?php
                                            $status_class = match($dev['status'] ?? '') {
                                                'online' => 'bg-green-500',
                                                'warning' => 'bg-yellow-500',
                                                'offline' => 'bg-red-500',
                                                default => 'bg-gray-400',
                                            };
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="device-row-<?php echo $index; ?>">
                                            <td class="px-4 py-3"><span class="w-2.5 h-2.5 rounded-full <?php echo $status_class; ?> inline-block"></span></td>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($dev['hostname'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($dev['device_type'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($dev['client_company'] ?: ($dev['client_name'] ?? 'N/A')); ?></td>
                                            <td class="px-4 py-3"><code class="text-xs font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($dev['ip_address'] ?? 'N/A'); ?></code></td>
                                            <td class="px-4 py-3 text-xs text-gray-500"><?php echo isset($dev['last_seen']) && $dev['last_seen'] ? date('M d, g:i A', strtotime($dev['last_seen'])) : 'Never'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="p-8 text-center text-gray-500 text-sm">
                                <i class="fas fa-server text-gray-300 text-2xl mb-2 block"></i>
                                No devices found. Add devices via the <a href="admin-network.php" class="text-primary hover:underline">Network Documentation</a> page.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-device-breakdown-title"><i class="fas fa-chart-pie text-blue-500 mr-2"></i>Device Breakdown</h2>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($device_types)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($device_types as $dt): ?>
                                        <?php
                                            $pct = $device_count > 0 ? round(($dt['cnt'] / $device_count) * 100) : 0;
                                            $type_colors = [
                                                'Server' => 'bg-blue-500',
                                                'Router' => 'bg-purple-500',
                                                'Switch' => 'bg-indigo-500',
                                                'Firewall' => 'bg-red-500',
                                                'Wireless AP' => 'bg-green-500',
                                                'Workstation' => 'bg-gray-500',
                                                'Laptop' => 'bg-gray-400',
                                                'NAS' => 'bg-orange-500',
                                                'Printer' => 'bg-yellow-500',
                                            ];
                                            $bar_color = $type_colors[$dt['device_type']] ?? 'bg-blue-400';
                                        ?>
                                        <div data-testid="device-type-<?php echo strtolower(str_replace(' ', '-', $dt['device_type'])); ?>">
                                            <div class="flex items-center justify-between text-sm mb-1">
                                                <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($dt['device_type']); ?></span>
                                                <span class="text-gray-500"><?php echo $dt['cnt']; ?> (<?php echo $pct; ?>%)</span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-2">
                                                <div class="<?php echo $bar_color; ?> rounded-full h-2" style="width: <?php echo $pct; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500 text-center py-4">No device data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-capabilities-title"><i class="fas fa-star text-blue-500 mr-2"></i>Capabilities</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="flex items-start gap-4" data-testid="capability-device-monitoring">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-satellite-dish text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Device Monitoring</h3>
                                <p class="text-xs text-gray-500">Import and monitor network devices from UISP. Track routers, switches, access points, and other infrastructure equipment with real-time status updates.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-service-plans">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-chart-line text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Service Plans</h3>
                                <p class="text-xs text-gray-500">Synchronize service plans and subscriptions. Map UISP service tiers to Blue Mogul products for unified billing and service management.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-network-maps">
                            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-map text-indigo-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Network Maps</h3>
                                <p class="text-xs text-gray-500">Visualize your network topology and infrastructure layout. View device connections, coverage areas, and network paths on interactive maps.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-outage-alerts">
                            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Outage Alerts</h3>
                                <p class="text-xs text-gray-500">Receive immediate notifications when devices go offline or experience issues. Automated alerts help minimize downtime and improve response times.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-sync-activity-title"><i class="fas fa-history text-blue-500 mr-2"></i>Recent Sync Activity</h2>
                    </div>
                    <?php if (!empty($recent_activity)): ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($recent_activity as $index => $act): ?>
                                <div class="px-6 py-3 flex items-start gap-3" data-testid="activity-row-<?php echo $index; ?>">
                                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <i class="fas fa-sync-alt text-blue-600 text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($act['details'] ?? $act['action'] ?? ''); ?></p>
                                        <p class="text-xs text-gray-400 mt-0.5"><?php echo isset($act['created_at']) ? date('M d, Y g:i A', strtotime($act['created_at'])) : ''; ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-8 text-center text-gray-500 text-sm">
                            <i class="fas fa-clock text-gray-300 text-2xl mb-2 block"></i>
                            No recent sync activity recorded
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$uisp_connected): ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-setup-title"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Setup Instructions</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Get your UISP API Key</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Log in to your UISP instance and navigate to Settings &rarr; Users &rarr; API Tokens. Create a new token with read access.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Add to Replit Secrets</p>
                                    <p class="text-xs text-gray-500 mt-0.5">In Replit, go to Tools &rarr; Secrets and add <code class="bg-gray-100 px-1 rounded text-xs">UISP_API_KEY</code> with your token value.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Verify Connection</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Refresh this page after adding the secret. The status should change to &ldquo;Connected&rdquo; and devices will begin syncing.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">4</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Configure Endpoint URL</p>
                                    <p class="text-xs text-gray-500 mt-0.5">The UISP endpoint is currently set to <code class="bg-gray-100 px-1 rounded text-xs"><?php echo htmlspecialchars($uisp_url); ?></code>. Update the <code class="bg-gray-100 px-1 rounded text-xs">UISP_URL</code> constant in config.php if needed.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-features-title"><i class="fas fa-bolt text-blue-500 mr-2"></i>Additional Features</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start gap-4" data-testid="feature-signal-monitoring">
                                <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-tachometer-alt text-yellow-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Signal Monitoring</h3>
                                    <p class="text-xs text-gray-500">Pull bandwidth usage, latency, and signal quality metrics from UISP to provide clients with network performance visibility.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-4" data-testid="feature-client-site-management">
                                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-map-marker-alt text-green-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Client Site Management</h3>
                                    <p class="text-xs text-gray-500">Manage client sites and locations from UISP. Track installations, site surveys, and deployment status across your service area.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-4" data-testid="feature-automated-provisioning">
                                <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-cogs text-indigo-600"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Automated Provisioning</h3>
                                    <p class="text-xs text-gray-500">Automatically provision new client connections and service changes through UISP API integration.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-config-title"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">UISP_API_KEY</p>
                                <p class="text-xs text-gray-500">Authentication token for UISP API &mdash; Set in Replit Secrets</p>
                            </div>
                            <?php if ($uisp_connected): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-uisp-api-key"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-uisp-api-key"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">UISP_URL</p>
                                <p class="text-xs text-gray-500">UISP instance endpoint URL &mdash; Defined in config.php</p>
                            </div>
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium" data-testid="env-uisp-url"><?php echo htmlspecialchars($uisp_url); ?></span>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">Sync Interval</p>
                                <p class="text-xs text-gray-500">How often device data is refreshed from UISP</p>
                            </div>
                            <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-medium" data-testid="text-sync-interval">Manual / On-Demand</span>
                        </div>
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">API Version</p>
                                <p class="text-xs text-gray-500">UISP REST API version in use</p>
                            </div>
                            <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-medium" data-testid="text-api-version">v2.1</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>