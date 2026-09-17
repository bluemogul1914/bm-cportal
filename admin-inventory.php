<?php
/**
 * admin-inventory.php — Inventory with deployed-status (P1 #4).
 * Extends `assets` with status (received → assigned → deployed), network site,
 * supplier + PO reference; per-site equipment list.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$success_message = '';
$error_message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $pdo = getDB();
    try {
        if ($action === 'add_asset') {
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) { $error_message = 'Asset name is required.'; }
            else {
                $pdo->prepare("INSERT INTO assets (client_id, name, type, serial_number, os, ip_address, managed, rmm_agent_id, plan_tier, status, network_site_id, supplier, po_reference, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([
                        (int)($_POST['client_id'] ?? 0) ?: null,
                        $name,
                        trim($_POST['type'] ?? '') ?: null,
                        trim($_POST['serial_number'] ?? '') ?: null,
                        trim($_POST['os'] ?? '') ?: null,
                        trim($_POST['ip_address'] ?? '') ?: null,
                        isset($_POST['managed']) ? 1 : 0,
                        trim($_POST['rmm_agent_id'] ?? '') ?: null,
                        trim($_POST['plan_tier'] ?? '') ?: null,
                        trim($_POST['status'] ?? 'received') ?: 'received',
                        (int)($_POST['network_site_id'] ?? 0) ?: null,
                        trim($_POST['supplier'] ?? '') ?: null,
                        trim($_POST['po_reference'] ?? '') ?: null,
                        trim($_POST['notes'] ?? '') ?: null,
                    ]);
                $success_message = 'Asset added.';
            }
        } elseif ($action === 'update_asset') {
            $id = (int)($_POST['asset_id'] ?? 0);
            $pdo->prepare("UPDATE assets SET name=?, type=?, serial_number=?, os=?, ip_address=?, managed=?, plan_tier=?, status=?, network_site_id=?, supplier=?, po_reference=?, notes=? WHERE id=?")
                ->execute([
                    trim($_POST['name'] ?? ''),
                    trim($_POST['type'] ?? '') ?: null,
                    trim($_POST['serial_number'] ?? '') ?: null,
                    trim($_POST['os'] ?? '') ?: null,
                    trim($_POST['ip_address'] ?? '') ?: null,
                    isset($_POST['managed']) ? 1 : 0,
                    trim($_POST['plan_tier'] ?? '') ?: null,
                    trim($_POST['status'] ?? 'received') ?: 'received',
                    (int)($_POST['network_site_id'] ?? 0) ?: null,
                    trim($_POST['supplier'] ?? '') ?: null,
                    trim($_POST['po_reference'] ?? '') ?: null,
                    trim($_POST['notes'] ?? '') ?: null,
                    $id,
                ]);
            $success_message = 'Asset updated.';
        } elseif ($action === 'update_status') {
            $id = (int)($_POST['asset_id'] ?? 0);
            $status = trim($_POST['status'] ?? 'received');
            $pdo->prepare("UPDATE assets SET status = ? WHERE id = ?")->execute([$status, $id]);
            $success_message = 'Asset status updated.';
        } elseif ($action === 'delete_asset') {
            $id = (int)($_POST['asset_id'] ?? 0);
            $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
            $success_message = 'Asset deleted.';
        } elseif ($action === 'add_site') {
            $name = trim($_POST['site_name'] ?? '');
            if (empty($name)) { $error_message = 'Site name is required.'; }
            else {
                $pdo->prepare("INSERT INTO network_sites (client_id, name, address, created_at) VALUES (?, ?, ?, NOW())")
                    ->execute([(int)($_POST['client_id'] ?? 0) ?: null, $name, trim($_POST['site_address'] ?? '') ?: null]);
                $success_message = 'Network site added.';
            }
        }
    } catch (PDOException $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

$pdo = getDB();
$status_filter = $_GET['status'] ?? '';
$site_filter = (int)($_GET['site'] ?? 0);
$client_filter = (int)($_GET['client'] ?? 0);

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
$sites = $pdo->query("SELECT s.*, c.name AS client_name FROM network_sites s LEFT JOIN clients c ON s.client_id = c.id ORDER BY s.name")->fetchAll();

$where = []; $params = [];
if ($status_filter) { $where[] = 'a.status = ?'; $params[] = $status_filter; }
if ($site_filter) { $where[] = 'a.network_site_id = ?'; $params[] = $site_filter; }
if ($client_filter) { $where[] = 'a.client_id = ?'; $params[] = $client_filter; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$assets = $pdo->prepare("SELECT a.*, c.name AS client_name, s.name AS site_name
    FROM assets a
    LEFT JOIN clients c ON a.client_id = c.id
    LEFT JOIN network_sites s ON a.network_site_id = s.id
    $where_sql
    ORDER BY a.created_at DESC");
$assets->execute($params);
$assets = $assets->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) total,
    SUM(CASE WHEN status='received' THEN 1 ELSE 0 END) received,
    SUM(CASE WHEN status='assigned' THEN 1 ELSE 0 END) assigned,
    SUM(CASE WHEN status='deployed' THEN 1 ELSE 0 END) deployed
    FROM assets")->fetch();
$site_count = $pdo->query("SELECT COUNT(*) FROM network_sites")->fetchColumn();

// Per-site equipment list (site detail view)
$site_detail = null;
if ($site_filter) {
    $st = $pdo->prepare("SELECT s.*, c.name AS client_name FROM network_sites s LEFT JOIN clients c ON s.client_id = c.id WHERE s.id = ?");
    $st->execute([$site_filter]);
    $site_detail = $st->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/tailwind-shared.js"></script>
    <script>tailwind.config = window.bmTailwindConfig;</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-boxes text-blue-500 mr-2"></i>Inventory</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Equipment with deployed-status, sites, suppliers</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="document.getElementById('siteModal').classList.remove('hidden')" class="px-3 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100"><i class="fas fa-map-marker-alt mr-1"></i>New Site</button>
                    <button onclick="document.getElementById('assetModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition"><i class="fas fa-plus mr-2"></i>Add Asset</button>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Assets</p><p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['total']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Received</p><p class="text-3xl font-bold text-gray-600"><?php echo (int)$stats['received']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Assigned</p><p class="text-3xl font-bold text-yellow-600"><?php echo (int)$stats['assigned']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Deployed</p><p class="text-3xl font-bold text-green-600"><?php echo (int)$stats['deployed']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Sites</p><p class="text-3xl font-bold text-blue-600"><?php echo (int)$site_count; ?></p></div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                            <option value="">All</option>
                            <?php foreach (['received','assigned','deployed'] as $s): ?><option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Site</label>
                        <select name="site" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                            <option value="">All sites</option>
                            <?php foreach ($sites as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo $site_filter===$s['id']?'selected':''; ?>><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Client</label>
                        <select name="client" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                            <option value="">All clients</option>
                            <?php foreach ($clients as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $client_filter===$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-md text-sm">Filter</button>
                    <a href="admin-inventory.php" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-800">Clear</a>
                </form>
            </div>

            <?php if ($site_detail): ?>
                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900"><i class="fas fa-map-marker-alt text-blue-500 mr-2"></i><?php echo htmlspecialchars($site_detail['name']); ?></h2>
                            <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($site_detail['client_name'] ?: 'No client'); ?><?php echo $site_detail['address'] ? ' · ' . htmlspecialchars($site_detail['address']) : ''; ?></p>
                        </div>
                        <a href="admin-inventory.php" class="text-sm text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i>All inventory</a>
                    </div>
                    <h3 class="text-sm font-semibold text-gray-700 uppercase mb-3">Equipment at this site (<?php echo count($assets); ?>)</h3>
                    <?php if (empty($assets)): ?>
                        <p class="text-sm text-gray-500">No equipment assigned to this site yet.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            <?php foreach ($assets as $a): ?>
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($a['name']); ?></p>
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo $a['status']==='deployed'?'bg-green-100 text-green-700':($a['status']==='assigned'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-600'); ?>"><?php echo ucfirst($a['status']); ?></span>
                                    </div>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($a['type'] ?: '—'); ?><?php echo $a['serial_number'] ? ' · SN ' . htmlspecialchars($a['serial_number']) : ''; ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Asset</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Site</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier / PO</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($assets)): ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No assets match. Add equipment to get started.</td></tr>
                            <?php else: foreach ($assets as $a): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4">
                                        <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($a['name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($a['type'] ?: '—'); ?><?php echo $a['serial_number'] ? ' · SN ' . htmlspecialchars($a['serial_number']) : ''; ?></p>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($a['client_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo $a['site_name'] ? '<a href="admin-inventory.php?site=' . $a['network_site_id'] . '" class="text-blue-600 hover:underline">' . htmlspecialchars($a['site_name']) . '</a>' : '—'; ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($a['supplier'] ?: '—'); ?><?php echo $a['po_reference'] ? ' · ' . htmlspecialchars($a['po_reference']) : ''; ?></td>
                                    <td class="px-6 py-4">
                                        <form method="POST" class="flex items-center gap-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="asset_id" value="<?php echo $a['id']; ?>">
                                            <select name="status" class="px-2 py-1 text-xs border border-gray-300 rounded-md">
                                                <?php foreach (['received','assigned','deployed'] as $s): ?><option value="<?php echo $s; ?>" <?php echo $a['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option><?php endforeach; ?>
                                            </select>
                                            <button class="text-xs text-blue-600 hover:underline">Update</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add asset modal -->
<div id="assetModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">Add Asset</h3>
            <button onclick="document.getElementById('assetModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="add_asset">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Asset name *</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. airMAX AC CPE">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <input type="text" name="type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Radio / CPE / ONT">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Serial number</label>
                    <input type="text" name="serial_number" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($clients as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Network site</label>
                    <select name="network_site_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($sites as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <?php foreach (['received','assigned','deployed'] as $s): ?><option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">IP address</label>
                    <input type="text" name="ip_address" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                    <input type="text" name="supplier" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. D&H / Ubiquiti">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO reference</label>
                    <input type="text" name="po_reference" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('assetModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Add Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- Add site modal -->
<div id="siteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">New Network Site</h3>
            <button onclick="document.getElementById('siteModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="add_site">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Site name *</label>
                <input type="text" name="site_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. SEARHC — Angoon clinic">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">— None —</option>
                    <?php foreach ($clients as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input type="text" name="site_address" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('siteModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Add Site</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
