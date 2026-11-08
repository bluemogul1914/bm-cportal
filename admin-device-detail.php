<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();
$user_name = $_SESSION['user_name'] ?? 'Admin';

$device_id = (int)($_GET['id'] ?? 0);
$source = $_GET['source'] ?? 'network';

$device = null;
$action1 = null;

if ($source === 'network' && $device_id > 0) {
    $stmt = $pdo->prepare("SELECT nd.*, c.name as client_name, c.company as client_company FROM network_devices nd LEFT JOIN clients c ON nd.client_id = c.id WHERE nd.id = ?");
    $stmt->execute([$device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($source === 'action1' && $device_id > 0) {
    $stmt = $pdo->prepare("SELECT ae.*, c.name as client_name, c.company as client_company FROM action1_endpoints ae LEFT JOIN clients c ON ae.client_id = c.id WHERE ae.id = ?");
    $stmt->execute([$device_id]);
    $action1 = $stmt->fetch(PDO::FETCH_ASSOC);
}

include 'includes/admin-header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <div class="p-6">
            <div class="mb-6">
                <a href="javascript:history.back()" class="text-sm text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i>Back</a>
                <h1 class="text-2xl font-bold text-gray-900 mt-2">
                    <i class="fas fa-desktop text-blue-500 mr-2"></i>Device Detail
                </h1>
            </div>

            <?php if ($device): ?>
            <div class="bg-white rounded-lg border border-gray-200 p-6 shadow-sm mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4"><?php echo htmlspecialchars($device['hostname'] ?? 'Device'); ?></h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><span class="font-medium text-gray-700">Client:</span> <?php echo htmlspecialchars($device['client_company'] ?: $device['client_name'] ?: 'Unassigned'); ?></div>
                    <div><span class="font-medium text-gray-700">Type:</span> <?php echo ucfirst(str_replace('_', ' ', $device['device_type'] ?? '—')); ?></div>
                    <div><span class="font-medium text-gray-700">Manufacturer:</span> <?php echo htmlspecialchars($device['manufacturer'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">Model:</span> <?php echo htmlspecialchars($device['model'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">Serial:</span> <?php echo htmlspecialchars($device['serial_number'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">IP Address:</span> <?php echo htmlspecialchars($device['ip_address'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">MAC Address:</span> <?php echo htmlspecialchars($device['mac_address'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">OS:</span> <?php echo htmlspecialchars(($device['os_name'] ?? '') . ' ' . ($device['os_version'] ?? '')); ?></div>
                    <div><span class="font-medium text-gray-700">CPU:</span> <?php echo htmlspecialchars($device['cpu'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">RAM:</span> <?php echo $device['ram_gb'] ? $device['ram_gb'] . ' GB' : '—'; ?></div>
                    <div><span class="font-medium text-gray-700">Disk:</span> <?php echo $device['disk_gb'] ? $device['disk_gb'] . ' GB' : '—'; ?></div>
                    <div><span class="font-medium text-gray-700">Status:</span> <?php echo ucfirst($device['status'] ?? 'online'); ?></div>
                    <div><span class="font-medium text-gray-700">Last Seen:</span> <?php echo $device['last_seen'] ?? '—'; ?></div>
                    <div><span class="font-medium text-gray-700">Source:</span> <?php echo htmlspecialchars($device['source'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">Notes:</span> <?php echo htmlspecialchars($device['notes'] ?? '—'); ?></div>
                </div>
            </div>
            <?php elseif ($action1): ?>
            <div class="bg-white rounded-lg border border-gray-200 p-6 shadow-sm mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4"><?php echo htmlspecialchars($action1['hostname'] ?: $action1['name'] ?: 'Device'); ?></h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><span class="font-medium text-gray-700">Client:</span> <?php echo htmlspecialchars($action1['client_company'] ?: $action1['client_name'] ?: 'Unassigned'); ?></div>
                    <div><span class="font-medium text-gray-700">Status:</span> <?php echo ucfirst($action1['status'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">OS:</span> <?php echo htmlspecialchars(($action1['os_name'] ?? '') . ' ' . ($action1['os_version'] ?? '')); ?></div>
                    <div><span class="font-medium text-gray-700">IP Address:</span> <?php echo htmlspecialchars($action1['ip_address'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">MAC Address:</span> <?php echo htmlspecialchars($action1['mac_address'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">Missing Updates:</span> <?php echo (int)($action1['missing_updates'] ?? 0); ?> (<?php echo (int)($action1['missing_updates_critical'] ?? 0); ?> critical)</div>
                    <div><span class="font-medium text-gray-700">Vulnerabilities:</span> <?php echo (int)($action1['vulnerabilities_critical'] ?? 0); ?> critical, <?php echo (int)($action1['vulnerabilities_noncritical'] ?? 0); ?> non-critical</div>
                    <div><span class="font-medium text-gray-700">Agent Version:</span> <?php echo htmlspecialchars($action1['agent_version'] ?? '—'); ?></div>
                    <div><span class="font-medium text-gray-700">Last Seen:</span> <?php echo $action1['last_seen'] ?? '—'; ?></div>
                    <div><span class="font-medium text-gray-700">Group:</span> <?php echo htmlspecialchars($action1['group_name'] ?? '—'); ?></div>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg border border-gray-200 p-6 shadow-sm">
                <p class="text-gray-600">Device not found.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'includes/admin-footer.php'; ?>
