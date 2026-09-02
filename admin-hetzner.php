<?php
/**
 * Hetzner Cloud API Dashboard — full lifecycle
 * https://docs.hetzner.cloud
 *
 * Tabs: Overview, Servers, Create, Resources, Images, Pricing, Settings
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

// ── Clients (for assignment + invoice) ──────────────────────────────
$hc_clients = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email, company FROM clients ORDER BY name");
    $stmt->execute();
    $hc_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

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
    if ($method === 'PUT')  { $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';  $opts[CURLOPT_POSTFIELDS] = $body; }
    if ($method === 'DELETE') { $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE'; }
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
function hc_api_put($token, $path, $bodyArr = []) {
    return hc_api($token, $path, 'PUT', json_encode($bodyArr));
}
function hc_api_delete($token, $path) {
    return hc_api($token, $path, 'DELETE');
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
$ssh_keys      = null;
$firewalls     = null;

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

    // Server power actions
    if (in_array($action, ['poweron','poweroff','reboot','reset','shutdown']) && $hc_connected) {
        require_csrf();
        $serverId = (int)($_POST['server_id'] ?? 0);
        if ($serverId) {
            $res = hc_api_post($hetzner_token, "/servers/$serverId/actions/$action");
            if (!empty($res['error'])) { $api_error = "Action $action failed: " . $res['error']; }
            else { $api_success = "Server #$serverId: $action command sent."; }
        }
    }

    // ── Create server ───────────────────────────────────────────────
    if ($action === 'create_server' && $hc_connected) {
        require_csrf();
        $name       = trim($_POST['name'] ?? '');
        $stype      = trim($_POST['server_type'] ?? '');
        $loc        = trim($_POST['location'] ?? '');
        $image      = trim($_POST['image'] ?? '');
        $ssh_key_ids = array_map('intval', (array)($_POST['ssh_keys'] ?? []));
        $labelsRaw  = trim($_POST['labels'] ?? '');
        $userData   = trim($_POST['user_data'] ?? '');
        $automount  = isset($_POST['automount']);
        $firewall_ids = array_map('intval', (array)($_POST['firewalls'] ?? []));

        if (!$name || !$stype || !$loc || !$image) {
            $api_error = 'Name, server type, location, and image are required.';
        } else {
            $body = [
                'name' => $name, 'server_type' => $stype,
                'location' => $loc, 'image' => $image,
            ];
            if ($ssh_key_ids) $body['ssh_keys'] = $ssh_key_ids;
            if ($labelsRaw) {
                $parsed = []; foreach (explode("\n", $labelsRaw) as $ln) {
                    $ln = trim($ln); if ($ln === '') continue;
                    $parts = explode('=', $ln, 2);
                    $parsed[trim($parts[0])] = trim($parts[1] ?? '');
                }
                $body['labels'] = $parsed;
            }
            if ($userData !== '') $body['user_data'] = base64_encode($userData);
            if ($automount) $body['automount'] = true;
            if ($firewall_ids) $body['firewalls'] = array_map(fn($id) => ['firewall' => $id], $firewall_ids);

            $res = hc_api_post($hetzner_token, '/servers', $body);
            if (!empty($res['error'])) { $api_error = 'Create failed: ' . $res['error']; }
            else {
                $pw = $res['root_password'] ?? '';
                $srvName = $res['server']['name'] ?? $name;
                $srvId = $res['server']['id'] ?? '?';
                $api_success = "Server <strong>{$srvName}</strong> (#{$srvId}) created." . ($pw ? " Root password: <code class=\"font-mono bg-gray-100 px-1\">{$pw}</code>" : '');
            }
        }
    }

    // ── Update server (rename + labels) ────────────────────────────
    if ($action === 'update_server' && $hc_connected) {
        require_csrf();
        $serverId = (int)($_POST['server_id'] ?? 0);
        $newName  = trim($_POST['new_name'] ?? '');
        if (!$serverId || !$newName) { $api_error = 'Server ID and new name required.'; }
        else {
            $res = hc_api_put($hetzner_token, "/servers/$serverId", ['name' => $newName]);
            if (!empty($res['error'])) { $api_error = 'Rename failed: ' . $res['error']; }
            else { $api_success = "Server #{$serverId} renamed to <strong>{$newName}</strong>."; }
        }
    }

    // ── Delete server ──────────────────────────────────────────────
    if ($action === 'delete_server' && $hc_connected) {
        require_csrf();
        $serverId = (int)($_POST['server_id'] ?? 0);
        $name     = trim($_POST['server_name'] ?? '');
        $confirm  = trim($_POST['confirm_name'] ?? '');
        if (!$serverId || !$confirm) { $api_error = 'Server ID and confirmation required.'; }
        elseif ($confirm !== $name) { $api_error = 'Confirmation name does not match. Type the server name exactly.'; }
        else {
            $res = hc_api_delete($hetzner_token, "/servers/$serverId");
            if (!empty($res['error'])) { $api_error = 'Delete failed: ' . $res['error']; }
            else { $api_success = "Server <strong>{$name}</strong> (#{$serverId}) deleted."; }
        }
    }

    // ── Assign to client (INSERT into assets) ──────────────────────
    if ($action === 'assign_client' && $hc_connected) {
        require_csrf();
        $serverId = (int)($_POST['server_id'] ?? 0);
        $srvName  = trim($_POST['server_name'] ?? '');
        $clientId = (int)($_POST['client_id'] ?? 0);
        $srvIp    = trim($_POST['server_ip'] ?? '');
        $srvType  = trim($_POST['server_type_name'] ?? '');
        $notes    = trim($_POST['assign_notes'] ?? '');
        $monthly  = (float)($_POST['monthly_cost'] ?? 0);

        if (!$serverId || !$clientId) { $api_error = 'Server and client are required.'; }
        else {
            try {
                $stmt = $pdo->prepare("INSERT INTO assets (client_id, name, type, serial_number, os, ip_address, managed, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $clientId,
                    $srvName ?: "Hetzner #{$serverId}",
                    'server',
                    (string)$serverId,                    // serial_number = Hetzner server ID
                    trim($_POST['server_os'] ?? '') ?: null,
                    $srvIp ?: null,
                    true,                                 // managed
                    "Hetzner {$srvType}" . ($notes ? " — {$notes}" : ''),
                ]);
                $assetId = $pdo->lastInsertId();

                // Optionally create an invoice for the monthly cost
                if ($monthly > 0) {
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(REPLACE(invoice_number, 'INV-', '') AS INTEGER)), 0) + 1 as next_num FROM invoices WHERE invoice_number ~ '^INV-[0-9]{3,5}$'");
                    $stmt->execute();
                    $next = (int)$stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
                    $invNum = 'INV-' . str_pad($next, 5, '0', STR_PAD_LEFT);
                    $items = json_encode([[
                        'description' => "Hetzner Server — {$srvName} ({$srvType})",
                        'quantity' => 1, 'unit_price' => number_format($monthly, 2, '.', ''),
                    ]]);
                    $stmt = $pdo->prepare("INSERT INTO invoices (client_id, invoice_number, amount, tax, total, status, due_date, notes, items, created_at) VALUES (?, ?, ?, ?, ?, 'unpaid', NOW() + INTERVAL '30 days', ?, ?::jsonb, NOW())");
                    $stmt->execute([$clientId, $invNum, $monthly, 0, $monthly, "Hetzner server #{$serverId} ({$srvName})", $items]);
                    $api_success = "Server assigned to client (asset #{$assetId}). Invoice <strong>{$invNum}</strong> created for &euro;" . number_format($monthly, 2) . ".";
                } else {
                    $api_success = "Server assigned to client (asset #{$assetId}).";
                }
            } catch (PDOException $e) {
                $api_error = 'Failed to assign: ' . $e->getMessage();
            }
        }
    }

    // ── Create invoice for server cost ─────────────────────────────
    if ($action === 'create_invoice' && $hc_connected) {
        require_csrf();
        $serverId = (int)($_POST['server_id'] ?? 0);
        $clientId = (int)($_POST['inv_client_id'] ?? 0);
        $srvName  = trim($_POST['server_name'] ?? '');
        $srvType  = trim($_POST['server_type_name'] ?? '');
        $amount   = (float)($_POST['inv_amount'] ?? 0);
        $notes    = trim($_POST['inv_notes'] ?? '');

        if (!$serverId || !$clientId || $amount <= 0) {
            $api_error = 'Server, client, and a positive amount required.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(REPLACE(invoice_number, 'INV-', '') AS INTEGER)), 0) + 1 as next_num FROM invoices WHERE invoice_number ~ '^INV-[0-9]{3,5}$'");
                $stmt->execute();
                $next = (int)$stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
                $invNum = 'INV-' . str_pad($next, 5, '0', STR_PAD_LEFT);
                $items = json_encode([[
                    'description' => "Hetzner Server — {$srvName} ({$srvType})",
                    'quantity' => 1, 'unit_price' => number_format($amount, 2, '.', ''),
                ]]);
                $stmt = $pdo->prepare("INSERT INTO invoices (client_id, invoice_number, amount, tax, total, status, due_date, notes, items, created_at) VALUES (?, ?, ?, ?, ?, 'unpaid', NOW() + INTERVAL '30 days', ?, ?::jsonb, NOW())");
                $stmt->execute([$clientId, $invNum, $amount, 0, $amount, ($notes ?: "Hetzner server #{$serverId} ({$srvName})"), $items]);
                $api_success = "Invoice <strong>{$invNum}</strong> created for &euro;" . number_format($amount, 2) . ".";
            } catch (PDOException $e) {
                $api_error = 'Invoice creation failed: ' . $e->getMessage();
            }
        }
    }
}

// ── GET data fetches ────────────────────────────────────────────────
if ($hc_connected) {
    // Servers list
    if ($tab === 'servers' || $tab === 'overview') {
        $sName  = trim($_GET['name'] ?? '');
        $sLabel = trim($_GET['label_selector'] ?? '');
        $sSort  = trim($_GET['sort'] ?? '');
        $sPage  = max(1, (int)($_GET['page'] ?? 1));
        $sPer   = max(10, min(50, (int)($_GET['per_page'] ?? 25)));
        $sStatus = trim($_GET['status'] ?? '');
        $params = [];
        $params[] = "page=$sPage"; $params[] = "per_page=$sPer";
        if ($sName !== '')     $params[] = 'name=' . urlencode($sName);
        if ($sLabel !== '')    $params[] = 'label_selector=' . urlencode($sLabel);
        if ($sSort !== '')     $params[] = 'sort=' . urlencode($sSort);
        if ($sStatus !== '')   $params[] = 'status=' . urlencode($sStatus);
        $servers = hc_api($hetzner_token, '/servers?' . implode('&', $params));
    }

    // Create tab — fetch SSH keys, firewalls, locations, server_types, images (system)
    if ($tab === 'create') {
        $ssh_keys    = hc_api($hetzner_token, '/ssh_keys');
        $firewalls   = hc_api($hetzner_token, '/firewalls');
        $locations   = hc_api($hetzner_token, '/locations');
        $server_types = hc_api($hetzner_token, '/server_types');
        $images      = hc_api($hetzner_token, '/images?type=system&per_page=100');
    }

    // Resources
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
                        <span class="h-2 w-2 bg-green-500 rounded-full"></span> Token Set
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">
                        <span class="h-2 w-2 bg-red-500 rounded-full"></span> No Token
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
                        'create'    => ['Create', 'fa-plus-circle'],
                        'resources' => ['Resources', 'fa-database'],
                        'images'    => ['Images', 'fa-image'],
                        'pricing'   => ['Pricing', 'fa-dollar-sign'],
                        'settings'  => ['Settings', 'fa-cog'],
                    ];
                    foreach ($tabs as $key => [$label, $icon]):
                        $active = $tab === $key;
                    ?>
                    <a href="?tab=<?= $key ?>" class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition <?= $active ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                        <i class="fas <?= $icon ?> text-xs"></i> <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- ═══ TAB: Overview ══════════════════════════════════════ -->
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
                        <div class="mt-4 flex gap-2">
                            <a href="?tab=servers" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition"><i class="fas fa-server mr-1"></i> Manage Servers</a>
                            <a href="?tab=create" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition"><i class="fas fa-plus mr-1"></i> Create Server</a>
                            <a href="?tab=settings" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition"><i class="fas fa-cog mr-1"></i> Settings</a>
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

            <!-- ═══ TAB: Servers ═══════════════════════════════════════ -->
            <?php if ($tab === 'servers'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-semibold text-gray-800">Filter Servers</h2>
                    <span class="text-xs text-gray-400">Total: <?= (int)($servers['meta']['pagination']['total_entries'] ?? '—') ?></span>
                </div>
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <input type="hidden" name="tab" value="servers">
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Name</label><input type="text" name="name" value="<?= htmlspecialchars($_GET['name'] ?? '') ?>" placeholder="e.g. my-server" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Status</label><select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                        <option value="">All</option><option value="running" <?= ($_GET['status'] ?? '') === 'running' ? 'selected' : '' ?>>Running</option><option value="off" <?= ($_GET['status'] ?? '') === 'off' ? 'selected' : '' ?>>Off</option><option value="starting" <?= ($_GET['status'] ?? '') === 'starting' ? 'selected' : '' ?>>Starting</option><option value="stopping" <?= ($_GET['status'] ?? '') === 'stopping' ? 'selected' : '' ?>>Stopping</option>
                    </select></div>
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Label Selector</label><input type="text" name="label_selector" value="<?= htmlspecialchars($_GET['label_selector'] ?? '') ?>" placeholder="e.g. env=prod" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Sort</label><select name="sort" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                        <option value="">Default</option><option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name ↑</option><option value="-name" <?= ($_GET['sort'] ?? '') === '-name' ? 'selected' : '' ?>>Name ↓</option><option value="created" <?= ($_GET['sort'] ?? '') === 'created' ? 'selected' : '' ?>>Created ↑</option><option value="-created" <?= ($_GET['sort'] ?? '') === '-created' ? 'selected' : '' ?>>Created ↓</option>
                    </select></div>
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
                            $st = $sv['server_type']['name'] ?? '';
                            $locName = $sv['datacenter']['location']['name'] ?? '';
                        ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50">
                                <td class="py-2 pr-3 font-mono text-xs"><?= $sid ?></td>
                                <td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars($sv['name']) ?></td>
                                <td class="py-2 pr-3 font-mono text-xs text-gray-500"><?= htmlspecialchars($st) ?></td>
                                <td class="py-2 pr-3 text-xs text-gray-500"><?= htmlspecialchars(strtoupper($locName)) ?></td>
                                <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars($ipv4 ?: ($ipv6 ? substr($ipv6,0,12).'…' : '—')) ?></td>
                                <td class="py-2 pr-3">
                                    <span class="inline-flex items-center gap-1.5 text-xs">
                                        <span class="h-2 w-2 rounded-full <?= $sv['status'] === 'running' ? 'bg-green-500' : ($sv['status'] === 'off' ? 'bg-red-400' : 'bg-yellow-400') ?>"></span>
                                        <?= $sv['status'] ?>
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <div class="flex items-center justify-end gap-1 flex-wrap">
                                    <?php if ($sv['status'] === 'running'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="poweroff"><input type="hidden" name="server_id" value="<?= $sid ?>"><?= csrf_field() ?>
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-medium" title="Power Off" onclick="return confirm('Power off <?= htmlspecialchars($sv['name']) ?>?')"><i class="fas fa-power-off mr-0.5"></i>Off</button>
                                    </form>
                                    <form method="POST" class="inline ml-1">
                                        <input type="hidden" name="action" value="reboot"><input type="hidden" name="server_id" value="<?= $sid ?>"><?= csrf_field() ?>
                                        <button type="submit" class="text-yellow-600 hover:text-yellow-800 text-xs font-medium" title="Reboot" onclick="return confirm('Reboot <?= htmlspecialchars($sv['name']) ?>?')"><i class="fas fa-sync-alt mr-0.5"></i>Reboot</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="poweron"><input type="hidden" name="server_id" value="<?= $sid ?>"><?= csrf_field() ?>
                                        <button type="submit" class="text-green-600 hover:text-green-800 text-xs font-medium"><i class="fas fa-play mr-0.5"></i>On</button>
                                    </form>
                                    <?php endif; ?>
                                    <button onclick="showRename(<?= $sid ?>,'<?= htmlspecialchars($sv['name'], ENT_QUOTES) ?>')" class="text-blue-600 hover:text-blue-800 text-xs font-medium ml-1" title="Rename"><i class="fas fa-pen mr-0.5"></i></button>
                                    <button onclick="showAssign(<?= $sid ?>,'<?= htmlspecialchars($sv['name'], ENT_QUOTES) ?>','<?= htmlspecialchars($st, ENT_QUOTES) ?>','<?= htmlspecialchars($ipv4, ENT_QUOTES) ?>')" class="text-purple-600 hover:text-purple-800 text-xs font-medium ml-1" title="Assign to client"><i class="fas fa-user-plus mr-0.5"></i></button>
                                    <button onclick="showInvoice(<?= $sid ?>,'<?= htmlspecialchars($sv['name'], ENT_QUOTES) ?>','<?= htmlspecialchars($st, ENT_QUOTES) ?>')" class="text-emerald-600 hover:text-emerald-800 text-xs font-medium ml-1" title="Create invoice"><i class="fas fa-file-invoice mr-0.5"></i></button>
                                    <button onclick="showDelete(<?= $sid ?>,'<?= htmlspecialchars($sv['name'], ENT_QUOTES) ?>')" class="text-red-600 hover:text-red-800 text-xs font-medium ml-1" title="Delete server"><i class="fas fa-trash mr-0.5"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

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
                <div class="text-center py-8 text-gray-400"><i class="fas fa-server text-3xl mb-2 block"></i><p class="text-sm">No servers found.</p></div>
                <?php endif; ?>
            </div>
            <?php elseif ($servers && !empty($servers['error'])): ?>
            <div class="bg-white rounded-xl border border-red-200 p-6"><p class="text-red-600 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i> <?= htmlspecialchars($servers['error']) ?></p></div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- ═══ TAB: Create Server ═════════════════════════════════ -->
            <?php if ($tab === 'create'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-3xl">
                <h2 class="text-base font-semibold text-gray-800 mb-4">Create New Server</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_server">
                    <?= csrf_field() ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                            <input type="text" name="name" required placeholder="my-server" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Server Type *</label>
                            <select name="server_type" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                                <option value="">— Select type —</option>
                                <?php if (!empty($server_types['server_types'])): foreach ($server_types['server_types'] as $st): ?>
                                <option value="<?= htmlspecialchars($st['name']) ?>"><?= htmlspecialchars($st['name']) ?> (<?= $st['cores'] ?>C · <?= round($st['memory']) ?>GB · <?= $st['disk'] ?>GB)</option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Location *</label>
                            <select name="location" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                                <option value="">— Select location —</option>
                                <?php if (!empty($locations['locations'])): foreach ($locations['locations'] as $loc): ?>
                                <option value="<?= htmlspecialchars($loc['name']) ?>"><?= htmlspecialchars(strtoupper($loc['name'])) ?> — <?= htmlspecialchars($loc['city'] . ', ' . $loc['country']) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Image *</label>
                            <select name="image" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                                <option value="">— Select image —</option>
                                <?php if (!empty($images['images'])): foreach ($images['images'] as $img):
                                    $label = $img['name'] ?: $img['description'];
                                ?>
                                <option value="<?= htmlspecialchars($img['name'] ?: $img['id']) ?>"><?= htmlspecialchars($label) ?> (<?= htmlspecialchars($img['architecture'] ?? 'x86') ?>)</option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">SSH Keys</label>
                            <select name="ssh_keys[]" multiple class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500 min-h-[80px]">
                                <?php if (!empty($ssh_keys['ssh_keys'])): foreach ($ssh_keys['ssh_keys'] as $k): ?>
                                <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['name']) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Ctrl+click to select multiple.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Firewalls</label>
                            <select name="firewalls[]" multiple class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500 min-h-[80px]">
                                <?php if (!empty($firewalls['firewalls'])): foreach ($firewalls['firewalls'] as $fw): ?>
                                <option value="<?= (int)$fw['id'] ?>"><?= htmlspecialchars($fw['name']) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Labels (one per line, key=value)</label>
                        <textarea name="labels" rows="2" placeholder="env=prod&#10;role=web" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500 font-mono"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">User Data (cloud-init)</label>
                        <textarea name="user_data" rows="3" placeholder="#cloud-config&#10;packages:&#10;  - nginx" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500 font-mono"></textarea>
                    </div>
                    <div class="flex items-center gap-6">
                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="automount" value="1" class="rounded border-gray-300"> Automount volumes</label>
                    </div>
                    <div class="flex items-center gap-3 pt-2 border-t border-gray-100">
                        <button type="submit" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold transition"><i class="fas fa-plus-circle mr-1"></i> Create Server</button>
                        <p class="text-xs text-gray-400">Charges apply. Created in <strong>TEST</strong> environment.</p>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ═══ TAB: Resources ═════════════════════════════════════ -->
            <?php if ($tab === 'resources'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Locations</h2>
                    <?php if ($locations && empty($locations['error']) && count($locations['locations'] ?? [])): ?>
                    <div class="space-y-2"><?php foreach ($locations['locations'] as $loc): ?><div class="flex justify-between items-center py-1.5 px-3 rounded bg-gray-50 text-sm"><span class="font-mono text-xs font-medium"><?= htmlspecialchars(strtoupper($loc['name'])) ?></span><span class="text-xs text-gray-500"><?= htmlspecialchars($loc['city'] . ', ' . $loc['country']) ?></span></div><?php endforeach; ?></div>
                    <?php else: ?><p class="text-sm text-gray-400">No locations data.</p><?php endif; ?>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Datacenters</h2>
                    <?php if ($datacenters && empty($datacenters['error']) && count($datacenters['datacenters'] ?? [])): ?>
                    <div class="space-y-2"><?php foreach ($datacenters['datacenters'] as $dc): ?><div class="flex justify-between items-center py-1.5 px-3 rounded bg-gray-50 text-sm"><span class="font-mono text-xs font-medium"><?= htmlspecialchars($dc['name']) ?></span><span class="text-xs text-gray-500"><?= htmlspecialchars($dc['description']) ?></span></div><?php endforeach; ?></div>
                    <?php else: ?><p class="text-sm text-gray-400">No datacenter data.</p><?php endif; ?>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Server Types</h2>
                    <?php if ($server_types && empty($server_types['error']) && count($server_types['server_types'] ?? [])): ?>
                    <div class="overflow-y-auto max-h-96 space-y-1"><?php foreach ($server_types['server_types'] as $st): ?><div class="py-1.5 px-3 rounded bg-gray-50 text-sm flex justify-between"><span class="font-mono text-xs font-medium"><?= htmlspecialchars($st['name']) ?></span><span class="text-xs text-gray-500"><?= $st['cores'] ?>C · <?= round($st['memory']) ?>GB · <?= $st['disk'] ?>GB</span></div><?php endforeach; ?></div>
                    <?php else: ?><p class="text-sm text-gray-400">No server type data.</p><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ TAB: Images ════════════════════════════════════════ -->
            <?php if ($tab === 'images'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="hidden" name="tab" value="images">
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Type</label><select name="img_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"><option value="">All</option><option value="system" <?= ($_GET['img_type'] ?? '') === 'system' ? 'selected' : '' ?>>System</option><option value="app" <?= ($_GET['img_type'] ?? '') === 'app' ? 'selected' : '' ?>>App</option><option value="snapshot" <?= ($_GET['img_type'] ?? '') === 'snapshot' ? 'selected' : '' ?>>Snapshot</option><option value="backup" <?= ($_GET['img_type'] ?? '') === 'backup' ? 'selected' : '' ?>>Backup</option></select></div>
                    <div><label class="block text-xs font-medium text-gray-500 mb-1">Architecture</label><select name="architecture" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"><option value="">All</option><option value="x86" <?= ($_GET['architecture'] ?? '') === 'x86' ? 'selected' : '' ?>>x86</option><option value="arm" <?= ($_GET['architecture'] ?? '') === 'arm' ? 'selected' : '' ?>>ARM</option></select></div>
                    <div class="flex items-end gap-2"><button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition"><i class="fas fa-search mr-1"></i> Filter</button><a href="?tab=images" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm transition"><i class="fas fa-times"></i></a></div>
                </form>
            </div>
            <?php if ($images && empty($images['error'])): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Images (<?= count($images['images']) ?>)</h3>
                <?php if (count($images['images']) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm"><thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wider"><th class="pb-2 pr-3 font-medium">ID</th><th class="pb-2 pr-3 font-medium">Name</th><th class="pb-2 pr-3 font-medium">OS / Flavor</th><th class="pb-2 pr-3 font-medium">Type</th><th class="pb-2 pr-3 font-medium">Arch</th><th class="pb-2 pr-3 font-medium">Size (GB)</th><th class="pb-2 font-medium">Created</th></tr></thead>
                    <tbody><?php foreach ($images['images'] as $img): ?><tr class="border-b border-gray-50 hover:bg-gray-50"><td class="py-2 pr-3 font-mono text-xs"><?= (int)$img['id'] ?></td><td class="py-2 pr-3 font-medium text-gray-800"><?= htmlspecialchars($img['name'] ?: $img['description'] ?: '—') ?></td><td class="py-2 pr-3 text-xs text-gray-600"><?= htmlspecialchars($img['os_flavor'] ?: '—') ?> <?= $img['os_version'] ?? '' ?></td><td class="py-2 pr-3 text-xs"><?= htmlspecialchars($img['type'] ?? '—') ?></td><td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars($img['architecture'] ?? '—') ?></td><td class="py-2 pr-3 text-xs"><?= round($img['image_size'] ?? 0, 1) ?> GB</td><td class="py-2 text-xs text-gray-400"><?= htmlspecialchars(substr($img['created'] ?? '', 0, 10)) ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
                <?php $ipg = $images['meta']['pagination'] ?? []; if (!empty($ipg)): ?>
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-100 text-xs text-gray-500"><span>Page <?= (int)$ipg['page'] ?> of <?= (int)$ipg['last_page'] ?></span><div class="flex gap-2"><?php if ($ipg['previous_page']): ?><a href="?tab=images&img_page=<?= (int)$ipg['previous_page'] ?>&img_type=<?= urlencode($imgType) ?>&architecture=<?= urlencode($imgArch) ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg">&larr; Prev</a><?php endif; ?><?php if ($ipg['next_page']): ?><a href="?tab=images&img_page=<?= (int)$ipg['next_page'] ?>&img_type=<?= urlencode($imgType) ?>&architecture=<?= urlencode($imgArch) ?>" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg">Next &rarr;</a><?php endif; ?></div></div>
                <?php endif; ?>
                <?php else: ?><div class="text-center py-8 text-gray-400"><p class="text-sm">No images found.</p></div><?php endif; ?>
            </div>
            <?php elseif ($images && !empty($images['error'])): ?><div class="bg-white rounded-xl border border-red-200 p-6"><p class="text-red-600 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i> <?= htmlspecialchars($images['error']) ?></p></div><?php endif; ?>
            <?php endif; ?>

            <!-- ═══ TAB: Pricing ═══════════════════════════════════════ -->
            <?php if ($tab === 'pricing'): ?>
            <?php if ($pricing && empty($pricing['error'])): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 class="text-base font-semibold text-gray-800 mb-4">Server Type Pricing (EUR)</h2>
                    <?php if (count($pricing['pricing']['server_types'] ?? [])): ?>
                    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wider"><th class="pb-2 pr-3 font-medium">Type</th><th class="pb-2 pr-3 font-medium">Cores</th><th class="pb-2 pr-3 font-medium">RAM</th><th class="pb-2 pr-3 font-medium">Disk</th><th class="pb-2 pr-3 font-medium text-right">Hourly</th><th class="pb-2 font-medium text-right">Monthly</th></tr></thead>
                    <tbody><?php foreach ($pricing['pricing']['server_types'] as $stp): $st = $stp['server_type'] ?? []; ?><tr class="border-b border-gray-50 hover:bg-gray-50"><td class="py-2 pr-3 font-mono text-xs font-medium"><?= htmlspecialchars($st['name'] ?? '—') ?></td><td class="py-2 pr-3 text-xs"><?= (int)($st['cores'] ?? 0) ?></td><td class="py-2 pr-3 text-xs"><?= round($st['memory'] ?? 0) ?> GB</td><td class="py-2 pr-3 text-xs"><?= (int)($st['disk'] ?? 0) ?> GB</td><td class="py-2 pr-3 text-right font-mono text-xs">&euro;<?= number_format((float)($stp['price']['hourly']['gross'] ?? 0), 4) ?></td><td class="py-2 text-right font-mono text-xs font-medium">&euro;<?= number_format((float)($stp['price']['monthly']['gross'] ?? 0), 2) ?></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php else: ?><p class="text-sm text-gray-400">No pricing data.</p><?php endif; ?>
                </div>
                <div class="space-y-6">
                    <div class="bg-white rounded-xl border border-gray-200 p-6"><h2 class="text-base font-semibold text-gray-800 mb-4">Floating IP Pricing</h2><?php if (count($pricing['pricing']['floating_ip_types'] ?? [])): ?><div class="space-y-2"><?php foreach ($pricing['pricing']['floating_ip_types'] as $fip): ?><div class="flex justify-between py-1.5 px-3 rounded bg-gray-50 text-sm"><span class="font-medium"><?= htmlspecialchars($fip['name'] ?? '—') ?></span><span class="font-mono text-xs">&euro;<?= number_format((float)($fip['price']['monthly']['gross'] ?? 0), 2) ?>/mo</span></div><?php endforeach; ?></div><?php endif; ?></div>
                    <div class="bg-white rounded-xl border border-gray-200 p-6"><h2 class="text-base font-semibold text-gray-800 mb-4">Volume / Traffic / Image / Backup</h2><div class="space-y-2 text-sm"><?php foreach ($pricing['pricing']['volumes'] ?? [] as $v): ?><div class="flex justify-between py-1.5 px-3 rounded bg-gray-50"><span>Up to <?= (int)$v['size'] ?> GB Volume</span><span class="font-mono text-xs">&euro;<?= number_format((float)($v['price']['monthly']['gross'] ?? 0), 4) ?>/GB/mo</span></div><?php endforeach; ?><div class="flex justify-between py-1.5 px-3 rounded bg-gray-50"><span>Traffic</span><span class="font-mono text-xs">&euro;<?= number_format((float)($pricing['pricing']['traffic']['price']['gross'] ?? 0), 4) ?>/TB</span></div><div class="flex justify-between py-1.5 px-3 rounded bg-gray-50"><span>Image</span><span class="font-mono text-xs">&euro;<?= number_format((float)($pricing['pricing']['image']['price_per_gb_month']['gross'] ?? 0), 4) ?>/GB/mo</span></div><div class="flex justify-between py-1.5 px-3 rounded bg-gray-50"><span>Backup</span><span class="font-mono text-xs"><?= (float)($pricing['pricing']['backup']['percentage'] ?? 0) ?>% of server price</span></div></div></div>
                </div>
            </div>
            <?php elseif ($pricing && !empty($pricing['error'])): ?><div class="bg-white rounded-xl border border-red-200 p-6"><p class="text-red-600 text-sm"><i class="fas fa-exclamation-triangle mr-1"></i> <?= htmlspecialchars($pricing['error']) ?></p></div><?php endif; ?>
            <?php endif; ?>

            <!-- ═══ TAB: Settings ══════════════════════════════════════ -->
            <?php if ($tab === 'settings'): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <h2 class="text-base font-semibold text-gray-800 mb-4">API Configuration</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="save_token">
                    <?= csrf_field() ?>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Hetzner API Token</label><input type="password" name="hetzner_api_token" value="<?= htmlspecialchars($hetzner_token) ?>" placeholder="Paste your Hetzner API token" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"><p class="text-xs text-gray-400 mt-1">Stored in system_settings. Needs read + write permissions for create/delete/power.</p></div>
                    <div class="flex items-center gap-3"><button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Save Token</button><?php if ($hc_connected): ?><a href="?tab=overview" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">Test Connection</a><?php endif; ?></div>
                </form>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- ═══ Modal: Rename Server ══════════════════════════════════════════ -->
<div id="renameModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-800 mb-4">Rename Server</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_server">
            <input type="hidden" name="server_id" id="renameServerId" value="">
            <?= csrf_field() ?>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">New Name</label><input type="text" name="new_name" id="renameNewName" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"></div>
            <div class="flex justify-end gap-3 mt-4"><button type="button" onclick="closeModal('renameModal')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm transition">Cancel</button><button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Rename</button></div>
        </form>
    </div>
</div>

<!-- ═══ Modal: Assign to Client ══════════════════════════════════════ -->
<div id="assignModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 max-w-lg w-full mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-800 mb-4">Assign Server to Client</h3>
        <form method="POST">
            <input type="hidden" name="action" value="assign_client">
            <input type="hidden" name="server_id" id="assignServerId" value="">
            <input type="hidden" name="server_name" id="assignServerName" value="">
            <input type="hidden" name="server_type_name" id="assignServerType" value="">
            <input type="hidden" name="server_ip" id="assignServerIp" value="">
            <?= csrf_field() ?>
            <div class="space-y-3">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Client *</label><select name="client_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"><option value="">— Select client —</option><?php foreach ($hc_clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name'] . ($c['company'] !== '' && $c['company'] !== $c['name'] ? ' — ' . $c['company'] : '')) ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Server Name (asset)</label><input type="text" name="server_name" id="assignServerNameDisp" readonly class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Monthly Cost (EUR) <span class="text-gray-400 font-normal">— creates invoice</span></label>
                    <input type="number" name="monthly_cost" step="0.01" min="0" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    <p class="text-xs text-gray-400 mt-1">Enter the monthly cost to auto-create an unpaid invoice. Leave 0 for assignment only.</p>
                </div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Notes</label><textarea name="assign_notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"></textarea></div>
            </div>
            <div class="flex justify-end gap-3 mt-4"><button type="button" onclick="closeModal('assignModal')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm transition">Cancel</button><button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition">Assign &amp; Invoice</button></div>
        </form>
    </div>
</div>

<!-- ═══ Modal: Create Invoice ════════════════════════════════════════ -->
<div id="invoiceModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-800 mb-4">Create Invoice for Server</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_invoice">
            <input type="hidden" name="server_id" id="invServerId" value="">
            <input type="hidden" name="server_name" id="invServerName" value="">
            <input type="hidden" name="server_type_name" id="invServerType" value="">
            <?= csrf_field() ?>
            <div class="space-y-3">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Client *</label><select name="inv_client_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"><option value="">— Select client —</option><?php foreach ($hc_clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name'] . ($c['company'] !== '' && $c['company'] !== $c['name'] ? ' — ' . $c['company'] : '')) ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Amount (EUR) *</label><input type="number" name="inv_amount" step="0.01" min="0.01" required placeholder="e.g. 8.99" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Notes</label><textarea name="inv_notes" rows="2" placeholder="Optional note on invoice" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500"></textarea></div>
            </div>
            <div class="flex justify-end gap-3 mt-4"><button type="button" onclick="closeModal('invoiceModal')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm transition">Cancel</button><button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition">Create Invoice</button></div>
        </form>
    </div>
</div>

<!-- ═══ Modal: Delete Server ═════════════════════════════════════════ -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-red-700 mb-2">⚠️ Delete Server</h3>
        <p class="text-sm text-gray-600 mb-4">This immediately removes the server from your Hetzner account. Type the server name to confirm.</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete_server">
            <input type="hidden" name="server_id" id="delServerId" value="">
            <input type="hidden" name="server_name" id="delServerName" value="">
            <?= csrf_field() ?>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Type <span id="delNameLabel" class="font-mono font-bold"></span> to confirm</label><input type="text" name="confirm_name" required placeholder="Type the server name" class="w-full border border-red-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-red-500 font-mono"></div>
            <div class="flex justify-end gap-3 mt-4"><button type="button" onclick="closeModal('deleteModal')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm transition">Cancel</button><button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium transition">Delete Permanently</button></div>
        </form>
    </div>
</div>

<script>
function showRename(id, name) {
    document.getElementById('renameServerId').value = id;
    document.getElementById('renameNewName').value = name;
    document.getElementById('renameModal').classList.remove('hidden');
}
function showAssign(id, name, type, ip) {
    document.getElementById('assignServerId').value = id;
    document.getElementById('assignServerName').value = name;
    document.getElementById('assignServerNameDisp').value = name;
    document.getElementById('assignServerType').value = type;
    document.getElementById('assignServerIp').value = ip;
    document.getElementById('assignModal').classList.remove('hidden');
}
function showInvoice(id, name, type) {
    document.getElementById('invServerId').value = id;
    document.getElementById('invServerName').value = name;
    document.getElementById('invServerType').value = type;
    document.getElementById('invoiceModal').classList.remove('hidden');
}
function showDelete(id, name) {
    document.getElementById('delServerId').value = id;
    document.getElementById('delServerName').value = name;
    document.getElementById('delNameLabel').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
</script>

<?php include 'includes/admin-footer.php'; ?>
