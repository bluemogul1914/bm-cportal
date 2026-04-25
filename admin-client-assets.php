<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';

$client_id = intval($_GET['id'] ?? 0);
if ($client_id <= 0) {
    portal_redirect('/portal/admin-clients.php');
}

$pdo = getDB();
$error_msg   = '';
$success_msg = '';

// Load client
$client = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
if (!$client) {
    portal_redirect('/portal/admin-clients.php');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_asset') {
        $name       = trim($_POST['name'] ?? '');
        $type       = trim($_POST['type'] ?? '');
        $serial     = trim($_POST['serial_number'] ?? '');
        $os         = trim($_POST['os'] ?? '');
        $ip         = trim($_POST['ip_address'] ?? '');
        $managed    = isset($_POST['managed']) ? 1 : 0;
        $rmm_id     = trim($_POST['rmm_agent_id'] ?? '');
        $plan_tier  = trim($_POST['plan_tier'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        if (empty($name)) {
            $error_msg = 'Asset name is required.';
        } else {
            try {
                $pdo->prepare("INSERT INTO assets (client_id, name, type, serial_number, os, ip_address, managed, rmm_agent_id, plan_tier, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$client_id, $name, $type ?: null, $serial ?: null, $os ?: null, $ip ?: null, $managed, $rmm_id ?: null, $plan_tier ?: null, $notes ?: null]);
                $success_msg = 'Asset added.';
            } catch (PDOException $e) {
                error_log("add_asset error: " . $e->getMessage());
                $error_msg = 'Failed to add asset.';
            }
        }
    } elseif ($action === 'delete_asset') {
        $asset_id = intval($_POST['asset_id'] ?? 0);
        if ($asset_id > 0) {
            try {
                $pdo->prepare("DELETE FROM assets WHERE id = ? AND client_id = ?")
                    ->execute([$asset_id, $client_id]);
                $success_msg = 'Asset removed.';
            } catch (PDOException $e) {
                $error_msg = 'Failed to delete asset.';
            }
        }
    }
}

// Load assets
$assets_list = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE client_id = ? ORDER BY managed DESC, name ASC");
    $stmt->execute([$client_id]);
    $assets_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("assets load error: " . $e->getMessage());
}
?>
<?php include 'includes/header.php'; ?>
<div class="flex h-screen overflow-hidden bg-gray-100">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm text-gray-500 mb-1">
                        <a href="/portal/admin-clients.php" class="hover:text-blue-600">Clients</a>
                        <span>/</span>
                        <a href="/portal/admin-client-detail.php?id=<?php echo $client_id; ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($client['name']); ?></a>
                        <span>/</span>
                        <span class="text-gray-900 font-medium">Assets</span>
                    </div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-laptop mr-2"></i>Assets — <?php echo htmlspecialchars($client['name']); ?></h1>
                </div>
                <a href="/portal/admin-client-detail.php?id=<?php echo $client_id; ?>" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium transition">
                    <i class="fas fa-arrow-left mr-1"></i>Back to Client
                </a>
            </div>
        </header>

        <?php if ($success_msg): ?>
            <div class="mx-6 mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="mx-6 mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <div class="flex-1 overflow-y-auto p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Add asset form -->
                <div class="bg-white rounded-lg border border-gray-200 p-6 h-fit">
                    <h2 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plus-circle mr-2 text-blue-500"></i>Add Asset</h2>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_asset">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="e.g. Workstation-01">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                                    <option value="">-- Select type --</option>
                                    <option value="workstation">Workstation</option>
                                    <option value="laptop">Laptop</option>
                                    <option value="server">Server</option>
                                    <option value="network">Network Device</option>
                                    <option value="printer">Printer</option>
                                    <option value="mobile">Mobile</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Serial Number</label>
                                <input type="text" name="serial_number" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="SN-XXXXXXXXX">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">OS</label>
                                <input type="text" name="os" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. Windows 11 Pro">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">IP Address</label>
                                <input type="text" name="ip_address" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="192.168.1.x">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">RMM Agent ID</label>
                                <input type="text" name="rmm_agent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Agent ID from RMM">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Plan Tier</label>
                                <input type="text" name="plan_tier" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. Starter, Pro">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="managed" id="managed" class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <label for="managed" class="text-sm text-gray-700">Managed by RMM</label>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                                <i class="fas fa-plus mr-1"></i>Add Asset
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Assets list -->
                <div class="lg:col-span-2">
                    <?php if (empty($assets_list)): ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <i class="fas fa-laptop text-4xl text-gray-300 mb-3"></i>
                            <p class="font-medium text-gray-900">No assets yet</p>
                            <p class="text-sm text-gray-500 mt-1">Add the first asset for this client using the form.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3" data-testid="assets-list">
                            <?php foreach ($assets_list as $asset): ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="asset-<?php echo $asset['id']; ?>">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                            <?php
                                            $type_icons = ['workstation' => 'fa-desktop', 'laptop' => 'fa-laptop', 'server' => 'fa-server', 'network' => 'fa-network-wired', 'printer' => 'fa-print', 'mobile' => 'fa-mobile-alt'];
                                            $icon = $type_icons[$asset['type'] ?? ''] ?? 'fa-hdd';
                                            ?>
                                            <i class="fas <?php echo $icon; ?> text-gray-600"></i>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-gray-900"><?php echo htmlspecialchars($asset['name']); ?></span>
                                                <?php if ($asset['managed']): ?>
                                                <span class="px-1.5 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium"><i class="fas fa-shield-alt mr-1"></i>Managed</span>
                                                <?php endif; ?>
                                                <?php if ($asset['type']): ?>
                                                <span class="px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded text-xs"><?php echo ucfirst(htmlspecialchars($asset['type'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1">
                                                <?php if ($asset['os']): ?><span class="text-xs text-gray-500"><i class="fab fa-windows mr-1"></i><?php echo htmlspecialchars($asset['os']); ?></span><?php endif; ?>
                                                <?php if ($asset['ip_address']): ?><span class="text-xs text-gray-500"><i class="fas fa-network-wired mr-1"></i><?php echo htmlspecialchars($asset['ip_address']); ?></span><?php endif; ?>
                                                <?php if ($asset['serial_number']): ?><span class="text-xs text-gray-500"><i class="fas fa-barcode mr-1"></i><?php echo htmlspecialchars($asset['serial_number']); ?></span><?php endif; ?>
                                                <?php if ($asset['plan_tier']): ?><span class="text-xs text-blue-600"><i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($asset['plan_tier']); ?></span><?php endif; ?>
                                                <?php if ($asset['rmm_agent_id']): ?><span class="text-xs text-gray-400">RMM: <?php echo htmlspecialchars($asset['rmm_agent_id']); ?></span><?php endif; ?>
                                            </div>
                                            <?php if ($asset['notes']): ?><p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($asset['notes']); ?></p><?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Remove this asset?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_asset">
                                        <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-600 text-sm p-1"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
