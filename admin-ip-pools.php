<?php
/**
 * admin-ip-pools.php — IP pools + allocations (P3 #8).
 * Subnet pools per network site with per-IP allocation to clients/devices.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$success_message = '';
$error_message = '';

$pdo = getDB();

/** Number of addresses in an IPv4 CIDR block (0 if unparseable). */
function cidr_size($cidr) {
    $parts = explode('/', trim((string)$cidr));
    if (count($parts) !== 2) return 0;
    $prefix = (int)$parts[1];
    if ($prefix < 0 || $prefix > 32) return 0;
    return (int)pow(2, 32 - $prefix);
}
/** Usable host addresses (subtract network + broadcast for prefix <= 30). */
function cidr_usable($cidr) {
    $size = cidr_size($cidr);
    if ($size === 0) return 0;
    $prefix = (int)explode('/', trim($cidr))[1];
    return $prefix >= 31 ? $size : max(0, $size - 2);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_pool') {
            $name = trim($_POST['name'] ?? '');
            $cidr = trim($_POST['cidr'] ?? '');
            if ($name === '' || $cidr === '') { $error_message = 'Name and CIDR are required.'; }
            elseif (cidr_size($cidr) === 0) { $error_message = 'CIDR must look like 10.0.0.0/24.'; }
            else {
                $pdo->prepare("INSERT INTO ip_pools (name, cidr, gateway, vlan, site_id, notes) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $name,
                        $cidr,
                        trim($_POST['gateway'] ?? '') ?: null,
                        ($_POST['vlan'] ?? '') !== '' ? (int)$_POST['vlan'] : null,
                        (int)($_POST['site_id'] ?? 0) ?: null,
                        trim($_POST['notes'] ?? '') ?: null,
                    ]);
                $success_message = 'IP pool created.';
            }
        } elseif ($action === 'delete_pool') {
            $pdo->prepare("DELETE FROM ip_pools WHERE id = ?")->execute([(int)($_POST['pool_id'] ?? 0)]);
            $success_message = 'IP pool deleted.';
        } elseif ($action === 'allocate_ip') {
            $ip = trim($_POST['ip_address'] ?? '');
            $pool_id = (int)($_POST['pool_id'] ?? 0);
            if ($ip === '' || $pool_id === 0) { $error_message = 'Pool and IP address are required.'; }
            else {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM ip_allocations WHERE ip_address = ?");
                $dup->execute([$ip]);
                if ((int)$dup->fetchColumn() > 0) {
                    $error_message = 'That IP is already allocated.';
                } else {
                    $pdo->prepare("INSERT INTO ip_allocations (pool_id, ip_address, client_id, status, notes) VALUES (?, ?, ?, ?, ?)")
                        ->execute([
                            $pool_id,
                            $ip,
                            (int)($_POST['client_id'] ?? 0) ?: null,
                            in_array($_POST['status'] ?? '', ['assigned', 'reserved', 'free'], true) ? $_POST['status'] : 'assigned',
                            trim($_POST['notes'] ?? '') ?: null,
                        ]);
                    $success_message = 'IP allocated.';
                }
            }
        } elseif ($action === 'release_ip') {
            $pdo->prepare("DELETE FROM ip_allocations WHERE id = ?")->execute([(int)($_POST['alloc_id'] ?? 0)]);
            $success_message = 'IP released.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// ── Data ────────────────────────────────────────────────────────────────
$pools = $pdo->query("SELECT p.*, s.name AS site_name FROM ip_pools p
    LEFT JOIN network_sites s ON p.site_id = s.id ORDER BY p.name")->fetchAll(PDO::FETCH_ASSOC);

$allocs = $pdo->query("SELECT a.*, c.name AS client_name, p.name AS pool_name, p.cidr AS pool_cidr
    FROM ip_allocations a
    LEFT JOIN clients c ON a.client_id = c.id
    LEFT JOIN ip_pools p ON a.pool_id = p.id
    ORDER BY a.ip_address")->fetchAll(PDO::FETCH_ASSOC);

$alloc_by_pool = [];
foreach ($allocs as $a) { $alloc_by_pool[(int)$a['pool_id']][] = $a; }

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$sites = $pdo->query("SELECT id, name FROM network_sites ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$total_ips = 0;
foreach ($pools as $p) { $total_ips += cidr_usable($p['cidr']); }
$assigned = count(array_filter($allocs, fn($a) => $a['status'] === 'assigned'));
$reserved = count(array_filter($allocs, fn($a) => $a['status'] === 'reserved'));
$available = max(0, $total_ips - $assigned - $reserved);

function fmt_ip_status($s) {
    return match ($s) {
        'assigned' => 'bg-blue-100 text-blue-700',
        'reserved' => 'bg-yellow-100 text-yellow-700',
        default    => 'bg-gray-200 text-gray-600',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Pools — Blue Mogul</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
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
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-network-wired text-blue-500 mr-2"></i>IP Pools</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Subnet pools &amp; IP allocations</p>
                </div>
                <button onclick="document.getElementById('poolModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition"><i class="fas fa-plus mr-2"></i>New Pool</button>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Pools</p><p class="text-3xl font-bold text-gray-900"><?php echo count($pools); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total IPs</p><p class="text-3xl font-bold text-gray-900"><?php echo number_format($total_ips); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Assigned</p><p class="text-3xl font-bold text-blue-600"><?php echo number_format($assigned); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Available</p><p class="text-3xl font-bold text-green-600"><?php echo number_format($available); ?></p></div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Pools</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">CIDR</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Gateway</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">VLAN</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Site</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Used</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($pools)): ?>
                                <tr><td colspan="7" class="px-6 py-12 text-center text-gray-500">No IP pools yet.</td></tr>
                            <?php else: foreach ($pools as $p):
                                $usable = cidr_usable($p['cidr']);
                                $used = count($alloc_by_pool[(int)$p['id']] ?? []);
                                $pct = $usable > 0 ? min(100, round($used / $usable * 100)) : 0;
                            ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="row-pool-<?php echo (int)$p['id']; ?>">
                                    <td class="px-6 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700 font-mono"><?php echo htmlspecialchars($p['cidr']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500 font-mono"><?php echo htmlspecialchars($p['gateway'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo $p['vlan'] !== null ? (int)$p['vlan'] : '—'; ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($p['site_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <div class="flex items-center gap-2">
                                            <span class="text-gray-700"><?php echo $used; ?>/<?php echo $usable; ?></span>
                                            <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden"><div class="h-full <?php echo $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-yellow-500' : 'bg-green-500'); ?>" style="width: <?php echo $pct; ?>%"></div></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-right whitespace-nowrap">
                                        <button type="button" onclick="allocateIP(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>')" class="text-blue-600 hover:text-blue-800 text-xs mr-3">Allocate IP</button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this pool and its allocations?');">
                                            <input type="hidden" name="action" value="delete_pool">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="pool_id" value="<?php echo (int)$p['id']; ?>">
                                            <button class="text-gray-400 hover:text-gray-700 text-xs">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Allocations (<?php echo count($allocs); ?>)</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">IP Address</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Pool</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($allocs)): ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No IPs allocated yet.</td></tr>
                            <?php else: foreach ($allocs as $a): ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="row-alloc-<?php echo (int)$a['id']; ?>">
                                    <td class="px-6 py-3 text-sm font-mono text-gray-900"><?php echo htmlspecialchars($a['ip_address']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo htmlspecialchars(($a['pool_name'] ?? '—') . ($a['pool_cidr'] ? ' (' . $a['pool_cidr'] . ')' : '')); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($a['client_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm"><span class="px-2 py-1 rounded-full text-xs font-medium <?php echo fmt_ip_status($a['status']); ?>"><?php echo ucfirst($a['status']); ?></span></td>
                                    <td class="px-6 py-3 text-sm text-right">
                                        <form method="POST" class="inline" onsubmit="return confirm('Release this IP?');">
                                            <input type="hidden" name="action" value="release_ip">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="alloc_id" value="<?php echo (int)$a['id']; ?>">
                                            <button class="text-gray-400 hover:text-gray-700 text-xs">Release</button>
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

<!-- New pool modal -->
<div id="poolModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">New IP Pool</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_pool">
            <?php echo csrf_field(); ?>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. Houston Core - CGNAT">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">CIDR</label>
                    <input type="text" name="cidr" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" placeholder="100.64.0.0/22">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gateway</label>
                    <input type="text" name="gateway" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" placeholder="100.64.0.1">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">VLAN</label>
                    <input type="number" name="vlan" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Site</label>
                    <select name="site_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">—</option>
                        <?php foreach ($sites as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('poolModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Create pool</button>
            </div>
        </form>
    </div>
</div>

<!-- Allocate IP modal -->
<div id="allocModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Allocate IP</h3>
        <form method="POST">
            <input type="hidden" name="action" value="allocate_ip">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="pool_id" id="al_pool_id">
            <p class="text-xs text-gray-500 mb-4">Pool: <span id="al_pool_name" class="font-medium text-gray-700"></span></p>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">IP address</label>
                    <input type="text" name="ip_address" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono" placeholder="100.64.0.10">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="assigned">Assigned</option>
                        <option value="reserved">Reserved</option>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">— No client —</option>
                    <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('allocModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Allocate</button>
            </div>
        </form>
    </div>
</div>

<script>
function allocateIP(poolId, poolName) {
    document.getElementById('al_pool_id').value = poolId;
    document.getElementById('al_pool_name').textContent = poolName;
    document.getElementById('allocModal').classList.remove('hidden');
}
</script>
</body>
</html>
