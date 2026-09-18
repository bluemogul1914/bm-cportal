<?php
/**
 * admin-network-map.php — Network site map (P3 #8b).
 * Geographic map of network_sites with lat/lng, plus site CRUD.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$success_message = '';
$error_message = '';

$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $lat = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
    $lng = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
    try {
        if ($action === 'add_site') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { $error_message = 'Site name is required.'; }
            else {
                $pdo->prepare("INSERT INTO network_sites (client_id, name, address, latitude, longitude, site_type, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        (int)($_POST['client_id'] ?? 0) ?: null,
                        $name,
                        trim($_POST['address'] ?? '') ?: null,
                        $lat, $lng,
                        trim($_POST['site_type'] ?? '') ?: null,
                        in_array($_POST['status'] ?? '', ['active', 'planned', 'decommissioned'], true) ? $_POST['status'] : 'active',
                    ]);
                $success_message = 'Network site added.';
            }
        } elseif ($action === 'update_site') {
            $id = (int)($_POST['site_id'] ?? 0);
            $pdo->prepare("UPDATE network_sites SET client_id=?, name=?, address=?, latitude=?, longitude=?, site_type=?, status=? WHERE id=?")
                ->execute([
                    (int)($_POST['client_id'] ?? 0) ?: null,
                    trim($_POST['name'] ?? ''),
                    trim($_POST['address'] ?? '') ?: null,
                    $lat, $lng,
                    trim($_POST['site_type'] ?? '') ?: null,
                    in_array($_POST['status'] ?? '', ['active', 'planned', 'decommissioned'], true) ? $_POST['status'] : 'active',
                    $id,
                ]);
            $success_message = 'Network site updated.';
        } elseif ($action === 'delete_site') {
            $pdo->prepare("DELETE FROM network_sites WHERE id = ?")->execute([(int)($_POST['site_id'] ?? 0)]);
            $success_message = 'Network site deleted.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// ── Data ────────────────────────────────────────────────────────────────
$sites = $pdo->query("SELECT s.*, c.name AS client_name FROM network_sites s
    LEFT JOIN clients c ON s.client_id = c.id ORDER BY s.name")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$sites_json = [];
$sites_by_id = [];
foreach ($sites as $s) {
    $entry = [
        'id' => (int)$s['id'],
        'name' => $s['name'],
        'client_id' => $s['client_id'] !== null ? (int)$s['client_id'] : '',
        'client' => $s['client_name'] ?? '',
        'address' => $s['address'] ?? '',
        'lat' => $s['latitude'] !== null ? (float)$s['latitude'] : null,
        'lng' => $s['longitude'] !== null ? (float)$s['longitude'] : null,
        'type' => $s['site_type'] ?? '',
        'status' => $s['status'] ?? 'active',
    ];
    $sites_json[] = $entry;
    $sites_by_id[(int)$s['id']] = $entry;
}
$mapped = count(array_filter($sites, fn($s) => $s['latitude'] !== null && $s['longitude'] !== null));
$active = count(array_filter($sites, fn($s) => ($s['status'] ?? 'active') === 'active'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Map — Blue Mogul</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-map-marked-alt text-blue-500 mr-2"></i>Network Site Map</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Sites across the service area</p>
                </div>
                <button onclick="openSiteModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition"><i class="fas fa-plus mr-2"></i>New Site</button>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Sites</p><p class="text-3xl font-bold text-gray-900"><?php echo count($sites); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Mapped</p><p class="text-3xl font-bold text-blue-600"><?php echo $mapped; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Unmapped</p><p class="text-3xl font-bold text-yellow-600"><?php echo count($sites) - $mapped; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active</p><p class="text-3xl font-bold text-green-600"><?php echo $active; ?></p></div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
                <div id="map" style="height: 460px; z-index: 1;"></div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Network Sites (<?php echo count($sites); ?>)</h2>
                    <span class="text-xs text-gray-500">Tip: open a site and click the map to set its coordinates</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Site</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Address</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Coords</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($sites)): ?>
                                <tr><td colspan="7" class="px-6 py-12 text-center text-gray-500">No network sites yet.</td></tr>
                            <?php else: foreach ($sites as $s): ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="row-site-<?php echo (int)$s['id']; ?>">
                                    <td class="px-6 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($s['name']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($s['client_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($s['address'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($s['site_type'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500 font-mono"><?php echo $s['latitude'] !== null && $s['longitude'] !== null ? htmlspecialchars($s['latitude'] . ', ' . $s['longitude']) : '—'; ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo ($s['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : (($s['status'] ?? '') === 'planned' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-200 text-gray-600'); ?>"><?php echo ucfirst($s['status'] ?? 'active'); ?></span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-right whitespace-nowrap">
                                        <button type="button" onclick='openSiteModal(<?php echo json_encode($sites_by_id[(int)$s['id']], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="text-blue-600 hover:text-blue-800 text-xs mr-3">Edit</button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this site?');">
                                            <input type="hidden" name="action" value="delete_site">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                                            <button class="text-gray-400 hover:text-gray-700 text-xs">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Site modal (add/edit) -->
<div id="siteModal" class="hidden fixed inset-0 z-[1000] flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <h3 id="siteModalTitle" class="text-lg font-semibold text-gray-900 mb-4">New Network Site</h3>
        <form method="POST">
            <input type="hidden" name="action" id="sm_action" value="add_site">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="site_id" id="sm_id">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Site name</label>
                    <input type="text" name="name" id="sm_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. SEARHC Sitka - Tower A">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select name="client_id" id="sm_client" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">—</option>
                        <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input type="text" name="address" id="sm_address" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Street, City, ST">
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Latitude</label>
                    <input type="text" name="latitude" id="sm_lat" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" placeholder="29.7604">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Longitude</label>
                    <input type="text" name="longitude" id="sm_lng" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" placeholder="-95.3698">
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4"><i class="fas fa-crosshairs mr-1"></i>Tip: with this dialog open, click anywhere on the map to set lat/long.</p>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <input type="text" name="site_type" id="sm_type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Tower / POP / Client premises">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" id="sm_status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="active">Active</option>
                        <option value="planned">Planned</option>
                        <option value="decommissioned">Decommissioned</option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('siteModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Save site</button>
            </div>
        </form>
    </div>
</div>

<script>
const SITES = <?php echo json_encode($sites_json); ?>;
const HOUSTON = [29.7604, -95.3698];
const map = L.map('map').setView(HOUSTON, 7);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

const statusColor = s => s === 'active' ? '#16a34a' : (s === 'planned' ? '#ca8a04' : '#6b7280');
const markers = [];
SITES.forEach(s => {
    if (s.lat === null || s.lng === null) return;
    const m = L.circleMarker([s.lat, s.lng], { radius: 8, color: statusColor(s.status), fillColor: statusColor(s.status), fillOpacity: 0.7, weight: 2 })
        .addTo(map)
        .bindPopup('<b>' + esc(s.name) + '</b><br>' + esc(s.client || '—') + '<br>' + esc(s.address || '') + '<br><span style="color:' + statusColor(s.status) + '">' + esc(s.status) + '</span>');
    markers.push(m);
});
if (markers.length) {
    const grp = L.featureGroup(markers);
    map.fitBounds(grp.getBounds().pad(0.2));
}

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function openSiteModal(site) {
    document.getElementById('sm_action').value = site ? 'update_site' : 'add_site';
    document.getElementById('siteModalTitle').textContent = site ? 'Edit Network Site' : 'New Network Site';
    document.getElementById('sm_id').value = site ? site.id : '';
    document.getElementById('sm_name').value = site ? (site.name || '') : '';
    document.getElementById('sm_client').value = site && site.client_id ? site.client_id : '';
    document.getElementById('sm_address').value = site ? (site.address || '') : '';
    document.getElementById('sm_lat').value = site && site.lat !== null ? site.lat : '';
    document.getElementById('sm_lng').value = site && site.lng !== null ? site.lng : '';
    document.getElementById('sm_type').value = site ? (site.type || '') : '';
    document.getElementById('sm_status').value = site ? (site.status || 'active') : 'active';
    document.getElementById('siteModal').classList.remove('hidden');
    if (site && site.lat !== null && site.lng !== null) map.setView([site.lat, site.lng], 13);
}
// click-to-set coordinates while the modal is open
map.on('click', e => {
    if (document.getElementById('siteModal').classList.contains('hidden')) return;
    document.getElementById('sm_lat').value = e.latlng.lat.toFixed(6);
    document.getElementById('sm_lng').value = e.latlng.lng.toFixed(6);
});
</script>
</body>
</html>
