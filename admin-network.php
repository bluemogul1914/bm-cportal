<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$pdo = getDB();
$success_msg = '';
$error_msg = '';

$client_id = intval($_GET['client_id'] ?? 0);
$view = $_GET['view'] ?? 'devices';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'add_device') {
        $cid = intval($_POST['client_id'] ?? 0);
        $hostname = trim($_POST['hostname'] ?? '');
        $device_type = trim($_POST['device_type'] ?? '');
        if ($cid && $hostname && $device_type) {
            $stmt = $pdo->prepare("INSERT INTO network_devices (client_id, hostname, device_type, manufacturer, model, serial_number, ip_address, mac_address, os_name, os_version, cpu, ram_gb, disk_gb, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $cid, $hostname, $device_type,
                trim($_POST['manufacturer'] ?? '') ?: null,
                trim($_POST['model'] ?? '') ?: null,
                trim($_POST['serial_number'] ?? '') ?: null,
                trim($_POST['ip_address'] ?? '') ?: null,
                trim($_POST['mac_address'] ?? '') ?: null,
                trim($_POST['os_name'] ?? '') ?: null,
                trim($_POST['os_version'] ?? '') ?: null,
                trim($_POST['cpu'] ?? '') ?: null,
                intval($_POST['ram_gb'] ?? 0) ?: null,
                intval($_POST['disk_gb'] ?? 0) ?: null,
                $_POST['status'] ?? 'online',
                trim($_POST['notes'] ?? '') ?: null,
            ]);
            $success_msg = 'Device added successfully.';
            if ($cid) $client_id = $cid;
        } else {
            $error_msg = 'Client, hostname, and device type are required.';
        }
    } elseif ($action === 'delete_device') {
        $did = intval($_POST['device_id'] ?? 0);
        if ($did) {
            $stmt = $pdo->prepare("DELETE FROM network_devices WHERE id = ?");
            $stmt->execute([$did]);
            $success_msg = 'Device removed.';
        }
    } elseif ($action === 'add_credential') {
        $cid = intval($_POST['client_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($cid && $title) {
            $stmt = $pdo->prepare("INSERT INTO network_credentials (client_id, service_name, credential_type, username, password_encrypted, url, notes) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([
                $cid, $title,
                trim($_POST['category'] ?? 'general'),
                trim($_POST['username'] ?? '') ?: null,
                trim($_POST['cred_password'] ?? '') ?: null,
                trim($_POST['url'] ?? '') ?: null,
                trim($_POST['notes'] ?? '') ?: null,
            ]);
            $success_msg = 'Credential saved.';
            if ($cid) $client_id = $cid;
            $view = 'credentials';
        }
    } elseif ($action === 'delete_credential') {
        $cid = intval($_POST['credential_id'] ?? 0);
        if ($cid) {
            $stmt = $pdo->prepare("DELETE FROM network_credentials WHERE id = ?");
            $stmt->execute([$cid]);
            $success_msg = 'Credential removed.';
            $view = 'credentials';
        }
    }
}

$clients_stmt = $pdo->query("SELECT id, name, company FROM clients ORDER BY name ASC");
$all_clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

$devices = [];
$credentials = [];
$client_name = '';

if ($client_id) {
    $stmt = $pdo->prepare("SELECT name, company FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $cl = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_name = $cl ? ($cl['company'] ?: $cl['name']) : '';

    $stmt = $pdo->prepare("SELECT * FROM network_devices WHERE client_id = ? ORDER BY device_type ASC, hostname ASC");
    $stmt->execute([$client_id]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM network_credentials WHERE client_id = ? ORDER BY credential_type ASC, service_name ASC");
    $stmt->execute([$client_id]);
    $credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$device_counts = $pdo->query("SELECT client_id, COUNT(*) as cnt FROM network_devices GROUP BY client_id")->fetchAll(PDO::FETCH_KEY_PAIR);

$total_devices = $pdo->query("SELECT COUNT(*) FROM network_devices")->fetchColumn();
$online_devices = $pdo->query("SELECT COUNT(*) FROM network_devices WHERE status = 'online'")->fetchColumn();
$offline_devices = $pdo->query("SELECT COUNT(*) FROM network_devices WHERE status = 'offline'")->fetchColumn();
$warning_devices = $pdo->query("SELECT COUNT(*) FROM network_devices WHERE status = 'warning'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Documentation - Blue Mogul Admin</title>
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
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-network-wired text-blue-500 mr-2"></i>Network Documentation</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Client device inventory, credentials vault, and network documentation</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium"><i class="fas fa-circle text-[8px] mr-1"></i><?php echo $online_devices; ?> Online</span>
                    <?php if ($warning_devices): ?><span class="px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium"><?php echo $warning_devices; ?> Warning</span><?php endif; ?>
                    <?php if ($offline_devices): ?><span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium"><?php echo $offline_devices; ?> Offline</span><?php endif; ?>
                    <span class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-full text-xs font-medium"><?php echo $total_devices; ?> Total Devices</span>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm mb-4"><i class="fas fa-check-circle mr-2"></i><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm mb-4"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-4 py-3 border-b border-gray-100">
                            <h3 class="font-semibold text-sm text-gray-900">Select Client</h3>
                        </div>
                        <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
                            <?php foreach ($all_clients as $cl): ?>
                                <a href="?client_id=<?php echo $cl['id']; ?>&view=<?php echo $view; ?>" class="block px-4 py-3 hover:bg-gray-50 transition <?php echo $client_id == $cl['id'] ? 'bg-blue-50 border-l-4 border-blue-500' : ''; ?>" data-testid="client-select-<?php echo $cl['id']; ?>">
                                    <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cl['company'] ?: $cl['name']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($cl['name']); ?></p>
                                    <span class="text-[10px] text-gray-400"><?php echo $device_counts[$cl['id']] ?? 0; ?> devices</span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (empty($all_clients)): ?>
                                <div class="p-4 text-center text-sm text-gray-500">No clients found</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-3">
                    <?php if (!$client_id): ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <i class="fas fa-network-wired text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Select a Client</h3>
                            <p class="text-sm text-gray-500">Choose a client from the left panel to view their network documentation.</p>
                        </div>
                    <?php else: ?>
                        <div class="mb-4 flex items-center justify-between">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($client_name); ?></h2>
                                <p class="text-sm text-gray-500"><?php echo count($devices); ?> devices, <?php echo count($credentials); ?> credentials</p>
                            </div>
                            <div class="flex bg-gray-100 rounded-lg p-1">
                                <a href="?client_id=<?php echo $client_id; ?>&view=devices" class="px-4 py-2 text-sm font-medium rounded-md transition <?php echo $view === 'devices' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'; ?>" data-testid="tab-devices">
                                    <i class="fas fa-desktop mr-1"></i>Devices
                                </a>
                                <a href="?client_id=<?php echo $client_id; ?>&view=credentials" class="px-4 py-2 text-sm font-medium rounded-md transition <?php echo $view === 'credentials' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'; ?>" data-testid="tab-credentials">
                                    <i class="fas fa-key mr-1"></i>Credentials
                                </a>
                            </div>
                        </div>

                        <?php if ($view === 'devices'): ?>
                        <div class="bg-white rounded-lg border border-gray-200 mb-4">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                                <h3 class="font-semibold text-gray-900"><i class="fas fa-server text-blue-500 mr-2"></i>Device Inventory</h3>
                                <button onclick="document.getElementById('add-device-form').classList.toggle('hidden')" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition" data-testid="button-add-device">
                                    <i class="fas fa-plus mr-1"></i>Add Device
                                </button>
                            </div>

                            <div id="add-device-form" class="hidden border-b border-gray-100 bg-gray-50 p-6">
                                <form method="POST">
                            <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_device">
                                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                                        <input type="text" name="hostname" placeholder="Hostname *" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <select name="device_type" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="">Device Type *</option>
                                            <option value="Server">Server</option>
                                            <option value="Workstation">Workstation</option>
                                            <option value="Laptop">Laptop</option>
                                            <option value="Firewall">Firewall</option>
                                            <option value="Router">Router</option>
                                            <option value="Switch">Switch</option>
                                            <option value="Wireless AP">Wireless AP</option>
                                            <option value="NAS">NAS</option>
                                            <option value="Printer">Printer</option>
                                            <option value="Camera">Camera</option>
                                            <option value="Phone">Phone</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <input type="text" name="manufacturer" placeholder="Manufacturer" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <input type="text" name="model" placeholder="Model" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                                        <input type="text" name="ip_address" placeholder="IP Address" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <input type="text" name="mac_address" placeholder="MAC Address" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <input type="text" name="serial_number" placeholder="Serial Number" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="online">Online</option>
                                            <option value="offline">Offline</option>
                                            <option value="warning">Warning</option>
                                        </select>
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                                        <input type="text" name="os_name" placeholder="OS Name" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <input type="text" name="os_version" placeholder="OS Version" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <input type="text" name="cpu" placeholder="CPU" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <div class="flex gap-2">
                                            <input type="number" name="ram_gb" placeholder="RAM (GB)" class="w-1/2 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <input type="number" name="disk_gb" placeholder="Disk (GB)" class="w-1/2 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <input type="text" name="notes" placeholder="Notes (optional)" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">Save Device</button>
                                    </div>
                                </form>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hostname</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Make / Model</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">IP Address</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">OS</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Specs</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Last Seen</th>
                                            <th class="px-4 py-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($devices as $dev): ?>
                                            <?php
                                                $status_class = match($dev['status']) {
                                                    'online' => 'bg-green-500',
                                                    'warning' => 'bg-yellow-500',
                                                    'offline' => 'bg-red-500',
                                                    default => 'bg-gray-400',
                                                };
                                                $type_icons = [
                                                    'Server' => 'fa-server text-blue-500',
                                                    'Workstation' => 'fa-desktop text-gray-600',
                                                    'Laptop' => 'fa-laptop text-gray-600',
                                                    'Firewall' => 'fa-shield-alt text-red-500',
                                                    'Router' => 'fa-network-wired text-purple-500',
                                                    'Switch' => 'fa-sitemap text-indigo-500',
                                                    'Wireless AP' => 'fa-wifi text-green-500',
                                                    'NAS' => 'fa-hdd text-orange-500',
                                                    'Printer' => 'fa-print text-gray-500',
                                                    'Camera' => 'fa-video text-teal-500',
                                                    'Phone' => 'fa-phone text-blue-500',
                                                ];
                                                $icon = $type_icons[$dev['device_type']] ?? 'fa-microchip text-gray-400';
                                            ?>
                                            <tr class="hover:bg-gray-50 transition" data-testid="device-row-<?php echo $dev['id']; ?>">
                                                <td class="px-4 py-3"><span class="w-2.5 h-2.5 rounded-full <?php echo $status_class; ?> inline-block"></span></td>
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center gap-2">
                                                        <i class="fas <?php echo $icon; ?>"></i>
                                                        <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($dev['hostname']); ?></span>
                                                    </div>
                                                    <?php if ($dev['serial_number']): ?><p class="text-[10px] text-gray-400 mt-0.5">S/N: <?php echo htmlspecialchars($dev['serial_number']); ?></p><?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($dev['device_type']); ?></td>
                                                <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars(($dev['manufacturer'] ?? '') . ' ' . ($dev['model'] ?? '')); ?></td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($dev['ip_address'] ?? 'N/A'); ?></code>
                                                    <?php if ($dev['mac_address']): ?><p class="text-[10px] text-gray-400 mt-0.5 font-mono"><?php echo htmlspecialchars($dev['mac_address']); ?></p><?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars(($dev['os_name'] ?? '') . ' ' . ($dev['os_version'] ?? '')); ?></td>
                                                <td class="px-4 py-3 text-xs text-gray-500">
                                                    <?php if ($dev['cpu']): ?><span class="block"><?php echo htmlspecialchars($dev['cpu']); ?></span><?php endif; ?>
                                                    <?php if ($dev['ram_gb']): ?><span class="text-gray-400"><?php echo $dev['ram_gb']; ?>GB RAM</span><?php endif; ?>
                                                    <?php if ($dev['disk_gb']): ?><span class="text-gray-400"> / <?php echo $dev['disk_gb']; ?>GB</span><?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-xs text-gray-500"><?php echo $dev['last_seen'] ? date('M d, g:i A', strtotime($dev['last_seen'])) : 'Never'; ?></td>
                                                <td class="px-4 py-3">
                                                    <form method="POST" onsubmit="return confirm('Remove this device?');">
                            <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_device">
                                                        <input type="hidden" name="device_id" value="<?php echo $dev['id']; ?>">
                                                        <button type="submit" class="text-gray-400 hover:text-red-600 transition" title="Delete"><i class="fas fa-trash-alt text-xs"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($devices)): ?>
                                            <tr><td colspan="9" class="px-6 py-8 text-center text-gray-500 text-sm"><i class="fas fa-server text-gray-300 text-2xl mb-2 block"></i>No devices documented yet</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($view === 'credentials'): ?>
                        <div class="bg-white rounded-lg border border-gray-200 mb-4">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                                <h3 class="font-semibold text-gray-900"><i class="fas fa-key text-yellow-500 mr-2"></i>Credentials Vault</h3>
                                <button onclick="document.getElementById('add-cred-form').classList.toggle('hidden')" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition" data-testid="button-add-credential">
                                    <i class="fas fa-plus mr-1"></i>Add Credential
                                </button>
                            </div>

                            <div id="add-cred-form" class="hidden border-b border-gray-100 bg-gray-50 p-6">
                                <form method="POST">
                            <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_credential">
                                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-3">
                                        <input type="text" name="title" placeholder="Title *" required class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <select name="category" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="general">General</option>
                                            <option value="Active Directory">Active Directory</option>
                                            <option value="Firewall">Firewall</option>
                                            <option value="Application">Application</option>
                                            <option value="Email">Email</option>
                                            <option value="Storage">Storage</option>
                                            <option value="VPN">VPN</option>
                                            <option value="Cloud Service">Cloud Service</option>
                                            <option value="Vendor Portal">Vendor Portal</option>
                                        </select>
                                        <input type="text" name="url" placeholder="URL (optional)" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-3">
                                        <input type="text" name="username" placeholder="Username" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <input type="password" name="cred_password" placeholder="Password" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <input type="text" name="notes" placeholder="Notes" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    </div>
                                    <div class="flex justify-end">
                                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">Save Credential</button>
                                    </div>
                                </form>
                            </div>

                            <div class="divide-y divide-gray-100">
                                <?php
                                    $cred_categories = [];
                                    foreach ($credentials as $cred) {
                                        $cred_categories[$cred['credential_type'] ?? 'general'][] = $cred;
                                    }
                                ?>
                                <?php foreach ($cred_categories as $cat => $creds): ?>
                                    <div class="px-6 py-3 bg-gray-50">
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars($cat); ?></p>
                                    </div>
                                    <?php foreach ($creds as $cred): ?>
                                        <div class="px-6 py-4 hover:bg-gray-50 transition flex items-center gap-4" data-testid="credential-row-<?php echo $cred['id']; ?>">
                                            <div class="w-10 h-10 rounded-lg bg-yellow-100 flex items-center justify-center flex-shrink-0">
                                                <i class="fas fa-lock text-yellow-600"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cred['service_name']); ?></p>
                                                <?php if ($cred['url']): ?><p class="text-xs text-blue-600 truncate"><?php echo htmlspecialchars($cred['url']); ?></p><?php endif; ?>
                                                <?php if ($cred['notes']): ?><p class="text-xs text-gray-500"><?php echo htmlspecialchars($cred['notes']); ?></p><?php endif; ?>
                                            </div>
                                            <div class="text-right">
                                                <?php if ($cred['username']): ?>
                                                    <p class="text-xs text-gray-600"><span class="text-gray-400">User:</span> <code class="bg-gray-100 px-1.5 py-0.5 rounded font-mono"><?php echo htmlspecialchars($cred['username']); ?></code></p>
                                                <?php endif; ?>
                                                <?php if ($cred['password_encrypted']): ?>
                                                    <p class="text-xs text-gray-600 mt-1">
                                                        <span class="text-gray-400">Pass:</span>
                                                        <code class="bg-gray-100 px-1.5 py-0.5 rounded font-mono cred-pass" data-pass="<?php echo htmlspecialchars($cred['password_encrypted']); ?>">••••••••</code>
                                                        <button onclick="togglePass(this)" class="ml-1 text-gray-400 hover:text-blue-600"><i class="fas fa-eye text-[10px]"></i></button>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST" onsubmit="return confirm('Delete this credential?');">
                            <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_credential">
                                                <input type="hidden" name="credential_id" value="<?php echo $cred['id']; ?>">
                                                <button type="submit" class="text-gray-400 hover:text-red-600 transition" title="Delete"><i class="fas fa-trash-alt text-xs"></i></button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php if (empty($credentials)): ?>
                                    <div class="p-8 text-center text-gray-500 text-sm"><i class="fas fa-key text-gray-300 text-2xl mb-2 block"></i>No credentials saved yet</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePass(btn) {
    const code = btn.parentElement.querySelector('.cred-pass');
    if (code.textContent === '••••••••') {
        code.textContent = code.dataset.pass;
        btn.innerHTML = '<i class="fas fa-eye-slash text-[10px]"></i>';
    } else {
        code.textContent = '••••••••';
        btn.innerHTML = '<i class="fas fa-eye text-[10px]"></i>';
    }
}
</script>
</body>
</html>