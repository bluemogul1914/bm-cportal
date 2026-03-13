<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$action1_client_id = ACTION1_CLIENT_ID;
$action1_client_secret = ACTION1_API_KEY;
$action1_api_url = ACTION1_API_URL;
$action1_connected = !empty($action1_client_id) && !empty($action1_client_secret);
$pdo = getDB();

$success_msg = '';
$error_msg = '';

function action1_get_token() {
    $base = rtrim(ACTION1_API_URL, '/');
    $url = $base . '/oauth2/token';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => ACTION1_CLIENT_ID,
            'client_secret' => ACTION1_API_KEY,
        ]),
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) return ['error' => 'Token request failed: ' . $curl_error];
    if ($http_code < 200 || $http_code >= 300) {
        $body = json_decode($response, true);
        $msg = $body['error_description'] ?? $body['error'] ?? $body['message'] ?? "HTTP $http_code";
        return ['error' => 'Authentication failed: ' . $msg];
    }
    $decoded = json_decode($response, true);
    if (!isset($decoded['access_token'])) return ['error' => 'No access_token in auth response'];
    return $decoded;
}

$_action1_token_cache = ['token' => null, 'expires_at' => 0];

function action1_api_request($method, $path, $body = null, $is_retry = false) {
    global $_action1_token_cache;

    if (!$_action1_token_cache['token'] || time() >= $_action1_token_cache['expires_at']) {
        $auth = action1_get_token();
        if (isset($auth['error'])) return $auth;
        $_action1_token_cache['token'] = $auth['access_token'];
        $_action1_token_cache['expires_at'] = time() + (($auth['expires_in'] ?? 3600) - 60);
    }

    $url = $path;
    if (strpos($path, 'http') !== 0) {
        $base = rtrim(ACTION1_API_URL, '/');
        $url = $base . $path;
    }

    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $_action1_token_cache['token'],
    ];
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    } elseif (strtoupper($method) === 'GET') {
        $opts[CURLOPT_HTTPGET] = true;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => $curl_error, 'http_code' => 0];
    }
    if (($http_code === 401 || $http_code === 403) && !$is_retry) {
        $_action1_token_cache = ['token' => null, 'expires_at' => 0];
        return action1_api_request($method, $path, $body, true);
    }
    if ($http_code < 200 || $http_code >= 300) {
        $body_resp = json_decode($response, true);
        $msg = $body_resp['error'] ?? $body_resp['message'] ?? $body_resp['detail'] ?? "HTTP $http_code";
        return ['error' => $msg, 'http_code' => $http_code];
    }
    $decoded = json_decode($response, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON: ' . json_last_error_msg(), 'http_code' => $http_code];
    }
    return $decoded;
}

function action1_get_org_id() {
    $result = action1_api_request('GET', '/organizations');
    if (isset($result['error'])) return $result;
    $items = $result['items'] ?? [];
    if (empty($items)) return ['error' => 'No organizations found in Action1 account'];
    return ['org_id' => $items[0]['id']];
}

$clients_list = [];
try {
    $clients_list = $pdo->query("SELECT id, name, company FROM clients WHERE status = 'active' ORDER BY company, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS action1_endpoints (
        id SERIAL PRIMARY KEY,
        action1_id VARCHAR(255) UNIQUE NOT NULL,
        name VARCHAR(255) DEFAULT '',
        hostname VARCHAR(255) DEFAULT '',
        os_name VARCHAR(255) DEFAULT '',
        os_version VARCHAR(255) DEFAULT '',
        ip_address VARCHAR(100) DEFAULT '',
        mac_address VARCHAR(100) DEFAULT '',
        status VARCHAR(50) DEFAULT 'unknown',
        last_seen TIMESTAMP,
        agent_version VARCHAR(100) DEFAULT '',
        group_name VARCHAR(255) DEFAULT '',
        client_id INTEGER,
        missing_updates INTEGER DEFAULT 0,
        missing_updates_critical INTEGER DEFAULT 0,
        vulnerabilities_critical INTEGER DEFAULT 0,
        vulnerabilities_noncritical INTEGER DEFAULT 0,
        reboot_required BOOLEAN DEFAULT FALSE,
        user_name VARCHAR(255) DEFAULT '',
        updated_at TIMESTAMP DEFAULT NOW()
    )");
    foreach ([
        'missing_updates INTEGER DEFAULT 0',
        'missing_updates_critical INTEGER DEFAULT 0',
        'vulnerabilities_critical INTEGER DEFAULT 0',
        'vulnerabilities_noncritical INTEGER DEFAULT 0',
        'reboot_required BOOLEAN DEFAULT FALSE',
        'user_name VARCHAR(255) DEFAULT \'\'',
    ] as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->exec("ALTER TABLE action1_endpoints ADD COLUMN IF NOT EXISTS $col_def"); } catch (Exception $e) {}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS action1_alerts (
        id SERIAL PRIMARY KEY,
        action1_alert_id VARCHAR(255) UNIQUE NOT NULL,
        endpoint_id VARCHAR(255) DEFAULT '',
        severity VARCHAR(50) DEFAULT 'info',
        category VARCHAR(255) DEFAULT '',
        message TEXT DEFAULT '',
        status VARCHAR(50) DEFAULT 'open',
        created_at_remote TIMESTAMP,
        updated_at TIMESTAMP DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS action1_vulnerabilities (
        id SERIAL PRIMARY KEY,
        cve_id VARCHAR(100) DEFAULT '',
        cvss_score NUMERIC(4,1) DEFAULT 0,
        cisa_kev BOOLEAN DEFAULT FALSE,
        published_date DATE,
        remediation_status VARCHAR(100) DEFAULT '',
        vulnerable_software VARCHAR(255) DEFAULT '',
        endpoints_count INTEGER DEFAULT 0,
        org_id VARCHAR(255) DEFAULT '',
        updated_at TIMESTAMP DEFAULT NOW(),
        UNIQUE (cve_id, org_id)
    )");
} catch (Exception $e) {}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'test_connection' && $action1_connected) {
        $token_result = action1_get_token();
        if (isset($token_result['error'])) {
            $error_msg = 'Step 1 (Auth) FAILED: ' . $token_result['error'] . ' — Token URL: ' . rtrim(ACTION1_API_URL, '/') . '/oauth2/token';
        } else {
            $org_result = action1_get_org_id();
            if (isset($org_result['error'])) {
                $error_msg = 'Step 1 (Auth) OK. Step 2 (Org lookup) FAILED: ' . $org_result['error'];
            } else {
                $org_id = $org_result['org_id'];
                $ep_test = action1_api_request('GET', '/endpoints/managed/' . urlencode($org_id));
                $ep_count = isset($ep_test['error']) ? ('ERROR: ' . $ep_test['error']) : count($ep_test['items'] ?? []) . ' endpoint(s) found';
                $success_msg = "Auth OK ✓ | Org ID: {$org_id} ✓ | Endpoint list: {$ep_count}";
                if (isset($ep_test['error'])) {
                    $ep_test2 = action1_api_request('GET', '/endpoints/managed/' . urlencode($org_id) . '?fields=*');
                    $ep_count2 = isset($ep_test2['error']) ? ('ERROR: ' . $ep_test2['error']) : count($ep_test2['items'] ?? []) . ' endpoint(s) with fields=*';
                    $success_msg .= " | With fields=*: {$ep_count2}";
                    $error_msg = $success_msg;
                    $success_msg = '';
                }
            }
        }
    }

    if ($action === 'sync_endpoints' && $action1_connected) {
        $org = action1_get_org_id();
        if (isset($org['error'])) {
            $error_msg = 'Endpoint sync failed: ' . $org['error'];
        } else {
            $org_id = $org['org_id'];
            $endpoint_paths = [
                '/endpoints/managed/' . urlencode($org_id),
                '/endpoints/managed/' . urlencode($org_id) . '?fields=*',
                '/endpoints/' . urlencode($org_id),
                '/endpoints/' . urlencode($org_id) . '?fields=*',
            ];
            $api_data = null;
            $tried_paths = [];
            foreach ($endpoint_paths as $ep_path) {
                $resp = action1_api_request('GET', $ep_path);
                $tried_paths[] = $ep_path . ' → ' . (isset($resp['error']) ? 'error: ' . $resp['error'] : count($resp['items'] ?? (is_array($resp) && !isset($resp['error']) ? $resp : [])) . ' items');
                if (!isset($resp['error'])) {
                    $api_data = $resp;
                    break;
                }
            }

            if ($api_data === null) {
                $error_msg = 'Endpoint sync failed. Tried paths: ' . implode(' | ', $tried_paths);
            } else {
                $api_endpoints = $api_data['items'] ?? (is_array($api_data) && isset($api_data[0]) ? $api_data : []);
                $synced = 0;
                $next_page = $api_data['next_page'] ?? null;

                $all_endpoints = $api_endpoints;
                while ($next_page) {
                    $page_data = action1_api_request('GET', $next_page);
                    if (isset($page_data['error'])) break;
                    $all_endpoints = array_merge($all_endpoints, $page_data['items'] ?? []);
                    $next_page = $page_data['next_page'] ?? null;
                }

                $db_errors = [];
                foreach ($all_endpoints as $ep) {
                    $ep_id = $ep['id'] ?? '';
                    if (!$ep_id) continue;

                    $vulns = $ep['vulnerabilities'] ?? [];
                    $vulns_critical = is_array($vulns) ? (int)($vulns['critical'] ?? $vulns['Critical'] ?? 0) : (int)$vulns;
                    $vulns_noncritical = is_array($vulns) ? (int)($vulns['non_critical'] ?? $vulns['non-critical'] ?? $vulns['NonCritical'] ?? 0) : 0;

                    $updates = $ep['missing_updates'] ?? $ep['updates'] ?? [];
                    $missing_critical = is_array($updates) ? (int)($updates['critical'] ?? $updates['Critical'] ?? 0) : 0;
                    $missing_total = is_array($updates)
                        ? ((int)($updates['total'] ?? ($updates['critical'] ?? 0) + ($updates['non_critical'] ?? $updates['non-critical'] ?? 0)))
                        : (int)$updates;

                    $reboot_raw = strtolower((string)($ep['reboot'] ?? $ep['reboot_required'] ?? ''));
                    $reboot_flag = ($reboot_raw === 'required' || $reboot_raw === 'true' || $reboot_raw === '1') ? 1 : 0;

                    $grp = $ep['endpoint_groups'] ?? $ep['endpoint_group'] ?? $ep['group_name'] ?? '';
                    if (is_array($grp)) $grp = implode(', ', $grp);

                    $ip = $ep['ip_address'] ?? '';
                    if (is_array($ip)) $ip = implode(', ', $ip);
                    if (!$ip) $ip = $ep['internal_addresses'] ?? '';
                    if (is_array($ip)) $ip = implode(', ', $ip);

                    $raw_last_seen = $ep['last_seen'] ?? $ep['last_seen_at'] ?? null;
                    $last_seen = null;
                    if ($raw_last_seen) {
                        $ts = is_numeric($raw_last_seen) ? (int)$raw_last_seen : strtotime($raw_last_seen);
                        if ($ts && $ts > 0) $last_seen = date('Y-m-d H:i:s', $ts);
                    }

                    $uname = $ep['user'] ?? $ep['user_name'] ?? $ep['username'] ?? '';
                    if (is_array($uname)) $uname = implode(', ', $uname);

                    try {
                        $pdo->prepare("INSERT INTO action1_endpoints (action1_id, name, hostname, os_name, os_version, ip_address, mac_address, status, last_seen, agent_version, group_name, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON CONFLICT (action1_id) DO UPDATE SET
                                name = EXCLUDED.name, hostname = EXCLUDED.hostname,
                                os_name = EXCLUDED.os_name, os_version = EXCLUDED.os_version,
                                ip_address = EXCLUDED.ip_address, mac_address = EXCLUDED.mac_address,
                                status = EXCLUDED.status, last_seen = EXCLUDED.last_seen,
                                agent_version = EXCLUDED.agent_version, group_name = EXCLUDED.group_name,
                                updated_at = NOW()")
                            ->execute([
                                $ep_id,
                                $ep['name'] ?? $ep['hostname'] ?? '',
                                $ep['hostname'] ?? '',
                                $ep['os_name'] ?? $ep['os_type'] ?? '',
                                $ep['os_version'] ?? '',
                                $ip,
                                $ep['mac_address'] ?? '',
                                $ep['status'] ?? $ep['online_status'] ?? 'unknown',
                                $last_seen,
                                $ep['agent_version'] ?? '',
                                $grp,
                            ]);
                        $synced++;

                        foreach ([
                            "UPDATE action1_endpoints SET user_name = ? WHERE action1_id = ?",
                            "UPDATE action1_endpoints SET missing_updates = ? WHERE action1_id = ?",
                            "UPDATE action1_endpoints SET missing_updates_critical = ? WHERE action1_id = ?",
                            "UPDATE action1_endpoints SET vulnerabilities_critical = ? WHERE action1_id = ?",
                            "UPDATE action1_endpoints SET vulnerabilities_noncritical = ? WHERE action1_id = ?",
                            "UPDATE action1_endpoints SET reboot_required = ? WHERE action1_id = ?",
                        ] as $idx => $upd_sql) {
                            $val = [$uname, $missing_total, $missing_critical, $vulns_critical, $vulns_noncritical, $reboot_flag][$idx];
                            try { $pdo->prepare($upd_sql)->execute([$val, $ep_id]); } catch (Exception $e) {}
                        }

                    } catch (Exception $e) {
                        $db_errors[] = substr($e->getMessage(), 0, 120);
                    }
                }
                if ($synced === 0) {
                    $db_err_detail = !empty($db_errors) ? ' DB error: ' . $db_errors[0] : ' (no DB errors captured — columns may be missing)';
                    $error_msg = "API returned " . count($all_endpoints) . " endpoint(s) but none saved to DB. Org: {$org_id}{$db_err_detail}";
                } else {
                    $success_msg = "Synced {$synced} endpoints from Action1 (Org: {$org_id}).";
                }

                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'action1_sync', 'action1_endpoint', 0, "Synced {$synced} endpoints from Action1", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            }
        }
    }

    if ($action === 'sync_alerts' && $action1_connected) {
        $org = action1_get_org_id();
        if (isset($org['error'])) {
            $error_msg = 'Alert sync failed: ' . $org['error'];
        } else {
            $org_id = $org['org_id'];
            $status_data = action1_api_request('GET', '/endpoints/status/' . urlencode($org_id));

            if (isset($status_data['error'])) {
                $error_msg = 'Alert sync failed: ' . $status_data['error'];
            } else {
                $status_items = $status_data['items'] ?? [$status_data];
                $synced = 0;
                foreach ($status_items as $st) {
                    $ep_id = $st['id'] ?? $st['endpoint_id'] ?? '';
                    if (!$ep_id) continue;
                    $ep_status = strtolower($st['status'] ?? $st['online_status'] ?? '');
                    if ($ep_status === 'offline' || $ep_status === 'disconnected' || $ep_status === 'error') {
                        $alert_id = 'status_' . $ep_id . '_' . date('Y-m-d');
                        $ep_name = $st['name'] ?? $st['hostname'] ?? $ep_id;
                        try {
                            $pdo->prepare("INSERT INTO action1_alerts (action1_alert_id, endpoint_id, severity, category, message, status, created_at_remote, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW()) ON CONFLICT (action1_alert_id) DO UPDATE SET severity = EXCLUDED.severity, status = EXCLUDED.status, message = EXCLUDED.message, updated_at = NOW()")
                                ->execute([
                                    $alert_id,
                                    $ep_id,
                                    $ep_status === 'error' ? 'critical' : 'warning',
                                    'endpoint_status',
                                    "Endpoint '{$ep_name}' is {$ep_status}",
                                    'open',
                                ]);
                            $synced++;
                        } catch (Exception $e) {}
                    }
                }
                $pdo->exec("UPDATE action1_alerts SET status = 'resolved', updated_at = NOW() WHERE category = 'endpoint_status' AND status = 'open' AND updated_at < NOW() - INTERVAL '1 day'");

                $success_msg = "Synced endpoint status. {$synced} alert(s) generated for offline/error endpoints.";

                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'action1_alert_sync', 'action1_alert', 0, "Alert sync: {$synced} alerts generated", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            }
        }
    }

    if ($action === 'sync_patches' && $action1_connected) {
        $org = action1_get_org_id();
        if (isset($org['error'])) {
            $error_msg = 'Patch sync failed: ' . $org['error'];
        } else {
            $org_id = $org['org_id'];
            $synced_vulns = 0;
            $vuln_paths = [
                '/vulnerabilities/' . urlencode($org_id) . '?limit=200&from=0',
                '/policy/vulnerabilities/' . urlencode($org_id) . '?limit=200&from=0',
                '/endpoints/vulnerabilities/' . urlencode($org_id) . '?limit=200&from=0',
            ];
            $vuln_items = [];
            foreach ($vuln_paths as $vpath) {
                $vdata = action1_api_request('GET', $vpath);
                if (!isset($vdata['error'])) {
                    $vuln_items = $vdata['items'] ?? (isset($vdata['cve_id']) ? [$vdata] : []);
                    break;
                }
            }
            foreach ($vuln_items as $vuln) {
                $cve = $vuln['cve_id'] ?? $vuln['cve'] ?? $vuln['id'] ?? '';
                if (!$cve) continue;
                $pub_date = $vuln['published_date'] ?? $vuln['publish_date'] ?? $vuln['date'] ?? null;
                if ($pub_date) {
                    try { $pub_date = date('Y-m-d', strtotime($pub_date)); } catch (Exception $e) { $pub_date = null; }
                }
                $cvss = (float)($vuln['cvss_score'] ?? $vuln['cvss'] ?? $vuln['score'] ?? 0);
                $cisa = !empty($vuln['cisa_kev']) || !empty($vuln['cisa']) ? 'true' : 'false';
                $rem_status = $vuln['remediation_status'] ?? $vuln['status'] ?? '';
                $software = $vuln['vulnerable_software'] ?? $vuln['software'] ?? $vuln['application'] ?? '';
                $ep_count = (int)($vuln['endpoints_count'] ?? $vuln['endpoints'] ?? $vuln['affected_endpoints'] ?? 0);
                try {
                    $pdo->prepare("INSERT INTO action1_vulnerabilities (cve_id, cvss_score, cisa_kev, published_date, remediation_status, vulnerable_software, endpoints_count, org_id, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON CONFLICT (cve_id, org_id) DO UPDATE SET
                            cvss_score = EXCLUDED.cvss_score, cisa_kev = EXCLUDED.cisa_kev,
                            published_date = EXCLUDED.published_date, remediation_status = EXCLUDED.remediation_status,
                            vulnerable_software = EXCLUDED.vulnerable_software, endpoints_count = EXCLUDED.endpoints_count,
                            updated_at = NOW()")
                        ->execute([$cve, $cvss, $cisa, $pub_date, $rem_status, $software, $ep_count, $org_id]);
                    $synced_vulns++;
                } catch (Exception $e) {}
            }

            $success_msg = "Patch sync complete. {$synced_vulns} vulnerabilit" . ($synced_vulns === 1 ? 'y' : 'ies') . " synced from Action1. Run Sync Endpoints first to update per-endpoint patch counts.";
            try {
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'action1_patch_sync', 'action1_vulnerability', 0, $success_msg, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            } catch (Exception $e) {}
        }
    }
}

$db_endpoints = [];
try {
    $db_endpoints = $pdo->query("SELECT ae.*, c.name as client_name, c.company as client_company FROM action1_endpoints ae LEFT JOIN clients c ON ae.client_id = c.id ORDER BY ae.status ASC, ae.name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$db_alerts = [];
try {
    $db_alerts = $pdo->query("SELECT * FROM action1_alerts ORDER BY created_at_remote DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$endpoint_count = count($db_endpoints);
$online_count = 0;
$offline_count = 0;
foreach ($db_endpoints as $ep) {
    $status = strtolower($ep['status'] ?? '');
    if ($status === 'online' || $status === 'active' || $status === 'connected') {
        $online_count++;
    } else {
        $offline_count++;
    }
}
$alert_count = count($db_alerts);

$os_breakdown = [];
foreach ($db_endpoints as $ep) {
    $os = $ep['os_name'] ?: 'Unknown';
    $os_breakdown[$os] = ($os_breakdown[$os] ?? 0) + 1;
}
arsort($os_breakdown);

$last_synced_time = null;
try {
    $stmt = $pdo->query("SELECT MAX(updated_at) as last_sync FROM action1_endpoints");
    $row = $stmt->fetch();
    $last_synced_time = $row['last_sync'] ?? null;
} catch (Exception $e) {}

$total_missing_updates = 0;
$total_vulns_critical = 0;
$total_vulns_noncritical = 0;
$total_reboot_required = 0;
foreach ($db_endpoints as $ep) {
    $total_missing_updates += (int)($ep['missing_updates'] ?? 0);
    $total_vulns_critical += (int)($ep['vulnerabilities_critical'] ?? 0);
    $total_vulns_noncritical += (int)($ep['vulnerabilities_noncritical'] ?? 0);
    if (!empty($ep['reboot_required']) && $ep['reboot_required'] !== 'false' && $ep['reboot_required'] !== '0') {
        $total_reboot_required++;
    }
}

$db_vulnerabilities = [];
try {
    $db_vulnerabilities = $pdo->query("SELECT * FROM action1_vulnerabilities ORDER BY cvss_score DESC, cve_id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$patch_last_synced = null;
try {
    $row = $pdo->query("SELECT MAX(updated_at) as ls FROM action1_vulnerabilities")->fetch();
    $patch_last_synced = $row['ls'] ?? null;
} catch (Exception $e) {}

$client_patch_summary = [];
foreach ($db_endpoints as $ep) {
    $cid = $ep['client_id'] ?? 0;
    $clabel = trim(($ep['client_company'] ?? '') . ' ' . ($ep['client_name'] ?? ''));
    if (!$clabel) $clabel = 'Unassigned';
    if (!isset($client_patch_summary[$cid])) {
        $client_patch_summary[$cid] = [
            'label' => $clabel,
            'endpoints' => 0,
            'missing_updates' => 0,
            'vulns_critical' => 0,
            'vulns_noncritical' => 0,
            'reboot_required' => 0,
        ];
    }
    $client_patch_summary[$cid]['endpoints']++;
    $client_patch_summary[$cid]['missing_updates'] += (int)($ep['missing_updates'] ?? 0);
    $client_patch_summary[$cid]['vulns_critical'] += (int)($ep['vulnerabilities_critical'] ?? 0);
    $client_patch_summary[$cid]['vulns_noncritical'] += (int)($ep['vulnerabilities_noncritical'] ?? 0);
    if (!empty($ep['reboot_required']) && $ep['reboot_required'] !== 'false' && $ep['reboot_required'] !== '0') {
        $client_patch_summary[$cid]['reboot_required']++;
    }
}
uasort($client_patch_summary, fn($a, $b) => $b['vulns_critical'] <=> $a['vulns_critical']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action1 RMM - Blue Mogul Admin</title>
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
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-shield-alt text-blue-500 mr-2"></i>Action1 RMM Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Remote monitoring &mdash; Endpoints, devices, patches, and alerts</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($action1_connected): ?>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-test-connection">
                                <i class="fas fa-plug mr-1"></i>Test API
                            </button>
                        </form>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sync_endpoints">
                            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-sync-endpoints">
                                <i class="fas fa-sync-alt mr-1"></i>Sync Endpoints
                            </button>
                        </form>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sync_patches">
                            <button type="submit" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-sync-patches">
                                <i class="fas fa-shield-virus mr-1"></i>Sync Patches
                            </button>
                        </form>
                        <form method="POST" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sync_alerts">
                            <button type="submit" class="px-3 py-1.5 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-sync-alerts">
                                <i class="fas fa-bell mr-1"></i>Sync Alerts
                            </button>
                        </form>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-3"></i><span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-3"></i><span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($action1_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">OAuth2 credentials configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">ACTION1_CLIENT_ID or ACTION1_API_KEY not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-endpoints">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Endpoints</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-endpoints"><?php echo (int)$endpoint_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $last_synced_time ? 'Last sync: ' . date('M d, g:i A', strtotime($last_synced_time)) : 'Not synced yet'; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-online-endpoints">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Online</p>
                    <p class="text-2xl font-bold text-green-600" data-testid="text-online-endpoints"><?php echo (int)$online_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $endpoint_count > 0 ? round(($online_count / $endpoint_count) * 100, 1) . '% online' : 'No data'; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-alerts">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active Alerts</p>
                    <p class="text-2xl font-bold text-yellow-600" data-testid="text-alert-count"><?php echo (int)$alert_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">Requires review</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-endpoints-title"><i class="fas fa-desktop text-blue-500 mr-2"></i>Endpoints / Devices</h2>
                            <span class="text-xs text-gray-400"><?php echo $endpoint_count; ?> total</span>
                        </div>
                        <?php if (!empty($db_endpoints)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-endpoints">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hostname</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">OS</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">IP Address</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Group</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Last Seen</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($db_endpoints as $index => $ep): ?>
                                        <?php
                                            $status = strtolower($ep['status'] ?? '');
                                            $status_class = match(true) {
                                                $status === 'online' || $status === 'active' || $status === 'connected' => 'bg-green-500',
                                                $status === 'offline' || $status === 'inactive' || $status === 'disconnected' => 'bg-red-500',
                                                default => 'bg-gray-400',
                                            };
                                            $status_label = match(true) {
                                                $status === 'online' || $status === 'active' || $status === 'connected' => 'Online',
                                                $status === 'offline' || $status === 'inactive' || $status === 'disconnected' => 'Offline',
                                                default => ucfirst($status ?: 'Unknown'),
                                            };
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="endpoint-row-<?php echo $index; ?>">
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <span class="w-2.5 h-2.5 rounded-full <?php echo $status_class; ?> inline-block"></span>
                                                    <span class="text-xs text-gray-600"><?php echo $status_label; ?></span>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ep['name'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($ep['hostname'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars(($ep['os_name'] ?? '') . ($ep['os_version'] ? ' ' . $ep['os_version'] : '')); ?></td>
                                            <td class="px-4 py-3"><code class="text-xs font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($ep['ip_address'] ?? 'N/A'); ?></code></td>
                                            <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($ep['group_name'] ?? ''); ?></td>
                                            <td class="px-4 py-3 text-xs text-gray-500"><?php echo isset($ep['last_seen']) && $ep['last_seen'] ? date('M d, g:i A', strtotime($ep['last_seen'])) : 'Never'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="p-8 text-center text-gray-500 text-sm">
                                <i class="fas fa-desktop text-gray-300 text-2xl mb-2 block"></i>
                                No endpoints found. <?php if ($action1_connected): ?>Click &ldquo;Sync Endpoints&rdquo; to pull data from Action1.<?php else: ?>Configure your API key to get started.<?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-os-breakdown-title"><i class="fas fa-chart-pie text-blue-500 mr-2"></i>OS Breakdown</h2>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($os_breakdown)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($os_breakdown as $os => $cnt): ?>
                                        <?php
                                            $pct = $endpoint_count > 0 ? round(($cnt / $endpoint_count) * 100) : 0;
                                            $os_lower = strtolower($os);
                                            $bar_color = 'bg-blue-400';
                                            if (strpos($os_lower, 'windows') !== false) $bar_color = 'bg-blue-500';
                                            elseif (strpos($os_lower, 'mac') !== false || strpos($os_lower, 'darwin') !== false) $bar_color = 'bg-gray-500';
                                            elseif (strpos($os_lower, 'linux') !== false || strpos($os_lower, 'ubuntu') !== false) $bar_color = 'bg-orange-500';
                                        ?>
                                        <div data-testid="os-type-<?php echo strtolower(str_replace(' ', '-', $os)); ?>">
                                            <div class="flex items-center justify-between text-sm mb-1">
                                                <span class="text-gray-700 font-medium"><?php echo htmlspecialchars($os); ?></span>
                                                <span class="text-gray-500"><?php echo $cnt; ?> (<?php echo $pct; ?>%)</span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-2">
                                                <div class="<?php echo $bar_color; ?> rounded-full h-2" style="width: <?php echo $pct; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-500 text-center py-4">No endpoint data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-alerts-title"><i class="fas fa-bell text-yellow-500 mr-2"></i>Recent Alerts</h2>
                    </div>
                    <?php if (!empty($db_alerts)): ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($db_alerts as $index => $al): ?>
                                <?php
                                    $severity = strtolower($al['severity'] ?? 'info');
                                    $sev_class = match($severity) {
                                        'critical', 'high' => 'bg-red-100 text-red-700',
                                        'warning', 'medium' => 'bg-yellow-100 text-yellow-700',
                                        'low' => 'bg-blue-100 text-blue-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };
                                    $sev_icon = match($severity) {
                                        'critical', 'high' => 'fa-exclamation-circle text-red-500',
                                        'warning', 'medium' => 'fa-exclamation-triangle text-yellow-500',
                                        default => 'fa-info-circle text-blue-500',
                                    };
                                ?>
                                <div class="px-6 py-3 flex items-start gap-3" data-testid="alert-row-<?php echo $index; ?>">
                                    <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <i class="fas <?php echo $sev_icon; ?> text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="text-sm text-gray-900"><?php echo htmlspecialchars($al['message'] ?? ''); ?></p>
                                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-medium <?php echo $sev_class; ?>"><?php echo ucfirst($severity); ?></span>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-0.5">
                                            <?php if (!empty($al['category'])): ?><span class="mr-2"><?php echo htmlspecialchars($al['category']); ?></span><?php endif; ?>
                                            <?php echo isset($al['created_at_remote']) && $al['created_at_remote'] ? date('M d, Y g:i A', strtotime($al['created_at_remote'])) : ''; ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-8 text-center text-gray-500 text-sm">
                            <i class="fas fa-bell-slash text-gray-300 text-2xl mb-2 block"></i>
                            No alerts found. <?php if ($action1_connected): ?>Click &ldquo;Sync Alerts&rdquo; to check endpoint status and generate alerts.<?php else: ?>Configure your API credentials first.<?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$action1_connected): ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-setup-title"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Setup Instructions</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Sign up for Action1</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Create an account at <a href="https://www.action1.com" target="_blank" class="text-blue-600 underline">action1.com</a> if you haven't already. Action1 offers a free tier for up to 200 endpoints.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Generate API Credentials</p>
                                    <p class="text-xs text-gray-500 mt-0.5">In your Action1 console, navigate to <strong>Configuration &rarr; Users &amp; API Credentials</strong>. Click <strong>+ New API Credentials</strong>, give it a name, and copy the <strong>Client ID</strong> and <strong>Client Secret</strong>.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Add Secrets in Replit</p>
                                    <p class="text-xs text-gray-500 mt-0.5">In Replit, go to Tools &rarr; Secrets and add:</p>
                                    <ul class="text-xs text-gray-500 mt-1 space-y-1 list-disc pl-4">
                                        <li><code class="bg-gray-100 px-1 rounded text-xs">ACTION1_CLIENT_ID</code> &mdash; Your Client ID from Action1</li>
                                        <li><code class="bg-gray-100 px-1 rounded text-xs">ACTION1_API_KEY</code> &mdash; Your Client Secret from Action1</li>
                                        <li><code class="bg-gray-100 px-1 rounded text-xs">ACTION1_API_URL</code> &mdash; (Optional) defaults to <code class="bg-gray-100 px-1 rounded text-xs">https://app.action1.com/api/3.0</code></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">4</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Verify Connection</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Refresh this page after adding the secrets. The status will show &ldquo;Connected&rdquo; once both <code class="bg-gray-100 px-1 rounded text-xs">ACTION1_CLIENT_ID</code> and <code class="bg-gray-100 px-1 rounded text-xs">ACTION1_API_KEY</code> are set.</p>
                                </div>
                            </div>
                            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-xs text-blue-700"><i class="fas fa-info-circle mr-1"></i><strong>API Reference:</strong> Action1 uses OAuth 2.0 with Client ID + Client Secret. The portal automatically obtains a JWT token, then queries <code class="bg-blue-100 px-1 rounded">/organizations</code>, <code class="bg-blue-100 px-1 rounded">/endpoints/managed/{orgId}</code>, and <code class="bg-blue-100 px-1 rounded">/alerts/{orgId}</code>. See <a href="https://www.action1.com/api-documentation/" target="_blank" class="underline">Action1 API Documentation</a></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-patches-title"><i class="fas fa-shield-alt text-blue-500 mr-2"></i>Patch Management</h2>
                    </div>
                    <div class="p-6">
                        <div class="text-center text-gray-500 text-sm mb-4">
                            <i class="fas fa-shield-alt text-gray-300 text-2xl mb-2 block"></i>
                            Patch status data is populated during endpoint sync. View individual endpoints for detailed patch information.
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-center" data-testid="card-patched">
                                <p class="text-lg font-bold text-green-700"><?php echo $online_count; ?></p>
                                <p class="text-xs text-green-600">Up to Date</p>
                            </div>
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-center" data-testid="card-needs-patches">
                                <p class="text-lg font-bold text-yellow-700"><?php echo $offline_count; ?></p>
                                <p class="text-xs text-yellow-600">Needs Review</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- PATCHING SECTION -->
            <div class="bg-white rounded-lg border border-gray-200 mb-6" data-testid="section-patching">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-patching-title">
                            <i class="fas fa-shield-virus text-purple-600 mr-2"></i>Patching
                        </h2>
                        <p class="text-xs text-gray-400 mt-0.5">
                            <?php if ($patch_last_synced): ?>Last synced: <?php echo date('M d, Y g:i A', strtotime($patch_last_synced)); ?><?php else: ?>Run &ldquo;Sync Endpoints&rdquo; then &ldquo;Sync Patches&rdquo; to populate data.<?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Patch Summary Stats -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-0 border-b border-gray-100">
                    <div class="p-5 border-r border-gray-100" data-testid="patch-stat-missing">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Missing Updates</p>
                        <p class="text-3xl font-bold text-yellow-600"><?php echo $total_missing_updates; ?></p>
                        <p class="text-xs text-gray-400 mt-1">Across all endpoints</p>
                    </div>
                    <div class="p-5 border-r border-gray-100" data-testid="patch-stat-critical-vulns">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Critical Vulnerabilities</p>
                        <p class="text-3xl font-bold text-red-600"><?php echo $total_vulns_critical; ?></p>
                        <p class="text-xs text-gray-400 mt-1">Require immediate attention</p>
                    </div>
                    <div class="p-5 border-r border-gray-100" data-testid="patch-stat-noncritical-vulns">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Non-Critical Vulnerabilities</p>
                        <p class="text-3xl font-bold text-orange-500"><?php echo $total_vulns_noncritical; ?></p>
                        <p class="text-xs text-gray-400 mt-1">Due soon or scheduled</p>
                    </div>
                    <div class="p-5" data-testid="patch-stat-reboot">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Reboot Required</p>
                        <p class="text-3xl font-bold <?php echo $total_reboot_required > 0 ? 'text-red-600' : 'text-gray-400'; ?>"><?php echo $total_reboot_required; ?></p>
                        <p class="text-xs text-gray-400 mt-1">Endpoints pending reboot</p>
                    </div>
                </div>

                <!-- Per-Client Summary -->
                <?php if (!empty($client_patch_summary)): ?>
                <div class="px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-users text-gray-400 mr-1"></i>By Client</h3>
                </div>
                <div class="overflow-x-auto border-b border-gray-100">
                    <table class="w-full" data-testid="table-client-patch-summary">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Endpoints</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Critical Vulns</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Non-Critical</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Missing Updates</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Reboot Req.</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($client_patch_summary as $cid => $cs): ?>
                            <tr class="hover:bg-gray-50" data-testid="client-patch-row-<?php echo (int)$cid; ?>">
                                <td class="px-6 py-2.5 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($cs['label']); ?></td>
                                <td class="px-4 py-2.5 text-center text-sm text-gray-600"><?php echo $cs['endpoints']; ?></td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($cs['vulns_critical'] > 0): ?>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700"><?php echo $cs['vulns_critical']; ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($cs['vulns_noncritical'] > 0): ?>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold bg-orange-100 text-orange-700"><?php echo $cs['vulns_noncritical']; ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($cs['missing_updates'] > 0): ?>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700"><?php echo $cs['missing_updates']; ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($cs['reboot_required'] > 0): ?>
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700"><?php echo $cs['reboot_required']; ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Per-Endpoint Patching Details -->
                <div class="px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-desktop text-gray-400 mr-1"></i>Endpoint Patch Status</h3>
                </div>
                <?php if (!empty($db_endpoints)): ?>
                <div class="overflow-x-auto border-b border-gray-100">
                    <table class="w-full" data-testid="table-endpoint-patch-status">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Name</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">User</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Reboot</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">OS</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Vulnerabilities</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Missing Updates</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($db_endpoints as $idx => $ep):
                                $status = strtolower($ep['status'] ?? '');
                                $online = ($status === 'online' || $status === 'active' || $status === 'connected');
                                $status_dot = $online ? 'bg-green-500' : 'bg-red-400';
                                $status_label = $online ? 'Connected' : ucfirst($status ?: 'Unknown');
                                $reboot_req = !empty($ep['reboot_required']) && $ep['reboot_required'] !== 'false' && $ep['reboot_required'] !== '0';
                                $vcrit = (int)($ep['vulnerabilities_critical'] ?? 0);
                                $vnon = (int)($ep['vulnerabilities_noncritical'] ?? 0);
                                $mu = (int)($ep['missing_updates'] ?? 0);
                                $mu_crit = (int)($ep['missing_updates_critical'] ?? 0);
                                $client_label = trim(($ep['client_company'] ?? '') . ' ' . ($ep['client_name'] ?? ''));
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="ep-patch-row-<?php echo $idx; ?>">
                                <td class="px-4 py-2.5 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ep['name'] ?? ''); ?></td>
                                <td class="px-4 py-2.5 text-xs text-gray-500"><?php echo htmlspecialchars($ep['user_name'] ?? 'None'); ?></td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full <?php echo $status_dot; ?>"></span>
                                        <span class="text-xs text-gray-600"><?php echo $status_label; ?></span>
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs">
                                    <?php if ($reboot_req): ?>
                                        <span class="px-2 py-0.5 rounded bg-red-100 text-red-700 font-medium">Required</span>
                                    <?php else: ?>
                                        <span class="text-gray-400">Not required</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-600"><?php echo htmlspecialchars(($ep['os_name'] ?? '') . ($ep['os_version'] ? ' ('.$ep['os_version'].')' : '')); ?></td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($vcrit > 0 || $vnon > 0): ?>
                                        <span class="inline-flex items-center gap-1">
                                            <?php if ($vcrit > 0): ?><span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700"><?php echo $vcrit; ?> critical</span><?php endif; ?>
                                            <?php if ($vnon > 0): ?><span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-orange-50 text-orange-600"><?php echo $vnon; ?> non-critical</span><?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-green-600 font-medium"><i class="fas fa-check-circle mr-0.5"></i>Clean</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($mu > 0): ?>
                                        <span class="inline-flex items-center gap-1">
                                            <?php if ($mu_crit > 0): ?><span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700"><?php echo $mu_crit; ?> critical</span><?php endif; ?>
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-yellow-50 text-yellow-700"><?php echo $mu; ?> total</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-green-600 font-medium"><i class="fas fa-check-circle mr-0.5"></i>Up to date</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-500"><?php echo htmlspecialchars($client_label ?: '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-8 text-center text-gray-400 text-sm border-b border-gray-100">
                    <i class="fas fa-desktop text-3xl mb-2 block text-gray-200"></i>
                    No endpoint data. Click <strong>Sync Endpoints</strong> to import device information from Action1.
                </div>
                <?php endif; ?>

                <!-- Vulnerabilities Table -->
                <div class="px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-bug text-gray-400 mr-1"></i>Vulnerability Remediation</h3>
                    <p class="text-xs text-gray-400 mb-3">Real-time assessment of software and OS vulnerabilities</p>
                </div>
                <?php if (!empty($db_vulnerabilities)): ?>
                <div class="overflow-x-auto pb-4">
                    <table class="w-full" data-testid="table-vulnerabilities">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">CVE</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">CVSS Score</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">CISA KEV</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Published Date</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Remediation Status</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Vulnerable Software</th>
                                <th class="px-4 py-2 text-center text-xs font-semibold text-gray-500 uppercase">Endpoints</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($db_vulnerabilities as $vi => $vuln):
                                $cvss = (float)($vuln['cvss_score'] ?? 0);
                                $cvss_class = match(true) {
                                    $cvss >= 9.0 => 'bg-red-600 text-white',
                                    $cvss >= 7.0 => 'bg-orange-500 text-white',
                                    $cvss >= 4.0 => 'bg-yellow-500 text-white',
                                    default      => 'bg-blue-100 text-blue-700',
                                };
                                $rem = strtolower($vuln['remediation_status'] ?? '');
                                $rem_class = match(true) {
                                    str_contains($rem, 'overdue') => 'bg-red-100 text-red-700',
                                    str_contains($rem, 'due') => 'bg-yellow-100 text-yellow-700',
                                    str_contains($rem, 'done') || str_contains($rem, 'resolved') => 'bg-green-100 text-green-700',
                                    default => 'bg-gray-100 text-gray-600',
                                };
                                $cisa = !empty($vuln['cisa_kev']) && $vuln['cisa_kev'] !== 'false' && $vuln['cisa_kev'] !== '0';
                            ?>
                            <tr class="hover:bg-gray-50" data-testid="vuln-row-<?php echo $vi; ?>">
                                <td class="px-4 py-2.5">
                                    <a href="https://nvd.nist.gov/vuln/detail/<?php echo htmlspecialchars($vuln['cve_id']); ?>" target="_blank" rel="noopener" class="text-sm font-medium text-blue-600 hover:underline" data-testid="link-cve-<?php echo $vi; ?>"><?php echo htmlspecialchars($vuln['cve_id']); ?></a>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-bold <?php echo $cvss_class; ?>"><?php echo $cvss > 0 ? number_format($cvss, 1) : '0'; ?></span>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <?php if ($cisa): ?>
                                        <span class="inline-block w-4 h-4 rounded-full bg-red-500" title="CISA KEV"></span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-600"><?php echo $vuln['published_date'] ? date('M d, Y', strtotime($vuln['published_date'])) : '—'; ?></td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold <?php echo $rem_class; ?>"><?php echo htmlspecialchars($vuln['remediation_status'] ?: '—'); ?></span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-700"><?php echo htmlspecialchars($vuln['vulnerable_software'] ?: '—'); ?></td>
                                <td class="px-4 py-2.5 text-center text-sm font-medium text-gray-700"><?php echo (int)($vuln['endpoints_count'] ?? 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-8 text-center text-gray-400 text-sm">
                    <i class="fas fa-bug text-3xl mb-2 block text-gray-200"></i>
                    No vulnerability data. Click <strong>Sync Patches</strong> to import CVE and patch information from Action1.
                </div>
                <?php endif; ?>
            </div>
            <!-- END PATCHING SECTION -->

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-capabilities-title"><i class="fas fa-star text-blue-500 mr-2"></i>Capabilities</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="flex items-start gap-4" data-testid="capability-endpoint-management">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-desktop text-blue-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Endpoint Management</h3>
                                <p class="text-xs text-gray-500">Import and monitor all endpoints from Action1 RMM. Track workstations, servers, and other managed devices with real-time status updates.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-patch-management">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-shield-alt text-green-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Patch Management</h3>
                                <p class="text-xs text-gray-500">Monitor OS and third-party patch compliance across all endpoints. Action1 provides automated patch deployment with flexible scheduling and approval workflows.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-alert-monitoring">
                            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-bell text-yellow-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Alert Monitoring</h3>
                                <p class="text-xs text-gray-500">Receive and manage alerts from Action1 RMM. Monitor endpoint health, software updates, and security compliance across all managed devices.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4" data-testid="capability-remote-access">
                            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-plug text-purple-600"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-1">Policy Deployment</h3>
                                <p class="text-xs text-gray-500">Leverage Action1's policy engine for automated software deployment, configuration management, and scripting across your managed endpoints.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>