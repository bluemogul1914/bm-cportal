<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin   = true;
$pdo        = getDB();

// ── Load API key (env constant first, fall back to provider_settings DB row) ──
$hw_api_key = HOSTWINDS_API_KEY;
if (empty($hw_api_key)) {
    try {
        $r = $pdo->prepare("SELECT key_value FROM provider_settings WHERE provider='hostwinds' AND key_name='api_key'");
        $r->execute();
        $hw_api_key = $r->fetchColumn() ?: '';
    } catch (Exception $e) {}
}
$hw_connected = !empty($hw_api_key);
$hw_base      = rtrim(HOSTWINDS_API_URL, '/');

$success_msg = '';
$error_msg   = '';
$tab         = $_GET['tab'] ?? 'servers';

// ── Core API helper ────────────────────────────────────────────────────────────
function hw_api(string $method, string $path, ?array $body = null): array {
    global $hw_api_key, $hw_base;
    $url = strpos($path, 'http') === 0 ? $path : $hw_base . $path;
    $ch  = curl_init();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'accesskey: ' . $hw_api_key,
    ];
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    $method = strtoupper($method);
    if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } else {
            $opts[CURLOPT_POSTFIELDS] = '';
        }
    }
    curl_setopt_array($ch, $opts);
    $resp      = curl_exec($ch);
    $code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return ['error' => $curl_err, 'http_code' => 0];
    if ($code < 200 || $code >= 300) {
        $d = json_decode($resp, true);
        $msg = $d['errors'][0]['message'] ?? $d['message'] ?? $d['error'] ?? "HTTP $code";
        return ['error' => $msg, 'http_code' => $code];
    }
    $decoded = json_decode($resp, true);
    if ($decoded === null) return ['error' => 'Invalid JSON response', 'http_code' => $code, 'raw' => substr($resp, 0, 200)];
    return array_merge(['http_code' => $code], is_array($decoded) ? $decoded : ['data' => $decoded]);
}

// ── POST action handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // Save API key
        if ($action === 'save_api_key') {
            $key = trim($_POST['hw_api_key'] ?? '');
            try {
                $pdo->prepare("INSERT INTO provider_settings (provider, key_name, key_value) VALUES ('hostwinds','api_key',?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")
                    ->execute([$key]);
                $hw_api_key   = $key;
                $hw_connected = !empty($key);
                $success_msg  = 'Hostwinds API key saved.';
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
            }
            $tab = 'settings';

        // Test connection
        } elseif ($action === 'test_connection') {
            $res = hw_api('GET', '/vps');
            if (isset($res['error'])) {
                $error_msg = 'Connection failed: ' . $res['error'];
            } else {
                $count = count($res['vps'] ?? $res['servers'] ?? $res['data'] ?? []);
                $success_msg = "Connected! Found $count VPS server(s) in your account.";
            }

        // Server power actions
        } elseif (in_array($action, ['reboot_server','start_server','stop_server'])) {
            $sid      = (int)($_POST['server_id'] ?? 0);
            $verb     = str_replace('_server', '', $action);
            $res      = hw_api('POST', "/vps/$sid/$verb");
            if (isset($res['error'])) {
                $error_msg = "Could not $verb server #$sid: " . $res['error'];
            } else {
                $success_msg = ucfirst($verb) . " command sent to server #$sid.";
            }
            $tab = 'servers';

        // Create snapshot
        } elseif ($action === 'create_snapshot') {
            $sid  = (int)($_POST['server_id'] ?? 0);
            $name = trim($_POST['snap_name'] ?? 'snapshot-' . date('Ymd-His'));
            $res  = hw_api('POST', "/snapshot", ['vpsid' => $sid, 'name' => $name]);
            if (isset($res['error'])) {
                $error_msg = 'Snapshot failed: ' . $res['error'];
            } else {
                $success_msg = "Snapshot \"$name\" created for server #$sid.";
            }
            $tab = 'snapshots';

        // Delete snapshot
        } elseif ($action === 'delete_snapshot') {
            $snap_id = (int)($_POST['snapshot_id'] ?? 0);
            $res     = hw_api('DELETE', "/snapshot/$snap_id");
            if (isset($res['error'])) {
                $error_msg = 'Delete failed: ' . $res['error'];
            } else {
                $success_msg = "Snapshot #$snap_id deleted.";
            }
            $tab = 'snapshots';

        // Add SSH key
        } elseif ($action === 'add_ssh_key') {
            $name    = trim($_POST['key_name'] ?? '');
            $pub_key = trim($_POST['public_key'] ?? '');
            if (!$name || !$pub_key) {
                $error_msg = 'Name and public key are required.';
            } else {
                $res = hw_api('POST', '/sshkey', ['name' => $name, 'key' => $pub_key]);
                if (isset($res['error'])) {
                    $error_msg = 'Failed to add SSH key: ' . $res['error'];
                } else {
                    $success_msg = "SSH key \"$name\" added.";
                }
            }
            $tab = 'sshkeys';

        // Delete SSH key
        } elseif ($action === 'delete_ssh_key') {
            $kid = (int)($_POST['key_id'] ?? 0);
            $res = hw_api('DELETE', "/sshkey/$kid");
            if (isset($res['error'])) {
                $error_msg = 'Delete failed: ' . $res['error'];
            } else {
                $success_msg = "SSH key #$kid removed.";
            }
            $tab = 'sshkeys';
        }
    }
}

// ── Live data per tab ──────────────────────────────────────────────────────────
$servers   = [];
$snapshots = [];
$ssh_keys  = [];
$invoices  = [];
$networks  = [];
$os_list   = [];

$api_error = '';

if ($hw_connected) {
    if ($tab === 'servers') {
        $res = hw_api('GET', '/vps');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $servers = $res['vps'] ?? $res['servers'] ?? $res['data'] ?? []; }
    } elseif ($tab === 'snapshots') {
        $res = hw_api('GET', '/snapshot');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $snapshots = $res['snapshots'] ?? $res['data'] ?? []; }
    } elseif ($tab === 'sshkeys') {
        $res = hw_api('GET', '/sshkey');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $ssh_keys = $res['sshkeys'] ?? $res['data'] ?? []; }
    } elseif ($tab === 'billing') {
        $res = hw_api('GET', '/billing');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $invoices = $res['invoices'] ?? $res['data'] ?? []; }
    } elseif ($tab === 'network') {
        $res = hw_api('GET', '/network');
        if (isset($res['error'])) { $api_error = $res['error']; }
        else { $networks = $res['networks'] ?? $res['data'] ?? []; }
    }
}

// Summary stats (pulled from servers list for use on any tab)
$total_servers  = count($servers);
$active_count   = 0;
$stopped_count  = 0;
$monthly_total  = 0;
foreach ($servers as $s) {
    $st = strtolower($s['status'] ?? $s['state'] ?? '');
    if (in_array($st, ['running','active','online'])) $active_count++;
    if (in_array($st, ['stopped','offline','halted'])) $stopped_count++;
    $monthly_total += (float)($s['price_monthly'] ?? $s['billing_monthly'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hostwinds Cloud - Blue Mogul Admin</title>
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

        <!-- ── Page Header ── -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">
                        <i class="fas fa-server text-cyan-500 mr-2"></i>Hostwinds Cloud Integration
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">VPS management &mdash; Servers, snapshots, SSH keys, billing, and networking</p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($hw_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test-connection">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <a href="?tab=servers" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-refresh">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-key mr-1"></i>Add API Key
                        </a>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Not Connected
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">

            <!-- Alerts -->
            <?php if ($success_msg): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-3"></i><span><?= htmlspecialchars($success_msg) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-3"></i><span><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($api_error): ?>
                <div class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-exclamation-triangle mr-3"></i>
                    <span>Hostwinds API returned an error: <strong><?= htmlspecialchars($api_error) ?></strong> — Data may be stale or credentials may need updating.</span>
                </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($hw_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">API key configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">HOSTWINDS_API_KEY not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-servers">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Servers</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-servers"><?= $hw_connected ? ($total_servers ?: '—') : '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1">VPS instances</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-running">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Running</p>
                    <p class="text-2xl font-bold text-green-600" data-testid="text-running"><?= $hw_connected ? ($active_count ?: '—') : '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $total_servers > 0 ? round(($active_count/$total_servers)*100).'% active' : 'No data' ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-monthly-cost">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Monthly Cost</p>
                    <p class="text-2xl font-bold text-cyan-700" data-testid="text-monthly"><?= $monthly_total > 0 ? '$'.number_format($monthly_total,2) : '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1">Combined VPS spend</p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'servers'   => ['fas fa-server',      'Servers'],
                    'snapshots' => ['fas fa-camera',       'Snapshots'],
                    'sshkeys'   => ['fas fa-key',          'SSH Keys'],
                    'network'   => ['fas fa-network-wired','Network'],
                    'billing'   => ['fas fa-receipt',      'Billing'],
                    'settings'  => ['fas fa-cog',          'Settings'],
                ];
                foreach ($tabs as $t => [$icon, $label]):
                    $active = $tab === $t;
                ?>
                <a href="?tab=<?= $t ?>"
                   class="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition <?= $active ? 'bg-white text-cyan-700 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                   data-testid="tab-<?= $t ?>">
                    <i class="<?= $icon ?> text-xs"></i><?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$hw_connected && $tab !== 'settings'): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 text-center">
                <i class="fas fa-server text-blue-300 text-4xl mb-3"></i>
                <h3 class="text-base font-semibold text-blue-800 mb-2">Hostwinds API Key Required</h3>
                <p class="text-sm text-blue-600 mb-4">Add your Hostwinds API key to start managing your VPS servers, snapshots, SSH keys, and billing from this dashboard.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fas fa-key mr-2"></i>Configure API Key
                </a>
            </div>
            <?php else: ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SERVERS TAB                                                -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'servers'): ?>
            <div class="space-y-4">

                <?php if (empty($servers) && !$api_error): ?>
                <div class="bg-white rounded-lg border border-gray-200 p-10 text-center text-gray-400">
                    <i class="fas fa-server text-4xl mb-3"></i>
                    <p class="font-medium">No servers found in this account.</p>
                    <p class="text-sm mt-1">Create your first VPS at <a href="https://www.hostwinds.com" target="_blank" class="text-blue-500 hover:underline">hostwinds.com</a></p>
                </div>
                <?php endif; ?>

                <?php foreach ($servers as $idx => $s):
                    $sid    = $s['vpsid'] ?? $s['id'] ?? $idx;
                    $sname  = $s['label'] ?? $s['name'] ?? "Server #$sid";
                    $st     = strtolower($s['status'] ?? $s['state'] ?? 'unknown');
                    $is_run = in_array($st, ['running','active','online']);
                    $is_stp = in_array($st, ['stopped','offline','halted','shutdown']);
                    $ip     = $s['main_ip'] ?? $s['ip'] ?? $s['ipv4'] ?? '—';
                    $os     = $s['os'] ?? $s['operating_system'] ?? '—';
                    $ram    = $s['ram'] ?? $s['memory_mb'] ?? null;
                    $vcpu   = $s['vcpus'] ?? $s['cpu'] ?? null;
                    $disk   = $s['storage'] ?? $s['disk_gb'] ?? null;
                    $price  = $s['price_monthly'] ?? $s['billing_monthly'] ?? null;
                    $region = $s['location'] ?? $s['datacenter'] ?? $s['region'] ?? '—';
                ?>
                <div class="bg-white rounded-lg border border-gray-200" data-testid="card-server-<?= htmlspecialchars((string)$sid) ?>">
                    <div class="px-5 py-4 flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= $is_run ? 'bg-green-100 text-green-600' : ($is_stp ? 'bg-red-100 text-red-500' : 'bg-gray-100 text-gray-500') ?>">
                                <i class="fas fa-server"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900" data-testid="text-server-name-<?= $sid ?>"><?= htmlspecialchars($sname) ?></p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    ID: <?= htmlspecialchars((string)$sid) ?> &nbsp;·&nbsp;
                                    <?= htmlspecialchars($ip) ?> &nbsp;·&nbsp;
                                    <?= htmlspecialchars($region) ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Status badge -->
                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $is_run ? 'bg-green-100 text-green-700' : ($is_stp ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') ?>" data-testid="status-server-<?= $sid ?>">
                                <span class="inline-block w-1.5 h-1.5 rounded-full mr-1 <?= $is_run ? 'bg-green-500' : ($is_stp ? 'bg-red-400' : 'bg-gray-400') ?>"></span>
                                <?= ucfirst($st) ?>
                            </span>
                            <!-- Power actions -->
                            <?php if ($is_run): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Reboot <?= htmlspecialchars(addslashes($sname)) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reboot_server">
                                <input type="hidden" name="server_id" value="<?= $sid ?>">
                                <button type="submit" class="px-2 py-1 bg-yellow-100 hover:bg-yellow-200 text-yellow-700 rounded text-xs font-medium transition" data-testid="button-reboot-<?= $sid ?>">
                                    <i class="fas fa-redo-alt mr-1"></i>Reboot
                                </button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Stop <?= htmlspecialchars(addslashes($sname)) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="stop_server">
                                <input type="hidden" name="server_id" value="<?= $sid ?>">
                                <button type="submit" class="px-2 py-1 bg-red-100 hover:bg-red-200 text-red-700 rounded text-xs font-medium transition" data-testid="button-stop-<?= $sid ?>">
                                    <i class="fas fa-stop mr-1"></i>Stop
                                </button>
                            </form>
                            <?php elseif ($is_stp): ?>
                            <form method="POST" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="start_server">
                                <input type="hidden" name="server_id" value="<?= $sid ?>">
                                <button type="submit" class="px-2 py-1 bg-green-100 hover:bg-green-200 text-green-700 rounded text-xs font-medium transition" data-testid="button-start-<?= $sid ?>">
                                    <i class="fas fa-play mr-1"></i>Start
                                </button>
                            </form>
                            <?php endif; ?>
                            <!-- Snapshot shortcut -->
                            <button type="button" onclick="openSnapForm(<?= $sid ?>, '<?= htmlspecialchars(addslashes($sname)) ?>')"
                                class="px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 rounded text-xs font-medium transition" data-testid="button-snapshot-<?= $sid ?>">
                                <i class="fas fa-camera mr-1"></i>Snapshot
                            </button>
                        </div>
                    </div>

                    <!-- Server specs row -->
                    <div class="px-5 pb-4 grid grid-cols-2 md:grid-cols-5 gap-3">
                        <div class="bg-gray-50 rounded p-2 text-center">
                            <p class="text-xs text-gray-400 uppercase font-semibold mb-1">vCPU</p>
                            <p class="text-sm font-bold text-gray-800"><?= $vcpu ? htmlspecialchars((string)$vcpu) : '—' ?></p>
                        </div>
                        <div class="bg-gray-50 rounded p-2 text-center">
                            <p class="text-xs text-gray-400 uppercase font-semibold mb-1">RAM</p>
                            <p class="text-sm font-bold text-gray-800"><?= $ram ? number_format($ram/1024,1).' GB' : '—' ?></p>
                        </div>
                        <div class="bg-gray-50 rounded p-2 text-center">
                            <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Storage</p>
                            <p class="text-sm font-bold text-gray-800"><?= $disk ? htmlspecialchars((string)$disk).' GB' : '—' ?></p>
                        </div>
                        <div class="bg-gray-50 rounded p-2 text-center">
                            <p class="text-xs text-gray-400 uppercase font-semibold mb-1">OS</p>
                            <p class="text-sm font-bold text-gray-800 truncate"><?= htmlspecialchars($os) ?></p>
                        </div>
                        <div class="bg-gray-50 rounded p-2 text-center">
                            <p class="text-xs text-gray-400 uppercase font-semibold mb-1">Price/mo</p>
                            <p class="text-sm font-bold text-cyan-700"><?= $price ? '$'.number_format((float)$price,2) : '—' ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Snapshot modal -->
            <div id="snap-modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-camera text-blue-500 mr-2"></i>Create Snapshot</h3>
                    <form method="POST" id="snap-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_snapshot">
                        <input type="hidden" name="server_id" id="snap-server-id">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Server</label>
                            <p class="text-sm text-gray-500" id="snap-server-name"></p>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Snapshot Name</label>
                            <input type="text" name="snap_name" id="snap-name-input" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="backup-2026-04-01">
                        </div>
                        <div class="flex gap-3 justify-end">
                            <button type="button" onclick="closeSnapForm()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 transition">Cancel</button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-confirm-snapshot">
                                <i class="fas fa-camera mr-1"></i>Create Snapshot
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SNAPSHOTS TAB                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'snapshots'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-camera text-blue-500 mr-2"></i>Snapshots</h2>
                    <span class="text-xs text-gray-400"><?= count($snapshots) ?> total</span>
                </div>

                <?php if (empty($snapshots) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-camera text-4xl mb-3"></i>
                    <p class="font-medium">No snapshots found.</p>
                    <p class="text-sm mt-1">Create a snapshot from the Servers tab to back up a VPS.</p>
                </div>
                <?php elseif (!empty($snapshots)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-snapshots">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Server</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Size</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Created</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($snapshots as $snap):
                                $snid  = $snap['snapshotid'] ?? $snap['id'] ?? '—';
                                $snname = $snap['name'] ?? $snap['label'] ?? "Snapshot #$snid";
                                $snsrv = $snap['vpsid'] ?? $snap['server_id'] ?? '—';
                                $snsize = $snap['size_mb'] ?? null;
                                $snst  = $snap['status'] ?? '—';
                                $sncr  = $snap['date_created'] ?? $snap['created_at'] ?? null;
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-snapshot-<?= htmlspecialchars((string)$snid) ?>">
                                <td class="px-4 py-3 text-gray-400 font-mono text-xs"><?= htmlspecialchars((string)$snid) ?></td>
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($snname) ?></td>
                                <td class="px-4 py-3 text-gray-500">VPS #<?= htmlspecialchars((string)$snsrv) ?></td>
                                <td class="px-4 py-3 text-gray-600"><?= $snsize ? number_format($snsize/1024,2).' GB' : '—' ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= strtolower($snst)==='complete'?'bg-green-100 text-green-700':'bg-gray-100 text-gray-600' ?>">
                                        <?= htmlspecialchars(ucfirst($snst)) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs"><?= $sncr ? date('M d Y, g:i A', strtotime($sncr)) : '—' ?></td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this snapshot? This cannot be undone.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_snapshot">
                                        <input type="hidden" name="snapshot_id" value="<?= $snid ?>">
                                        <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium" data-testid="button-delete-snap-<?= $snid ?>">
                                            <i class="fas fa-trash mr-1"></i>Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SSH KEYS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'sshkeys'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Key list -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-key text-yellow-500 mr-2"></i>SSH Keys</h2>
                        <span class="text-xs text-gray-400"><?= count($ssh_keys) ?> key(s)</span>
                    </div>
                    <?php if (empty($ssh_keys) && !$api_error): ?>
                    <div class="p-10 text-center text-gray-400">
                        <i class="fas fa-key text-4xl mb-3"></i>
                        <p class="font-medium">No SSH keys on file.</p>
                        <p class="text-sm mt-1">Add a public key using the form on the right.</p>
                    </div>
                    <?php elseif (!empty($ssh_keys)): ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($ssh_keys as $key):
                            $kid    = $key['sshkeyid'] ?? $key['id'] ?? '—';
                            $kname  = $key['name'] ?? "Key #$kid";
                            $kkey   = $key['ssh_key'] ?? $key['public_key'] ?? '';
                            $kfp    = $key['fingerprint'] ?? '';
                        ?>
                        <div class="px-5 py-4 flex items-start justify-between gap-4" data-testid="row-sshkey-<?= htmlspecialchars((string)$kid) ?>">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-900"><?= htmlspecialchars($kname) ?></p>
                                <?php if ($kfp): ?><p class="text-xs text-gray-400 font-mono mt-1"><?= htmlspecialchars($kfp) ?></p><?php endif; ?>
                                <?php if ($kkey): ?><p class="text-xs text-gray-300 font-mono mt-1 truncate"><?= htmlspecialchars(substr($kkey,0,60)).'…' ?></p><?php endif; ?>
                            </div>
                            <form method="POST" class="inline shrink-0" onsubmit="return confirm('Remove SSH key \"<?= htmlspecialchars(addslashes($kname)) ?>\"?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_ssh_key">
                                <input type="hidden" name="key_id" value="<?= $kid ?>">
                                <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium" data-testid="button-delete-key-<?= $kid ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Add key form -->
                <div class="bg-white rounded-lg border border-gray-200 p-5 self-start">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plus text-green-500 mr-2"></i>Add SSH Key</h3>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_ssh_key">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Key Name</label>
                            <input type="text" name="key_name" required placeholder="e.g. macbook-pro" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-ssh-key-name">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Public Key</label>
                            <textarea name="public_key" required rows="5" placeholder="ssh-rsa AAAA... user@host" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-ssh-public-key"></textarea>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-add-ssh-key">
                            <i class="fas fa-plus mr-2"></i>Add Key
                        </button>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- NETWORK TAB                                                -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'network'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-network-wired text-purple-500 mr-2"></i>Networks</h2>
                    <span class="text-xs text-gray-400"><?= count($networks) ?> network(s)</span>
                </div>
                <?php if (empty($networks) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-network-wired text-4xl mb-3"></i>
                    <p class="font-medium">No private networks found.</p>
                    <p class="text-sm mt-1">Create private networks via your <a href="https://manage.hostwinds.com/cloud" target="_blank" class="text-blue-500 hover:underline">Hostwinds control panel</a>.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-networks">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">CIDR</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Location</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($networks as $net):
                                $nid  = $net['networkid'] ?? $net['id'] ?? '—';
                                $nn   = $net['name'] ?? $net['label'] ?? "Net #$nid";
                                $cidr = $net['cidr_block'] ?? $net['subnet'] ?? '—';
                                $loc  = $net['location'] ?? $net['datacenter'] ?? '—';
                                $nst  = $net['status'] ?? '—';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-network-<?= htmlspecialchars((string)$nid) ?>">
                                <td class="px-4 py-3 text-gray-400 font-mono text-xs"><?= htmlspecialchars((string)$nid) ?></td>
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($nn) ?></td>
                                <td class="px-4 py-3 text-gray-600 font-mono text-xs"><?= htmlspecialchars($cidr) ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($loc) ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                        <?= htmlspecialchars(ucfirst($nst)) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- BILLING TAB                                                -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'billing'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-receipt text-green-500 mr-2"></i>Billing &amp; Invoices</h2>
                    <a href="https://manage.hostwinds.com/billing" target="_blank" class="text-xs text-blue-500 hover:underline">View in portal →</a>
                </div>

                <?php if (!empty($invoices)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-invoices">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Invoice #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Due</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($invoices as $inv):
                                $iid  = $inv['invoiceid'] ?? $inv['id'] ?? '—';
                                $idat = $inv['date'] ?? $inv['created_at'] ?? null;
                                $idue = $inv['duedate'] ?? $inv['due_date'] ?? null;
                                $iamt = $inv['total'] ?? $inv['amount'] ?? null;
                                $ist  = strtolower($inv['status'] ?? 'unpaid');
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-invoice-<?= htmlspecialchars((string)$iid) ?>">
                                <td class="px-4 py-3 font-mono text-xs text-gray-500">#<?= htmlspecialchars((string)$iid) ?></td>
                                <td class="px-4 py-3 text-gray-600"><?= $idat ? date('M d Y', strtotime($idat)) : '—' ?></td>
                                <td class="px-4 py-3 text-gray-500"><?= $idue ? date('M d Y', strtotime($idue)) : '—' ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900"><?= $iamt !== null ? '$'.number_format((float)$iamt,2) : '—' ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                        <?= $ist==='paid'?'bg-green-100 text-green-700':($ist==='unpaid'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-600') ?>">
                                        <?= ucfirst($ist) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php elseif (!$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-receipt text-4xl mb-3"></i>
                    <p class="font-medium">No invoices returned from the API.</p>
                    <p class="text-sm mt-1">Check your <a href="https://manage.hostwinds.com/billing" target="_blank" class="text-blue-500 hover:underline">Hostwinds billing portal</a> directly.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SETTINGS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'settings'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- API credentials -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-key text-yellow-500 mr-2"></i>API Credentials</h3>
                    <p class="text-sm text-gray-500 mb-4">Your Hostwinds Cloud API key is used to manage servers, snapshots, and SSH keys. Find it at
                        <a href="https://manage.hostwinds.com/account/api" target="_blank" class="text-blue-500 hover:underline">manage.hostwinds.com → Account → API</a>.
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_api_key">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                            <input type="password" name="hw_api_key" value="<?= htmlspecialchars($hw_api_key) ?>"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="••••••••••••" autocomplete="off" data-testid="input-api-key">
                            <p class="text-xs text-gray-400 mt-1">
                                <?= $hw_connected ? '✓ A key is currently stored.' : 'No key saved yet.' ?>
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-api-key">
                                <i class="fas fa-save mr-2"></i>Save API Key
                            </button>
                            <?php if ($hw_connected): ?>
                            <form method="POST" class="flex-1 m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="test_connection">
                                <button type="submit" class="w-full px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition" data-testid="button-test-api">
                                    <i class="fas fa-plug mr-2"></i>Test Connection
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- About & Links -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About Hostwinds API</h3>
                    <p class="text-sm text-gray-600 mb-4">The Hostwinds Cloud API lets you manage VPS servers programmatically — start/stop/reboot, create snapshots, manage SSH keys, and view invoices. Authentication uses an API key passed in the <code class="bg-gray-100 px-1 rounded text-xs">accesskey</code> header.</p>

                    <div class="space-y-2">
                        <a href="https://developers.hostwinds.com/cloud/" target="_blank"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-book text-cyan-500 w-4"></i>
                            <span>API Documentation</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://manage.hostwinds.com/cloud" target="_blank"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-server text-cyan-500 w-4"></i>
                            <span>Hostwinds Cloud Control Panel</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://manage.hostwinds.com/account/api" target="_blank"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-key text-yellow-500 w-4"></i>
                            <span>Generate API Key</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <a href="https://manage.hostwinds.com/billing" target="_blank"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="fas fa-receipt text-green-500 w-4"></i>
                            <span>Billing &amp; Invoices</span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                    </div>

                    <div class="mt-4 p-3 bg-cyan-50 rounded-lg border border-cyan-200">
                        <p class="text-xs text-cyan-700 font-semibold mb-1">Environment variable (optional)</p>
                        <p class="text-xs text-cyan-600">You can also set <code class="bg-cyan-100 px-1 rounded">HOSTWINDS_API_KEY</code> as a Replit environment secret to avoid storing the key in the database. The env var takes precedence over the saved key above.</p>
                    </div>
                </div>

                <!-- Supported API features -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-purple-500 mr-2"></i>Supported API Features</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php
                        $features = [
                            ['fas fa-server',        'green',  'List VPS Servers',       'View all VPS instances with specs, IP, status, and pricing'],
                            ['fas fa-play',           'green',  'Start Server',           'Power on a stopped VPS via one-click action'],
                            ['fas fa-stop',           'red',    'Stop Server',            'Gracefully shut down a running VPS'],
                            ['fas fa-redo-alt',       'yellow', 'Reboot Server',          'Restart a running VPS — equivalent to a warm reboot'],
                            ['fas fa-camera',         'blue',   'Create Snapshot',        'Instant full-disk backup of any VPS, named and timestamped'],
                            ['fas fa-trash',          'red',    'Delete Snapshot',        'Remove a snapshot to reclaim storage quota'],
                            ['fas fa-key',            'yellow', 'Manage SSH Keys',        'Add and remove SSH public keys for passwordless server login'],
                            ['fas fa-network-wired',  'purple', 'Private Networks',       'List and inspect private network segments between servers'],
                            ['fas fa-receipt',        'green',  'Billing & Invoices',     'View invoice history and outstanding amounts from the API'],
                        ];
                        foreach ($features as [$icon, $color, $title, $desc]):
                            $colors = ['green'=>'bg-green-50 border-green-200','blue'=>'bg-blue-50 border-blue-200','yellow'=>'bg-yellow-50 border-yellow-200','red'=>'bg-red-50 border-red-200','purple'=>'bg-purple-50 border-purple-200'];
                            $icolors = ['green'=>'text-green-600','blue'=>'text-blue-600','yellow'=>'text-yellow-600','red'=>'text-red-500','purple'=>'text-purple-600'];
                        ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg border <?= $colors[$color] ?>">
                            <i class="<?= $icon ?> <?= $icolors[$color] ?> mt-0.5 w-4 text-center"></i>
                            <div>
                                <p class="text-sm font-semibold text-gray-800"><?= $title ?></p>
                                <p class="text-xs text-gray-500 mt-0.5"><?= $desc ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <?php endif; // end $hw_connected ?>
        </div><!-- /p-6 -->
    </div><!-- /flex-1 -->
</div><!-- /flex -->

<script>
function openSnapForm(serverId, serverName) {
    document.getElementById('snap-server-id').value  = serverId;
    document.getElementById('snap-server-name').textContent = serverName;
    document.getElementById('snap-name-input').value = 'backup-' + new Date().toISOString().slice(0,10) + '-vps' + serverId;
    document.getElementById('snap-modal').classList.remove('hidden');
}
function closeSnapForm() {
    document.getElementById('snap-modal').classList.add('hidden');
}
document.getElementById('snap-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSnapForm();
});
</script>
</body>
</html>
