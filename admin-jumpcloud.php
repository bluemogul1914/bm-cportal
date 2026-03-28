<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$pdo = getDB();

$jc_api_key = JUMPCLOUD_API_KEY;
$jc_org_id  = JUMPCLOUD_ORG_ID;
$jc_connected = !empty($jc_api_key);

$success_msg = '';
$error_msg   = '';
$active_tab  = $_GET['tab'] ?? 'overview';

// ─────────────────────────────────────────────────────────
// DB bootstrap
// ─────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jc_systems (
            id               SERIAL PRIMARY KEY,
            jc_id            VARCHAR(255) UNIQUE NOT NULL,
            display_name     VARCHAR(255) DEFAULT '',
            hostname         VARCHAR(255) DEFAULT '',
            os               VARCHAR(100) DEFAULT '',
            os_version       VARCHAR(100) DEFAULT '',
            arch             VARCHAR(50)  DEFAULT '',
            active           BOOLEAN      DEFAULT TRUE,
            allow_ssh        BOOLEAN      DEFAULT FALSE,
            allow_multi_factor BOOLEAN    DEFAULT FALSE,
            agent_version    VARCHAR(100) DEFAULT '',
            remote_ip        VARCHAR(100) DEFAULT '',
            last_contact     TIMESTAMP,
            created_remote   TIMESTAMP,
            updated_at       TIMESTAMP    DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS jc_users (
            id               SERIAL PRIMARY KEY,
            jc_id            VARCHAR(255) UNIQUE NOT NULL,
            username         VARCHAR(255) DEFAULT '',
            firstname        VARCHAR(100) DEFAULT '',
            lastname         VARCHAR(100) DEFAULT '',
            email            VARCHAR(255) DEFAULT '',
            state            VARCHAR(50)  DEFAULT 'STAGED',
            mfa_enabled      BOOLEAN      DEFAULT FALSE,
            ldap_binding_user BOOLEAN     DEFAULT FALSE,
            sudo_enabled     BOOLEAN      DEFAULT FALSE,
            account_locked   BOOLEAN      DEFAULT FALSE,
            activated        BOOLEAN      DEFAULT FALSE,
            created_remote   TIMESTAMP,
            updated_at       TIMESTAMP    DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS jc_events (
            id               SERIAL PRIMARY KEY,
            event_type       VARCHAR(100) DEFAULT '',
            initiated_by     VARCHAR(255) DEFAULT '',
            event_time       TIMESTAMP,
            description      TEXT         DEFAULT '',
            resource_type    VARCHAR(100) DEFAULT '',
            resource_id      VARCHAR(255) DEFAULT '',
            service          VARCHAR(100) DEFAULT '',
            raw              TEXT         DEFAULT '',
            created_at       TIMESTAMP    DEFAULT NOW()
        );
    ");
} catch (Exception $e) {}

// ─────────────────────────────────────────────────────────
// JumpCloud API helper
// ─────────────────────────────────────────────────────────
function jc_api(string $method, string $endpoint, ?array $body = null, bool $v2 = false): array {
    if (!JUMPCLOUD_API_KEY) return ['error' => 'JUMPCLOUD_API_KEY is not configured'];
    $base = $v2 ? JUMPCLOUD_API_V2 : JUMPCLOUD_API_V1;
    $url  = strpos($endpoint,'http') === 0 ? $endpoint : rtrim($base,'/').$endpoint;
    $headers = [
        'x-api-key: '.JUMPCLOUD_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if (JUMPCLOUD_ORG_ID) $headers[] = 'x-org-id: '.JUMPCLOUD_ORG_ID;

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    $upper = strtoupper($method);
    if ($upper === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    } elseif ($upper === 'PUT' || $upper === 'PATCH' || $upper === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = $upper;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err)    return ['error' => $curl_err, 'http_code' => 0];
    if ($http_code === 401) return ['error' => 'Unauthorized — check your API key', 'http_code' => 401];
    if ($http_code === 403) return ['error' => 'Forbidden — check org ID or permissions', 'http_code' => 403];
    if ($http_code < 200 || $http_code >= 300) {
        $b = json_decode($response, true) ?: [];
        return ['error' => $b['message'] ?? $b['error'] ?? "HTTP $http_code", 'http_code' => $http_code];
    }
    if (empty($response)) return ['results' => [], 'http_code' => $http_code];
    $decoded = json_decode($response, true);
    if ($decoded === null) return ['error' => 'Invalid JSON response', 'raw' => substr($response,0,300)];
    return $decoded;
}

// ─────────────────────────────────────────────────────────
// POST action handlers
// ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ── Test Connection
    if ($action === 'test_connection') {
        if (!$jc_connected) {
            $error_msg = 'JUMPCLOUD_API_KEY is not set.';
        } else {
            $res = jc_api('GET', '/systems?limit=1&skip=0');
            if (isset($res['error'])) {
                $error_msg = 'Connection FAILED: '.$res['error'];
            } else {
                $cnt = $res['totalCount'] ?? count($res['results'] ?? $res);
                $success_msg = "JumpCloud connected ✓ | Systems visible: {$cnt}";
            }
        }
        $active_tab = 'settings';
    }

    // ── Sync Systems
    if ($action === 'sync_systems') {
        if (!$jc_connected) { $error_msg = 'JUMPCLOUD_API_KEY not set.'; }
        else {
            $limit = 100; $skip = 0; $all = []; $synced = 0;
            do {
                $res = jc_api('GET', "/systems?limit={$limit}&skip={$skip}&fields=id,displayName,hostname,os,osVersionDetail,agentVersion,active,allowPublicKeyAuthentication,allowSshPasswordAuthentication,allowMultiFactorAuthentication,lastContact,created,remoteIP,arch");
                if (isset($res['error'])) { $error_msg = 'Sync failed: '.$res['error']; break; }
                $batch = $res['results'] ?? (is_array($res) ? array_filter($res, 'is_array') : []);
                $all = array_merge($all, $batch);
                $skip += $limit;
                $total = (int)($res['totalCount'] ?? count($all));
            } while (count($all) < $total && count($batch) === $limit);

            foreach ($all as $s) {
                $jid = $s['id'] ?? ''; if (!$jid) continue;
                $os_detail = $s['osVersionDetail'] ?? [];
                $os_name   = is_array($os_detail) ? ($os_detail['osName'] ?? $os_detail['name'] ?? '') : '';
                $os_ver    = is_array($os_detail) ? ($os_detail['version'] ?? '') : '';
                if (!$os_name) $os_name = $s['os'] ?? '';
                $lc = null;
                if (!empty($s['lastContact'])) { try { $lc = date('Y-m-d H:i:s', strtotime($s['lastContact'])); } catch(Exception $e){} }
                $cr = null;
                if (!empty($s['created'])) { try { $cr = date('Y-m-d H:i:s', strtotime($s['created'])); } catch(Exception $e){} }
                try {
                    $pdo->prepare("INSERT INTO jc_systems (jc_id,display_name,hostname,os,os_version,arch,active,allow_ssh,allow_multi_factor,agent_version,remote_ip,last_contact,created_remote,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                        ON CONFLICT (jc_id) DO UPDATE SET
                            display_name=EXCLUDED.display_name, hostname=EXCLUDED.hostname, os=EXCLUDED.os,
                            os_version=EXCLUDED.os_version, arch=EXCLUDED.arch, active=EXCLUDED.active,
                            allow_ssh=EXCLUDED.allow_ssh, allow_multi_factor=EXCLUDED.allow_multi_factor,
                            agent_version=EXCLUDED.agent_version, remote_ip=EXCLUDED.remote_ip,
                            last_contact=EXCLUDED.last_contact, updated_at=NOW()")
                        ->execute([
                            $jid, $s['displayName']??$s['hostname']??'', $s['hostname']??'',
                            $os_name, $os_ver, $s['arch']??'',
                            ($s['active']??true)?'true':'false',
                            (!empty($s['allowPublicKeyAuthentication'])||!empty($s['allowSshPasswordAuthentication']))?'true':'false',
                            !empty($s['allowMultiFactorAuthentication'])?'true':'false',
                            $s['agentVersion']??'', $s['remoteIP']??'', $lc, $cr,
                        ]);
                    $synced++;
                } catch(Exception $e){}
            }
            $success_msg = "Synced {$synced} system(s) from JumpCloud.";
            try { $pdo->prepare("INSERT INTO activity_log (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")->execute([$_SESSION['user_id'],'jc_sync_systems','jc_system',0,"Synced {$synced} systems",$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
        }
        $active_tab = 'systems';
    }

    // ── Sync Users
    if ($action === 'sync_users') {
        if (!$jc_connected) { $error_msg = 'JUMPCLOUD_API_KEY not set.'; }
        else {
            $limit = 100; $skip = 0; $all = []; $synced = 0;
            do {
                $res = jc_api('GET', "/systemusers?limit={$limit}&skip={$skip}&fields=id,username,firstname,lastname,email,state,mfa,ldap_binding_user,sudo,account_locked,activated,created");
                if (isset($res['error'])) { $error_msg = 'User sync failed: '.$res['error']; break; }
                $batch = $res['results'] ?? [];
                $all   = array_merge($all, $batch);
                $skip += $limit;
                $total = (int)($res['totalCount'] ?? count($all));
            } while (count($all) < $total && count($batch) === $limit);

            foreach ($all as $u) {
                $jid = $u['id'] ?? ''; if (!$jid) continue;
                $cr = null;
                if (!empty($u['created'])) { try { $cr = date('Y-m-d H:i:s', strtotime($u['created'])); } catch(Exception $e){} }
                $mfa_cfg  = $u['mfa'] ?? [];
                $mfa_on   = is_array($mfa_cfg) ? !empty($mfa_cfg['configured']||$mfa_cfg['enabled']??false) : (bool)$mfa_cfg;
                try {
                    $pdo->prepare("INSERT INTO jc_users (jc_id,username,firstname,lastname,email,state,mfa_enabled,ldap_binding_user,sudo_enabled,account_locked,activated,created_remote,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                        ON CONFLICT (jc_id) DO UPDATE SET
                            username=EXCLUDED.username, firstname=EXCLUDED.firstname, lastname=EXCLUDED.lastname,
                            email=EXCLUDED.email, state=EXCLUDED.state, mfa_enabled=EXCLUDED.mfa_enabled,
                            ldap_binding_user=EXCLUDED.ldap_binding_user, sudo_enabled=EXCLUDED.sudo_enabled,
                            account_locked=EXCLUDED.account_locked, activated=EXCLUDED.activated, updated_at=NOW()")
                        ->execute([
                            $jid, $u['username']??'', $u['firstname']??'', $u['lastname']??'',
                            $u['email']??'', $u['state']??'STAGED', $mfa_on?'true':'false',
                            !empty($u['ldap_binding_user'])?'true':'false',
                            !empty($u['sudo'])?'true':'false',
                            !empty($u['account_locked'])?'true':'false',
                            !empty($u['activated'])?'true':'false',
                            $cr,
                        ]);
                    $synced++;
                } catch(Exception $e){}
            }
            $success_msg = "Synced {$synced} user(s) from JumpCloud.";
            try { $pdo->prepare("INSERT INTO activity_log (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")->execute([$_SESSION['user_id'],'jc_sync_users','jc_user',0,"Synced {$synced} users",$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
        }
        $active_tab = 'users';
    }

    // ── Suspend / Activate user
    if (in_array($action, ['suspend_user','activate_user'])) {
        $jc_uid = $_POST['jc_user_id'] ?? '';
        if ($jc_uid && $jc_connected) {
            $state = $action === 'suspend_user' ? 'SUSPENDED' : 'ACTIVATED';
            $res = jc_api('PUT', "/systemusers/{$jc_uid}", ['state' => $state]);
            if (isset($res['error'])) $error_msg = ucfirst(str_replace('_',' ',$action)).' failed: '.$res['error'];
            else {
                $pdo->prepare("UPDATE jc_users SET state=?,updated_at=NOW() WHERE jc_id=?")->execute([$state,$jc_uid]);
                $success_msg = ucwords(str_replace('_',' ',$action)).' successful.';
            }
        }
        $active_tab = 'users';
    }

    // ── Unlock user
    if ($action === 'unlock_user') {
        $jc_uid = $_POST['jc_user_id'] ?? '';
        if ($jc_uid && $jc_connected) {
            $res = jc_api('PUT', "/systemusers/{$jc_uid}", ['account_locked' => false]);
            if (isset($res['error'])) $error_msg = 'Unlock failed: '.$res['error'];
            else { $pdo->prepare("UPDATE jc_users SET account_locked='false',updated_at=NOW() WHERE jc_id=?")->execute([$jc_uid]); $success_msg = 'User unlocked.'; }
        }
        $active_tab = 'users';
    }

    // ── Sync Directory Insights
    if ($action === 'sync_insights') {
        if (!$jc_connected) { $error_msg = 'JUMPCLOUD_API_KEY not set.'; }
        else {
            $end_date   = date('c');
            $start_date = date('c', strtotime('-7 days'));
            $res = jc_api('POST', '/insights/directory/insights', [
                'service'     => ['all'],
                'start_date'  => $start_date,
                'end_date'    => $end_date,
                'sort'        => 'DESC',
                'limit'       => 100,
                'searchTermQueryType' => 'or',
            ], true); // v2

            if (isset($res['error'])) {
                $error_msg = 'Insights sync failed: '.$res['error'];
            } else {
                $events  = is_array($res) && isset($res[0]) ? $res : ($res['results'] ?? $res);
                $synced  = 0;
                // clear old
                try { $pdo->exec("TRUNCATE TABLE jc_events RESTART IDENTITY"); } catch(Exception $e){}
                foreach ((array)$events as $ev) {
                    $etime = null;
                    if (!empty($ev['timestamp'])) { try { $etime = date('Y-m-d H:i:s', strtotime($ev['timestamp'])); } catch(Exception $e){} }
                    $iby = $ev['initiated_by'] ?? [];
                    $iby = is_array($iby) ? ($iby['name'] ?? $iby['email'] ?? json_encode($iby)) : (string)$iby;
                    try {
                        $pdo->prepare("INSERT INTO jc_events (event_type,initiated_by,event_time,description,resource_type,resource_id,service,raw,created_at)
                            VALUES (?,?,?,?,?,?,?,?,NOW())")
                            ->execute([
                                $ev['event_type']??$ev['type']??'',
                                $iby,
                                $etime,
                                $ev['changes_in_event']??json_encode($ev['changes']??[]),
                                $ev['resource']['type']??'', $ev['resource']['id']??'',
                                $ev['service']??'', json_encode($ev),
                            ]);
                        $synced++;
                    } catch(Exception $e){}
                }
                $success_msg = "Synced {$synced} directory insight event(s).";
            }
        }
        $active_tab = 'insights';
    }
}

// ─────────────────────────────────────────────────────────
// Load from DB
// ─────────────────────────────────────────────────────────
$db_systems = []; $db_users = []; $db_events = [];
try {
    $db_systems = $pdo->query("SELECT * FROM jc_systems ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);
    $db_users   = $pdo->query("SELECT * FROM jc_users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $db_events  = $pdo->query("SELECT * FROM jc_events ORDER BY event_time DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Stat counts
$sys_total  = count($db_systems);
$sys_active = count(array_filter($db_systems, fn($s)=>!empty($s['active'])&&$s['active']!=='false'));
$sys_mfa    = count(array_filter($db_systems, fn($s)=>!empty($s['allow_multi_factor'])&&$s['allow_multi_factor']!=='false'));
$usr_total  = count($db_users);
$usr_active = count(array_filter($db_users, fn($u)=>strtoupper($u['state']??'')!=='SUSPENDED'&&strtoupper($u['state']??'')!=='STAGED'));
$usr_mfa    = count(array_filter($db_users, fn($u)=>!empty($u['mfa_enabled'])&&$u['mfa_enabled']!=='false'));
$usr_locked = count(array_filter($db_users, fn($u)=>!empty($u['account_locked'])&&$u['account_locked']!=='false'));

// Live data for groups / policies / apps (from API on demand per tab)
$live_usergroups   = [];
$live_systemgroups = [];
$live_policies     = [];
$live_apps         = [];
$live_radius       = [];

if ($jc_connected) {
    if ($active_tab === 'groups') {
        $ug = jc_api('GET', '/usergroups?limit=50', null, true);
        $live_usergroups = $ug['results'] ?? (isset($ug[0]) ? $ug : []);
        $sg = jc_api('GET', '/systemgroups?limit=50', null, true);
        $live_systemgroups = $sg['results'] ?? (isset($sg[0]) ? $sg : []);
    }
    if ($active_tab === 'policies') {
        $pol = jc_api('GET', '/policies?limit=100', null, true);
        $live_policies = $pol['results'] ?? (isset($pol[0]) ? $pol : []);
        $app = jc_api('GET', '/applications?limit=100&fields=id,name,active,ssoUrl,learnMore,ssoType');
        $live_apps = $app['results'] ?? (isset($app[0]) ? $app : []);
        $rad = jc_api('GET', '/radiusservers');
        $live_radius = $rad['results'] ?? (isset($rad[0]) ? $rad : []);
    }
}

// Search filters
$s_sys   = trim($_GET['s_sys']  ?? '');
$s_usr   = trim($_GET['s_usr']  ?? '');
$s_evnt  = trim($_GET['s_evnt'] ?? '');
if ($s_sys)  $db_systems = array_filter($db_systems, fn($s)=>stripos($s['display_name'].$s['hostname'].$s['os'],$s_sys)!==false);
if ($s_usr)  $db_users   = array_filter($db_users,   fn($u)=>stripos($u['username'].$u['email'].$u['firstname'].' '.$u['lastname'],$s_usr)!==false);
if ($s_evnt) $db_events  = array_filter($db_events,  fn($e)=>stripos($e['event_type'].$e['initiated_by'].$e['description'],$s_evnt)!==false);

function jc_state_badge(string $state): string {
    return match(strtoupper($state)) {
        'ACTIVATED','ACTIVE' => '<span class="px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700">Active</span>',
        'SUSPENDED'          => '<span class="px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700">Suspended</span>',
        'STAGED'             => '<span class="px-2 py-0.5 rounded text-xs font-semibold bg-yellow-100 text-yellow-800">Staged</span>',
        default              => '<span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-500">'.htmlspecialchars($state).'</span>',
    };
}
function jc_bool_icon(mixed $val): string {
    $is = !empty($val) && $val !== 'false' && $val !== false;
    return $is ? '<i class="fas fa-check-circle text-green-500"></i>' : '<i class="fas fa-times-circle text-gray-300"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>JumpCloud — Blue Mogul Admin</title>
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

<!-- ══════════════════ HEADER ══════════════════ -->
<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-green-600 rounded-xl flex items-center justify-center shadow-sm">
                <i class="fas fa-cloud-upload-alt text-white"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold text-gray-900">JumpCloud</h1>
                <p class="text-xs text-gray-400">Directory-as-a-Service — Identity & Device Management</p>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <?php if ($jc_connected): ?>
            <span class="flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>API Key Set
            </span>
            <?php else: ?>
            <span class="flex items-center gap-1.5 px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium">
                <span class="w-2 h-2 bg-red-500 rounded-full"></span>API Key Missing
            </span>
            <?php endif; ?>
            <?php if ($jc_connected): ?>
            <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sync_systems">
                <button class="px-3 py-1.5 border border-gray-300 text-sm text-gray-700 rounded-lg hover:bg-gray-50 flex items-center gap-2" data-testid="button-sync-systems">
                    <i class="fas fa-desktop text-green-500 text-xs"></i>Sync Systems
                </button>
            </form>
            <form method="POST" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sync_users">
                <button class="px-3 py-1.5 border border-gray-300 text-sm text-gray-700 rounded-lg hover:bg-gray-50 flex items-center gap-2" data-testid="button-sync-users">
                    <i class="fas fa-users text-blue-500 text-xs"></i>Sync Users
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <!-- Tabs -->
    <div class="flex gap-0 border-t border-gray-200 overflow-x-auto">
        <?php foreach ([
            ['overview',  'fa-tachometer-alt', 'Overview'],
            ['systems',   'fa-desktop',        'Systems ('.$sys_total.')'],
            ['users',     'fa-users',          'Users ('.$usr_total.')'],
            ['groups',    'fa-object-group',   'Groups'],
            ['policies',  'fa-file-shield',    'Policies & Apps'],
            ['insights',  'fa-history',        'Directory Insights'],
            ['settings',  'fa-cog',            'Settings'],
        ] as [$t,$ico,$lbl]): ?>
        <a href="?tab=<?= $t ?>" class="flex items-center gap-2 px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition
            <?= $active_tab===$t ? 'border-green-600 text-green-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>" data-testid="tab-<?= $t ?>">
            <i class="fas <?= $ico ?> text-xs"></i><?= $lbl ?>
        </a>
        <?php endforeach; ?>
    </div>
</header>

<div class="p-6">
<?php if ($success_msg): ?><div class="mb-5 flex items-center gap-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg" data-testid="alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success_msg) ?></div><?php endif; ?>
<?php if ($error_msg):   ?><div class="mb-5 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg" data-testid="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error_msg) ?></div><?php endif; ?>
<?php if (!$jc_connected): ?>
<div class="mb-5 flex items-start gap-3 bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg text-sm">
    <i class="fas fa-exclamation-triangle mt-0.5"></i>
    <div>
        <strong>JumpCloud API key not configured.</strong>
        Set the <code class="bg-amber-100 px-1 rounded font-mono text-xs">JUMPCLOUD_API_KEY</code> environment variable (and optionally <code class="bg-amber-100 px-1 rounded font-mono text-xs">JUMPCLOUD_ORG_ID</code> for multi-tenant) and restart the server.
        <a href="https://console.jumpcloud.com/#/settings/admin" target="_blank" rel="noopener" class="underline ml-1">Get your API key →</a>
    </div>
</div>
<?php endif; ?>

<?php /* ══════════════════ OVERVIEW ══════════════════ */ if ($active_tab === 'overview'): ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php foreach ([
        ['fa-desktop',     'bg-green-100 text-green-600',  $sys_total,  'Total Systems',    'text-gray-900'],
        ['fa-check-circle','bg-emerald-100 text-emerald-600',$sys_active,'Active Systems',   'text-gray-900'],
        ['fa-users',       'bg-blue-100 text-blue-600',    $usr_total,  'Total Users',       'text-gray-900'],
        ['fa-user-check',  'bg-indigo-100 text-indigo-600',$usr_active, 'Active Users',      'text-gray-900'],
        ['fa-shield-alt',  'bg-purple-100 text-purple-600',$usr_mfa,    'MFA Enabled',       'text-gray-900'],
        ['fa-lock',        'bg-red-100 text-red-600',      $usr_locked, 'Locked Accounts',   'text-red-700'],
        ['fa-history',     'bg-yellow-100 text-yellow-600',count($db_events),'Events Cached','text-gray-900'],
        ['fa-key',         $jc_connected?'bg-green-100 text-green-600':'bg-red-100 text-red-600',
                            $jc_connected?'Connected':'Not Set','API Status','text-gray-900'],
    ] as [$ico,$cls,$val,$lbl,$vcls]): ?>
    <div class="bg-white rounded-lg border border-gray-200 p-5">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-9 h-9 <?= $cls ?> rounded-lg flex items-center justify-center"><i class="fas <?= $ico ?> text-sm"></i></div>
            <span class="text-xs text-gray-500"><?= $lbl ?></span>
        </div>
        <p class="text-2xl font-bold <?= $vcls ?>"><?= is_numeric($val) ? number_format((int)$val) : htmlspecialchars((string)$val) ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Recent events preview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900 text-sm"><i class="fas fa-history text-yellow-500 mr-2"></i>Recent Events</h3>
        <a href="?tab=insights" class="text-xs text-blue-500 hover:underline">View all</a>
    </div>
    <?php if (empty($db_events)): ?>
    <div class="p-8 text-center text-gray-400 text-sm">
        No events cached. <a href="?tab=insights" class="text-blue-500 hover:underline">Sync Directory Insights</a>
    </div>
    <?php else: ?>
    <div class="divide-y divide-gray-50">
    <?php foreach(array_slice($db_events,0,6) as $ev): ?>
    <div class="px-5 py-3 flex items-start gap-3">
        <div class="w-7 h-7 bg-gray-100 rounded-full flex items-center justify-center shrink-0"><i class="fas fa-bolt text-gray-400 text-xs"></i></div>
        <div class="min-w-0">
            <p class="text-sm text-gray-800 font-medium truncate"><?= htmlspecialchars($ev['event_type']) ?></p>
            <p class="text-xs text-gray-400"><?= htmlspecialchars($ev['initiated_by']??'') ?> · <?= $ev['event_time'] ? date('M j H:i',strtotime($ev['event_time'])) : '' ?></p>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Systems preview -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900 text-sm"><i class="fas fa-desktop text-green-500 mr-2"></i>Managed Systems</h3>
        <a href="?tab=systems" class="text-xs text-blue-500 hover:underline">View all</a>
    </div>
    <?php if (empty($db_systems)): ?>
    <div class="p-8 text-center text-gray-400 text-sm">No systems synced yet. <a href="?tab=systems" class="text-blue-500 hover:underline">Sync Systems</a></div>
    <?php else: ?>
    <div class="divide-y divide-gray-50">
    <?php foreach(array_slice($db_systems,0,6) as $s): ?>
    <div class="px-5 py-2.5 flex items-center gap-3">
        <div class="w-7 h-7 <?= (!empty($s['active'])&&$s['active']!=='false')?'bg-green-100':'bg-gray-100' ?> rounded flex items-center justify-center shrink-0">
            <i class="fas fa-desktop text-xs <?= (!empty($s['active'])&&$s['active']!=='false')?'text-green-500':'text-gray-400' ?>"></i>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($s['display_name']?:$s['hostname']) ?></p>
            <p class="text-xs text-gray-400"><?= htmlspecialchars($s['os']) ?> <?= htmlspecialchars($s['os_version']) ?></p>
        </div>
        <span class="text-xs text-gray-400 shrink-0"><?= $s['last_contact'] ? date('M j',strtotime($s['last_contact'])) : '—' ?></span>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<?php /* ══════════════════ SYSTEMS ══════════════════ */ elseif ($active_tab === 'systems'): ?>
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-4 flex-wrap">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <input type="hidden" name="tab" value="systems">
            <input type="search" name="s_sys" value="<?= htmlspecialchars($s_sys) ?>" placeholder="Search hostname, OS…"
                class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-green-500" data-testid="input-search-systems">
            <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">Search</button>
        </form>
        <form method="POST" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="sync_systems">
            <button class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm flex items-center gap-2">
                <i class="fas fa-sync-alt text-xs"></i>Sync from JumpCloud
            </button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-systems">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name / Hostname</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">OS</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Version</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Arch</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">Active</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">SSH</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">MFA</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Agent</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Remote IP</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Last Contact</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($db_systems)): ?>
            <tr><td colspan="10" class="px-4 py-10 text-center text-gray-400">
                No systems synced. <?= $jc_connected ? '<a href="?tab=systems" class="text-blue-500 hover:underline">Click "Sync from JumpCloud"</a>' : 'Configure API key first.' ?>
            </td></tr>
            <?php else: ?>
            <?php foreach ($db_systems as $s): ?>
            <tr class="hover:bg-gray-50" data-testid="row-system-<?= $s['id'] ?>">
                <td class="px-4 py-3">
                    <p class="font-medium text-gray-900"><?= htmlspecialchars($s['display_name']?:$s['hostname']) ?></p>
                    <?php if ($s['hostname'] && $s['hostname'] !== $s['display_name']): ?>
                    <p class="text-xs text-gray-400"><?= htmlspecialchars($s['hostname']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($s['os']) ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs font-mono"><?= htmlspecialchars($s['os_version']) ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($s['arch']) ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon($s['active']) ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon($s['allow_ssh']) ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon($s['allow_multi_factor']) ?></td>
                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars($s['agent_version']) ?></td>
                <td class="px-4 py-3 text-xs font-mono text-gray-400"><?= htmlspecialchars($s['remote_ip']) ?></td>
                <td class="px-4 py-3 text-xs text-gray-400"><?= $s['last_contact'] ? date('Y-m-d H:i', strtotime($s['last_contact'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400">
        <?= count($db_systems) ?> system(s) · Last updated: <?= !empty($db_systems[0]['updated_at']) ? date('Y-m-d H:i', strtotime($db_systems[0]['updated_at'])) : 'Never' ?>
    </div>
</div>

<?php /* ══════════════════ USERS ══════════════════ */ elseif ($active_tab === 'users'): ?>
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-4 flex-wrap">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <input type="hidden" name="tab" value="users">
            <input type="search" name="s_usr" value="<?= htmlspecialchars($s_usr) ?>" placeholder="Search username, email…"
                class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-green-500" data-testid="input-search-users">
            <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">Search</button>
        </form>
        <div class="flex gap-2 text-xs text-gray-500">
            <span class="bg-gray-100 px-2 py-1 rounded"><?= $usr_active ?> Active</span>
            <span class="bg-yellow-100 text-yellow-700 px-2 py-1 rounded"><?= count(array_filter($db_users,fn($u)=>strtoupper($u['state']??'')==='STAGED')) ?> Staged</span>
            <span class="bg-red-100 text-red-700 px-2 py-1 rounded"><?= $usr_locked ?> Locked</span>
            <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded"><?= $usr_mfa ?> MFA</span>
        </div>
        <form method="POST" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="sync_users">
            <button class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm flex items-center gap-2">
                <i class="fas fa-sync-alt text-xs"></i>Sync Users
            </button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-users">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Username</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Email</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">State</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">MFA</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">LDAP</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">Sudo</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">Locked</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($db_users)): ?>
            <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">No users synced yet.</td></tr>
            <?php else: ?>
            <?php foreach ($db_users as $u): ?>
            <tr class="hover:bg-gray-50" data-testid="row-user-<?= $u['id'] ?>">
                <td class="px-4 py-3 font-mono font-medium text-gray-900"><?= htmlspecialchars($u['username']) ?></td>
                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars(trim(($u['firstname']??'').' '.($u['lastname']??''))) ?: '—' ?></td>
                <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($u['email']??'') ?></td>
                <td class="px-4 py-3 text-center"><?= jc_state_badge($u['state']??'') ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon($u['mfa_enabled']) ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon($u['ldap_binding_user']) ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon($u['sudo_enabled']) ?></td>
                <td class="px-4 py-3 text-center">
                    <?php if (!empty($u['account_locked'])&&$u['account_locked']!=='false'): ?>
                    <i class="fas fa-lock text-red-500"></i>
                    <?php else: ?>
                    <i class="fas fa-lock-open text-gray-300"></i>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3" onclick="event.stopPropagation()">
                    <div class="flex gap-1">
                    <?php if (strtoupper($u['state']??'') !== 'SUSPENDED'): ?>
                    <form method="POST" class="inline"><input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"><input type="hidden" name="action" value="suspend_user"><input type="hidden" name="jc_user_id" value="<?= htmlspecialchars($u['jc_id']) ?>">
                    <button class="px-2 py-1 border border-orange-300 text-orange-600 rounded text-xs hover:bg-orange-50" title="Suspend" data-testid="button-suspend-<?= $u['id'] ?>">Suspend</button></form>
                    <?php else: ?>
                    <form method="POST" class="inline"><input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"><input type="hidden" name="action" value="activate_user"><input type="hidden" name="jc_user_id" value="<?= htmlspecialchars($u['jc_id']) ?>">
                    <button class="px-2 py-1 border border-green-300 text-green-600 rounded text-xs hover:bg-green-50" data-testid="button-activate-<?= $u['id'] ?>">Activate</button></form>
                    <?php endif; ?>
                    <?php if (!empty($u['account_locked'])&&$u['account_locked']!=='false'): ?>
                    <form method="POST" class="inline"><input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"><input type="hidden" name="action" value="unlock_user"><input type="hidden" name="jc_user_id" value="<?= htmlspecialchars($u['jc_id']) ?>">
                    <button class="px-2 py-1 border border-blue-300 text-blue-600 rounded text-xs hover:bg-blue-50" data-testid="button-unlock-<?= $u['id'] ?>">Unlock</button></form>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400"><?= count($db_users) ?> user(s)</div>
</div>

<?php /* ══════════════════ GROUPS ══════════════════ */ elseif ($active_tab === 'groups'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- User Groups -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-users text-blue-500 mr-2"></i>User Groups</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-user-groups">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Description</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_usergroups)): ?>
            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No user groups found.' : 'API key required.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_usergroups as $g): ?>
            <tr class="hover:bg-gray-50" data-testid="row-ugroup-<?= htmlspecialchars($g['id']??'') ?>">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($g['name']??'—') ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700"><?= htmlspecialchars($g['type']??'user_group') ?></span></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($g['description']??'') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- System Groups -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-server text-green-500 mr-2"></i>System Groups</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-system-groups">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Description</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_systemgroups)): ?>
            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No system groups found.' : 'API key required.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_systemgroups as $g): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($g['name']??'—') ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700"><?= htmlspecialchars($g['type']??'system_group') ?></span></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($g['description']??'') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php /* ══════════════════ POLICIES & APPS ══════════════════ */ elseif ($active_tab === 'policies'): ?>
<!-- Policies -->
<div class="bg-white rounded-lg border border-gray-200 mb-6">
    <div class="px-5 py-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-file-shield text-purple-500 mr-2"></i>Policies</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-policies">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Template</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">Active</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">ID</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_policies)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No policies found.' : 'Configure API key.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_policies as $p): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($p['name']??'—') ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($p['template']['name']??$p['templateId']??'—') ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon(!empty($p['active'])) ?></td>
                <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= htmlspecialchars(substr($p['id']??'',0,24)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SSO Applications -->
<div class="bg-white rounded-lg border border-gray-200 mb-6">
    <div class="px-5 py-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-th text-indigo-500 mr-2"></i>SSO Applications</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-apps">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">Active</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">SSO Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">URL</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_apps)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No applications found.' : 'Configure API key.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_apps as $a): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($a['name']??'—') ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon(!empty($a['active'])) ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs bg-indigo-100 text-indigo-700"><?= htmlspecialchars($a['ssoType']??'—') ?></span></td>
                <td class="px-4 py-3 text-xs text-blue-500 truncate max-w-xs">
                    <?php if (!empty($a['ssoUrl'])): ?><a href="<?= htmlspecialchars($a['ssoUrl']) ?>" target="_blank" rel="noopener" class="hover:underline"><?= htmlspecialchars(substr($a['ssoUrl'],0,50)).(strlen($a['ssoUrl'])>50?'…':'') ?></a><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- RADIUS Servers -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-wifi text-cyan-500 mr-2"></i>RADIUS Servers</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-radius">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Network CIDR</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Auth Protocol</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">ID</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_radius)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No RADIUS servers found.' : 'Configure API key.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_radius as $r): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($r['name']??'—') ?></td>
                <td class="px-4 py-3 font-mono text-xs text-gray-500"><?= htmlspecialchars($r['networkSourceIp']??'—') ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs bg-cyan-100 text-cyan-700"><?= htmlspecialchars($r['authIdp']??$r['mfa']??'—') ?></span></td>
                <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= htmlspecialchars(substr($r['id']??'',0,18)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* ══════════════════ DIRECTORY INSIGHTS ══════════════════ */ elseif ($active_tab === 'insights'): ?>
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-history text-yellow-500 mr-2"></i>Directory Insights (Audit Log)</h3>
        <div class="flex gap-3 items-center flex-wrap">
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="tab" value="insights">
                <input type="search" name="s_evnt" value="<?= htmlspecialchars($s_evnt) ?>" placeholder="Filter events…"
                    class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-52 focus:outline-none focus:ring-2 focus:ring-yellow-500" data-testid="input-search-events">
                <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm">Filter</button>
            </form>
            <form method="POST" class="inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="sync_insights">
                <button class="px-3 py-1.5 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg text-sm flex items-center gap-2" data-testid="button-sync-insights">
                    <i class="fas fa-sync-alt text-xs"></i>Sync Last 7 Days
                </button>
            </form>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-events">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Time</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Event Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Initiated By</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Service</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Resource</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Details</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($db_events)): ?>
            <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">
                No events cached. <?= $jc_connected ? '<a href="?tab=insights" class="text-blue-500 hover:underline">Click "Sync Last 7 Days"</a>' : 'Configure API key first.' ?>
            </td></tr>
            <?php else: ?>
            <?php foreach ($db_events as $ev): ?>
            <tr class="hover:bg-gray-50" data-testid="row-event-<?= $ev['id'] ?>">
                <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap"><?= $ev['event_time'] ? date('Y-m-d H:i:s',strtotime($ev['event_time'])) : '—' ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-semibold bg-blue-100 text-blue-700"><?= htmlspecialchars($ev['event_type']) ?></span></td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars($ev['initiated_by']??'') ?></td>
                <td class="px-4 py-3 text-xs text-gray-400"><?= htmlspecialchars($ev['service']??'') ?></td>
                <td class="px-4 py-3 text-xs text-gray-400"><?= htmlspecialchars($ev['resource_type']??'') ?></td>
                <td class="px-4 py-3 text-xs text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($ev['description']??'') ?>"><?= htmlspecialchars(substr($ev['description']??'',0,80)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400"><?= count($db_events) ?> event(s) cached</div>
</div>

<?php /* ══════════════════ SETTINGS ══════════════════ */ elseif ($active_tab === 'settings'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-4xl">
    <!-- Connection info -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-900 mb-4"><i class="fas fa-plug text-green-500 mr-2"></i>Connection Details</h3>
        <div class="space-y-3">
            <?php foreach ([
                ['API Key',    $jc_api_key  ? '••••••••••••••••'.substr($jc_api_key,-4)  : 'NOT SET', !empty($jc_api_key)],
                ['Org ID',     $jc_org_id   ?: '(not set — single org mode)',              true],
                ['API v1 URL', JUMPCLOUD_API_V1,  true],
                ['API v2 URL', JUMPCLOUD_API_V2,  true],
            ] as [$lbl,$val,$ok]): ?>
            <div class="flex items-start justify-between gap-4">
                <span class="text-sm text-gray-500 w-24 shrink-0"><?= $lbl ?></span>
                <span class="text-sm font-mono <?= $ok?'text-gray-700':'text-red-600 font-semibold' ?> text-right break-all"><?= htmlspecialchars($val) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-5 pt-4 border-t border-gray-100">
            <form method="POST">
                <?= csrf_field() ?><input type="hidden" name="action" value="test_connection">
                <button class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium flex items-center justify-center gap-2 transition" data-testid="button-test-connection">
                    <i class="fas fa-plug"></i>Test Connection
                </button>
            </form>
        </div>
    </div>

    <!-- Env vars guide -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-900 mb-3"><i class="fas fa-book text-blue-500 mr-2"></i>Configuration</h3>
        <p class="text-sm text-gray-500 mb-4">Set these environment variables in your Replit Secrets or deployment environment:</p>
        <div class="space-y-2">
            <?php foreach ([
                ['JUMPCLOUD_API_KEY', 'Your JumpCloud API key', $jc_api_key ? 'Set ✓' : 'MISSING', $jc_api_key ? 'text-green-600' : 'text-red-600'],
                ['JUMPCLOUD_ORG_ID',  'For multi-tenant / MSP orgs (optional)', $jc_org_id ? 'Set ✓' : 'Not set', $jc_org_id ? 'text-green-600' : 'text-gray-400'],
            ] as [$key,$desc,$status,$cls]): ?>
            <div class="border border-gray-100 rounded-lg p-3">
                <div class="flex items-center justify-between mb-0.5">
                    <code class="font-mono text-xs bg-gray-100 px-1.5 py-0.5 rounded text-gray-700"><?= $key ?></code>
                    <span class="text-xs font-medium <?= $cls ?>"><?= $status ?></span>
                </div>
                <p class="text-xs text-gray-400"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-700">
            <strong>How to get your API key:</strong><br>
            JumpCloud Console → Admin → API Key<br>
            or visit <a href="https://console.jumpcloud.com/#/settings/admin" target="_blank" class="underline">console.jumpcloud.com → Settings → Admin</a>
        </div>
    </div>

    <!-- Sync summary -->
    <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-900 mb-4"><i class="fas fa-database text-indigo-500 mr-2"></i>Local Cache Summary</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach ([
                ['jc_systems','Systems',  $sys_total, 'fa-desktop',  'bg-green-100 text-green-600'],
                ['jc_users',  'Users',    $usr_total, 'fa-users',    'bg-blue-100 text-blue-600'],
                ['jc_events', 'Events',   count($db_events), 'fa-history', 'bg-yellow-100 text-yellow-600'],
            ] as [$tbl,$lbl,$cnt,$ico,$cls]): ?>
            <div class="border border-gray-100 rounded-lg p-4 text-center">
                <div class="w-10 h-10 <?= $cls ?> rounded-full flex items-center justify-center mx-auto mb-2"><i class="fas <?= $ico ?> text-sm"></i></div>
                <p class="text-2xl font-bold text-gray-900"><?= $cnt ?></p>
                <p class="text-xs text-gray-500"><?= $lbl ?></p>
            </div>
            <?php endforeach; ?>
            <div class="border border-gray-100 rounded-lg p-4 text-center">
                <div class="w-10 h-10 <?= $jc_connected ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' ?> rounded-full flex items-center justify-center mx-auto mb-2">
                    <i class="fas <?= $jc_connected ? 'fa-check' : 'fa-times' ?> text-sm"></i>
                </div>
                <p class="text-sm font-bold text-gray-900"><?= $jc_connected ? 'Active' : 'Inactive' ?></p>
                <p class="text-xs text-gray-500">API Status</p>
            </div>
        </div>
        <!-- Quick actions -->
        <div class="mt-4 flex flex-wrap gap-3">
            <?php foreach ([
                ['sync_systems',  'Sync Systems',         'bg-green-600 hover:bg-green-700'],
                ['sync_users',    'Sync Users',            'bg-blue-600 hover:bg-blue-700'],
                ['sync_insights', 'Sync Events (7 days)',  'bg-yellow-600 hover:bg-yellow-700'],
            ] as [$act,$lbl,$cls]): ?>
            <form method="POST" class="inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>">
                <button class="px-4 py-2 <?= $cls ?> text-white rounded-lg text-sm font-medium flex items-center gap-2 transition" data-testid="button-<?= $act ?>-settings">
                    <i class="fas fa-sync-alt text-xs"></i><?= $lbl ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /p-6 -->
</div><!-- /flex-1 -->
</div><!-- /flex -->
</body>
</html>
