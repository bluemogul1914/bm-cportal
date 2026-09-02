<?php
/**
 * Hetzner Cloud API Dashboard
 * https://docs.hetzner.cloud
 *
 * Tabs: Overview, Servers, Resources (locations/datacenters/types),
 *       Images, Pricing
 */
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();

// ── Load token ──────────────────────────────────────────────────────
$hetzner_token = '';
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='hetzner_api_token' LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $hetzner_token = $row['setting_value'] ?? '';
} catch (PDOException $e) {}

$hc_connected = $hetzner_token !== '';

// ── Helpers ─────────────────────────────────────────────────────────
function hc_api($token, $path, $method = 'GET', $body = null) {
    $url = 'https://api.hetzner.cloud/v1' . $path;
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($body) $headers[] = 'Content-Type: application/json';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $body; }
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => "cURL: $err"];
    $j = json_decode($r, true);
    if ($code < 200 || $code >= 300) {
        $msg = $j['error']['message'] ?? substr($r, 0, 300);
        return ['error' => "HTTP $code: $msg"];
    }
    return $j;
}

function hc_api_post($token, $path, $bodyArr = []) {
    return hc_api($token, $path, 'POST', json_encode($bodyArr));
}

// ── Tab state ───────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';

// ── POST handlers ───────────────────────────────────────────────────
$api_error   = '';
$api_success = '';
$servers       = null;
$server_detail = null;
$locations     = null;
$datacenters   = null;
$server_types  = null;
$images        = null;
$pricing       = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save token
    if ($action === 'save_token') {
        require_csrf();
        $new_token = trim($_POST['hetzner_api_token'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('hetzner_api_token', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value");
        $stmt->execute([$new_token]);
        $hetzner_token = $new_token;
        $hc_connected = $new_token !== '';
        $api_success = 'API token saved.';
    }

    // Server power actions (poweron, poweroff, reboot, reset, shutdown)
    if (in_array($action, ['poweron','poweroff','reboot','reset','shutdown']) && $hc_connected) {
        require_csrf();
        $serverId = (int)($_POST['server_id'] ?? 0);
        if ($serverId) {
            $res = hc_api_post($hetzner_token, "/servers/$serverId/actions/$action");
            if (!empty($res['error'])) {
                $api_error = "Action $action failed: " . $res['error'];
            } else {
                $api_success = "Server #$serverId: $action command sent (action #{$res['action']['id']}).";
            }
        }
    }
}

// ── GET data fetches ────────────────────────────────────────────────
if ($hc_connected) {
    // Servers list (with query params)
    if ($tab === 'servers' || $tab === 'overview') {
        $sName  = trim($_GET['name'] ?? '');
        $sLabel = trim($_GET['label_selector'] ?? '');
        $sSort  = trim($_GET['sort'] ?? '');
        $sPage  = max(1, (int)($_GET['page'] ?? 1));
        $sPer   = max(10, min(50, (int)($_GET['per_page'] ?? 25)));
        $sStatus = trim($_GET['status'] ?? '');

        $params = [];
        $params[] = "page=$sPage";
        $params[] = "per_page=$sPer";
        if ($sName !== '')     $params[] = 'name=' . urlencode($sName);
        if ($sLabel !== '')    $params[] = 'label_selector=' . urlencode($sLabel);
        if ($sSort !== '')     $params[] = 'sort=' . urlencode($sSort);
        if ($sStatus !== '')   $params[] = 'status=' . urlencode($sStatus);

        $servers = hc_api($hetzner_token, '/servers?' . implode('&', $params));
    }

    // Locations
    if ($tab === 'resources') {
        $locations   = hc_api($hetzner_token, '/locations');
        $datacenters = hc_api($hetzner_token, '/datacenters');
        $server_types = hc_api($hetzner_token, '/server_types');
    }

    // Images
    if ($tab === 'images') {
        $imgType = trim($_GET['img_type'] ?? '');
        $imgArch = trim($_GET['architecture'] ?? '');
        $imgPage = max(1, (int)($_GET['img_page'] ?? 1));
        $imgPer  = max(10, min(50, (int)($_GET['img_per_page'] ?? 20)));
        $params = ["page=$imgPage", "per_page=$imgPer"];
        if ($imgType !== '') $params[] = 'type=' . urlencode($imgType);
        if ($imgArch !== '') $params[] = 'architecture=' . urlencode($imgArch);
        $images = hc_api($hetzner_token, '/images?' . implode('&', $params));
    }

    // Pricing
    if ($tab === 'pricing') {
        $pricing = hc_api($hetzner_token, '/pricing');
    }
}

include 'includes/admin-header.php';
?>
<div class="flex h-screen bg-gray-50">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/admin-topbar.php'; ?>
        <main class="flex-1 overflow-y-auto p-6">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Hetzner Cloud</h1>
                    <p class="text-gray-500 text-sm mt-1">API v1 — <a href="https://docs.hetzner.cloud" target="_blank" class="text-blue-600 hover:underline">docs.hetzner.cloud</a></p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($hc_connected): ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">
                        <span class="h-2 w-2 bg-green-500 rounded-full"></span>
                        Token Set
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">
                        <span class="h-2 w-2 bg-red-500 rounded-full"></span>
                        No Token
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($api_success): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm"><?= $api_success ?></div>
            <?php endif; ?>
            <?php if ($api_error): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm"><?= htmlspecialchars($api_error) ?></div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="border-b border-gray-200 mb-6">
                <nav class="flex space-x-6 -mb-px overflow-x-auto">
                    <?php $tabs = [
                        'overview'  => ['Overview', 'fa-info-circle'],
                        'servers'   => ['Servers', 'fa-server'],
                        'resources' => ['Resources', 'fa-database'],
                        'images'    => ['Images', 'fa-image'],
                        'pricing'   => ['Pricing', 'fa-dollar-sign'],
                        'settings'  => ['Settings', 'fa-cog'],
                    ];
                    foreach ($tabs as $key => [$label, $icon]):
                        $active = $tab === $key;
                    ?>
                    <a href="?tab=<?= $key ?>" class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition <?= $active ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                        <i class="fas <?= $icon ?> text-xs"></i>
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- ── TAB: Overview ──────────────────────────────────────── -->
            <?php if ($tab === 'overview'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Connection Status</h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">API Token</span>
                            <span class="font-medium <?= $hc_connected ? 'text-green-600' : 'text-red-600' ?>"><?= $hc_connected ? 'Configured' : 'Not Set' ?></span>
                        </div>
                        <?php if ($hc_connected && !empty($servers) && empty($servers['error'])): ?>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Servers</span>
                            <span class="font-medium"><?= (int)($servers['meta']['pagination']['total_entries'] ?? 0) ?></span>
                        </div>
                        <div class="mt-4">
                            <a href="?tab=servers" class="inline-block px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition"><i class="fas fa-server mr-1"></i> Manage Servers</a>
                            <a href="?tab=settings" class="inline-block ml-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition"><i class="fas fa-cog mr-1"></i> Settings</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($hc_connected && !empty($servers) && empty($servers['error']) && count($servers['servers'] ?? [])): ?>
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Recent Servers</h2>
                    <div class="space-y-2">
                        <?php foreach (array_slice($servers['servers'], 0, 5) as $sv): ?>
                        <div class="flex justify-between items-center text-sm py-1.5 px-3 rounded bg-gray-50">
                            <span class="font-medium font-mono text-xs"><?= htmlspecialchars($sv['name']) ?></span>
                            <span class="inline-flex items-center gap-1.5 text-xs">
                                <span class="h-2 w-2 rounded-full <?= $sv['status'] === 'running' ? 'bg-green-500' : ($sv['status'] === 'off' ? 'bg-red-400' : 'bg-yellow-400') ?>"></span>
                                <?= $sv['status'] ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── TAB: Servers ──────────────────────────────────────── -->
            <?php if ($tab === 'servers'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-semibold text-gray-800">Filter Servers</h2>
                    <span class="text-xs text-gray-400">Total: <?= (int)($servers['meta']['pagination']['total_entries'] ?? '—') ?></span>
                </div>
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <input type="hidden" name="tab" value="servers">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($_GET['name'] ?? '') ?>" placeholder="e.g. my-server" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                            <option value="">All</option>
                            <option value="running" <?= ($_GET['status'] ?? '') === 'running' ? 'selected' : '' ?>>Running</option>
                            <option value="off" <?= ($_GET['status'] ?? '') === 'off' ? 'selected' : '' ?>>Off</option>
                            <option value="starting" <?= ($_GET['status'] ?? '') === 'starting' ? 'selected' : '' ?>>Starting</option>
                            <option value="stopping" <?= ($_GET['status'] ?? '') === 'stopping' ? 'selected' : '' ?>>Stopping</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Label Selector</label>
                        <input type="text" name="label_selector" value="<?= htmlspecialchars($_GET['label_selector'] ?? '') ?>" placeholder="e.g. env=prod" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Sort</label>
                        <select name="sort" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                            <option value="">Default</option>
                            <option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name ↑</option>
                            <option value="-name" <?= ($_GET['sort'] ?? '') === '-name' ? 'selected' : '' ?>>Name ↓</option>
                            <option value="created" <?= ($_GET['sort'] ?? '') === 'created' ? 'selected' : '' ?>>Created ↑</option>
                            <option value="-created" <?= ($_GET['sort'] ?? '') === '-created' ? 'selected' : '' ?>>Created ↓</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition flex-1"><i class="fas fa-search mr-1"></i> Search</button>
                        <a href="?tab=servers" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm transition" title="Clear filters"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>

            <?php if ($servers && empty($servers['error'])): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Servers (<?= count($servers['servers']) ?>)</h3>
                <?php if (count($servers['servers']) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wider">
                                <th class="pb-2 pr-3 font-medium">ID</th>
                                <th class="pb-2 pr-3 font-medium">Name</th>
                                <th class="pb-2 pr-3 font-medium">Type</th>
                                <th class="pb-2 pr-3 font-medium">Location</th>
                                <th class="pb-2 pr-3 font-medium">IP</th>
                                <th class="pb-2 pr-3 font-medium">Status</th>
                                <th class="pb-2 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($servers['servers'] as $sv):
                            $sid = (int)$sv['id'];
                            $pub = $sv['public_net'] ?? [];
                            $ipv4 = $pub['ipv4']['ip'] ?? '';
                            $ipv6 = $pub['ipv6']['ip'] ?? '';
                        ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50">
                                <td class="py-2 pr-3 font-mono text-xs"><?= $sid ?></td>
                                <td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars($sv['name']) ?></td>
                                <td class="py-2 pr-3 font-mono text-xs text-gray-500"><?= htmlspecialchars($sv['server_type']['name'] ?? '—') ?></td>
                                <td class="py-2 pr-3 text-xs text-gray-500"><?= htmlspecialchars(strtoupper($sv['datacenter']['location']['city'] ?? $sv['datacenter']['location']['name'] ?? '')) ?></td>
                                <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars($ipv4 ?: ($ipv6 ? substr($ipv6,0,12).'…' : '—')) ?></td>
                                <td class="py-2 pr-3">
                                    <span class="inline-flex items-center gap-1.5 text-xs">
                                        <span class="h-2 w-2 rounded-full <?= $sv['status'] === 'running' ? 'bg-green-500' : ($sv['status'] === 'off' ? 'bg-red-400' : 'bg-yellow-400') ?>"></span>
                                        <?= $sv['status'] ?>
                                    </span>
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <?php if ($sv['status'] === 'running'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="poweroff">
                                        <input type="hidden" name="server_id" value="<?= $sid ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-medium" title="Power Off" onclick="return confirm('Power off <?= htmlspecialchars($sv['name']) ?>?')"><i class="fas fa-power-off mr-0.5"></i>Off</button>
                                    </form>
                                    <form method="POST" class="inline ml-2">
                                        <input type="hidden" name="action" value="reboot">
                                        <input type="hidden" name="server_id" value="<?= $sid ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-yellow-600 hover:text-yellow-800 text-xs font-medium" title="Reboot" onclick="return confirm('Reboot <?= htmlspecialchars($sv['name']) ?>?')"><i class="fas fa-sync-alt mr-0.5"></i>Reboot</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="poweron">
                                        <input type="hidden" name="server_id" value="<?= $sid ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-green-600 hover:text-green-800 text-xs font-medium" title="Power On"><i class="fas fa-play mr-0.5"></i>On</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php $pg = $servers['meta']['pagination'] ?? []; if (!empty($pg)): ?>
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-100 text-xs text-gray-500">
                    <span>Page <?= (int)($pg['page']) ?> of <?= (int)($pg['last_page']) ?> (<?= (int)($pg['total_entries']) ?> total)</span>
                    <div class="flex gap-2">
                        <?php if ($pg['previous_page']): ?>
                        <a href="?tab=servers&page=<?= (int)$pg['previous_page'] ?>&per_page=<?= (int)$sPer ?>&name=<?= urlencode($sName) ?>&label_selector=<?= urlencode($sLabel) ?>&sort=<?= urlencode($sSort) ?>&status=<?= urlencode($sStatus) ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg">&larr; Prev</a>
                        <?php endif; ?>
                        <?php if ($pg['next_page']): ?>
                        <a href="?tab=servers&page=<?= (int)$pg['next_page'] ?>&per_page=<?= (int)$sPer ?>&name=<?= urlencode($sName) ?>&label_selector=<?= urlencode($sLabel) ?>&sort=<?= urlencode($sSort) ?>&status=<?= urlencode($sStatus) ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-server text-3xl mb-2 block"></i>
                    <p class="text-sm">No servers found matching your filters.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php elseif ($servers && !empty($servers['error'])): ?>
            <div class="bg-white rounded-xl border border-red-200 p-6">
                <p class="text-red-600 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i> <?= htmlspecialchars($servers['error']) ?></p>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ── TAB: Resources (Locations, Datacenters, Server Types) -->
            <?php if ($tab === 'resources'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Locations -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Locations</h2>
                    <?php if ($locations && empty($locations['error']) && count($locations['locations'] ?? [])): ?>
                    <div class="space-y-2">
                        <?php foreach ($locations['locations'] as $loc): ?>
                        <div class="flex justify-between items-center py-1.5 px-3 rounded bg-gray-50 text-sm">
                            <span class="font-mono text-xs font-medium"><?= htmlspecialchars(strtoupper($loc['name'])) ?></span>
                            <span class="text-xs text-gray-500"><?= htmlspecialchars($loc['city'] . ', ' . $loc['country']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400">No locations data.</p>
                    <?php endif; ?>
                </div>

                <!-- Datacenters -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Datacenters</h2>
                    <?php if ($datacenters && empty($datacenters['error']) && count($datacenters['datacenters'] ?? [])): ?>
                    <div class="space-y-2">
                        <?php foreach ($datacenters['datacenters'] as $dc): ?>
                        <div class="flex justify-between items-center py-1.5 px-3 rounded bg-gray-50 text-sm">
                            <span class="font-mono text-xs font-medium"><?= htmlspecialchars($dc['name']) ?></span>
                            <span class="text-xs text-gray-500"><?= htmlspecialchars($dc['description']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400">No datacenter data.</p>
                    <?php endif; ?>
                </div>

                <!-- Server Types -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Server Types</h2>
                    <?php if ($server_types && empty($server_types['error']) && count($server_types['server_types'] ?? [])): ?>
                    <div class="overflow-y-auto max-h-96 space-y-1">
                        <?php foreach ($server_types['server_types'] as $st): ?>
                        <div class="py-1.5 px-3 rounded bg-gray-50 text-sm flex justify-between">
                            <span class="font-mono text-xs font-medium"><?= htmlspecialchars($st['name']) ?></span>
                            <span class="text-xs text-gray-500"><?= $st['cores'] ?>C · <?= round($st['memory']) ?>GB · <?= $st['disk'] ?>GB</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400">No server type data.</p>
                    <?php endif; ?>
                </div>

            </div>
            <?php endif; ?>

            <!-- ── TAB: Images ────────────────────────────────────────── -->
            <?php if ($tab === 'images'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="hidden" name="tab" value="images">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                        <select name="img_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                            <option value="">All</option>
                            <option value="system" <?= ($_GET['img_type'] ?? '') === 'system' ? 'selected' : '' ?>>System</option>
                            <option value="app" <?= ($_GET['img_type'] ?? '') === 'app' ? 'selected' : '' ?>>App</option>
                            <option value="snapshot" <?= ($_GET['img_type'] ?? '') === 'snapshot' ? 'selected' : '' ?>>Snapshot</option>
                            <option value="backup" <?= ($_GET['img_type'] ?? '') === 'backup' ? 'selected' : '' ?>>Backup</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Architecture</label>
                        <select name="architecture" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                            <option value="">All</option>
                            <option value="x86" <?= ($_GET['architecture'] ?? '') === 'x86' ? 'selected' : '' ?>>x86</option>
                            <option value="arm" <?= ($_GET['architecture'] ?? '') === 'arm' ? 'selected' : '' ?>>ARM</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition"><i class="fas fa-search mr-1"></i> Filter</button>
                        <a href="?tab=images" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm transition"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>

            <?php if ($images && empty($images['error'])): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Images (<?= count($images['images']) ?>)</h3>
                <?php if (count($images['images']) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wider">
                                <th class="pb-2 pr-3 font-medium">ID</th>
                                <th class="pb-2 pr-3 font-medium">Name</th>
                                <th class="pb-2 pr-3 font-medium">OS / Flavor</th>
                                <th class="pb-2 pr-3 font-medium">Type</th>
                                <th class="pb-2 pr-3 font-medium">Arch</th>
                                <th class="pb-2 pr-3 font-medium">Size (GB)</th>
                                <th class="pb-2 font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($images['images'] as $img): ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50">
                                <td class="py-2 pr-3 font-mono text-xs"><?= (int)$img['id'] ?></td>
                                <td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars($img['name'] ?: $img['description'] ?: '—') ?></td>
                                <td class="py-2 pr-3 text-xs text-gray-600"><?= htmlspecialchars($img['os_flavor'] ?: '—') ?> <?= $img['os_version'] ?? '' ?></td>
                                <td class="py-2 pr-3 text-xs"><?= htmlspecialchars($img['type'] ?? '—') ?></td>
                                <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars($img['architecture'] ?? '—') ?></td>
                                <td class="py-2 pr-3 text-xs"><?= round($img['image_size'] ?? 0, 1) ?> GB</td>
                                <td class="py-2 text-xs text-gray-400"><?= htmlspecialchars(substr($img['created'] ?? '', 0, 10)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php $ipg = $images['meta']['pagination'] ?? []; if (!empty($ipg)): ?>
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-100 text-xs text-gray-500">
                    <span>Page <?= (int)$ipg['page'] ?> of <?= (int)$ipg['last_page'] ?></span>
                    <div class="flex gap-2">
                        <?php if ($ipg['previous_page']): ?>
                        <a href="?tab=images&img_page=<?= (int)$ipg['previous_page'] ?>&img_type=<?= urlencode($imgType) ?>&architecture=<?= urlencode($imgArch) ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg">&larr; Prev</a>
                        <?php endif; ?>
                        <?php if ($ipg['next_page']): ?>
                        <a href="?tab=images&img_page=<?= (int)$ipg['next_page'] ?>&img_type=<?= urlencode($imgType) ?>&architecture=<?= urlencode($imgArch) ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-8 text-gray-400"><p class="text-sm">No images found.</p></div>
                <?php endif; ?>
            </div>
            <?php elseif ($images && !empty($images['error'])): ?>
            <div class="bg-white rounded-xl border border-red-200 p-6">
                <p class="text-red-600 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i> <?= htmlspecialchars($images['error']) ?></p>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ── TAB: Pricing ────────────────────────────────────────── -->
            <?php if ($tab === 'pricing'): ?>
            <?php if ($pricing && empty($pricing['error'])): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Server Type Pricing (EUR)</h2>
                    <?php if (count($pricing['pricing']['server_types'] ?? [])): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wider">
                                    <th class="pb-2 pr-3 font-medium">Type</th>
                                    <th class="pb-2 pr-3 font-medium">Cores</th>
                                    <th class="pb-2 pr-3 font-medium">RAM</th>
                                    <th class="pb-2 pr-3 font-medium">Disk</th>
                                    <th class="pb-2 pr-3 font-medium text-right">Hourly</th>
                                    <th class="pb-2 font-medium text-right">Monthly</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pricing['pricing']['server_types'] as $stp):
                                $st = $stp['server_type'] ?? [];
                            ?>
                                <tr class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="py-2 pr-3 font-mono text-xs font-medium"><?= htmlspecialchars($st['name'] ?? '—') ?></td>
                                    <td class="py-2 pr-3 text-xs"><?= (int)($st['cores'] ?? 0) ?></td>
                                    <td class="py-2 pr-3 text-xs"><?= round($st['memory'] ?? 0) ?> GB</td>
                                    <td class="py-2 pr-3 text-xs"><?= (int)($st['disk'] ?? 0) ?> GB</td>
                                    <td class="py-2 pr-3 text-right font-mono text-xs">&euro;<?= number_format((float)($stp['price']['hourly']['gross'] ?? 0), 4) ?></td>
                                    <td class="py-2 text-right font-mono text-xs font-medium">&euro;<?= number_format((float)($stp['price']['monthly']['gross'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-gray-400">No pricing data.</p>
                    <?php endif; ?>
                </div>
                <div class="space-y-6">
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h2 class="text-base font-semibold text-gray-800 mb-4">Floating IP Pricing</h2>
                        <?php if (count($pricing['pricing']['floating_ip_types'] ?? [])): ?>
                        <div class="space-y-2">
                            <?php foreach ($pricing['pricing']['floating_ip_types'] as $fip): ?>
                            <div class="flex justify-between py-1.5 px-3 rounded bg-gray-50 text-sm">
                                <span class="font-medium"><?= htmlspecialchars($fip['name'] ?? '—') ?></span>
                                <span class="font-mono text-xs">&euro;<?= number_format((float)($fip['price']['monthly']['gross'] ?? 0), 2) ?>/mo</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h2 class="text-base font-semibold text-gray-800 mb-4">Volume Pricing</h2>
                        <?php if (count($pricing['pricing']['volumes'] ?? [])): ?>
                        <div class="space-y-2">
                            <?php foreach ($pricing['pricing']['volumes'] as $v): ?>
                            <div class="flex justify-between py-1.5 px-3 rounded bg-gray-50 text-sm">
                                <span class="font-medium">Up to <?= (int)$v['size'] ?> GB</span>
                                <span class="font-mono text-xs">&euro;<?= number_format((float)($v['price']['monthly']['gross'] ?? 0), 4) ?>/GB/mo</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h2 class="text-base font-semibold text-gray-800 mb-4">Traffic / Image / Backup</h2>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between py-1.5 px-3 rounded bg-gray-50">
                                <span>Traffic</span>
                                <span class="font-mono text-xs">&euro;<?= number_format((float)($pricing['pricing']['traffic']['price']['gross'] ?? 0), 4) ?>/TB</span>
                            </div>
                            <div class="flex justify-between py-1.5 px-3 rounded bg-gray-50">
                                <span>Image</span>
                                <span class="font-mono text-xs">&euro;<?= number_format((float)($pricing['pricing']['image']['price_per_gb_month']['gross'] ?? 0), 4) ?>/GB/mo</span>
                            </div>
                            <div class="flex justify-between py-1.5 px-3 rounded bg-gray-50">
                                <span>Backup</span>
                                <span class="font-mono text-xs"><?= (float)($pricing['pricing']['backup']['percentage'] ?? 0) ?>% of server price</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($pricing && !empty($pricing['error'])): ?>
            <div class="bg-white rounded-xl border border-red-200 p-6">
                <p class="text-red-600 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i> <?= htmlspecialchars($pricing['error']) ?></p>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ── TAB: Settings ──────────────────────────────────────── -->
            <?php if ($tab === 'settings'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <h2 class="text-base font-semibold text-gray-800 mb-4">API Configuration</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="save_token">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hetzner API Token</label>
                        <input type="password" name="hetzner_api_token" value="<?= htmlspecialchars($hetzner_token) ?>" placeholder="Paste your Hetzner API token" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Stored in system_settings. Token needs read + write permissions for server actions.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Save Token</button>
                        <?php if ($hc_connected): ?>
                        <a href="?tab=overview" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">Test Connection</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>
<?php include 'includes/admin-footer.php'; ?>
