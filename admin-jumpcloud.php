<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');

$user_name  = $_SESSION['user_name']  ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$pdo = getDB();

$jc_api_key   = JUMPCLOUD_API_KEY;
$jc_provider_id = JUMPCLOUD_ORG_ID;          // The secret is the Provider ID for MSP
$jc_connected = !empty($jc_api_key);

// ── Session-based active org (MSP multi-tenant)
$jc_active_org_id   = $_SESSION['jc_active_org_id']   ?? '';
$jc_active_org_name = $_SESSION['jc_active_org_name'] ?? '';

$success_msg = '';
$error_msg   = '';
$active_tab  = $_GET['tab'] ?? 'overview';

// ─────────────────────────────────────────────────────────
// DB bootstrap
// ─────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jc_organizations (
            id               SERIAL PRIMARY KEY,
            jc_id            VARCHAR(255) UNIQUE NOT NULL,
            display_name     VARCHAR(255) DEFAULT '',
            logo_url         TEXT         DEFAULT '',
            website_url      TEXT         DEFAULT '',
            contact_name     VARCHAR(255) DEFAULT '',
            contact_email    VARCHAR(255) DEFAULT '',
            num_users        INTEGER      DEFAULT 0,
            num_systems      INTEGER      DEFAULT 0,
            provider_id      VARCHAR(255) DEFAULT '',
            updated_at       TIMESTAMP    DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS jc_systems (
            id               SERIAL PRIMARY KEY,
            jc_id            VARCHAR(255) UNIQUE NOT NULL,
            org_id           VARCHAR(255) DEFAULT '',
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
            org_id           VARCHAR(255) DEFAULT '',
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
            org_id           VARCHAR(255) DEFAULT '',
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
    // Add org_id columns to existing tables if missing
    foreach (['jc_systems','jc_users','jc_events'] as $tbl) {
        try { $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN IF NOT EXISTS org_id VARCHAR(255) DEFAULT ''"); } catch(Exception $e){}
    }
} catch (Exception $e) {}

// ─────────────────────────────────────────────────────────
// JumpCloud API helpers
// ─────────────────────────────────────────────────────────

// Provider-level call (no x-org-id — scoped to the MSP provider)
function jc_provider_api(string $method, string $endpoint, ?array $body = null): array {
    if (!JUMPCLOUD_API_KEY) return ['error' => 'JUMPCLOUD_API_KEY is not configured'];
    $url = strpos($endpoint,'http') === 0 ? $endpoint : rtrim(JUMPCLOUD_API_V2,'/').$endpoint;
    $headers = ['x-api-key: '.JUMPCLOUD_API_KEY,'Content-Type: application/json','Accept: application/json'];
    $ch = curl_init();
    $opts = [CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true];
    $upper = strtoupper($method);
    if ($upper==='POST'){$opts[CURLOPT_POST]=true;if($body!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($body);}
    elseif(in_array($upper,['PUT','PATCH','DELETE'])){$opts[CURLOPT_CUSTOMREQUEST]=$upper;if($body!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($body);}
    curl_setopt_array($ch,$opts);
    $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($err) return ['error'=>$err,'http_code'=>0];
    if($code===401) return ['error'=>'Unauthorized','http_code'=>401];
    if($code===403) return ['error'=>'Forbidden — check Provider ID','http_code'=>403];
    if($code<200||$code>=300){$b=json_decode($resp,true)??[];return['error'=>$b['message']??$b['error']??"HTTP $code",'http_code'=>$code];}
    if(empty($resp)) return ['results'=>[],'http_code'=>$code];
    $d=json_decode($resp,true);
    return $d??['error'=>'Invalid JSON','raw'=>substr($resp,0,300)];
}

// Org-level call — sends x-org-id of the currently selected org (session)
function jc_api(string $method, string $endpoint, ?array $body = null, bool $v2 = false): array {
    if (!JUMPCLOUD_API_KEY) return ['error' => 'JUMPCLOUD_API_KEY is not configured'];
    $base = $v2 ? JUMPCLOUD_API_V2 : JUMPCLOUD_API_V1;
    $url  = strpos($endpoint,'http') === 0 ? $endpoint : rtrim($base,'/').$endpoint;
    $headers = [
        'x-api-key: '.JUMPCLOUD_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    // Use session-selected org for org-level calls (MSP multi-tenant)
    $active_org = $_SESSION['jc_active_org_id'] ?? '';
    if ($active_org) $headers[] = 'x-org-id: '.$active_org;

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
            // Test provider API first
            if ($jc_provider_id) {
                $res = jc_provider_api('GET', "/providers/{$jc_provider_id}/organizations?limit=1");
                if (isset($res['error'])) {
                    $error_msg = 'Provider API FAILED: '.$res['error'];
                } else {
                    $cnt = $res['totalCount'] ?? count($res['results'] ?? []);
                    $success_msg = "MSP Provider connected ✓ | Managed organizations: {$cnt}";
                }
            } else {
                $res = jc_api('GET', '/systems?limit=1&skip=0');
                if (isset($res['error'])) {
                    $error_msg = 'Connection FAILED: '.$res['error'];
                } else {
                    $cnt = $res['totalCount'] ?? count($res['results'] ?? $res);
                    $success_msg = "JumpCloud connected ✓ | Systems visible: {$cnt}";
                }
            }
        }
        $active_tab = 'settings';
    }

    // ── Sync Organizations (MSP Provider API)
    if ($action === 'sync_orgs') {
        if (!$jc_connected || !$jc_provider_id) {
            $error_msg = 'Provider ID (JUMPCLOUD_ORG_ID) and API key required.';
        } else {
            $limit = 100; $skip = 0; $all = []; $synced = 0;
            do {
                $res = jc_provider_api('GET', "/providers/{$jc_provider_id}/organizations?limit={$limit}&skip={$skip}");
                if (isset($res['error'])) { $error_msg = 'Org sync failed: '.$res['error']; break; }
                $batch = $res['results'] ?? (isset($res[0]) ? $res : []);
                $all = array_merge($all, $batch);
                $skip += $limit;
                $total = (int)($res['totalCount'] ?? count($all));
            } while (count($all) < $total && count($batch) === $limit);

            foreach ($all as $org) {
                $jid = $org['_id'] ?? $org['id'] ?? ''; if (!$jid) continue;
                $contact = $org['contactName'] ?? $org['contact']['name'] ?? '';
                $cemail  = $org['contactEmail'] ?? $org['contact']['email'] ?? '';
                try {
                    $pdo->prepare("INSERT INTO jc_organizations (jc_id,display_name,logo_url,website_url,contact_name,contact_email,num_users,num_systems,provider_id,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,NOW())
                        ON CONFLICT (jc_id) DO UPDATE SET
                            display_name=EXCLUDED.display_name, logo_url=EXCLUDED.logo_url,
                            website_url=EXCLUDED.website_url, contact_name=EXCLUDED.contact_name,
                            contact_email=EXCLUDED.contact_email, num_users=EXCLUDED.num_users,
                            num_systems=EXCLUDED.num_systems, updated_at=NOW()")
                        ->execute([
                            $jid, $org['displayName']??$org['name']??'', $org['logoUrl']??$org['logo']??'',
                            $org['websiteUrl']??'', $contact, $cemail,
                            (int)($org['numUsers']??0), (int)($org['numSystems']??0), $jc_provider_id,
                        ]);
                    $synced++;
                } catch(Exception $e){}
            }
            $success_msg = "Synced {$synced} organization(s) from the MSP provider.";
            try { $pdo->prepare("INSERT INTO activity_log (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")->execute([$_SESSION['user_id'],'jc_sync_orgs','jc_org',0,"Synced {$synced} orgs",$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
        }
        $active_tab = 'organizations';
    }

    // ── Set active org (switch context to a managed org)
    if ($action === 'set_active_org') {
        $_SESSION['jc_active_org_id']   = $_POST['org_id']   ?? '';
        $_SESSION['jc_active_org_name'] = $_POST['org_name'] ?? '';
        $jc_active_org_id   = $_SESSION['jc_active_org_id'];
        $jc_active_org_name = $_SESSION['jc_active_org_name'];
        $success_msg = "Now managing: {$jc_active_org_name}";
        $active_tab = 'systems';
    }

    // ── Clear active org (return to provider view)
    if ($action === 'clear_active_org') {
        unset($_SESSION['jc_active_org_id'], $_SESSION['jc_active_org_name']);
        $jc_active_org_id = ''; $jc_active_org_name = '';
        $success_msg = 'Returned to Provider view.';
        $active_tab = 'organizations';
    }

    // ── Create Organization under Provider
    if ($action === 'create_org') {
        if (!$jc_connected || !$jc_provider_id) {
            $error_msg = 'Provider ID required.';
        } else {
            $org_name    = trim($_POST['new_org_name']    ?? '');
            $org_contact = trim($_POST['new_org_contact'] ?? '');
            $org_email   = trim($_POST['new_org_email']   ?? '');
            if (!$org_name) { $error_msg = 'Organization name is required.'; }
            else {
                $payload = ['name' => $org_name];
                if ($org_contact) $payload['contactName']  = $org_contact;
                if ($org_email)   $payload['contactEmail'] = $org_email;
                $res = jc_provider_api('POST', "/providers/{$jc_provider_id}/organizations", $payload);
                if (isset($res['error'])) {
                    $error_msg = 'Create org failed: '.$res['error'];
                } else {
                    $new_id = $res['_id'] ?? $res['id'] ?? '';
                    if ($new_id) {
                        try {
                            $pdo->prepare("INSERT INTO jc_organizations (jc_id,display_name,contact_name,contact_email,provider_id,updated_at) VALUES (?,?,?,?,?,NOW()) ON CONFLICT (jc_id) DO NOTHING")
                                ->execute([$new_id,$org_name,$org_contact,$org_email,$jc_provider_id]);
                        } catch(Exception $e){}
                    }
                    $success_msg = "Organization '{$org_name}' created.";
                }
            }
        }
        $active_tab = 'organizations';
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

            $sync_org_id = $_SESSION['jc_active_org_id'] ?? '';
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
                    $pdo->prepare("INSERT INTO jc_systems (jc_id,org_id,display_name,hostname,os,os_version,arch,active,allow_ssh,allow_multi_factor,agent_version,remote_ip,last_contact,created_remote,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                        ON CONFLICT (jc_id) DO UPDATE SET
                            org_id=EXCLUDED.org_id, display_name=EXCLUDED.display_name, hostname=EXCLUDED.hostname, os=EXCLUDED.os,
                            os_version=EXCLUDED.os_version, arch=EXCLUDED.arch, active=EXCLUDED.active,
                            allow_ssh=EXCLUDED.allow_ssh, allow_multi_factor=EXCLUDED.allow_multi_factor,
                            agent_version=EXCLUDED.agent_version, remote_ip=EXCLUDED.remote_ip,
                            last_contact=EXCLUDED.last_contact, updated_at=NOW()")
                        ->execute([
                            $jid, $sync_org_id, $s['displayName']??$s['hostname']??'', $s['hostname']??'',
                            $os_name, $os_ver, $s['arch']??'',
                            ($s['active']??true)?'true':'false',
                            (!empty($s['allowPublicKeyAuthentication'])||!empty($s['allowSshPasswordAuthentication']))?'true':'false',
                            !empty($s['allowMultiFactorAuthentication'])?'true':'false',
                            $s['agentVersion']??'', $s['remoteIP']??'', $lc, $cr,
                        ]);
                    $synced++;
                } catch(Exception $e){}
            }
            $org_label = ($_SESSION['jc_active_org_name'] ?? '') ?: 'all orgs';
            $success_msg = "Synced {$synced} system(s) from JumpCloud ({$org_label}).";
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
                $u_org_id = $_SESSION['jc_active_org_id'] ?? '';
                try {
                    $pdo->prepare("INSERT INTO jc_users (jc_id,org_id,username,firstname,lastname,email,state,mfa_enabled,ldap_binding_user,sudo_enabled,account_locked,activated,created_remote,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                        ON CONFLICT (jc_id) DO UPDATE SET
                            org_id=EXCLUDED.org_id, username=EXCLUDED.username, firstname=EXCLUDED.firstname, lastname=EXCLUDED.lastname,
                            email=EXCLUDED.email, state=EXCLUDED.state, mfa_enabled=EXCLUDED.mfa_enabled,
                            ldap_binding_user=EXCLUDED.ldap_binding_user, sudo_enabled=EXCLUDED.sudo_enabled,
                            account_locked=EXCLUDED.account_locked, activated=EXCLUDED.activated, updated_at=NOW()")
                        ->execute([
                            $jid, $u_org_id, $u['username']??'', $u['firstname']??'', $u['lastname']??'',
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
            $u_org_label = ($_SESSION['jc_active_org_name'] ?? '') ?: 'all orgs';
            $success_msg = "Synced {$synced} user(s) from JumpCloud ({$u_org_label}).";
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

    // ── Create User
    if ($action === 'create_user') {
        if (!$jc_connected) { $error_msg = 'API key not set.'; }
        else {
            $uname = trim($_POST['new_username'] ?? '');
            $email = trim($_POST['new_email']    ?? '');
            $pass  = $_POST['new_password']  ?? '';
            if (!$uname || !$email || !$pass) {
                $error_msg = 'Username, email and password are required.';
            } else {
                $payload = [
                    'username'  => $uname,
                    'email'     => $email,
                    'password'  => $pass,
                    'firstname' => trim($_POST['new_firstname'] ?? ''),
                    'lastname'  => trim($_POST['new_lastname']  ?? ''),
                ];
                if (!empty($_POST['new_sudo']))  $payload['sudo']          = true;
                if (!empty($_POST['new_ldap']))  $payload['ldap_binding_user'] = true;
                $res = jc_api('POST', '/systemusers', $payload);
                if (isset($res['error'])) {
                    $error_msg = 'Create user failed: '.$res['error'];
                } else {
                    $jid = $res['_id'] ?? $res['id'] ?? '';
                    if ($jid) {
                        try {
                            $pdo->prepare("INSERT INTO jc_users (jc_id,username,firstname,lastname,email,state,updated_at) VALUES (?,?,?,?,?,?,NOW()) ON CONFLICT (jc_id) DO UPDATE SET username=EXCLUDED.username,updated_at=NOW()")
                                ->execute([$jid,$uname,$payload['firstname'],$payload['lastname'],$email,'STAGED']);
                        } catch(Exception $e){}
                    }
                    $success_msg = "User '{$uname}' created in JumpCloud.";
                    try { $pdo->prepare("INSERT INTO activity_log (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")->execute([$_SESSION['user_id'],'jc_create_user','jc_user',0,"Created JumpCloud user: {$uname}",$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
                }
            }
        }
        $active_tab = 'users';
    }

    // ── Delete User
    if ($action === 'delete_user') {
        $jc_uid = $_POST['jc_user_id'] ?? '';
        if ($jc_uid && $jc_connected) {
            $res = jc_api('DELETE', "/systemusers/{$jc_uid}");
            if (isset($res['error'])) { $error_msg = 'Delete failed: '.$res['error']; }
            else {
                try { $pdo->prepare("DELETE FROM jc_users WHERE jc_id=?")->execute([$jc_uid]); } catch(Exception $e){}
                $success_msg = 'User deleted from JumpCloud.';
            }
        }
        $active_tab = 'users';
    }

    // ── Update System settings
    if ($action === 'update_system') {
        $jc_sid      = $_POST['jc_system_id'] ?? '';
        $display     = trim($_POST['sys_display_name'] ?? '');
        $allow_mfa   = !empty($_POST['sys_allow_mfa']);
        $allow_ssh   = !empty($_POST['sys_allow_ssh']);
        if ($jc_sid && $jc_connected) {
            $payload = ['allowMultiFactorAuthentication' => $allow_mfa, 'allowSshPasswordAuthentication' => $allow_ssh];
            if ($display) $payload['displayName'] = $display;
            $res = jc_api('PUT', "/systems/{$jc_sid}", $payload);
            if (isset($res['error'])) { $error_msg = 'System update failed: '.$res['error']; }
            else {
                try { $pdo->prepare("UPDATE jc_systems SET display_name=COALESCE(NULLIF(?,?),display_name),allow_multi_factor=?,allow_ssh=?,updated_at=NOW() WHERE jc_id=?")->execute([$display,$display,$allow_mfa?'true':'false',$allow_ssh?'true':'false',$jc_sid]); } catch(Exception $e){}
                $success_msg = 'System settings updated.';
            }
        }
        $active_tab = 'systems';
    }

    // ── Delete System
    if ($action === 'delete_system') {
        $jc_sid = $_POST['jc_system_id'] ?? '';
        if ($jc_sid && $jc_connected) {
            $res = jc_api('DELETE', "/systems/{$jc_sid}");
            if (isset($res['error'])) { $error_msg = 'Delete failed: '.$res['error']; }
            else {
                try { $pdo->prepare("DELETE FROM jc_systems WHERE jc_id=?")->execute([$jc_sid]); } catch(Exception $e){}
                $success_msg = 'System removed from JumpCloud.';
            }
        }
        $active_tab = 'systems';
    }

    // ── Create User Group
    if ($action === 'create_user_group') {
        if (!$jc_connected) { $error_msg = 'API key not set.'; }
        else {
            $name = trim($_POST['ug_name'] ?? '');
            $desc = trim($_POST['ug_desc'] ?? '');
            if (!$name) { $error_msg = 'Group name is required.'; }
            else {
                $res = jc_api('POST', '/usergroups', ['name' => $name, 'description' => $desc], true);
                if (isset($res['error'])) $error_msg = 'Create group failed: '.$res['error'];
                else { $success_msg = "User group '{$name}' created."; }
            }
        }
        $active_tab = 'groups';
    }

    // ── Delete User Group
    if ($action === 'delete_user_group') {
        $gid = $_POST['group_id'] ?? '';
        if ($gid && $jc_connected) {
            $res = jc_api('DELETE', "/usergroups/{$gid}", null, true);
            if (isset($res['error'])) $error_msg = 'Delete failed: '.$res['error'];
            else $success_msg = 'User group deleted.';
        }
        $active_tab = 'groups';
    }

    // ── Create System Group
    if ($action === 'create_system_group') {
        if (!$jc_connected) { $error_msg = 'API key not set.'; }
        else {
            $name = trim($_POST['sg_name'] ?? '');
            $desc = trim($_POST['sg_desc'] ?? '');
            if (!$name) { $error_msg = 'Group name is required.'; }
            else {
                $res = jc_api('POST', '/systemgroups', ['name' => $name, 'description' => $desc], true);
                if (isset($res['error'])) $error_msg = 'Create system group failed: '.$res['error'];
                else $success_msg = "System group '{$name}' created.";
            }
        }
        $active_tab = 'groups';
    }

    // ── Delete System Group
    if ($action === 'delete_system_group') {
        $gid = $_POST['group_id'] ?? '';
        if ($gid && $jc_connected) {
            $res = jc_api('DELETE', "/systemgroups/{$gid}", null, true);
            if (isset($res['error'])) $error_msg = 'Delete failed: '.$res['error'];
            else $success_msg = 'System group deleted.';
        }
        $active_tab = 'groups';
    }

    // ── Create Policy
    if ($action === 'create_policy') {
        if (!$jc_connected) { $error_msg = 'API key not set.'; }
        else {
            $pol_name  = trim($_POST['pol_name']     ?? '');
            $tmpl_id   = trim($_POST['pol_template'] ?? '');
            if (!$pol_name || !$tmpl_id) { $error_msg = 'Policy name and template are required.'; }
            else {
                $res = jc_api('POST', '/policies', [
                    'name'       => $pol_name,
                    'template'   => ['id' => $tmpl_id],
                    'values'     => [],
                ], true);
                if (isset($res['error'])) $error_msg = 'Create policy failed: '.$res['error'];
                else $success_msg = "Policy '{$pol_name}' created.";
            }
        }
        $active_tab = 'policies';
    }

    // ── Delete Policy
    if ($action === 'delete_policy') {
        $pid = $_POST['policy_id'] ?? '';
        if ($pid && $jc_connected) {
            $res = jc_api('DELETE', "/policies/{$pid}", null, true);
            if (isset($res['error'])) $error_msg = 'Delete failed: '.$res['error'];
            else $success_msg = 'Policy deleted.';
        }
        $active_tab = 'policies';
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
$db_systems = []; $db_users = []; $db_events = []; $db_orgs = [];
try {
    // Organizations (always load all)
    $db_orgs = $pdo->query("SELECT * FROM jc_organizations ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);

    // Systems and users scoped to the active org (if one is selected)
    if ($jc_active_org_id) {
        $stmt = $pdo->prepare("SELECT * FROM jc_systems WHERE org_id=? ORDER BY display_name");
        $stmt->execute([$jc_active_org_id]);
        $db_systems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM jc_users WHERE org_id=? ORDER BY username");
        $stmt->execute([$jc_active_org_id]);
        $db_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM jc_events WHERE org_id=? ORDER BY event_time DESC LIMIT 100");
        $stmt->execute([$jc_active_org_id]);
        $db_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $db_systems = $pdo->query("SELECT * FROM jc_systems ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);
        $db_users   = $pdo->query("SELECT * FROM jc_users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
        $db_events  = $pdo->query("SELECT * FROM jc_events ORDER BY event_time DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
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
$live_pol_templates = [];

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
        $tmpl_r = jc_api('GET', '/policytemplates?limit=100', null, true);
        $live_pol_templates = $tmpl_r['results'] ?? (isset($tmpl_r[0]) ? $tmpl_r : []);
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
            ['overview',       'fa-tachometer-alt',  'Overview'],
            ['organizations',  'fa-building',        'Organizations ('.count($db_orgs).')'],
            ['systems',        'fa-desktop',         'Systems ('.$sys_total.')'],
            ['users',          'fa-users',           'Users ('.$usr_total.')'],
            ['groups',         'fa-object-group',    'Groups'],
            ['policies',       'fa-file-shield',     'Policies & Apps'],
            ['insights',       'fa-history',         'Directory Insights'],
            ['settings',       'fa-cog',             'Settings'],
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

<?php if ($jc_active_org_id): ?>
<div class="mb-5 flex items-center justify-between bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-sm" data-testid="org-context-banner">
    <div class="flex items-center gap-2">
        <i class="fas fa-building text-blue-500"></i>
        <span>Managing: <strong><?= htmlspecialchars($jc_active_org_name ?: $jc_active_org_id) ?></strong></span>
        <span class="text-xs text-blue-400">(Org ID: <?= htmlspecialchars($jc_active_org_id) ?>)</span>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_active_org">
        <button type="submit" class="text-xs bg-white border border-blue-300 text-blue-700 px-3 py-1 rounded hover:bg-blue-100 transition" data-testid="btn-clear-org">
            <i class="fas fa-times mr-1"></i>Return to Provider View
        </button>
    </form>
</div>
<?php endif; ?>

<?php if (!$jc_connected): ?>
<div class="mb-5 flex items-start gap-3 bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg text-sm">
    <i class="fas fa-exclamation-triangle mt-0.5"></i>
    <div>
        <strong>JumpCloud API key not configured.</strong>
        Set <code class="bg-amber-100 px-1 rounded font-mono text-xs">JUMPCLOUD_API_KEY</code> (your API key) and <code class="bg-amber-100 px-1 rounded font-mono text-xs">JUMPCLOUD_ORG_ID</code> (your MSP Provider ID) in environment variables and restart the server.
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

<?php /* ══════════════════ ORGANIZATIONS ══════════════════ */ elseif ($active_tab === 'organizations'): ?>
<div class="space-y-5">

    <!-- Toolbar -->
    <div class="bg-white rounded-lg border border-gray-200 px-5 py-3 flex items-center gap-3 flex-wrap">
        <h2 class="font-semibold text-gray-700 flex-1"><i class="fas fa-building text-blue-500 mr-2"></i>Managed Organizations</h2>
        <?php if ($jc_connected && $jc_provider_id): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_orgs">
            <button type="submit" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded transition" data-testid="btn-sync-orgs">
                <i class="fas fa-sync-alt"></i>Sync from JumpCloud
            </button>
        </form>
        <button onclick="document.getElementById('modal-create-org').classList.remove('hidden')"
            class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white text-sm px-4 py-2 rounded transition" data-testid="btn-create-org">
            <i class="fas fa-plus"></i>New Organization
        </button>
        <?php endif; ?>
    </div>

    <!-- Provider info banner -->
    <?php if ($jc_provider_id): ?>
    <div class="bg-indigo-50 border border-indigo-200 rounded-lg px-5 py-3 flex items-center gap-3 text-sm text-indigo-800">
        <i class="fas fa-shield-alt text-indigo-500"></i>
        <div>
            <strong>MSP Provider Mode</strong> — Provider ID: <code class="font-mono text-xs bg-indigo-100 px-1 rounded"><?= htmlspecialchars($jc_provider_id) ?></code>
        </div>
    </div>
    <?php endif; ?>

    <!-- No active org notice (when viewing orgs with an org selected) -->
    <?php if ($jc_active_org_id): ?>
    <div class="bg-blue-50 border border-blue-200 text-blue-700 rounded-lg px-5 py-3 text-sm flex items-center gap-3">
        <i class="fas fa-info-circle"></i>
        You are currently managing <strong><?= htmlspecialchars($jc_active_org_name) ?></strong>. Click an org below to switch, or use "Return to Provider View" above.
    </div>
    <?php endif; ?>

    <!-- Orgs table -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <?php if (empty($db_orgs)): ?>
        <div class="p-10 text-center text-gray-400 text-sm">
            <i class="fas fa-building text-4xl mb-3 block opacity-30"></i>
            No organizations synced yet.
            <?php if ($jc_connected && $jc_provider_id): ?>
            Click <strong>Sync from JumpCloud</strong> to pull your managed organizations.
            <?php else: ?>
            Configure your API key and Provider ID first.
            <?php endif; ?>
        </div>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Organization</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Contact</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-600">Users</th>
                    <th class="text-center px-4 py-3 font-semibold text-gray-600">Systems</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Org ID</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($db_orgs as $org): ?>
                <tr class="hover:bg-gray-50 <?= $jc_active_org_id === $org['jc_id'] ? 'bg-blue-50 ring-1 ring-inset ring-blue-200' : '' ?>" data-testid="org-row-<?= htmlspecialchars($org['jc_id']) ?>">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($org['logo_url'])): ?>
                            <img src="<?= htmlspecialchars($org['logo_url']) ?>" alt="" class="w-8 h-8 rounded object-contain border">
                            <?php else: ?>
                            <div class="w-8 h-8 rounded bg-indigo-100 flex items-center justify-center text-indigo-600">
                                <i class="fas fa-building text-xs"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div class="font-medium text-gray-900"><?= htmlspecialchars($org['display_name'] ?: 'Unnamed Org') ?></div>
                                <?php if (!empty($org['website_url'])): ?>
                                <a href="<?= htmlspecialchars($org['website_url']) ?>" target="_blank" rel="noopener" class="text-xs text-blue-500 hover:underline"><?= htmlspecialchars($org['website_url']) ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        <?= htmlspecialchars($org['contact_name'] ?: '—') ?>
                        <?php if (!empty($org['contact_email'])): ?>
                        <div class="text-xs text-gray-400"><?= htmlspecialchars($org['contact_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-700"><?= (int)$org['num_users'] ?></td>
                    <td class="px-4 py-3 text-center text-gray-700"><?= (int)$org['num_systems'] ?></td>
                    <td class="px-4 py-3">
                        <code class="font-mono text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded"><?= htmlspecialchars(substr($org['jc_id'],0,20)).'…' ?></code>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($jc_active_org_id === $org['jc_id']): ?>
                        <span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 text-xs px-3 py-1 rounded-full font-medium">
                            <i class="fas fa-check-circle"></i>Active
                        </span>
                        <?php else: ?>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_active_org">
                            <input type="hidden" name="org_id" value="<?= htmlspecialchars($org['jc_id']) ?>">
                            <input type="hidden" name="org_name" value="<?= htmlspecialchars($org['display_name']) ?>">
                            <button type="submit"
                                class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded transition"
                                data-testid="btn-manage-org-<?= htmlspecialchars($org['jc_id']) ?>">
                                <i class="fas fa-arrow-right mr-1"></i>Manage
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Create Organization Modal -->
<div id="modal-create-org" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-plus-circle text-green-500 mr-2"></i>New Organization</h3>
            <button onclick="document.getElementById('modal-create-org').classList.add('hidden')" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_org">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Organization Name <span class="text-red-500">*</span></label>
                <input type="text" name="new_org_name" required placeholder="Acme Corp"
                    class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-green-400 focus:outline-none"
                    data-testid="input-new-org-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contact Name</label>
                <input type="text" name="new_org_contact" placeholder="John Smith"
                    class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-green-400 focus:outline-none"
                    data-testid="input-new-org-contact">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contact Email</label>
                <input type="email" name="new_org_email" placeholder="john@acme.com"
                    class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-green-400 focus:outline-none"
                    data-testid="input-new-org-email">
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('modal-create-org').classList.add('hidden')"
                    class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 text-sm bg-green-600 hover:bg-green-700 text-white rounded transition" data-testid="btn-submit-create-org">
                    <i class="fas fa-plus mr-1"></i>Create Organization
                </button>
            </div>
        </form>
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
        <button onclick="document.getElementById('modal-agent-install').classList.remove('hidden')"
            class="px-3 py-1.5 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50 flex items-center gap-2" data-testid="button-agent-install">
            <i class="fas fa-terminal text-xs text-purple-500"></i>Agent Install Guide
        </button>
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
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($db_systems)): ?>
            <tr><td colspan="11" class="px-4 py-10 text-center text-gray-400">
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
                <td class="px-4 py-3">
                    <div class="flex gap-1">
                    <button onclick='openEditSystem(<?= json_encode(['id'=>$s['jc_id'],'name'=>$s['display_name'],'mfa'=>(!empty($s['allow_multi_factor'])&&$s['allow_multi_factor']!=='false'),'ssh'=>(!empty($s['allow_ssh'])&&$s['allow_ssh']!=='false')]) ?>)'
                        class="px-2 py-1 border border-blue-300 text-blue-600 rounded text-xs hover:bg-blue-50" data-testid="button-edit-system-<?= $s['id'] ?>">Edit</button>
                    <form method="POST" class="inline" onsubmit="return confirm('Remove this system from JumpCloud? This will unmanage the device.')">
                        <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="action" value="delete_system">
                        <input type="hidden" name="jc_system_id" value="<?= htmlspecialchars($s['jc_id']) ?>">
                        <button class="px-2 py-1 border border-red-300 text-red-600 rounded text-xs hover:bg-red-50" data-testid="button-delete-system-<?= $s['id'] ?>">Remove</button>
                    </form>
                    </div>
                </td>
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

<!-- ── Agent Install Modal -->
<div id="modal-agent-install" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-terminal text-purple-500 mr-2"></i>JumpCloud Agent Install Guide</h3>
        <button onclick="document.getElementById('modal-agent-install').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <div class="p-6 space-y-5 text-sm">
        <p class="text-gray-600">Systems are enrolled into JumpCloud by installing the JumpCloud agent. Once installed, the system will appear here automatically after syncing.</p>
        <div class="bg-gray-900 rounded-lg p-4">
            <p class="text-xs text-gray-400 mb-2 font-semibold">Linux (Debian / Ubuntu)</p>
            <code class="text-green-400 text-xs break-all">curl --tlsv1.2 --silent --show-error --output /tmp/jc-install.sh https://raw.githubusercontent.com/TheJumpCloud/support/master/scripts/install/jumpcloud_agent_installer.sh &amp;&amp; bash /tmp/jc-install.sh</code>
        </div>
        <div class="bg-gray-900 rounded-lg p-4">
            <p class="text-xs text-gray-400 mb-2 font-semibold">Linux (RHEL / CentOS)</p>
            <code class="text-green-400 text-xs break-all">curl --tlsv1.2 --silent --show-error --output /tmp/jc-install.sh https://raw.githubusercontent.com/TheJumpCloud/support/master/scripts/install/jumpcloud_agent_installer_rpm.sh &amp;&amp; bash /tmp/jc-install.sh</code>
        </div>
        <div class="bg-gray-900 rounded-lg p-4">
            <p class="text-xs text-gray-400 mb-2 font-semibold">macOS</p>
            <code class="text-green-400 text-xs break-all">curl --tlsv1.2 --silent --show-error --output /tmp/jc-install.sh https://raw.githubusercontent.com/TheJumpCloud/support/master/scripts/install/jumpcloud_agent_installer.sh &amp;&amp; sudo bash /tmp/jc-install.sh</code>
        </div>
        <div class="bg-gray-900 rounded-lg p-4">
            <p class="text-xs text-gray-400 mb-2 font-semibold">Windows (PowerShell, run as Administrator)</p>
            <code class="text-green-400 text-xs break-all">[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-Expression (Invoke-WebRequest -Uri "https://raw.githubusercontent.com/TheJumpCloud/support/master/scripts/install/jumpcloud_agent_installer.ps1" -UseBasicParsing).Content</code>
        </div>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-xs text-blue-700">
            <strong>Note:</strong> After running the installer, you will be prompted for a <strong>Connect Key</strong>. Find yours in <a href="https://console.jumpcloud.com/#/settings" target="_blank" class="underline">JumpCloud Console → Settings → Connect Key</a>.
        </div>
        <div class="flex justify-end">
            <a href="https://docs.jumpcloud.com/help/agent-installation" target="_blank" rel="noopener"
               class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm text-gray-700 flex items-center gap-2">
               <i class="fas fa-external-link-alt text-xs"></i>Full Documentation
            </a>
        </div>
    </div>
</div>
</div>

<!-- ── Edit System Modal -->
<div id="modal-edit-system" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-xl shadow-xl max-w-md w-full">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-desktop text-blue-500 mr-2"></i>Edit System Settings</h3>
        <button onclick="document.getElementById('modal-edit-system').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
        <div class="p-6 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_system">
            <input type="hidden" name="jc_system_id" id="edit_sys_id">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Display Name</label>
                <input type="text" name="sys_display_name" id="edit_sys_name" placeholder="Leave blank to keep current"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-sys-display-name">
            </div>
            <div class="flex items-center gap-3">
                <input type="checkbox" name="sys_allow_mfa" id="edit_sys_mfa" class="w-4 h-4 rounded border-gray-300" data-testid="checkbox-sys-mfa">
                <label for="edit_sys_mfa" class="text-sm text-gray-700">Allow Multi-Factor Authentication</label>
            </div>
            <div class="flex items-center gap-3">
                <input type="checkbox" name="sys_allow_ssh" id="edit_sys_ssh" class="w-4 h-4 rounded border-gray-300" data-testid="checkbox-sys-ssh">
                <label for="edit_sys_ssh" class="text-sm text-gray-700">Allow SSH Password Authentication</label>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button type="button" onclick="document.getElementById('modal-edit-system').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-save-system">Save Changes</button>
        </div>
    </form>
</div>
</div>
<script>
function openEditSystem(data) {
    document.getElementById('edit_sys_id').value  = data.id;
    document.getElementById('edit_sys_name').value = data.name;
    document.getElementById('edit_sys_mfa').checked = data.mfa;
    document.getElementById('edit_sys_ssh').checked = data.ssh;
    document.getElementById('modal-edit-system').classList.remove('hidden');
}
</script>

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
        <button onclick="document.getElementById('modal-add-user').classList.remove('hidden')"
            class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm flex items-center gap-2" data-testid="button-add-user">
            <i class="fas fa-user-plus text-xs"></i>Add User
        </button>
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
                    <form method="POST" class="inline" onsubmit="return confirm('Permanently delete user <?= htmlspecialchars(addslashes($u['username'])) ?> from JumpCloud?')">
                        <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="jc_user_id" value="<?= htmlspecialchars($u['jc_id']) ?>">
                        <button class="px-2 py-1 border border-red-300 text-red-600 rounded text-xs hover:bg-red-50" data-testid="button-delete-user-<?= $u['id'] ?>">Delete</button>
                    </form>
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

<!-- ── Add User Modal -->
<div id="modal-add-user" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-user-plus text-indigo-500 mr-2"></i>Create JumpCloud User</h3>
        <button onclick="document.getElementById('modal-add-user').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
        <div class="p-6 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">First Name</label>
                    <input type="text" name="new_firstname" placeholder="Jane"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-new-firstname">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Last Name</label>
                    <input type="text" name="new_lastname" placeholder="Smith"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-new-lastname">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                <input type="text" name="new_username" required placeholder="jsmith"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-new-username">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                <input type="email" name="new_email" required placeholder="jsmith@example.com"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-new-email">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                <input type="password" name="new_password" required placeholder="Min. 8 chars, mixed case + number"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" data-testid="input-new-password">
            </div>
            <div class="border border-gray-100 rounded-lg p-3 space-y-2 bg-gray-50">
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" name="new_sudo" class="w-4 h-4 rounded" data-testid="checkbox-new-sudo">
                    Enable sudo privileges
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" name="new_ldap" class="w-4 h-4 rounded" data-testid="checkbox-new-ldap">
                    LDAP binding user
                </label>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button type="button" onclick="document.getElementById('modal-add-user').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-add-user">Create User</button>
        </div>
    </form>
</div>
</div>

<?php /* ══════════════════ GROUPS ══════════════════ */ elseif ($active_tab === 'groups'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- User Groups -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-users text-blue-500 mr-2"></i>User Groups</h3>
        <button onclick="document.getElementById('modal-add-ugroup').classList.remove('hidden')"
            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs flex items-center gap-1.5" data-testid="button-add-ugroup">
            <i class="fas fa-plus text-xs"></i>Add Group
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-user-groups">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Description</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_usergroups)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No user groups found.' : 'API key required.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_usergroups as $g): ?>
            <tr class="hover:bg-gray-50" data-testid="row-ugroup-<?= htmlspecialchars($g['id']??'') ?>">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($g['name']??'—') ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700"><?= htmlspecialchars($g['type']??'user_group') ?></span></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($g['description']??'') ?></td>
                <td class="px-4 py-3">
                    <form method="POST" class="inline" onsubmit="return confirm('Delete user group <?= htmlspecialchars(addslashes($g['name']??'')) ?>?')">
                        <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="action" value="delete_user_group">
                        <input type="hidden" name="group_id" value="<?= htmlspecialchars($g['id']??'') ?>">
                        <button class="px-2 py-1 border border-red-300 text-red-600 rounded text-xs hover:bg-red-50">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- System Groups -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-server text-green-500 mr-2"></i>System Groups</h3>
        <button onclick="document.getElementById('modal-add-sgroup').classList.remove('hidden')"
            class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs flex items-center gap-1.5" data-testid="button-add-sgroup">
            <i class="fas fa-plus text-xs"></i>Add Group
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-system-groups">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Type</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Description</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_systemgroups)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No system groups found.' : 'API key required.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_systemgroups as $g): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($g['name']??'—') ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700"><?= htmlspecialchars($g['type']??'system_group') ?></span></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($g['description']??'') ?></td>
                <td class="px-4 py-3">
                    <form method="POST" class="inline" onsubmit="return confirm('Delete system group <?= htmlspecialchars(addslashes($g['name']??'')) ?>?')">
                        <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="action" value="delete_system_group">
                        <input type="hidden" name="group_id" value="<?= htmlspecialchars($g['id']??'') ?>">
                        <button class="px-2 py-1 border border-red-300 text-red-600 rounded text-xs hover:bg-red-50">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- ── Add User Group Modal -->
<div id="modal-add-ugroup" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-xl shadow-xl max-w-md w-full">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-users text-blue-500 mr-2"></i>Create User Group</h3>
        <button onclick="document.getElementById('modal-add-ugroup').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
        <div class="p-6 space-y-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_user_group">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Group Name <span class="text-red-500">*</span></label>
                <input type="text" name="ug_name" required placeholder="e.g. Engineering"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-ug-name">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <input type="text" name="ug_desc" placeholder="Optional description"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" data-testid="input-ug-desc">
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button type="button" onclick="document.getElementById('modal-add-ugroup').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-ugroup">Create Group</button>
        </div>
    </form>
</div>
</div>

<!-- ── Add System Group Modal -->
<div id="modal-add-sgroup" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-xl shadow-xl max-w-md w-full">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-server text-green-500 mr-2"></i>Create System Group</h3>
        <button onclick="document.getElementById('modal-add-sgroup').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
        <div class="p-6 space-y-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_system_group">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Group Name <span class="text-red-500">*</span></label>
                <input type="text" name="sg_name" required placeholder="e.g. Production Servers"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" data-testid="input-sg-name">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <input type="text" name="sg_desc" placeholder="Optional description"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" data-testid="input-sg-desc">
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button type="button" onclick="document.getElementById('modal-add-sgroup').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-sgroup">Create Group</button>
        </div>
    </form>
</div>
</div>

<?php /* ══════════════════ POLICIES & APPS ══════════════════ */ elseif ($active_tab === 'policies'): ?>
<!-- Policies -->
<div class="bg-white rounded-lg border border-gray-200 mb-6">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-file-shield text-purple-500 mr-2"></i>Policies</h3>
        <button onclick="document.getElementById('modal-add-policy').classList.remove('hidden')"
            class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs flex items-center gap-1.5" data-testid="button-add-policy">
            <i class="fas fa-plus text-xs"></i>Add Policy
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-policies">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Template</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500">Active</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">ID</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($live_policies)): ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400"><?= $jc_connected ? 'No policies found.' : 'Configure API key.' ?></td></tr>
            <?php else: ?>
            <?php foreach ($live_policies as $p): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($p['name']??'—') ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars($p['template']['name']??$p['templateId']??'—') ?></td>
                <td class="px-4 py-3 text-center"><?= jc_bool_icon(!empty($p['active'])) ?></td>
                <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= htmlspecialchars(substr($p['id']??'',0,24)) ?></td>
                <td class="px-4 py-3">
                    <form method="POST" class="inline" onsubmit="return confirm('Delete policy <?= htmlspecialchars(addslashes($p['name']??'')) ?>?')">
                        <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="action" value="delete_policy">
                        <input type="hidden" name="policy_id" value="<?= htmlspecialchars($p['id']??'') ?>">
                        <button class="px-2 py-1 border border-red-300 text-red-600 rounded text-xs hover:bg-red-50">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Add Policy Modal -->
<div id="modal-add-policy" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900"><i class="fas fa-file-shield text-purple-500 mr-2"></i>Create Policy</h3>
        <button onclick="document.getElementById('modal-add-policy').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
        <div class="p-6 space-y-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_policy">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Policy Name <span class="text-red-500">*</span></label>
                <input type="text" name="pol_name" required placeholder="e.g. Disable USB Storage"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" data-testid="input-pol-name">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Template <span class="text-red-500">*</span></label>
                <?php if (!empty($live_pol_templates)): ?>
                <select name="pol_template" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" data-testid="select-pol-template">
                    <option value="">— Select a template —</option>
                    <?php foreach ($live_pol_templates as $tmpl): ?>
                    <option value="<?= htmlspecialchars($tmpl['id']??'') ?>"><?= htmlspecialchars($tmpl['name']??$tmpl['id']??'Unknown') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="text" name="pol_template" required placeholder="Template ID (e.g. 5f08f8c2a7b54e001d8c98a2)"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 font-mono" data-testid="input-pol-template">
                <p class="text-xs text-gray-400 mt-1">Templates could not be loaded from the API. Enter the template ID manually.</p>
                <?php endif; ?>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
                <strong>Note:</strong> The policy will be created with default values. You can configure specific settings in the <a href="https://console.jumpcloud.com/#/policies" target="_blank" class="underline">JumpCloud Console</a>.
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
            <button type="button" onclick="document.getElementById('modal-add-policy').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-policy">Create Policy</button>
        </div>
    </form>
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
                ['Provider ID', $jc_provider_id ?: '(not set)',                              !empty($jc_provider_id)],
                ['Active Org',  $jc_active_org_name ?: ($jc_active_org_id ?: 'Provider View (no org selected)'), true],
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
                ['JUMPCLOUD_API_KEY', 'Your JumpCloud MSP admin API key', $jc_api_key ? 'Set ✓' : 'MISSING', $jc_api_key ? 'text-green-600' : 'text-red-600'],
                ['JUMPCLOUD_ORG_ID',  'Your JumpCloud MSP Provider ID (required for multi-tenant)', $jc_provider_id ? 'Set ✓' : 'Not set', $jc_provider_id ? 'text-green-600' : 'text-amber-500'],
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
