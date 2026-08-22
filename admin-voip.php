<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$voip_username = VOIP_API_USERNAME;
$voip_password = VOIP_API_PASSWORD;
$voip_token = VOIP_API_TOKEN;
$voip_url = VOIP_API_URL;
$voip_username_set = !empty($voip_username);
$voip_password_set = !empty($voip_password);
$voip_token_set = !empty($voip_token);
$voip_connected = $voip_username_set && ($voip_password_set || $voip_token_set);

$active_tab = $_GET['tab'] ?? 'overview';
$error_msg = '';
$success_msg = '';

$balance = null;
$dids = [];
$subaccounts = [];
$cdr_records = [];
$registration_status = [];
$servers = [];
$ip_info = null;

function voip_api_call($action, $extra_params = []) {
    $password = !empty(VOIP_API_PASSWORD) ? VOIP_API_PASSWORD : VOIP_API_TOKEN;
    $params = array_merge([
        'api_username' => VOIP_API_USERNAME,
        'api_password' => $password,
        'method' => $action,
    ], $extra_params);
    $url = VOIP_API_URL . '?' . http_build_query($params);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return ['status' => 'error', 'message' => 'Connection failed'];
    $data = json_decode($response, true);
    if (!$data) return ['status' => 'error', 'message' => 'Invalid response'];

    $status = $data['status'] ?? '';
    if ($status === 'ip_not_enabled') {
        return $data;
    }

    if ($status === 'invalid_credentials' && !empty(VOIP_API_PASSWORD) && !empty(VOIP_API_TOKEN)) {
        $params['api_password'] = VOIP_API_TOKEN;
        $url = VOIP_API_URL . '?' . http_build_query($params);
        $response = @file_get_contents($url, false, $ctx);
        if ($response !== false) {
            $data2 = json_decode($response, true);
            if ($data2 && ($data2['status'] ?? '') !== 'invalid_credentials') return $data2;
        }
    }

    return $data;
}

$test_connection_result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'test_connection') {
    require_csrf();
    
    $active_tab = 'config';
    $tc_user = VOIP_API_USERNAME;
    $tc_pass = !empty(VOIP_API_PASSWORD) ? VOIP_API_PASSWORD : VOIP_API_TOKEN;
    $tc_url = VOIP_API_URL . '?' . http_build_query([
        'method' => 'getBalance',
        'api_username' => $tc_user,
        'api_password' => $tc_pass,
    ]);
    $tc_ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $tc_response = @file_get_contents($tc_url, false, $tc_ctx);
    if ($tc_response === false) {
        $test_connection_result = ['status' => 'error', 'message' => 'Connection failed - could not reach VoIP.ms API'];
    } else {
        $test_connection_result = json_decode($tc_response, true);
        if (!$test_connection_result) {
            $test_connection_result = ['status' => 'error', 'message' => 'Invalid response from API'];
        }
    }
}

if ($voip_connected && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_did') {
        $did = trim($_POST['did'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $routing = trim($_POST['routing'] ?? '');
        $pop = trim($_POST['pop'] ?? '');
        $callerid_prefix = trim($_POST['callerid_prefix'] ?? '');

        $routing_type = trim($_POST['routing_type'] ?? '');
        if ($routing_type && $routing === '') {
            $rt_map = [
                'account' => 'routing_account',
                'ivr' => 'routing_ivr',
                'queue' => 'routing_queue',
                'timecondition' => 'routing_timecondition',
                'fwd' => 'routing_fwd',
                'conference' => 'routing_conference',
                'ring_group' => 'routing_ring_group',
                'recording' => 'routing_recording',
                'callback' => 'routing_callback',
                'disa' => 'routing_disa',
                'voicemail' => 'routing_voicemail',
                'sys' => 'routing_sys',
            ];
            $rt_field = $rt_map[$routing_type] ?? '';
            $rt_value = trim($_POST[$rt_field] ?? '');
            if ($rt_value !== '') {
                if ($routing_type === 'account') $routing = 'account:' . $rt_value;
                elseif ($routing_type === 'fwd') $routing = 'fwd:' . $rt_value;
                elseif ($routing_type === 'sys') $routing = 'sys:' . $rt_value;
                else $routing = $routing_type . ':' . $rt_value;
            }
        }

        if ($did) {
            $params = ['did' => $did];
            if ($description !== '') $params['description'] = $description;
            if ($routing !== '') $params['routing'] = $routing;
            if ($pop !== '') $params['pop'] = $pop;
            if ($callerid_prefix !== '') $params['callerid_prefix'] = $callerid_prefix;

            $result = voip_api_call('setDIDInfo', $params);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'DID ' . htmlspecialchars($did) . ' updated successfully.';
                try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], 'did_updated', 'voip_did', $did, 'Updated DID ' . $did, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
            } else {
                $error_msg = 'Failed to update DID: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'dids';
    }

    if ($action === 'route_did') {
        $did = trim($_POST['did'] ?? '');
        $routing = trim($_POST['routing'] ?? '');
        if ($did && $routing) {
            $result = voip_api_call('setDIDInfo', ['did' => $did, 'routing' => $routing]);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'DID ' . htmlspecialchars($did) . ' routed to ' . htmlspecialchars($routing) . ' successfully.';
                try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], 'did_routed', 'voip_did', $did, 'Routed DID ' . $did . ' to ' . $routing, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
            } else {
                $error_msg = 'Failed to route DID: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'dids';
    }

    if ($action === 'cancel_did') {
        $did = trim($_POST['did'] ?? '');
        $comment = trim($_POST['cancel_comment'] ?? 'Cancelled via portal');
        $port_out = isset($_POST['port_out']) ? '1' : '0';
        if ($did) {
            $result = voip_api_call('cancelDID', ['did' => $did, 'cancelcomment' => $comment, 'portout' => $port_out]);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'DID ' . htmlspecialchars($did) . ' has been cancelled. It will be released at the end of the billing period.';
                try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], 'did_cancelled', 'voip_did', $did, 'Cancelled DID ' . $did, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
            } else {
                $error_msg = 'Failed to cancel DID: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'dids';
    }

    if ($action === 'renew_did') {
        $did = trim($_POST['did'] ?? '');
        $renew_period = trim($_POST['renew_period'] ?? 'monthly');
        if ($did) {
            $result = voip_api_call('setDIDInfo', ['did' => $did, 'billing_type' => ($renew_period === 'annual' ? '2' : '1')]);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'DID ' . htmlspecialchars($did) . ' renewal set to ' . htmlspecialchars($renew_period) . '.';
            } else {
                $error_msg = 'Failed to update DID renewal: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'dids';
    }

    if ($action === 'set_did_sms') {
        $did = trim($_POST['did'] ?? '');
        $sms_enabled = isset($_POST['sms_enabled']) ? '1' : '0';
        $sms_email = trim($_POST['sms_email'] ?? '');
        $sms_url = trim($_POST['sms_url'] ?? '');
        if ($did) {
            $params = ['did' => $did, 'sms_enabled' => $sms_enabled];
            if ($sms_email !== '') $params['sms_email'] = $sms_email;
            if ($sms_url !== '') $params['sms_url_callback'] = $sms_url;
            $result = voip_api_call('setSMS', $params);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'SMS settings updated for DID ' . htmlspecialchars($did) . '.';
            } else {
                $error_msg = 'Failed to update SMS: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'dids';
    }

    if ($action === 'set_did_voicemail') {
        $did = trim($_POST['did'] ?? '');
        $vm_enabled = isset($_POST['vm_enabled']) ? '1' : '0';
        $vm_email = trim($_POST['vm_email'] ?? '');
        $vm_attach = isset($_POST['vm_attach']) ? '1' : '0';
        if ($did) {
            $params = ['did' => $did, 'enable' => $vm_enabled];
            if ($vm_email !== '') $params['email'] = $vm_email;
            $params['attach_message'] = $vm_attach;
            $params['delete_message'] = '0';
            $result = voip_api_call('setVoicemail', $params);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'Voicemail settings updated for DID ' . htmlspecialchars($did) . '.';
            } else {
                $error_msg = 'Failed to update voicemail: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'dids';
    }

    if ($action === 'set_did_forwarding') {
        $did = trim($_POST['did'] ?? '');
        $fwd_enable = isset($_POST['fwd_enable']) ? 'yes' : 'no';
        $fwd_number = trim($_POST['fwd_number'] ?? '');
        $fwd_type = trim($_POST['fwd_type'] ?? 'all');
        $fwd_timeout = trim($_POST['fwd_timeout'] ?? '20');
        if ($did) {
            $params = ['did' => $did];
            if ($fwd_enable === 'yes' && $fwd_number !== '') {
                $params['routing'] = 'sys:fwd:' . $fwd_number;
            }
            $result = voip_api_call('setDIDInfo', $params);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'Forwarding updated for DID ' . htmlspecialchars($did) . '.';
            } else {
                $error_msg = 'Failed to update forwarding: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'dids';
    }

    if ($action === 'create_subaccount') {
        $sa_username = trim($_POST['sa_username'] ?? '');
        $sa_password = trim($_POST['sa_password'] ?? '');
        $sa_protocol = trim($_POST['sa_protocol'] ?? '1');
        $sa_description = trim($_POST['sa_description'] ?? '');
        $sa_auth_type = trim($_POST['sa_auth_type'] ?? '1');
        $sa_callerid = trim($_POST['sa_callerid'] ?? '');
        $sa_lock_intl = isset($_POST['sa_lock_intl']) ? 'yes' : 'no';
        $sa_internal_ext = trim($_POST['sa_internal_extension'] ?? '');
        $sa_internal_voicemail = trim($_POST['sa_internal_voicemail'] ?? '');
        $sa_internal_callerid = trim($_POST['sa_internal_callerid'] ?? '');

        if ($sa_username && $sa_password) {
            $create_params = [
                'username' => $sa_username,
                'password' => $sa_password,
                'protocol' => $sa_protocol,
                'description' => $sa_description,
                'auth_type' => $sa_auth_type,
                'lock_international' => $sa_lock_intl,
                'device_type' => '2',
                'canada_routing' => '1',
                'international_route' => '1',
                'music_on_hold' => 'default',
                'allowed_codecs' => 'ulaw;alaw;g729',
                'dtmf_mode' => 'auto',
                'nat' => 'yes',
            ];
            if ($sa_callerid !== '') $create_params['callerid_number'] = $sa_callerid;
            if ($sa_internal_ext !== '') $create_params['internal_extension'] = $sa_internal_ext;
            if ($sa_internal_voicemail !== '') $create_params['internal_voicemail'] = $sa_internal_voicemail;
            if ($sa_internal_callerid !== '') $create_params['internal_callerid'] = $sa_internal_callerid;

            $result = voip_api_call('createSubAccount', $create_params);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'Sub-account &ldquo;' . htmlspecialchars($sa_username) . '&rdquo; created successfully! (ID: ' . htmlspecialchars($result['id'] ?? 'N/A') . ')';
                try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], 'subaccount_created', 'voip_subaccount', $result['id'] ?? '', 'Created sub-account: ' . $sa_username, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
            } else {
                $error_msg = 'Failed to create sub-account: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        } else {
            $error_msg = 'Username and password are required.';
        }
        $active_tab = 'subaccounts';
    }

    if ($action === 'update_subaccount') {
        $sa_id = trim($_POST['sa_id'] ?? '');
        $sa_description = trim($_POST['sa_description'] ?? '');
        $sa_password = trim($_POST['sa_password'] ?? '');
        $sa_callerid = trim($_POST['sa_callerid'] ?? '');
        $sa_lock_intl = isset($_POST['sa_lock_intl']) ? 'yes' : 'no';

        if ($sa_id) {
            $update_params = ['id' => $sa_id, 'lock_international' => $sa_lock_intl];
            if ($sa_description !== '') $update_params['description'] = $sa_description;
            if ($sa_password !== '') $update_params['password'] = $sa_password;
            if ($sa_callerid !== '') $update_params['callerid_number'] = $sa_callerid;

            $result = voip_api_call('setSubAccount', $update_params);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'Sub-account updated successfully.';
                try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], 'subaccount_updated', 'voip_subaccount', $sa_id, 'Updated sub-account ID ' . $sa_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
            } else {
                $error_msg = 'Failed to update sub-account: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'subaccounts';
    }

    if ($action === 'delete_subaccount') {
        $sa_id = trim($_POST['sa_id'] ?? '');
        if ($sa_id) {
            $result = voip_api_call('delSubAccount', ['id' => $sa_id]);
            if (($result['status'] ?? '') === 'success') {
                $success_msg = 'Sub-account deleted.';
                try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'], 'subaccount_deleted', 'voip_subaccount', $sa_id, 'Deleted sub-account ID ' . $sa_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
            } else {
                $error_msg = 'Failed to delete sub-account: ' . htmlspecialchars($result['status'] ?? 'Unknown error');
            }
        }
        $active_tab = 'subaccounts';
    }
}

$api_authenticated = false;
if ($voip_connected) {
    $balance_result = voip_api_call('getBalance');
    if (($balance_result['status'] ?? '') === 'success') {
        $balance = $balance_result['balance'] ?? null;
        $api_authenticated = true;
    } elseif (($balance_result['status'] ?? '') === 'ip_not_enabled') {
        if (!$error_msg) {
            $error_msg = 'API credentials are correct, but this server\'s IP is not whitelisted in VoIP.ms. Go to VoIP.ms → Main Menu → SOAP and REST/JSON API → Allowed IPs, and add this server\'s IP address (or select "Allow All" for testing).';
        }
    } elseif (($balance_result['status'] ?? '') === 'invalid_credentials') {
        if (!$error_msg) {
            $error_msg = 'VoIP.ms API credentials are invalid. Please verify your VOIP_USERNAME is your VoIP.ms email and VOIP_PASSWORD is the API password (set under SOAP/REST API in your VoIP.ms panel — this is different from your login password).';
        }
    }

    if ($active_tab === 'overview') {
        $ip_result = voip_api_call('getIP');
        if (($ip_result['status'] ?? '') === 'success') {
            $ip_info = $ip_result['ip'] ?? null;
        }
        $servers_result = voip_api_call('getServersInfo');
        if (($servers_result['status'] ?? '') === 'success') {
            $servers = $servers_result['servers'] ?? [];
            if (!is_array($servers)) $servers = [];
        }
        $dids_result = voip_api_call('getDIDsInfo');
        $did_count = 0;
        if (($dids_result['status'] ?? '') === 'success') {
            $did_count = count($dids_result['dids'] ?? []);
        }
        $sub_result_ov = voip_api_call('getSubAccounts');
        $sub_count = 0;
        if (($sub_result_ov['status'] ?? '') === 'success') {
            $sub_count = count($sub_result_ov['accounts'] ?? []);
        }
    }

    $routing_accounts = [];
    $ivrs = [];
    $ring_groups = [];
    $queues = [];
    $voicemails_list = [];
    $conferences = [];
    $callbacks_list = [];
    $disas = [];
    $recordings_list = [];
    $forwarding_list = [];
    $time_conditions = [];
    $pop_servers = [];

    if ($active_tab === 'dids' || $active_tab === 'did_edit') {
        $dids_result = voip_api_call('getDIDsInfo');
        if (($dids_result['status'] ?? '') === 'success') {
            $dids = $dids_result['dids'] ?? [];
            if (!is_array($dids)) $dids = [];
        } elseif (($dids_result['status'] ?? '') === 'no_did') {
            $dids = [];
        } else {
            if (!$error_msg) $error_msg = 'DIDs: ' . ($dids_result['message'] ?? $dids_result['status'] ?? 'Unknown error');
        }

        $sub_for_routing = voip_api_call('getSubAccounts');
        if (($sub_for_routing['status'] ?? '') === 'success') {
            $routing_accounts = $sub_for_routing['accounts'] ?? [];
            if (!is_array($routing_accounts)) $routing_accounts = [];
        }

        $ivr_result = voip_api_call('getIVRs');
        if (($ivr_result['status'] ?? '') === 'success') {
            $ivrs = $ivr_result['ivrs'] ?? [];
            if (!is_array($ivrs)) $ivrs = [];
        }

        $rg_result = voip_api_call('getRingGroups');
        if (($rg_result['status'] ?? '') === 'success') {
            $ring_groups = $rg_result['ring_groups'] ?? [];
            if (!is_array($ring_groups)) $ring_groups = [];
        }

        $q_result = voip_api_call('getQueues');
        if (($q_result['status'] ?? '') === 'success') {
            $queues = $q_result['queues'] ?? [];
            if (!is_array($queues)) $queues = [];
        }

        $vm_result = voip_api_call('getVoicemails');
        if (($vm_result['status'] ?? '') === 'success') {
            $voicemails_list = $vm_result['voicemails'] ?? [];
            if (!is_array($voicemails_list)) $voicemails_list = [];
        }

        $conf_result = voip_api_call('getConferences');
        if (($conf_result['status'] ?? '') === 'success') {
            $conferences = $conf_result['conferences'] ?? [];
            if (!is_array($conferences)) $conferences = [];
        }

        $cb_result = voip_api_call('getCallbacks');
        if (($cb_result['status'] ?? '') === 'success') {
            $callbacks_list = $cb_result['callbacks'] ?? [];
            if (!is_array($callbacks_list)) $callbacks_list = [];
        }

        $disa_result = voip_api_call('getDISAs');
        if (($disa_result['status'] ?? '') === 'success') {
            $disas = $disa_result['disas'] ?? [];
            if (!is_array($disas)) $disas = [];
        }

        $rec_result = voip_api_call('getRecordings');
        if (($rec_result['status'] ?? '') === 'success') {
            $recordings_list = $rec_result['recordings'] ?? [];
            if (!is_array($recordings_list)) $recordings_list = [];
        }

        $fwd_result = voip_api_call('getForwardings');
        if (($fwd_result['status'] ?? '') === 'success') {
            $forwarding_list = $fwd_result['forwardings'] ?? [];
            if (!is_array($forwarding_list)) $forwarding_list = [];
        }

        $tc_result = voip_api_call('getTimeConditions');
        if (($tc_result['status'] ?? '') === 'success') {
            $time_conditions = $tc_result['timeconditions'] ?? [];
            if (!is_array($time_conditions)) $time_conditions = [];
        }

        $pop_result = voip_api_call('getServersInfo');
        if (($pop_result['status'] ?? '') === 'success') {
            $pop_servers = $pop_result['servers'] ?? [];
            if (!is_array($pop_servers)) $pop_servers = [];
        }
    }

    if ($active_tab === 'subaccounts') {
        $sub_result = voip_api_call('getSubAccounts');
        if (($sub_result['status'] ?? '') === 'success') {
            $subaccounts = $sub_result['accounts'] ?? [];
            if (!is_array($subaccounts)) $subaccounts = [];
        } elseif (($sub_result['status'] ?? '') === 'no_subaccount') {
            $subaccounts = [];
        } else {
            if (!$error_msg) $error_msg = 'Sub-accounts: ' . ($sub_result['message'] ?? $sub_result['status'] ?? 'Unknown error');
        }
    }

    if ($active_tab === 'cdr') {
        $cdr_from = $_GET['cdr_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $cdr_to = $_GET['cdr_to'] ?? date('Y-m-d');
        $cdr_type = $_GET['cdr_type'] ?? '1';
        $cdr_result = voip_api_call('getCDR', [
            'date_from' => $cdr_from,
            'date_to' => $cdr_to,
            'answered' => $cdr_type === '1' ? '1' : '',
            'timezone' => '-5',
        ]);
        if (($cdr_result['status'] ?? '') === 'success') {
            $cdr_records = $cdr_result['cdr'] ?? [];
            if (!is_array($cdr_records)) $cdr_records = [];
        } elseif (($cdr_result['status'] ?? '') === 'no_cdr') {
            $cdr_records = [];
        } else {
            if (!$error_msg) $error_msg = 'CDR: ' . ($cdr_result['message'] ?? $cdr_result['status'] ?? 'Unknown error');
        }
    }

    if ($active_tab === 'registration') {
        $reg_result = voip_api_call('getRegistrationStatus', ['account' => $voip_username]);
        if (($reg_result['status'] ?? '') === 'success') {
            $registration_status = $reg_result['registered'] ?? [];
            if (!is_array($registration_status)) $registration_status = [];
        }
        $sub_result2 = voip_api_call('getSubAccounts');
        if (($sub_result2['status'] ?? '') === 'success') {
            $sub_list = $sub_result2['accounts'] ?? [];
            if (is_array($sub_list)) {
                foreach ($sub_list as $sa) {
                    $sa_user = $sa['username'] ?? '';
                    if (!empty($sa_user)) {
                        $sa_reg = voip_api_call('getRegistrationStatus', ['account' => $sa_user]);
                        if (($sa_reg['status'] ?? '') === 'success') {
                            $sa_registered = $sa_reg['registered'] ?? [];
                            if (is_array($sa_registered)) {
                                foreach ($sa_registered as $r) {
                                    $r['sub_account'] = $sa_user;
                                    $registration_status[] = $r;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$tabs = [
    'overview' => ['icon' => 'fa-tachometer-alt', 'label' => 'Overview'],
    'dids' => ['icon' => 'fa-phone-volume', 'label' => 'DIDs'],
    'subaccounts' => ['icon' => 'fa-headset', 'label' => 'Sub-Accounts'],
    'cdr' => ['icon' => 'fa-list-alt', 'label' => 'Call Records'],
    'registration' => ['icon' => 'fa-signal', 'label' => 'Registration'],
    'config' => ['icon' => 'fa-cog', 'label' => 'Configuration'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VoIP.ms Integration - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-phone text-blue-500 mr-2"></i>VoIP.ms Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Phone system &mdash; CDR lookup, DID management, sub-accounts, and call routing</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($balance !== null): ?>
                        <span class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-full text-xs font-medium" data-testid="text-balance"><i class="fas fa-wallet mr-1"></i>$<?php echo number_format((float)$balance, 2); ?></span>
                    <?php endif; ?>
                    <?php if ($api_authenticated): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php elseif ($voip_connected && ($balance_result['status'] ?? '') === 'ip_not_enabled'): ?>
                        <span class="px-3 py-1.5 bg-orange-100 text-orange-700 rounded-full text-xs font-medium" data-testid="status-ip-blocked"><i class="fas fa-shield-alt text-[10px] mr-1"></i>IP Not Whitelisted</span>
                    <?php elseif ($voip_connected): ?>
                        <span class="px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium" data-testid="status-auth-failed"><i class="fas fa-exclamation-triangle text-[10px] mr-1"></i>Auth Failed</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                    <a href="https://voip.ms/m/main.php" target="_blank" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-full text-xs font-medium transition" data-testid="link-voipms-portal"><i class="fas fa-external-link-alt mr-1"></i>VoIP.ms Portal</a>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm mb-4" data-testid="alert-error"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm mb-4" data-testid="alert-success"><i class="fas fa-check-circle mr-2"></i><?php echo $success_msg; ?></div>
            <?php endif; ?>

            <div class="flex bg-gray-100 rounded-lg p-1 mb-6 overflow-x-auto">
                <?php foreach ($tabs as $key => $tab): ?>
                    <a href="?tab=<?php echo $key; ?>" class="px-4 py-2 text-sm font-medium rounded-md transition whitespace-nowrap <?php echo $active_tab === $key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'; ?>" data-testid="tab-<?php echo $key; ?>">
                        <i class="fas <?php echo $tab['icon']; ?> mr-1"></i><?php echo $tab['label']; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$voip_connected && $active_tab !== 'config'): ?>
                <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                    <i class="fas fa-phone-slash text-gray-300 text-5xl mb-4"></i>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">VoIP.ms Not Connected</h3>
                    <p class="text-sm text-gray-500 mb-4">Set your API credentials in Replit Secrets to connect.</p>
                    <a href="?tab=config" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-configure"><i class="fas fa-cog mr-2"></i>Configure</a>
                </div>

            <?php elseif ($active_tab === 'overview'): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-balance">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Account Balance</p>
                        <?php if ($balance !== null): ?>
                            <p class="text-2xl font-bold <?php echo ((float)$balance < 5) ? 'text-red-600' : 'text-green-600'; ?>">$<?php echo number_format((float)$balance, 2); ?></p>
                            <p class="text-xs text-gray-400 mt-1"><?php echo ((float)$balance < 5) ? '<span class="text-red-500 font-medium">Low balance warning</span>' : 'Sufficient funds'; ?></p>
                        <?php else: ?>
                            <p class="text-lg font-bold text-gray-400">--</p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-dids-count">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active DIDs</p>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $did_count ?? 0; ?></p>
                        <a href="?tab=dids" class="text-xs text-blue-500 hover:underline mt-1 inline-block">Manage &rarr;</a>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-subs-count">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Sub-Accounts</p>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $sub_count ?? 0; ?></p>
                        <a href="?tab=subaccounts" class="text-xs text-purple-500 hover:underline mt-1 inline-block">Manage &rarr;</a>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-ip">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Your IP</p>
                        <?php if ($ip_info): ?>
                            <p class="text-sm font-bold text-gray-900 font-mono"><?php echo htmlspecialchars($ip_info); ?></p>
                        <?php else: ?>
                            <p class="text-sm font-bold text-gray-400">--</p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-400 mt-1">Registered with VoIP.ms</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-bolt text-yellow-500 mr-2"></i>Quick Actions</h2>
                        </div>
                        <div class="p-6 grid grid-cols-2 gap-3">
                            <a href="?tab=dids" class="flex items-center gap-3 p-3 bg-blue-50 hover:bg-blue-100 rounded-lg transition" data-testid="action-view-dids">
                                <div class="w-9 h-9 bg-blue-500 rounded-lg flex items-center justify-center"><i class="fas fa-phone-volume text-white text-sm"></i></div>
                                <div><p class="text-sm font-medium text-gray-900">Manage DIDs</p><p class="text-[10px] text-gray-500">View & route numbers</p></div>
                            </a>
                            <a href="?tab=subaccounts" class="flex items-center gap-3 p-3 bg-purple-50 hover:bg-purple-100 rounded-lg transition" data-testid="action-subaccounts">
                                <div class="w-9 h-9 bg-purple-500 rounded-lg flex items-center justify-center"><i class="fas fa-headset text-white text-sm"></i></div>
                                <div><p class="text-sm font-medium text-gray-900">Sub-Accounts</p><p class="text-[10px] text-gray-500">Create & manage SIP</p></div>
                            </a>
                            <a href="?tab=cdr" class="flex items-center gap-3 p-3 bg-green-50 hover:bg-green-100 rounded-lg transition" data-testid="action-cdr">
                                <div class="w-9 h-9 bg-green-500 rounded-lg flex items-center justify-center"><i class="fas fa-list-alt text-white text-sm"></i></div>
                                <div><p class="text-sm font-medium text-gray-900">Call Records</p><p class="text-[10px] text-gray-500">CDR lookup</p></div>
                            </a>
                            <a href="?tab=registration" class="flex items-center gap-3 p-3 bg-orange-50 hover:bg-orange-100 rounded-lg transition" data-testid="action-registration">
                                <div class="w-9 h-9 bg-orange-500 rounded-lg flex items-center justify-center"><i class="fas fa-signal text-white text-sm"></i></div>
                                <div><p class="text-sm font-medium text-gray-900">Registration</p><p class="text-[10px] text-gray-500">SIP status</p></div>
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($servers)): ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-server text-green-500 mr-2"></i>POP Servers (<?php echo count($servers); ?>)</h2>
                        </div>
                        <div class="overflow-y-auto max-h-[260px]">
                            <table class="w-full">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Server</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Hostname</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Country</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach (array_slice($servers, 0, 15) as $srv): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 text-xs font-medium text-gray-900"><?php echo htmlspecialchars($srv['server_name'] ?? $srv['server_pop'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($srv['server_hostname'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($srv['server_country'] ?? ''); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-info-circle text-blue-500 mr-2"></i>API Capabilities</h2>
                        </div>
                        <div class="p-6 space-y-3 text-sm text-gray-600">
                            <p><i class="fas fa-check text-green-500 mr-2"></i>Account balance & billing info</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>DID (phone number) management</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>Sub-account provisioning</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>Call Detail Records (CDR)</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>SIP registration status</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>Voicemail management</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>Call forwarding & routing</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>IVR & ring group config</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'dids' || $active_tab === 'did_edit'): ?>
                <?php
                    $edit_did_number = $_GET['did'] ?? '';
                    $edit_did_data = null;
                    if ($active_tab === 'did_edit' && $edit_did_number) {
                        foreach ($dids as $d) { if (($d['did'] ?? '') === $edit_did_number) { $edit_did_data = $d; break; } }
                    }
                ?>
                <?php if ($active_tab === 'did_edit' && $edit_did_data): ?>
                <?php
                    $ed = $edit_did_data;
                    $ed_routing = $ed['routing'] ?? '';
                    $ed_routing_type = '';
                    $ed_routing_value = '';
                    if (strpos($ed_routing, 'account:') === 0) { $ed_routing_type = 'account'; $ed_routing_value = substr($ed_routing, 8); }
                    elseif (strpos($ed_routing, 'ivr:') === 0) { $ed_routing_type = 'ivr'; $ed_routing_value = substr($ed_routing, 4); }
                    elseif (strpos($ed_routing, 'ring_group:') === 0) { $ed_routing_type = 'ring_group'; $ed_routing_value = substr($ed_routing, 11); }
                    elseif (strpos($ed_routing, 'queue:') === 0) { $ed_routing_type = 'queue'; $ed_routing_value = substr($ed_routing, 6); }
                    elseif (strpos($ed_routing, 'callforward:') === 0) { $ed_routing_type = 'callforward'; $ed_routing_value = substr($ed_routing, 12); }
                    elseif (strpos($ed_routing, 'recording:') === 0) { $ed_routing_type = 'recording'; $ed_routing_value = substr($ed_routing, 10); }
                    elseif (strpos($ed_routing, 'callback:') === 0) { $ed_routing_type = 'callback'; $ed_routing_value = substr($ed_routing, 9); }
                    elseif (strpos($ed_routing, 'disa:') === 0) { $ed_routing_type = 'disa'; $ed_routing_value = substr($ed_routing, 5); }
                    elseif (strpos($ed_routing, 'conference:') === 0) { $ed_routing_type = 'conference'; $ed_routing_value = substr($ed_routing, 11); }
                    elseif (strpos($ed_routing, 'timecondition:') === 0) { $ed_routing_type = 'timecondition'; $ed_routing_value = substr($ed_routing, 14); }
                    elseif (strpos($ed_routing, 'callingcard:') === 0) { $ed_routing_type = 'callingcard'; $ed_routing_value = substr($ed_routing, 12); }
                    elseif (strpos($ed_routing, 'fwd:') === 0) { $ed_routing_type = 'fwd'; $ed_routing_value = substr($ed_routing, 4); }
                    elseif (strpos($ed_routing, 'voicemail:') === 0) { $ed_routing_type = 'voicemail'; $ed_routing_value = substr($ed_routing, 10); }
                    elseif (strpos($ed_routing, 'sys:') === 0) { $ed_routing_type = 'sys'; $ed_routing_value = substr($ed_routing, 4); }
                    else { $ed_routing_type = 'account'; $ed_routing_value = $ed_routing; }
                ?>
                <div class="mb-4">
                    <a href="?tab=dids" class="text-sm text-blue-600 hover:underline" data-testid="link-back-to-dids"><i class="fas fa-arrow-left mr-1"></i>Back to DID Numbers</a>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-edit text-blue-500 mr-2"></i>Edit DID Settings</h2>
                            <span class="text-sm font-mono font-semibold text-blue-600 bg-blue-50 px-3 py-1 rounded-lg"><?php echo htmlspecialchars($ed['did']); ?></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($ed['description'] ?? 'No description'); ?></p>
                    </div>
                    <form method="POST" class="p-6">
                            <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_did">
                        <input type="hidden" name="did" value="<?php echo htmlspecialchars($ed['did']); ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Description / Note</label>
                                <input type="text" name="description" value="<?php echo htmlspecialchars($ed['description'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. Main Office, Fax Line" data-testid="input-did-description">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">CallerID Prefix</label>
                                <input type="text" name="callerid_prefix" value="<?php echo htmlspecialchars($ed['callerid_prefix'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. BM-STD-" data-testid="input-did-callerid">
                            </div>
                        </div>

                        <div class="border border-gray-200 rounded-lg mb-6">
                            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between rounded-t-lg">
                                <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-route text-blue-500 mr-1"></i>Routing Settings</h3>
                                <span class="text-xs text-gray-500">Current: <code class="bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($ed_routing ?: 'none'); ?></code></span>
                            </div>
                            <div class="p-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-blue-50 cursor-pointer transition <?php echo $ed_routing_type === 'account' ? 'bg-blue-50 ring-1 ring-blue-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="account" <?php echo $ed_routing_type === 'account' ? 'checked' : ''; ?> class="text-blue-600 focus:ring-blue-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-headset text-blue-500 w-4"></i>
                                        <span class="text-sm text-gray-700">SIP / IAX</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-green-50 cursor-pointer transition <?php echo $ed_routing_type === 'ivr' ? 'bg-green-50 ring-1 ring-green-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="ivr" <?php echo $ed_routing_type === 'ivr' ? 'checked' : ''; ?> class="text-green-600 focus:ring-green-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-sitemap text-green-500 w-4"></i>
                                        <span class="text-sm text-gray-700">IVR</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-purple-50 cursor-pointer transition <?php echo $ed_routing_type === 'queue' ? 'bg-purple-50 ring-1 ring-purple-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="queue" <?php echo $ed_routing_type === 'queue' ? 'checked' : ''; ?> class="text-purple-600 focus:ring-purple-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-users-cog text-purple-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Calling Queue</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-yellow-50 cursor-pointer transition <?php echo $ed_routing_type === 'timecondition' ? 'bg-yellow-50 ring-1 ring-yellow-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="timecondition" <?php echo $ed_routing_type === 'timecondition' ? 'checked' : ''; ?> class="text-yellow-600 focus:ring-yellow-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-clock text-yellow-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Time Conditions</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-orange-50 cursor-pointer transition <?php echo ($ed_routing_type === 'fwd' || $ed_routing_type === 'callforward') ? 'bg-orange-50 ring-1 ring-orange-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="fwd" <?php echo ($ed_routing_type === 'fwd' || $ed_routing_type === 'callforward') ? 'checked' : ''; ?> class="text-orange-600 focus:ring-orange-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-forward text-orange-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Call Forwarding</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-teal-50 cursor-pointer transition <?php echo $ed_routing_type === 'conference' ? 'bg-teal-50 ring-1 ring-teal-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="conference" <?php echo $ed_routing_type === 'conference' ? 'checked' : ''; ?> class="text-teal-600 focus:ring-teal-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-users text-teal-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Audio Conferencing</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-indigo-50 cursor-pointer transition <?php echo $ed_routing_type === 'ring_group' ? 'bg-indigo-50 ring-1 ring-indigo-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="ring_group" <?php echo $ed_routing_type === 'ring_group' ? 'checked' : ''; ?> class="text-indigo-600 focus:ring-indigo-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-phone-volume text-indigo-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Ring Group</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-pink-50 cursor-pointer transition <?php echo $ed_routing_type === 'recording' ? 'bg-pink-50 ring-1 ring-pink-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="recording" <?php echo $ed_routing_type === 'recording' ? 'checked' : ''; ?> class="text-pink-600 focus:ring-pink-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-microphone text-pink-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Play Recording</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-amber-50 cursor-pointer transition <?php echo $ed_routing_type === 'callback' ? 'bg-amber-50 ring-1 ring-amber-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="callback" <?php echo $ed_routing_type === 'callback' ? 'checked' : ''; ?> class="text-amber-600 focus:ring-amber-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-phone-alt text-amber-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Callback</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-cyan-50 cursor-pointer transition <?php echo $ed_routing_type === 'disa' ? 'bg-cyan-50 ring-1 ring-cyan-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="disa" <?php echo $ed_routing_type === 'disa' ? 'checked' : ''; ?> class="text-cyan-600 focus:ring-cyan-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-key text-cyan-500 w-4"></i>
                                        <span class="text-sm text-gray-700">DISA</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-violet-50 cursor-pointer transition <?php echo $ed_routing_type === 'voicemail' ? 'bg-violet-50 ring-1 ring-violet-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="voicemail" <?php echo $ed_routing_type === 'voicemail' ? 'checked' : ''; ?> class="text-violet-600 focus:ring-violet-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-voicemail text-violet-500 w-4"></i>
                                        <span class="text-sm text-gray-700">Voicemail</span>
                                    </label>
                                    <label class="flex items-center gap-2 p-2 rounded hover:bg-gray-100 cursor-pointer transition <?php echo $ed_routing_type === 'sys' ? 'bg-gray-100 ring-1 ring-gray-300' : ''; ?>">
                                        <input type="radio" name="routing_type" value="sys" <?php echo $ed_routing_type === 'sys' ? 'checked' : ''; ?> class="text-gray-600 focus:ring-gray-500" onchange="showRoutingOption(this.value)">
                                        <i class="fas fa-cog text-gray-500 w-4"></i>
                                        <span class="text-sm text-gray-700">System</span>
                                    </label>
                                </div>

                                <div class="mt-4 border-t border-gray-200 pt-4">
                                    <div id="routing-opt-account" class="routing-option <?php echo $ed_routing_type !== 'account' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Sub-Account (SIP/IAX)</label>
                                        <select name="routing_account" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" data-testid="select-routing-account">
                                            <option value="">-- Select Sub-Account --</option>
                                            <?php foreach ($routing_accounts as $ra): ?>
                                                <option value="<?php echo htmlspecialchars($ra['username'] ?? $ra['account'] ?? ''); ?>" <?php echo $ed_routing_value === ($ra['username'] ?? $ra['account'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($ra['username'] ?? $ra['account'] ?? ''); ?> <?php echo ($ra['description'] ?? '') ? '(' . htmlspecialchars($ra['description']) . ')' : ''; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-ivr" class="routing-option <?php echo $ed_routing_type !== 'ivr' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">IVR</label>
                                        <select name="routing_ivr" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500" data-testid="select-routing-ivr">
                                            <option value="">-- Select IVR --</option>
                                            <?php foreach ($ivrs as $iv): ?><option value="<?php echo htmlspecialchars($iv['ivr'] ?? $iv['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($iv['ivr'] ?? $iv['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($iv['name'] ?? $iv['description'] ?? 'IVR #' . ($iv['ivr'] ?? $iv['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($ivrs)): ?><option disabled>No IVRs configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-queue" class="routing-option <?php echo $ed_routing_type !== 'queue' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Calling Queue</label>
                                        <select name="routing_queue" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500" data-testid="select-routing-queue">
                                            <option value="">-- Select Queue --</option>
                                            <?php foreach ($queues as $qu): ?><option value="<?php echo htmlspecialchars($qu['queue'] ?? $qu['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($qu['queue'] ?? $qu['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($qu['description'] ?? $qu['name'] ?? 'Queue #' . ($qu['queue'] ?? $qu['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($queues)): ?><option disabled>No queues configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-timecondition" class="routing-option <?php echo $ed_routing_type !== 'timecondition' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Time Condition</label>
                                        <select name="routing_timecondition" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-500" data-testid="select-routing-tc">
                                            <option value="">-- Select Time Condition --</option>
                                            <?php foreach ($time_conditions as $tc): ?><option value="<?php echo htmlspecialchars($tc['timecondition'] ?? $tc['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($tc['timecondition'] ?? $tc['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($tc['name'] ?? $tc['description'] ?? 'TC #' . ($tc['timecondition'] ?? $tc['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($time_conditions)): ?><option disabled>No time conditions configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-fwd" class="routing-option <?php echo ($ed_routing_type !== 'fwd' && $ed_routing_type !== 'callforward') ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Forward to Number</label>
                                        <input type="text" name="routing_fwd" value="<?php echo htmlspecialchars($ed_routing_value); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-orange-500" placeholder="e.g. 15551234567" data-testid="input-routing-fwd">
                                        <p class="text-xs text-gray-400 mt-1">Enter the full phone number to forward calls to</p>
                                    </div>
                                    <div id="routing-opt-conference" class="routing-option <?php echo $ed_routing_type !== 'conference' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Conference</label>
                                        <select name="routing_conference" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-teal-500" data-testid="select-routing-conf">
                                            <option value="">-- Select Conference --</option>
                                            <?php foreach ($conferences as $cf): ?><option value="<?php echo htmlspecialchars($cf['conference'] ?? $cf['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($cf['conference'] ?? $cf['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($cf['description'] ?? $cf['name'] ?? 'Conf #' . ($cf['conference'] ?? $cf['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($conferences)): ?><option disabled>No conferences configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-ring_group" class="routing-option <?php echo $ed_routing_type !== 'ring_group' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Ring Group</label>
                                        <select name="routing_ring_group" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500" data-testid="select-routing-rg">
                                            <option value="">-- Select Ring Group --</option>
                                            <?php foreach ($ring_groups as $rg): ?><option value="<?php echo htmlspecialchars($rg['ring_group'] ?? $rg['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($rg['ring_group'] ?? $rg['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($rg['description'] ?? $rg['name'] ?? 'RG #' . ($rg['ring_group'] ?? $rg['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($ring_groups)): ?><option disabled>No ring groups configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-recording" class="routing-option <?php echo $ed_routing_type !== 'recording' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Play Recording</label>
                                        <select name="routing_recording" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-pink-500" data-testid="select-routing-rec">
                                            <option value="">-- Select Recording --</option>
                                            <?php foreach ($recordings_list as $rc): ?><option value="<?php echo htmlspecialchars($rc['recording'] ?? $rc['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($rc['recording'] ?? $rc['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($rc['description'] ?? $rc['name'] ?? 'Rec #' . ($rc['recording'] ?? $rc['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($recordings_list)): ?><option disabled>No recordings found</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-callback" class="routing-option <?php echo $ed_routing_type !== 'callback' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Callback</label>
                                        <select name="routing_callback" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-amber-500" data-testid="select-routing-cb">
                                            <option value="">-- Select Callback --</option>
                                            <?php foreach ($callbacks_list as $cb): ?><option value="<?php echo htmlspecialchars($cb['callback'] ?? $cb['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($cb['callback'] ?? $cb['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($cb['description'] ?? $cb['name'] ?? 'CB #' . ($cb['callback'] ?? $cb['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($callbacks_list)): ?><option disabled>No callbacks configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-disa" class="routing-option <?php echo $ed_routing_type !== 'disa' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">DISA</label>
                                        <select name="routing_disa" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-cyan-500" data-testid="select-routing-disa">
                                            <option value="">-- Select DISA --</option>
                                            <?php foreach ($disas as $ds): ?><option value="<?php echo htmlspecialchars($ds['disa'] ?? $ds['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($ds['disa'] ?? $ds['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($ds['name'] ?? $ds['description'] ?? 'DISA #' . ($ds['disa'] ?? $ds['id'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($disas)): ?><option disabled>No DISAs configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-voicemail" class="routing-option <?php echo $ed_routing_type !== 'voicemail' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Voicemail</label>
                                        <select name="routing_voicemail" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-violet-500" data-testid="select-routing-vm">
                                            <option value="">-- Select Voicemail --</option>
                                            <?php foreach ($voicemails_list as $vm): ?><option value="<?php echo htmlspecialchars($vm['mailbox'] ?? $vm['id'] ?? ''); ?>" <?php echo $ed_routing_value == ($vm['mailbox'] ?? $vm['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars(($vm['mailbox'] ?? '') . ' - ' . ($vm['name'] ?? $vm['description'] ?? '')); ?></option><?php endforeach; ?>
                                            <?php if (empty($voicemails_list)): ?><option disabled>No voicemails configured</option><?php endif; ?>
                                        </select>
                                    </div>
                                    <div id="routing-opt-sys" class="routing-option <?php echo $ed_routing_type !== 'sys' ? 'hidden' : ''; ?>">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">System Action</label>
                                        <select name="routing_sys" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-gray-500" data-testid="select-routing-sys">
                                            <option value="hangup" <?php echo $ed_routing_value === 'hangup' ? 'selected' : ''; ?>>Hangup</option>
                                            <option value="noservice" <?php echo $ed_routing_value === 'noservice' ? 'selected' : ''; ?>>No Service</option>
                                            <option value="busy" <?php echo $ed_routing_value === 'busy' ? 'selected' : ''; ?>>Busy</option>
                                            <option value="disconnected" <?php echo $ed_routing_value === 'disconnected' ? 'selected' : ''; ?>>Disconnected Number</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border border-gray-200 rounded-lg mb-6">
                            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 rounded-t-lg">
                                <h3 class="text-sm font-semibold text-gray-900"><i class="fas fa-server text-green-500 mr-1"></i>DID Point of Presence (POP)</h3>
                            </div>
                            <div class="p-4">
                                <p class="text-xs text-gray-500 mb-3">Select the POP server closest to your location for best call quality.</p>
                                <?php if (!empty($pop_servers)): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6">
                                    <?php
                                        $us_servers = []; $ca_servers = []; $intl_servers = [];
                                        foreach ($pop_servers as $ps) {
                                            $country = strtolower($ps['server_country'] ?? '');
                                            if ($country === 'us' || $country === 'united states' || strpos($country, 'us') !== false) $us_servers[] = $ps;
                                            elseif ($country === 'ca' || $country === 'canada') $ca_servers[] = $ps;
                                            else $intl_servers[] = $ps;
                                        }
                                    ?>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-700 mb-2 border-b border-gray-100 pb-1"><i class="fas fa-flag-usa mr-1"></i>United States</p>
                                        <div class="space-y-1 max-h-48 overflow-y-auto">
                                            <?php foreach ($us_servers as $ps): ?>
                                            <label class="flex items-center gap-2 p-1 rounded text-xs cursor-pointer hover:bg-gray-50">
                                                <input type="radio" name="pop" value="<?php echo htmlspecialchars($ps['server_pop'] ?? ''); ?>" <?php echo ($ed['pop'] ?? '') == ($ps['server_pop'] ?? '') ? 'checked' : ''; ?> class="text-green-600 focus:ring-green-500">
                                                <span class="font-medium"><?php echo htmlspecialchars($ps['server_name'] ?? $ps['server_pop'] ?? ''); ?></span>
                                                <span class="text-gray-400 font-mono text-[10px]"><?php echo htmlspecialchars($ps['server_hostname'] ?? ''); ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <?php if (!empty($ca_servers)): ?>
                                        <p class="text-xs font-semibold text-gray-700 mb-2 border-b border-gray-100 pb-1"><i class="fas fa-leaf mr-1"></i>Canada</p>
                                        <div class="space-y-1 max-h-24 overflow-y-auto mb-3">
                                            <?php foreach ($ca_servers as $ps): ?>
                                            <label class="flex items-center gap-2 p-1 rounded text-xs cursor-pointer hover:bg-gray-50">
                                                <input type="radio" name="pop" value="<?php echo htmlspecialchars($ps['server_pop'] ?? ''); ?>" <?php echo ($ed['pop'] ?? '') == ($ps['server_pop'] ?? '') ? 'checked' : ''; ?> class="text-green-600 focus:ring-green-500">
                                                <span class="font-medium"><?php echo htmlspecialchars($ps['server_name'] ?? $ps['server_pop'] ?? ''); ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($intl_servers)): ?>
                                        <p class="text-xs font-semibold text-gray-700 mb-2 border-b border-gray-100 pb-1"><i class="fas fa-globe mr-1"></i>International</p>
                                        <div class="space-y-1 max-h-24 overflow-y-auto">
                                            <?php foreach ($intl_servers as $ps): ?>
                                            <label class="flex items-center gap-2 p-1 rounded text-xs cursor-pointer hover:bg-gray-50">
                                                <input type="radio" name="pop" value="<?php echo htmlspecialchars($ps['server_pop'] ?? ''); ?>" <?php echo ($ed['pop'] ?? '') == ($ps['server_pop'] ?? '') ? 'checked' : ''; ?> class="text-green-600 focus:ring-green-500">
                                                <span class="font-medium"><?php echo htmlspecialchars($ps['server_name'] ?? $ps['server_pop'] ?? ''); ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <p class="text-xs text-gray-400">POP server list unavailable.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-4 border-t border-gray-200">
                            <a href="?tab=dids" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">Cancel</a>
                            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-did"><i class="fas fa-save mr-1"></i>Save DID Settings</button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-lg border border-red-200 p-6">
                    <h3 class="text-sm font-semibold text-red-700 mb-3"><i class="fas fa-exclamation-triangle mr-1"></i>Cancel DID</h3>
                    <p class="text-xs text-gray-600 mb-3">Cancelling this DID will release the number at the end of the current billing period.</p>
                    <form method="POST" class="flex items-end gap-3">
                            <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_did">
                        <input type="hidden" name="did" value="<?php echo htmlspecialchars($ed['did']); ?>">
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Reason</label>
                            <input type="text" name="cancel_comment" value="Cancelled via Blue Mogul portal" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="input-cancel-comment">
                        </div>
                        <label class="flex items-center gap-2 pb-2 cursor-pointer"><input type="checkbox" name="port_out" class="rounded text-red-600"><span class="text-xs text-gray-700">Port-out</span></label>
                        <button type="submit" onclick="return confirm('Cancel DID <?php echo htmlspecialchars($ed['did']); ?>? This cannot be undone.')" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-confirm-cancel"><i class="fas fa-trash-alt mr-1"></i>Cancel DID</button>
                    </form>
                </div>

                <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-phone-volume text-blue-500 mr-2"></i>Manage DID Numbers (<?php echo count($dids); ?>)</h2>
                        <div class="flex items-center gap-2">
                            <a href="https://voip.ms/m/orderdid.php" target="_blank" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition" data-testid="button-order-did"><i class="fas fa-plus mr-1"></i>Order New DID</a>
                            <a href="https://voip.ms/m/cancelDID.php" target="_blank" class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-medium rounded-lg transition border border-red-200" data-testid="button-cancel-dids"><i class="fas fa-ban mr-1"></i>Cancel DIDs</a>
                        </div>
                    </div>

                    <div class="px-6 py-3 bg-gray-50 border-b border-gray-100">
                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">Search</label>
                                <input type="text" id="did-search" placeholder="DID or Description" class="px-3 py-1.5 border border-gray-300 rounded-lg text-xs w-40 focus:ring-2 focus:ring-blue-500" data-testid="input-did-search" oninput="filterDids()">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-gray-500 uppercase mb-1">Routing</label>
                                <select id="did-filter-routing" class="px-3 py-1.5 border border-gray-300 rounded-lg text-xs w-36 focus:ring-2 focus:ring-blue-500" data-testid="select-filter-routing" onchange="filterDids()">
                                    <option value="">Any</option>
                                    <option value="account:">Sub-Account</option>
                                    <option value="ivr:">IVR</option>
                                    <option value="ring_group:">Ring Group</option>
                                    <option value="queue:">Queue</option>
                                    <option value="fwd:">Forwarding</option>
                                    <option value="voicemail:">Voicemail</option>
                                    <option value="sys:">System</option>
                                </select>
                            </div>
                            <button onclick="document.getElementById('did-search').value=''; document.getElementById('did-filter-routing').value=''; filterDids();" class="px-3 py-1.5 text-xs text-gray-500 hover:text-gray-700 transition">Clear Filters</button>
                        </div>
                    </div>

                    <?php if (empty($dids)): ?>
                        <div class="p-12 text-center text-gray-500">
                            <i class="fas fa-phone-slash text-gray-300 text-4xl mb-3"></i>
                            <p class="text-sm">No DIDs found on this account.</p>
                            <a href="https://voip.ms/m/orderdid.php" target="_blank" class="inline-flex items-center mt-3 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition"><i class="fas fa-plus mr-1"></i>Order a DID from VoIP.ms</a>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-dids">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">DID Number</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Routing</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Billing</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SMS</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order Date</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($dids as $did): ?>
                                    <?php
                                        $routing = $did['routing'] ?? '';
                                        if (strpos($routing, 'account:') === 0) $routing_label = '<span class="text-blue-600"><i class="fas fa-headset mr-1"></i>' . htmlspecialchars(substr($routing, 8)) . '</span>';
                                        elseif (strpos($routing, 'sys:') === 0) $routing_label = '<span class="text-purple-600"><i class="fas fa-cog mr-1"></i>' . htmlspecialchars(substr($routing, 4)) . '</span>';
                                        elseif (strpos($routing, 'ivr:') === 0) $routing_label = '<span class="text-green-600"><i class="fas fa-sitemap mr-1"></i>IVR ' . htmlspecialchars(substr($routing, 4)) . '</span>';
                                        elseif (strpos($routing, 'ring_group:') === 0) $routing_label = '<span class="text-orange-600"><i class="fas fa-users mr-1"></i>Ring Group ' . htmlspecialchars(substr($routing, 11)) . '</span>';
                                        else $routing_label = '<span class="text-gray-500">' . htmlspecialchars($routing ?: 'Not set') . '</span>';
                                        $did_number = $did['did'] ?? '';
                                        $billing_type = $did['billing_type'] ?? '1';
                                        $billing_label = ($billing_type === '2') ? 'Annual' : 'Monthly';
                                        $next_billing = $did['next_billing'] ?? '';
                                        $did_json_safe = htmlspecialchars(json_encode([
                                            'did' => $did_number,
                                            'description' => $did['description'] ?? '',
                                            'routing' => $routing,
                                            'callerid_prefix' => $did['callerid_prefix'] ?? '',
                                            'pop' => $did['pop'] ?? '',
                                            'billing_type' => $billing_type,
                                            'sms_enabled' => $did['sms_enabled'] ?? '0',
                                            'sms_email' => $did['sms_email'] ?? '',
                                        ]), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="row-did-<?php echo htmlspecialchars($did_number); ?>">
                                        <td class="px-4 py-3">
                                            <span class="text-sm font-medium text-gray-900 font-mono"><?php echo htmlspecialchars($did_number); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($did['description'] ?? '--'); ?></td>
                                        <td class="px-4 py-3 text-xs"><?php echo $routing_label; ?></td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-0.5 <?php echo $billing_type === '2' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'; ?> rounded text-[10px] font-medium"><?php echo $billing_label; ?></span>
                                            <?php if ($next_billing): ?>
                                                <p class="text-[10px] text-gray-400 mt-0.5">Next: <?php echo htmlspecialchars($next_billing); ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if (($did['sms_available'] ?? '') === '1' || ($did['sms_enabled'] ?? '') === '1'): ?>
                                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-[10px] font-medium"><i class="fas fa-check mr-1"></i>SMS</span>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400">--</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($did['order_date'] ?? '--'); ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex items-center justify-center gap-1" data-testid="actions-did-<?php echo htmlspecialchars($did_number); ?>">
                                                <a href="?tab=did_edit&did=<?php echo urlencode($did_number); ?>" class="px-2 py-1 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded text-xs font-medium transition" title="Edit DID" data-testid="button-edit-did-<?php echo htmlspecialchars($did_number); ?>"><i class="fas fa-edit"></i></a>
                                                <a href="?tab=did_edit&did=<?php echo urlencode($did_number); ?>#routing" class="px-2 py-1 text-purple-600 hover:text-purple-800 hover:bg-purple-50 rounded text-xs font-medium transition" title="Routing" data-testid="button-route-did-<?php echo htmlspecialchars($did_number); ?>"><i class="fas fa-exchange-alt"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>

            <?php elseif ($active_tab === 'subaccounts'): ?>
                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-headset text-purple-500 mr-2"></i>Sub-Accounts (<?php echo count($subaccounts); ?>)</h2>
                        <button onclick="document.getElementById('create-sa-form').classList.toggle('hidden')" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded-lg transition" data-testid="button-create-subaccount"><i class="fas fa-plus mr-1"></i>Create Sub-Account</button>
                    </div>

                    <div id="create-sa-form" class="hidden border-b border-gray-100 bg-purple-50 p-6">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_subaccount">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3"><i class="fas fa-user-plus text-purple-500 mr-1"></i>New Sub-Account</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Username *</label>
                                    <input type="text" name="sa_username" required placeholder="e.g. extension100" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="input-sa-username">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Password *</label>
                                    <input type="text" name="sa_password" required placeholder="Min 8 characters" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="input-sa-password">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                                    <input type="text" name="sa_description" placeholder="e.g. John's Desk Phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="input-sa-description">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Protocol</label>
                                    <select name="sa_protocol" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="select-sa-protocol">
                                        <option value="1">SIP (UDP)</option>
                                        <option value="3">SIP (TCP)</option>
                                        <option value="4">SIP (TLS)</option>
                                        <option value="2">IAX2</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Auth Type</label>
                                    <select name="sa_auth_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="select-sa-auth">
                                        <option value="1">User/Pass</option>
                                        <option value="2">IP Auth</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">CallerID Number</label>
                                    <input type="text" name="sa_callerid" placeholder="e.g. 5551234567" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="input-sa-callerid">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Internal Extension</label>
                                    <input type="text" name="sa_internal_extension" placeholder="e.g. 100" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="input-sa-ext">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Internal Voicemail</label>
                                    <input type="text" name="sa_internal_voicemail" placeholder="e.g. 101" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="input-sa-vm">
                                </div>
                                <div class="flex items-end pb-1">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="sa_lock_intl" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" data-testid="check-sa-lock-intl">
                                        <span class="text-xs text-gray-700">Lock International Calls</span>
                                    </label>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 mt-4">
                                <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-submit-sa"><i class="fas fa-user-plus mr-1"></i>Create Sub-Account</button>
                                <button type="button" onclick="document.getElementById('create-sa-form').classList.add('hidden')" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <?php if (empty($subaccounts)): ?>
                        <div class="p-12 text-center text-gray-500">
                            <i class="fas fa-headset text-gray-300 text-4xl mb-3"></i>
                            <p class="text-sm">No sub-accounts found.</p>
                            <p class="text-xs text-gray-400 mt-1">Click "Create Sub-Account" to provision a new SIP extension.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-subaccounts">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Username</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Protocol</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">CallerID</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Intl Lock</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($subaccounts as $sa): ?>
                                    <?php
                                        $sa_id = $sa['id'] ?? $sa['account'] ?? '';
                                        $sa_username = $sa['username'] ?? $sa['account'] ?? '';
                                    ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="row-subaccount-<?php echo htmlspecialchars($sa_username); ?>">
                                        <td class="px-4 py-3">
                                            <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($sa_username); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($sa['description'] ?? '--'); ?></td>
                                        <td class="px-4 py-3">
                                            <?php
                                                $proto_map = ['1' => 'SIP/UDP', '2' => 'IAX2', '3' => 'SIP/TCP', '4' => 'SIP/TLS'];
                                                $proto = $proto_map[$sa['protocol'] ?? ''] ?? strtoupper($sa['protocol'] ?? 'SIP');
                                            ?>
                                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-[10px] font-medium"><?php echo htmlspecialchars($proto); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($sa['callerid_number'] ?? '--'); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if (($sa['lock_international'] ?? '') === 'yes'): ?>
                                                <span class="px-2 py-0.5 bg-red-100 text-red-600 rounded text-[10px] font-medium"><i class="fas fa-lock mr-1"></i>Locked</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-green-100 text-green-600 rounded text-[10px] font-medium"><i class="fas fa-globe mr-1"></i>Open</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <button onclick="openEditSa('<?php echo htmlspecialchars($sa_id); ?>', '<?php echo htmlspecialchars(addslashes($sa_username)); ?>', '<?php echo htmlspecialchars(addslashes($sa['description'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($sa['callerid_number'] ?? '')); ?>', '<?php echo ($sa['lock_international'] ?? '') === 'yes' ? '1' : '0'; ?>')" class="text-blue-600 hover:text-blue-800 text-xs font-medium mr-2" data-testid="button-edit-sa-<?php echo htmlspecialchars($sa_id); ?>"><i class="fas fa-edit mr-1"></i>Edit</button>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete sub-account <?php echo htmlspecialchars(addslashes($sa_username)); ?>
                            <?= csrf_field() ?>? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_subaccount">
                                                <input type="hidden" name="sa_id" value="<?php echo htmlspecialchars($sa_id); ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium" data-testid="button-delete-sa-<?php echo htmlspecialchars($sa_id); ?>"><i class="fas fa-trash-alt mr-1"></i>Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="edit-sa-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
                        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-900"><i class="fas fa-edit text-purple-500 mr-2"></i>Edit Sub-Account</h3>
                            <button onclick="closeEditSa()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                        <form method="POST" class="p-6">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_subaccount">
                            <input type="hidden" name="sa_id" id="edit-sa-id">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                                <input type="text" id="edit-sa-username" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50">
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <input type="text" name="sa_description" id="edit-sa-desc" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" data-testid="input-edit-sa-desc">
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">New Password <span class="text-gray-400 font-normal">(leave blank to keep current)</span></label>
                                <input type="text" name="sa_password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="New password" data-testid="input-edit-sa-pass">
                            </div>
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">CallerID Number</label>
                                <input type="text" name="sa_callerid" id="edit-sa-callerid" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="e.g. 5551234567" data-testid="input-edit-sa-callerid">
                            </div>
                            <div class="mb-4">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="sa_lock_intl" id="edit-sa-lock-intl" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                    <span class="text-sm text-gray-700">Lock International Calls</span>
                                </label>
                            </div>
                            <div class="flex justify-end gap-3">
                                <button type="button" onclick="closeEditSa()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-sa"><i class="fas fa-save mr-1"></i>Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($active_tab === 'cdr'): ?>
                <?php
                    $cdr_from = $_GET['cdr_from'] ?? date('Y-m-d', strtotime('-7 days'));
                    $cdr_to = $_GET['cdr_to'] ?? date('Y-m-d');
                    $cdr_type = $_GET['cdr_type'] ?? '1';
                ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-list-alt text-green-500 mr-2"></i>Call Detail Records</h2>
                    </div>
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                        <form method="GET" class="flex flex-wrap items-end gap-4">
                            <input type="hidden" name="tab" value="cdr">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">From Date</label>
                                <input type="date" name="cdr_from" value="<?php echo htmlspecialchars($cdr_from); ?>" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-cdr-from">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">To Date</label>
                                <input type="date" name="cdr_to" value="<?php echo htmlspecialchars($cdr_to); ?>" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-cdr-to">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Filter</label>
                                <select name="cdr_type" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-cdr-type">
                                    <option value="1" <?php echo $cdr_type === '1' ? 'selected' : ''; ?>>Answered Only</option>
                                    <option value="0" <?php echo $cdr_type === '0' ? 'selected' : ''; ?>>All Calls</option>
                                </select>
                            </div>
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-search-cdr"><i class="fas fa-search mr-1"></i>Search</button>
                        </form>
                    </div>

                    <?php if (empty($cdr_records)): ?>
                        <div class="p-12 text-center text-gray-500">
                            <i class="fas fa-phone-slash text-gray-300 text-4xl mb-3"></i>
                            <p class="text-sm">No call records found for the selected period.</p>
                        </div>
                    <?php else: ?>
                        <div class="px-6 py-3 bg-blue-50 border-b border-blue-100 flex items-center justify-between">
                            <span class="text-sm text-blue-700 font-medium"><i class="fas fa-info-circle mr-1"></i><?php echo count($cdr_records); ?> records found</span>
                            <?php
                                $total_duration = 0; $total_cost = 0;
                                foreach ($cdr_records as $c) { $total_duration += intval($c['seconds'] ?? $c['duration'] ?? 0); $total_cost += floatval($c['total'] ?? $c['cost'] ?? 0); }
                            ?>
                            <span class="text-sm text-blue-600">Total: <?php echo gmdate("H:i:s", $total_duration); ?> | $<?php echo number_format($total_cost, 4); ?></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-cdr">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date/Time</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">From</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">To</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Duration</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Disposition</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Cost</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Account</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach (array_slice($cdr_records, 0, 100) as $cdr): ?>
                                    <?php
                                        $seconds = intval($cdr['seconds'] ?? $cdr['duration'] ?? 0);
                                        $disposition = strtolower($cdr['disposition'] ?? '');
                                        $disp_class = $disposition === 'answered' ? 'bg-green-100 text-green-700' : ($disposition === 'no answer' ? 'bg-yellow-100 text-yellow-700' : ($disposition === 'busy' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'));
                                    ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-4 py-2 text-xs text-gray-900 whitespace-nowrap"><?php echo htmlspecialchars($cdr['date'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-900 font-mono"><?php echo htmlspecialchars($cdr['callerid'] ?? $cdr['caller_id'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-900 font-mono"><?php echo htmlspecialchars($cdr['destination'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-700"><?php echo floor($seconds/60) . 'm ' . ($seconds%60) . 's'; ?></td>
                                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-[10px] font-medium <?php echo $disp_class; ?>"><?php echo htmlspecialchars(ucfirst($cdr['disposition'] ?? '')); ?></span></td>
                                        <td class="px-4 py-2 text-xs text-gray-700">$<?php echo number_format(floatval($cdr['total'] ?? $cdr['cost'] ?? 0), 4); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($cdr['account'] ?? ''); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($cdr_records) > 100): ?>
                            <div class="px-6 py-3 text-center text-xs text-gray-500 border-t border-gray-100">Showing first 100 of <?php echo count($cdr_records); ?> records.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'registration'): ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-signal text-orange-500 mr-2"></i>SIP Registration Status</h2>
                        <p class="text-xs text-gray-500 mt-1">Shows which devices/endpoints are currently registered and online</p>
                    </div>
                    <?php if (empty($registration_status)): ?>
                        <div class="p-12 text-center text-gray-500">
                            <i class="fas fa-signal text-gray-300 text-4xl mb-3"></i>
                            <p class="text-sm">No active registrations found.</p>
                            <p class="text-xs text-gray-400 mt-1">Devices may be offline or not configured.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-registration">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Account</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Server</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">IP Address</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">User Agent</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Port</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($registration_status as $reg): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-4 py-3"><span class="w-2.5 h-2.5 rounded-full bg-green-500 inline-block mr-1"></span><span class="text-xs text-green-600 font-medium">Online</span></td>
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($reg['sub_account'] ?? $voip_username); ?></td>
                                        <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($reg['server_name'] ?? $reg['server'] ?? ''); ?></td>
                                        <td class="px-4 py-3"><code class="text-xs font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($reg['register_ip'] ?? $reg['ip'] ?? ''); ?></code></td>
                                        <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($reg['useragent'] ?? $reg['user_agent'] ?? ''); ?></td>
                                        <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($reg['register_port'] ?? $reg['port'] ?? ''); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'config'): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                        <?php if ($voip_connected): ?>
                            <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <?php else: ?>
                            <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-api-endpoint">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Endpoint</p>
                        <p class="text-sm font-medium text-gray-900 break-all"><?php echo htmlspecialchars($voip_url); ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-api-username">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Username</p>
                        <?php if ($voip_username_set):
                            $masked = substr($voip_username, 0, 3) . str_repeat('*', max(0, strlen($voip_username) - 6)) . substr($voip_username, -3);
                        ?>
                            <p class="text-sm font-medium text-gray-900" data-testid="text-masked-username"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs"><?php echo htmlspecialchars($masked); ?></code></p>
                        <?php else: ?>
                            <p class="text-sm text-gray-500">Not configured</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-plug text-green-500 mr-2"></i>Test Connection</h2>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="px-4 py-2 bg-primary hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-test-connection"><i class="fas fa-bolt mr-1"></i>Test Connection</button>
                        </form>
                    </div>
                    <div class="p-6">
                        <?php if ($test_connection_result !== null): ?>
                            <?php if (($test_connection_result['status'] ?? '') === 'success'): ?>
                                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="test-result-success">
                                    <i class="fas fa-check-circle mr-2"></i>Connection successful! Balance: $<?php echo number_format((float)($test_connection_result['balance']['current_balance'] ?? $test_connection_result['balance'] ?? 0), 2); ?>
                                </div>
                            <?php else: ?>
                                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="test-result-error">
                                    <i class="fas fa-exclamation-circle mr-2"></i>Connection failed: <?php echo htmlspecialchars($test_connection_result['status'] ?? $test_connection_result['message'] ?? 'Unknown error'); ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-sm text-gray-500"><i class="fas fa-info-circle mr-1"></i>Click "Test Connection" to verify your VoIP.ms API credentials by calling the getBalance method.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cog text-gray-500 mr-2"></i>Environment Variables</h2>
                    </div>
                    <div class="p-6 space-y-4">
                        <?php
                            $env_vars = [
                                ['VOIP_USERNAME', 'Your VoIP.ms account email', $voip_username_set],
                                ['VOIP_PASSWORD', 'API password from VoIP.ms settings', $voip_password_set],
                                ['VOIP_TOKEN', 'Alternative: API token (used if password not set)', $voip_token_set],
                            ];
                            foreach ($env_vars as $ev):
                        ?>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900"><?php echo $ev[0]; ?></p>
                                <p class="text-xs text-gray-500"><?php echo $ev[1]; ?></p>
                            </div>
                            <?php if ($ev[2]): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">VOIP_API_URL</p>
                                <p class="text-xs text-gray-500">VoIP.ms REST API endpoint (default)</p>
                            </div>
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium"><?php echo htmlspecialchars($voip_url); ?></span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-question-circle text-blue-500 mr-2"></i>Setup Instructions</h2>
                    </div>
                    <div class="p-6 space-y-3 text-sm text-gray-600">
                        <p><strong>1.</strong> Log in to your VoIP.ms account at <a href="https://voip.ms" target="_blank" class="text-blue-600 hover:underline">voip.ms</a></p>
                        <p><strong>2.</strong> Navigate to <strong>SOAP and REST/JSON API</strong> under Main Menu</p>
                        <p><strong>3.</strong> Enable the API and set an API password</p>
                        <p><strong>4.</strong> Add your server's IP address to the allowed IPs list</p>
                        <p><strong>5.</strong> Set the following secrets in Replit Secrets:</p>
                        <div class="bg-gray-50 rounded-lg p-4 font-mono text-xs space-y-1">
                            <p><span class="text-blue-600">VOIP_USERNAME</span> = your@email.com</p>
                            <p><span class="text-blue-600">VOIP_PASSWORD</span> = your_api_password</p>
                        </div>
                        <p class="text-xs text-gray-400 mt-2"><i class="fas fa-shield-alt mr-1"></i>API docs: <a href="https://voip.ms/m/apidocs.php" target="_blank" class="text-blue-500 hover:underline">voip.ms/m/apidocs.php</a></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function showRoutingOption(type) {
    document.querySelectorAll('.routing-option').forEach(function(el) { el.classList.add('hidden'); });
    var opt = document.getElementById('routing-opt-' + type);
    if (opt) opt.classList.remove('hidden');
    document.querySelectorAll('input[name="routing_type"]').forEach(function(radio) {
        var label = radio.closest('label');
        if (label) {
            label.className = label.className.replace(/bg-\w+-50|ring-1|ring-\w+-300/g, '').trim();
            if (radio.checked) {
                var colors = { account: 'bg-blue-50 ring-1 ring-blue-300', ivr: 'bg-green-50 ring-1 ring-green-300', queue: 'bg-purple-50 ring-1 ring-purple-300', timecondition: 'bg-yellow-50 ring-1 ring-yellow-300', fwd: 'bg-orange-50 ring-1 ring-orange-300', conference: 'bg-teal-50 ring-1 ring-teal-300', ring_group: 'bg-indigo-50 ring-1 ring-indigo-300', recording: 'bg-pink-50 ring-1 ring-pink-300', callback: 'bg-amber-50 ring-1 ring-amber-300', disa: 'bg-cyan-50 ring-1 ring-cyan-300', voicemail: 'bg-violet-50 ring-1 ring-violet-300', sys: 'bg-gray-100 ring-1 ring-gray-300' };
                label.className += ' ' + (colors[type] || '');
            }
        }
    });
}

function filterDids() {
    var search = (document.getElementById('did-search')?.value || '').toLowerCase();
    var routingFilter = document.getElementById('did-filter-routing')?.value || '';
    var rows = document.querySelectorAll('[data-testid^="row-did-"]');
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        var matchSearch = !search || text.indexOf(search) !== -1;
        var matchRouting = !routingFilter || text.indexOf(routingFilter.replace(':', '').toLowerCase()) !== -1;
        row.style.display = (matchSearch && matchRouting) ? '' : 'none';
    });
}

function openEditSa(id, username, desc, callerid, lockIntl) {
    document.getElementById('edit-sa-id').value = id;
    document.getElementById('edit-sa-username').value = username;
    document.getElementById('edit-sa-desc').value = desc;
    document.getElementById('edit-sa-callerid').value = callerid;
    document.getElementById('edit-sa-lock-intl').checked = lockIntl === '1';
    document.getElementById('edit-sa-modal').classList.remove('hidden');
}
function closeEditSa() {
    document.getElementById('edit-sa-modal').classList.add('hidden');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeManageDid(); closeRouteDid(); closeRenewDid(); closeCancelDid(); closeEditSa(); }
});
</script>
</body>
</html>