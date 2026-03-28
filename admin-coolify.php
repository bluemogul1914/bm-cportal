<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$is_admin   = true;
$pdo        = getDB();

// ── Ensure provider_settings table exists ─────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_settings (
        id SERIAL PRIMARY KEY, provider VARCHAR(50) NOT NULL,
        key_name VARCHAR(100) NOT NULL, key_value TEXT DEFAULT '',
        updated_at TIMESTAMP DEFAULT NOW(), UNIQUE(provider, key_name)
    )");
} catch (Exception $e) {}

// ── Load credentials: env secrets → provider_settings fallback ────────────────
$cf_url   = COOLIFY_URL;
$cf_token = COOLIFY_TOKEN;
if (empty($cf_url)) {
    try {
        $rows = $pdo->prepare("SELECT key_name, key_value FROM provider_settings WHERE provider='coolify'");
        $rows->execute();
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key_name'] === 'url')   $cf_url   = $row['key_value'];
            if ($row['key_name'] === 'token') $cf_token = $row['key_value'];
        }
    } catch (Exception $e) {}
}
$cf_url       = rtrim($cf_url, '/');
$cf_connected = !empty($cf_url) && !empty($cf_token);
$cf_base      = $cf_url . '/api/v1';

$success_msg = '';
$error_msg   = '';
$tab         = $_GET['tab'] ?? 'overview';

// ── Core API helper ────────────────────────────────────────────────────────────
function cf_api(string $endpoint, string $method = 'GET', array $body = []): array {
    global $cf_base, $cf_token;
    $url  = $cf_base . $endpoint;
    $hdrs = [
        'Authorization: Bearer ' . $cf_token,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'BlueMogulPortal/1.0',
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = empty($body) ? '' : json_encode($body);
    } elseif ($method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    } elseif ($method === 'PATCH') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        $opts[CURLOPT_POSTFIELDS]    = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['_error' => $err, '_code' => $code];
    if (empty($resp)) return ['_ok' => true, '_code' => $code];

    $d = json_decode($resp, true);
    if ($d === null) return ['_error' => "Invalid JSON (HTTP $code): " . substr($resp,0,200)];
    if (isset($d['message']) && $code >= 400) return ['_error' => $d['message'], '_code' => $code];
    return array_merge(['_code' => $code], is_array($d) ? $d : ['data' => $d]);
}

// ── POST action handlers ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['_csrf_token'] ?? '')) {
        $error_msg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        // Save credentials
        if ($action === 'save_credentials') {
            $url   = rtrim(trim($_POST['cf_url']   ?? ''), '/');
            $token = trim($_POST['cf_token'] ?? '');
            try {
                foreach (['url' => $url, 'token' => $token] as $k => $v) {
                    $pdo->prepare("INSERT INTO provider_settings (provider,key_name,key_value) VALUES ('coolify',?,?) ON CONFLICT (provider,key_name) DO UPDATE SET key_value=EXCLUDED.key_value")
                        ->execute([$k, $v]);
                }
                $cf_url = $url; $cf_token = $token;
                $cf_base = rtrim($url, '/') . '/api/v1';
                $cf_connected = !empty($url) && !empty($token);
                $success_msg = 'Coolify credentials saved.';
            } catch (Exception $e) { $error_msg = $e->getMessage(); }
            $tab = 'settings';

        // Application actions
        } elseif (in_array($action, ['app_start','app_stop','app_restart','app_deploy'])) {
            $uuid = trim($_POST['uuid'] ?? '');
            $ep_map = ['app_start'=>'/start','app_stop'=>'/stop','app_restart'=>'/restart','app_deploy'=>'/deploy'];
            $res = cf_api('/applications/' . $uuid . $ep_map[$action], 'POST');
            if (isset($res['_error'])) {
                $error_msg = ucfirst(str_replace('_',' ',$action)) . " failed: " . $res['_error'];
            } else {
                $labels = ['app_start'=>'started','app_stop'=>'stopped','app_restart'=>'restarted','app_deploy'=>'deployed'];
                $success_msg = "Application " . htmlspecialchars($uuid) . " " . $labels[$action] . " successfully.";
            }
            $tab = 'applications';

        // Database actions
        } elseif (in_array($action, ['db_start','db_stop','db_restart'])) {
            $uuid = trim($_POST['uuid'] ?? '');
            $ep_map = ['db_start'=>'/start','db_stop'=>'/stop','db_restart'=>'/restart'];
            $res = cf_api('/databases/' . $uuid . $ep_map[$action], 'POST');
            if (isset($res['_error'])) {
                $error_msg = "Database action failed: " . $res['_error'];
            } else {
                $labels = ['db_start'=>'started','db_stop'=>'stopped','db_restart'=>'restarted'];
                $success_msg = "Database " . htmlspecialchars($uuid) . " " . $labels[$action] . ".";
            }
            $tab = 'databases';

        // Service actions
        } elseif (in_array($action, ['svc_start','svc_stop','svc_restart'])) {
            $uuid = trim($_POST['uuid'] ?? '');
            $ep_map = ['svc_start'=>'/start','svc_stop'=>'/stop','svc_restart'=>'/restart'];
            $res = cf_api('/services/' . $uuid . $ep_map[$action], 'POST');
            if (isset($res['_error'])) {
                $error_msg = "Service action failed: " . $res['_error'];
            } else {
                $labels = ['svc_start'=>'started','svc_stop'=>'stopped','svc_restart'=>'restarted'];
                $success_msg = "Service " . htmlspecialchars($uuid) . " " . $labels[$action] . ".";
            }
            $tab = 'services';

        // Validate server
        } elseif ($action === 'validate_server') {
            $uuid = trim($_POST['uuid'] ?? '');
            $res = cf_api('/servers/' . $uuid . '/validate', 'POST');
            if (isset($res['_error'])) {
                $error_msg = "Server validation failed: " . $res['_error'];
            } else {
                $success_msg = "Server " . htmlspecialchars($uuid) . " validated successfully.";
            }
            $tab = 'servers';
        }
    }
}

// ── Live data per tab ──────────────────────────────────────────────────────────
$version_info = [];
$health_info  = [];
$servers      = [];
$applications = [];
$databases    = [];
$services     = [];
$projects     = [];
$deployments  = [];
$resources    = [];
$teams        = [];
$api_error    = '';

if ($cf_connected) {
    if ($tab === 'overview') {
        $vr = cf_api('/version');
        if (!isset($vr['_error'])) $version_info = $vr;
        else $api_error = $vr['_error'];

        $hr = cf_api('/healthcheck');
        if (!isset($hr['_error'])) $health_info = $hr;

        // Quick counts
        $ar = cf_api('/applications');
        if (!isset($ar['_error']) && is_array($ar)) {
            unset($ar['_code']);
            $applications = array_values($ar);
        }
        $dr = cf_api('/databases');
        if (!isset($dr['_error']) && is_array($dr)) {
            unset($dr['_code']);
            $databases = array_values($dr);
        }
        $sr = cf_api('/servers');
        if (!isset($sr['_error']) && is_array($sr)) {
            unset($sr['_code']);
            $servers = array_values($sr);
        }

    } elseif ($tab === 'applications') {
        $r = cf_api('/applications');
        if (isset($r['_error'])) { $api_error = $r['_error']; }
        else { unset($r['_code']); $applications = array_values($r); }

    } elseif ($tab === 'databases') {
        $r = cf_api('/databases');
        if (isset($r['_error'])) { $api_error = $r['_error']; }
        else { unset($r['_code']); $databases = array_values($r); }

    } elseif ($tab === 'services') {
        $r = cf_api('/services');
        if (isset($r['_error'])) { $api_error = $r['_error']; }
        else { unset($r['_code']); $services = array_values($r); }

    } elseif ($tab === 'servers') {
        $r = cf_api('/servers');
        if (isset($r['_error'])) { $api_error = $r['_error']; }
        else { unset($r['_code']); $servers = array_values($r); }

    } elseif ($tab === 'projects') {
        $r = cf_api('/projects');
        if (isset($r['_error'])) { $api_error = $r['_error']; }
        else { unset($r['_code']); $projects = array_values($r); }

    } elseif ($tab === 'deployments') {
        $r = cf_api('/deployments');
        if (isset($r['_error'])) { $api_error = $r['_error']; }
        else { unset($r['_code']); $deployments = array_values($r); }

    } elseif ($tab === 'resources') {
        $r = cf_api('/resources');
        if (isset($r['_error'])) { $api_error = $r['_error']; }
        else { unset($r['_code']); $resources = array_values($r); }
    }
}

// Stats
$app_running  = count(array_filter($applications, fn($a) => strtolower($a['status'] ?? '') === 'running'));
$app_stopped  = count(array_filter($applications, fn($a) => in_array(strtolower($a['status']??''), ['stopped','exited'])));
$db_running   = count(array_filter($databases, fn($d) => strtolower($d['status']??'') === 'running'));
$cf_version   = $version_info['version'] ?? null;

// Status badge helper
function status_badge(string $status): string {
    $s = strtolower($status);
    $map = [
        'running'    => 'bg-green-100 text-green-700',
        'stopped'    => 'bg-gray-100 text-gray-600',
        'exited'     => 'bg-red-100 text-red-700',
        'starting'   => 'bg-yellow-100 text-yellow-700',
        'restarting' => 'bg-orange-100 text-orange-700',
        'error'      => 'bg-red-100 text-red-700',
        'degraded'   => 'bg-orange-100 text-orange-700',
    ];
    $cls = $map[$s] ?? 'bg-gray-100 text-gray-500';
    return "<span class=\"px-2 py-0.5 rounded-full text-xs font-medium $cls\">" . ucfirst($status) . "</span>";
}

// Action buttons helper
function action_buttons(string $uuid, string $prefix, string $status, bool $has_deploy = false): string {
    $is_running = strtolower($status) === 'running';
    $csrf       = csrf_field();
    $btns = '';

    if ($is_running) {
        $btns .= "<form method='POST' class='inline'>$csrf<input type='hidden' name='action' value='{$prefix}_stop'><input type='hidden' name='uuid' value='" . htmlspecialchars($uuid) . "'><button type='submit' class='text-xs text-red-500 hover:text-red-700 font-medium' onclick=\"return confirm('Stop this resource?')\"><i class='fas fa-stop mr-1'></i>Stop</button></form>";
        $btns .= " <form method='POST' class='inline'>$csrf<input type='hidden' name='action' value='{$prefix}_restart'><input type='hidden' name='uuid' value='" . htmlspecialchars($uuid) . "'><button type='submit' class='text-xs text-orange-500 hover:text-orange-700 font-medium' onclick=\"return confirm('Restart?')\"><i class='fas fa-sync-alt mr-1'></i>Restart</button></form>";
    } else {
        $btns .= "<form method='POST' class='inline'>$csrf<input type='hidden' name='action' value='{$prefix}_start'><input type='hidden' name='uuid' value='" . htmlspecialchars($uuid) . "'><button type='submit' class='text-xs text-green-600 hover:text-green-800 font-medium'><i class='fas fa-play mr-1'></i>Start</button></form>";
    }
    if ($has_deploy) {
        $btns .= " <form method='POST' class='inline'>$csrf<input type='hidden' name='action' value='app_deploy'><input type='hidden' name='uuid' value='" . htmlspecialchars($uuid) . "'><button type='submit' class='text-xs text-purple-600 hover:text-purple-800 font-medium'><i class='fas fa-rocket mr-1'></i>Deploy</button></form>";
    }
    return $btns;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coolify - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">

        <!-- ── Header ── -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">
                        <i class="fas fa-rocket text-purple-500 mr-2"></i>Coolify
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Self-hosted deployment platform — apps, databases, services &amp; servers
                        <?php if ($cf_version): ?>· <span class="font-medium text-purple-600">v<?= htmlspecialchars($cf_version) ?></span><?php endif; ?>
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($cf_connected): ?>
                        <a href="?tab=<?= $tab ?>" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                        </a>
                        <?php if ($cf_url): ?>
                        <a href="<?= htmlspecialchars($cf_url) ?>" target="_blank" class="px-3 py-1.5 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition">
                            <i class="fas fa-external-link-alt mr-1"></i>Open Coolify
                        </a>
                        <?php endif; ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Connected
                        </span>
                    <?php else: ?>
                        <a href="?tab=settings" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition">
                            <i class="fas fa-key mr-1"></i>Configure
                        </a>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected">
                            <i class="fas fa-circle text-[8px] mr-1"></i>Not Connected
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">

            <?php if ($success_msg): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                <i class="fas fa-check-circle mr-3"></i><?= htmlspecialchars($success_msg) ?>
            </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                <i class="fas fa-exclamation-circle mr-3"></i><?= htmlspecialchars($error_msg) ?>
            </div>
            <?php endif; ?>
            <?php if ($api_error): ?>
            <div class="mb-6 bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg flex items-center">
                <i class="fas fa-exclamation-triangle mr-3"></i>Coolify API error: <strong class="ml-1"><?= htmlspecialchars($api_error) ?></strong>
            </div>
            <?php endif; ?>

            <!-- ── Stat Cards ── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection</p>
                    <?php if ($cf_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1 truncate"><?= htmlspecialchars(parse_url($cf_url, PHP_URL_HOST) ?: $cf_url) ?></p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Credentials not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-apps">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Applications</p>
                    <p class="text-2xl font-bold text-purple-700"><?= count($applications) ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $app_running ?> running · <?= $app_stopped ?> stopped</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-dbs">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Databases</p>
                    <p class="text-2xl font-bold text-blue-700"><?= count($databases) ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?= $db_running ?> running</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-servers">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Servers</p>
                    <p class="text-2xl font-bold text-gray-900"><?= count($servers) ?: '—' ?></p>
                    <p class="text-xs text-gray-400 mt-1">
                        <?= count(array_filter($servers, fn($s) => ($s['settings']['is_reachable'] ?? false))) ?> reachable
                    </p>
                </div>
            </div>

            <!-- ── Tab Nav ── -->
            <div class="flex gap-1 mb-6 bg-gray-100 rounded-lg p-1 flex-wrap" data-testid="tab-nav">
                <?php
                $tabs = [
                    'overview'     => ['fas fa-tachometer-alt', 'Overview'],
                    'applications' => ['fas fa-layer-group',    'Applications'],
                    'databases'    => ['fas fa-database',       'Databases'],
                    'services'     => ['fas fa-cubes',          'Services'],
                    'servers'      => ['fas fa-server',         'Servers'],
                    'projects'     => ['fas fa-folder-open',    'Projects'],
                    'deployments'  => ['fas fa-history',        'Deployments'],
                    'resources'    => ['fas fa-th-large',       'All Resources'],
                    'settings'     => ['fas fa-cog',            'Settings'],
                ];
                foreach ($tabs as $t => [$icon, $label]):
                    $active = $tab === $t;
                ?>
                <a href="?tab=<?= $t ?>"
                   class="flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition <?= $active ? 'bg-white text-purple-700 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>"
                   data-testid="tab-<?= $t ?>">
                    <i class="<?= $icon ?> text-xs"></i><?= $label ?>
                    <?php if ($t === 'applications' && $app_stopped > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-gray-400 text-white rounded-full text-[10px] font-bold"><?= $app_stopped ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$cf_connected && $tab !== 'settings'): ?>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-6 text-center">
                <i class="fas fa-rocket text-purple-300 text-4xl mb-3"></i>
                <h3 class="text-base font-semibold text-purple-800 mb-2">Coolify Credentials Required</h3>
                <p class="text-sm text-purple-600 mb-4">Add your Coolify instance URL and API token to manage applications, databases, services, and servers from this dashboard.</p>
                <a href="?tab=settings" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition">
                    <i class="fas fa-key mr-2"></i>Configure Credentials
                </a>
            </div>
            <?php else: ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- OVERVIEW TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'overview'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Health & version card -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-heartbeat text-red-500 mr-2"></i>Health &amp; Version</h3>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between py-2 border-b border-gray-50">
                            <span class="text-sm text-gray-500">API Health</span>
                            <?php if (!empty($health_info)): ?>
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium"><i class="fas fa-check mr-1"></i>Healthy</span>
                            <?php else: ?>
                            <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs font-medium">Unreachable</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($cf_version): ?>
                        <div class="flex items-center justify-between py-2 border-b border-gray-50">
                            <span class="text-sm text-gray-500">Version</span>
                            <span class="text-sm font-mono font-semibold text-purple-700">v<?= htmlspecialchars($cf_version) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($version_info as $k => $v):
                            if (in_array($k, ['_code','version']) || !is_scalar($v)) continue; ?>
                        <div class="flex items-center justify-between py-2 border-b border-gray-50">
                            <span class="text-sm text-gray-500 capitalize"><?= htmlspecialchars(str_replace('_',' ',$k)) ?></span>
                            <span class="text-sm text-gray-700 font-medium"><?= htmlspecialchars((string)$v) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <div class="pt-2">
                            <a href="<?= htmlspecialchars($cf_url) ?>" target="_blank" class="flex items-center gap-2 text-sm text-purple-500 hover:text-purple-700">
                                <i class="fas fa-external-link-alt text-xs"></i>Open Coolify Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Application summary -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-layer-group text-purple-500 mr-2"></i>Applications</h3>
                        <a href="?tab=applications" class="text-xs text-purple-500 hover:underline">View all →</a>
                    </div>
                    <?php if (empty($applications)): ?>
                    <p class="text-sm text-gray-400 text-center py-4">No applications found.</p>
                    <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($applications, 0, 6) as $app):
                            $aname  = $app['name'] ?? $app['uuid'] ?? '—';
                            $astatus = $app['status'] ?? 'unknown';
                        ?>
                        <div class="flex items-center justify-between px-3 py-2 bg-gray-50 rounded-lg">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($aname) ?></p>
                                <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($app['fqdn'] ?? $app['git_repository'] ?? '') ?></p>
                            </div>
                            <?= status_badge($astatus) ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($applications) > 6): ?>
                        <p class="text-xs text-center text-gray-400 pt-1">+ <?= count($applications) - 6 ?> more — <a href="?tab=applications" class="text-purple-500 hover:underline">view all</a></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Database & server summary -->
                <div class="space-y-5">
                    <div class="bg-white rounded-lg border border-gray-200 p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-database text-blue-500 mr-2"></i>Databases</h3>
                            <a href="?tab=databases" class="text-xs text-blue-500 hover:underline">View all →</a>
                        </div>
                        <?php if (empty($databases)): ?>
                        <p class="text-xs text-gray-400">No databases.</p>
                        <?php else: ?>
                        <div class="space-y-1.5">
                            <?php foreach (array_slice($databases, 0, 4) as $db):
                                $dname   = $db['name'] ?? $db['uuid'] ?? '—';
                                $dtype   = $db['type'] ?? '';
                                $dstatus = $db['status'] ?? 'unknown';
                            ?>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-circle text-[8px] <?= strtolower($dstatus)==='running'?'text-green-500':'text-gray-300' ?>"></i>
                                <span class="text-sm text-gray-700 flex-1 truncate"><?= htmlspecialchars($dname) ?></span>
                                <span class="text-xs text-gray-400"><?= htmlspecialchars(ucfirst($dtype)) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-5">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-server text-gray-500 mr-2"></i>Servers</h3>
                            <a href="?tab=servers" class="text-xs text-gray-500 hover:underline">View all →</a>
                        </div>
                        <?php if (empty($servers)): ?>
                        <p class="text-xs text-gray-400">No servers.</p>
                        <?php else: ?>
                        <div class="space-y-1.5">
                            <?php foreach ($servers as $sv):
                                $svname = $sv['name'] ?? $sv['uuid'] ?? '—';
                                $svip   = $sv['ip'] ?? '';
                                $reach  = $sv['settings']['is_reachable'] ?? false;
                            ?>
                            <div class="flex items-center gap-2">
                                <i class="fas fa-circle text-[8px] <?= $reach ? 'text-green-500' : 'text-red-400' ?>"></i>
                                <span class="text-sm text-gray-700 flex-1 truncate"><?= htmlspecialchars($svname) ?></span>
                                <span class="text-xs font-mono text-gray-400"><?= htmlspecialchars($svip) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- APPLICATIONS TAB                                           -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'applications'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-layer-group text-purple-500 mr-2"></i>Applications</h2>
                    <div class="flex items-center gap-3">
                        <input type="search" id="app-search" placeholder="Filter…" onkeyup="filterTable('app-tbody','app-name')"
                            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 w-48">
                        <span class="text-xs text-gray-400"><?= count($applications) ?> total</span>
                    </div>
                </div>
                <?php if (empty($applications) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-layer-group text-4xl mb-3"></i>
                    <p class="font-medium">No applications found.</p>
                    <p class="text-sm mt-1">Create your first application in the <a href="<?= htmlspecialchars($cf_url) ?>" target="_blank" class="text-purple-500 hover:underline">Coolify dashboard</a>.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-apps">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Application</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">URL / Repo</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="app-tbody">
                            <?php foreach ($applications as $app):
                                $uuid    = $app['uuid'] ?? '';
                                $aname   = $app['name'] ?? $uuid;
                                $atype   = $app['type'] ?? $app['build_pack'] ?? '—';
                                $fqdn    = $app['fqdn'] ?? '';
                                $repo    = $app['git_repository'] ?? '';
                                $branch  = $app['git_branch'] ?? '';
                                $astatus = $app['status'] ?? 'unknown';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-app-<?= htmlspecialchars($uuid) ?>">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900 app-name"><?= htmlspecialchars($aname) ?></p>
                                    <p class="text-xs font-mono text-gray-400"><?= htmlspecialchars(substr($uuid,0,12)) ?>…</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded-full text-xs font-medium"><?= htmlspecialchars(ucfirst($atype)) ?></span>
                                </td>
                                <td class="px-4 py-3 max-w-xs">
                                    <?php if ($fqdn): ?>
                                    <a href="https://<?= htmlspecialchars($fqdn) ?>" target="_blank" class="text-sm text-purple-500 hover:underline truncate block"><?= htmlspecialchars($fqdn) ?></a>
                                    <?php endif; ?>
                                    <?php if ($repo): ?>
                                    <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($repo) ?><?= $branch ? " @ $branch" : '' ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center"><?= status_badge($astatus) ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <?= action_buttons($uuid, 'app', $astatus, true) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- DATABASES TAB                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'databases'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-database text-blue-500 mr-2"></i>Databases</h2>
                    <span class="text-xs text-gray-400"><?= count($databases) ?> total</span>
                </div>
                <?php if (empty($databases) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-database text-4xl mb-3"></i>
                    <p class="font-medium">No databases found.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-dbs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Database</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Engine</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Version</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Project</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($databases as $db):
                                $uuid    = $db['uuid'] ?? '';
                                $dname   = $db['name'] ?? $uuid;
                                $dtype   = $db['type'] ?? $db['database_type'] ?? '—';
                                $dver    = $db['image'] ?? $db['version'] ?? '—';
                                $dproj   = $db['project'] ?? $db['environment']['project']['name'] ?? '—';
                                $dstatus = $db['status'] ?? 'unknown';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-db-<?= htmlspecialchars($uuid) ?>">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900"><?= htmlspecialchars($dname) ?></p>
                                    <p class="text-xs font-mono text-gray-400"><?= htmlspecialchars(substr($uuid,0,12)) ?>…</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs font-medium"><?= htmlspecialchars(ucfirst(str_replace(['standalone-','_'],['',''],$dtype))) ?></span>
                                </td>
                                <td class="px-4 py-3 text-xs font-mono text-gray-500"><?= htmlspecialchars($dver) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars(is_array($dproj) ? ($dproj['name']??'—') : $dproj) ?></td>
                                <td class="px-4 py-3 text-center"><?= status_badge($dstatus) ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <?= action_buttons($uuid, 'db', $dstatus) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SERVICES TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'services'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cubes text-orange-500 mr-2"></i>Services</h2>
                    <span class="text-xs text-gray-400"><?= count($services) ?> total</span>
                </div>
                <?php if (empty($services) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-cubes text-4xl mb-3"></i>
                    <p class="font-medium">No services found.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-services">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Service</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">URL</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($services as $svc):
                                $uuid    = $svc['uuid'] ?? '';
                                $sname   = $svc['name'] ?? $uuid;
                                $stype   = $svc['type'] ?? '—';
                                $sfqdn   = $svc['fqdn'] ?? '';
                                $sstatus = $svc['status'] ?? 'unknown';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-svc-<?= htmlspecialchars($uuid) ?>">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900"><?= htmlspecialchars($sname) ?></p>
                                    <p class="text-xs font-mono text-gray-400"><?= htmlspecialchars(substr($uuid,0,12)) ?>…</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded-full text-xs font-medium"><?= htmlspecialchars(ucfirst($stype)) ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($sfqdn): ?>
                                    <a href="https://<?= htmlspecialchars($sfqdn) ?>" target="_blank" class="text-sm text-purple-500 hover:underline"><?= htmlspecialchars($sfqdn) ?></a>
                                    <?php else: ?>
                                    <span class="text-gray-400 text-sm">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center"><?= status_badge($sstatus) ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <?= action_buttons($uuid, 'svc', $sstatus) ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SERVERS TAB                                                -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'servers'): ?>
            <div class="grid grid-cols-1 gap-4">
                <?php if (empty($servers) && !$api_error): ?>
                <div class="bg-white rounded-lg border border-gray-200 p-10 text-center text-gray-400">
                    <i class="fas fa-server text-4xl mb-3"></i>
                    <p class="font-medium">No servers configured.</p>
                </div>
                <?php else: ?>
                <?php foreach ($servers as $sv):
                    $svuuid  = $sv['uuid'] ?? '';
                    $svname  = $sv['name'] ?? $svuuid;
                    $svip    = $sv['ip'] ?? '—';
                    $svport  = $sv['port'] ?? 22;
                    $svuser  = $sv['user'] ?? 'root';
                    $reach   = $sv['settings']['is_reachable'] ?? false;
                    $deploy  = $sv['settings']['is_usable_for_deployment'] ?? false;
                    $concurr = $sv['settings']['concurrent_builds'] ?? 1;
                    $wild    = $sv['settings']['wildcard_domain'] ?? '';
                    $svdesc  = $sv['description'] ?? '';
                ?>
                <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="card-server-<?= htmlspecialchars($svuuid) ?>">
                    <div class="flex items-start gap-4 flex-wrap">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-3 mb-2 flex-wrap">
                                <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($svname) ?></h3>
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $reach ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                    <i class="fas fa-circle text-[8px] mr-1"></i><?= $reach ? 'Reachable' : 'Unreachable' ?>
                                </span>
                                <?php if ($deploy): ?>
                                <span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded-full text-xs font-medium">Deployable</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($svdesc): ?><p class="text-sm text-gray-500 mb-3"><?= htmlspecialchars($svdesc) ?></p><?php endif; ?>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="bg-gray-50 rounded-lg p-3">
                                    <p class="text-xs text-gray-400 mb-0.5 uppercase font-semibold">IP Address</p>
                                    <p class="text-sm font-mono font-semibold text-gray-800"><?= htmlspecialchars($svip) ?></p>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-3">
                                    <p class="text-xs text-gray-400 mb-0.5 uppercase font-semibold">SSH Port</p>
                                    <p class="text-sm font-mono font-semibold text-gray-800"><?= htmlspecialchars((string)$svport) ?></p>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-3">
                                    <p class="text-xs text-gray-400 mb-0.5 uppercase font-semibold">User</p>
                                    <p class="text-sm font-mono font-semibold text-gray-800"><?= htmlspecialchars($svuser) ?></p>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-3">
                                    <p class="text-xs text-gray-400 mb-0.5 uppercase font-semibold">Concurrent Builds</p>
                                    <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars((string)$concurr) ?></p>
                                </div>
                                <?php if ($wild): ?>
                                <div class="bg-gray-50 rounded-lg p-3 col-span-2">
                                    <p class="text-xs text-gray-400 mb-0.5 uppercase font-semibold">Wildcard Domain</p>
                                    <p class="text-sm font-mono text-gray-800"><?= htmlspecialchars($wild) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="validate_server">
                                <input type="hidden" name="uuid" value="<?= htmlspecialchars($svuuid) ?>">
                                <button type="submit" class="flex items-center gap-2 px-4 py-2 bg-purple-100 hover:bg-purple-200 text-purple-700 rounded-lg text-sm font-medium transition whitespace-nowrap" data-testid="button-validate-<?= htmlspecialchars($svuuid) ?>">
                                    <i class="fas fa-plug"></i>Validate
                                </button>
                            </form>
                            <a href="<?= htmlspecialchars($cf_url) ?>/server/<?= urlencode($svuuid) ?>" target="_blank"
                                class="flex items-center gap-2 px-4 py-2 border border-gray-200 text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50 transition whitespace-nowrap">
                                <i class="fas fa-external-link-alt"></i>View in Coolify
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- PROJECTS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'projects'): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php if (empty($projects) && !$api_error): ?>
                <div class="col-span-3 bg-white rounded-lg border border-gray-200 p-10 text-center text-gray-400">
                    <i class="fas fa-folder-open text-4xl mb-3"></i>
                    <p class="font-medium">No projects found.</p>
                </div>
                <?php else: ?>
                <?php foreach ($projects as $prj):
                    $puuid  = $prj['uuid'] ?? '';
                    $pname  = $prj['name'] ?? $puuid;
                    $pdesc  = $prj['description'] ?? '';
                    $penvs  = $prj['environments'] ?? [];
                ?>
                <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="card-project-<?= htmlspecialchars($puuid) ?>">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($pname) ?></h3>
                        <a href="<?= htmlspecialchars($cf_url) ?>/project/<?= urlencode($puuid) ?>" target="_blank" class="text-xs text-purple-500 hover:underline">Open →</a>
                    </div>
                    <?php if ($pdesc): ?><p class="text-sm text-gray-500 mb-3"><?= htmlspecialchars($pdesc) ?></p><?php endif; ?>
                    <p class="text-xs font-mono text-gray-400 mb-3"><?= htmlspecialchars($puuid) ?></p>
                    <?php if (!empty($penvs)): ?>
                    <div class="flex flex-wrap gap-1">
                        <?php foreach ($penvs as $env):
                            $ename = $env['name'] ?? '';
                        ?>
                        <span class="px-2 py-0.5 bg-purple-50 border border-purple-200 text-purple-700 rounded text-xs font-medium"><?= htmlspecialchars($ename) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- DEPLOYMENTS TAB                                            -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'deployments'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-history text-indigo-500 mr-2"></i>Deployment Queue</h2>
                    <span class="text-xs text-gray-400"><?= count($deployments) ?> entries</span>
                </div>
                <?php if (empty($deployments) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-history text-4xl mb-3"></i>
                    <p class="font-medium">Deployment queue is empty.</p>
                    <p class="text-sm mt-1">Trigger a deploy from the Applications tab to see entries here.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-deployments">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Deployment ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Application</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Commit</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Started</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($deployments as $dep):
                                $did    = $dep['id']   ?? $dep['deployment_uuid'] ?? '—';
                                $dapp   = $dep['application']['name'] ?? $dep['application_uuid'] ?? '—';
                                $dst    = $dep['status'] ?? 'unknown';
                                $dcom   = substr($dep['commit'] ?? '', 0, 8) ?: '—';
                                $dtime  = $dep['created_at'] ?? $dep['started_at'] ?? '—';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-deploy">
                                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars(substr((string)$did,0,16)) ?>…</td>
                                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars(is_array($dapp) ? ($dapp['name']??'—') : $dapp) ?></td>
                                <td class="px-4 py-3 text-center"><?= status_badge($dst) ?></td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-500"><?= htmlspecialchars($dcom) ?></td>
                                <td class="px-4 py-3 text-xs text-gray-400"><?= $dtime !== '—' ? date('M d Y H:i', strtotime($dtime)) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ALL RESOURCES TAB                                          -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'resources'): ?>
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-th-large text-gray-500 mr-2"></i>All Resources</h2>
                    <div class="flex items-center gap-3">
                        <input type="search" placeholder="Filter resources…" onkeyup="filterTable('res-tbody','res-name')"
                            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 w-48">
                        <span class="text-xs text-gray-400"><?= count($resources) ?> total</span>
                    </div>
                </div>
                <?php if (empty($resources) && !$api_error): ?>
                <div class="p-10 text-center text-gray-400">
                    <i class="fas fa-th-large text-4xl mb-3"></i>
                    <p class="font-medium">No resources found.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-testid="table-resources">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Project</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Environment</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="res-tbody">
                            <?php foreach ($resources as $res):
                                $rname  = $res['name'] ?? $res['uuid'] ?? '—';
                                $rtype  = $res['type'] ?? '—';
                                $rproj  = is_array($res['project'] ?? null) ? ($res['project']['name']??'—') : ($res['project'] ?? '—');
                                $renv   = is_array($res['environment'] ?? null) ? ($res['environment']['name']??'—') : ($res['environment'] ?? '—');
                                $rst    = $res['status'] ?? 'unknown';
                                $typecol = ['application'=>'purple','database'=>'blue','service'=>'orange'][$rtype] ?? 'gray';
                                $cols = ['purple'=>'bg-purple-100 text-purple-700','blue'=>'bg-blue-100 text-blue-700','orange'=>'bg-orange-100 text-orange-700','gray'=>'bg-gray-100 text-gray-600'];
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="row-resource">
                                <td class="px-4 py-3 font-medium text-gray-900 res-name"><?= htmlspecialchars($rname) ?></td>
                                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $cols[$typecol] ?>"><?= htmlspecialchars(ucfirst($rtype)) ?></span></td>
                                <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($rproj) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($renv) ?></td>
                                <td class="px-4 py-3 text-center"><?= status_badge($rst) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- SETTINGS TAB                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'settings'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Credentials -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1"><i class="fas fa-key text-yellow-500 mr-2"></i>API Credentials</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Your Coolify instance URL and API token. Generate a token at
                        <strong>Coolify → Security → API Tokens</strong>.
                        <?php if (COOLIFY_URL): ?>
                        <span class="block mt-1 text-xs text-green-600"><i class="fas fa-check-circle mr-1"></i><code>COOLIFY_URL</code> and <code>COOLIFY_TOKEN</code> environment secrets detected.</span>
                        <?php endif; ?>
                    </p>
                    <form method="POST" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_credentials">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Coolify Instance URL</label>
                            <input type="url" name="cf_url" value="<?= htmlspecialchars($cf_url) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                                placeholder="https://coolify.yourdomain.com" data-testid="input-cf-url">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">API Token</label>
                            <input type="password" name="cf_token" value="<?= htmlspecialchars($cf_token) ?>"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                                placeholder="••••••••" autocomplete="off" data-testid="input-cf-token">
                            <p class="text-xs text-gray-400 mt-1"><?= $cf_connected ? '✓ Credentials active.' : 'Not configured yet.' ?></p>
                        </div>
                        <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-save-creds">
                            <i class="fas fa-save mr-2"></i>Save Credentials
                        </button>
                        <p class="text-xs text-gray-400 text-center">Credentials are also auto-loaded from the <code>COOLIFY_URL</code> and <code>COOLIFY_TOKEN</code> environment secrets (already configured).</p>
                    </form>
                </div>

                <!-- About -->
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>About Coolify API</h3>
                    <p class="text-sm text-gray-600 mb-4">Coolify is an open-source self-hosted deployment platform. Its REST API (<code class="bg-gray-100 px-1 rounded text-xs">/api/v1</code>) provides full control over apps, databases, services, servers, and deployments. Authentication uses a Bearer token.</p>
                    <div class="space-y-2 mb-4">
                        <?php foreach ([
                            ['fas fa-book',           'Coolify API Docs',     'https://coolify.io/docs/api-reference/api/operations/version'],
                            ['fas fa-rocket',         'Coolify Dashboard',    $cf_url ?: 'https://coolify.io'],
                            ['fas fa-code-branch',    'Coolify GitHub',       'https://github.com/coollabsio/coolify'],
                        ] as [$ico,$lbl,$href]): ?>
                        <a href="<?= htmlspecialchars($href) ?>" target="_blank" class="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50 transition text-sm text-gray-700">
                            <i class="<?= $ico ?> text-purple-500 w-4"></i><span><?= $lbl ?></span>
                            <i class="fas fa-external-link-alt text-xs text-gray-400 ml-auto"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                        <p class="text-xs text-purple-700"><strong>Token generation:</strong> In your Coolify dashboard go to <strong>Security → API Tokens</strong>, click "Create New Token", give it a name, and paste it above. Tokens have configurable permission scopes.</p>
                    </div>
                </div>

                <!-- Feature grid -->
                <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-purple-500 mr-2"></i>Integrated API Features</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <?php foreach ([
                            ['fas fa-heartbeat',   'red',    'Health & Version',     'Live API health check and Coolify version display'],
                            ['fas fa-layer-group', 'purple', 'Application Manager',  'List, start, stop, restart, and deploy all applications'],
                            ['fas fa-database',    'blue',   'Database Manager',     'List, start, stop, and restart all database instances'],
                            ['fas fa-cubes',       'orange', 'Service Manager',      'List, start, stop, and restart one-click services'],
                            ['fas fa-server',      'gray',   'Server Manager',       'View servers, SSH details, and validate connectivity'],
                            ['fas fa-folder-open', 'indigo', 'Project Browser',      'Browse all Coolify projects and their environments'],
                            ['fas fa-history',     'violet', 'Deployment Queue',     'Monitor the deployment queue and status of each deploy'],
                            ['fas fa-th-large',    'gray',   'All Resources',        'Unified view of every resource across all projects'],
                            ['fas fa-rocket',      'purple', 'Deploy Trigger',       'Trigger a new deployment for any application instantly'],
                        ] as [$icon,$color,$title,$desc]):
                            $bg=['purple'=>'bg-purple-50 border-purple-200','blue'=>'bg-blue-50 border-blue-200','orange'=>'bg-orange-50 border-orange-200','gray'=>'bg-gray-50 border-gray-200','indigo'=>'bg-indigo-50 border-indigo-200','violet'=>'bg-violet-50 border-violet-200','red'=>'bg-red-50 border-red-200'];
                            $ic=['purple'=>'text-purple-600','blue'=>'text-blue-600','orange'=>'text-orange-500','gray'=>'text-gray-500','indigo'=>'text-indigo-600','violet'=>'text-violet-600','red'=>'text-red-500'];
                        ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg border <?= $bg[$color] ?>">
                            <i class="<?= $icon ?> <?= $ic[$color] ?> mt-0.5 w-4 text-center shrink-0"></i>
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

            <?php endif; // end $cf_connected ?>
        </div>
    </div>
</div>

<script>
function filterTable(tbodyId, cellClass) {
    const inputs = document.querySelectorAll('input[type="search"]');
    const input  = inputs[inputs.length - 1];
    if (!input) return;
    const q = input.value.toLowerCase();
    document.querySelectorAll('#' + tbodyId + ' tr').forEach(row => {
        const cell = row.querySelector('.' + cellClass);
        row.style.display = !cell || cell.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>
