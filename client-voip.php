<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$voip_username = VOIP_API_USERNAME;
$voip_password = VOIP_API_PASSWORD;
$voip_token = VOIP_API_TOKEN;
$voip_url = VOIP_API_URL;
$voip_connected = !empty($voip_username) && (!empty($voip_password) || !empty($voip_token));

$active_tab = $_GET['tab'] ?? 'myservices';
$error_msg = '';
$success_msg = '';

$subaccounts = [];
$dids = [];
$callbacks = [];
$callerid_filters = [];
$forwardings = [];
$voicemails = [];
$ivrs = [];
$ring_groups = [];
$queues = [];
$recordings = [];
$time_conditions = [];
$disas = [];
$conferences = [];
$servers = [];
$balance = null;
$order_dids_results = [];

function voip_client_api($action, $extra_params = []) {
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
    if (($data['status'] ?? '') === 'invalid_credentials' && !empty(VOIP_API_PASSWORD) && !empty(VOIP_API_TOKEN)) {
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

if ($voip_connected && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'update_account') {
        $account = $_POST['account'] ?? '';
        $params = ['account' => $account];
        if (isset($_POST['description'])) $params['description'] = $_POST['description'];
        if (isset($_POST['callerid_prefix'])) $params['callerid_prefix'] = $_POST['callerid_prefix'];
        if (isset($_POST['music_on_hold'])) $params['music_on_hold'] = $_POST['music_on_hold'];
        if (isset($_POST['allowed_codecs'])) $params['allowed_codecs'] = implode(';', $_POST['allowed_codecs']);
        if (isset($_POST['dtmf_mode'])) $params['dtmf_mode'] = $_POST['dtmf_mode'];
        if (isset($_POST['nat'])) $params['nat'] = $_POST['nat'];
        if (isset($_POST['internal_extension'])) $params['internal_extension'] = $_POST['internal_extension'];
        if (isset($_POST['internal_voicemail'])) $params['internal_voicemail'] = $_POST['internal_voicemail'];
        $result = voip_client_api('setSubAccount', $params);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Account settings updated successfully.';
            try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'voip_account_updated', 'voip_account', $account, 'Updated VoIP account settings', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
        } else {
            $error_msg = 'Failed to update account: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'create_callback') {
        $params = [
            'description' => $_POST['cb_description'] ?? '',
            'number' => $_POST['cb_number'] ?? '',
            'delay_before' => $_POST['cb_delay'] ?? '5',
            'response_timeout' => $_POST['cb_response_timeout'] ?? '10',
            'digit_timeout' => $_POST['cb_digit_timeout'] ?? '5',
            'callerid' => $_POST['cb_callerid'] ?? '',
        ];
        $result = voip_client_api('createCallback', $params);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Callback created successfully.';
            try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'voip_callback_created', 'voip_callback', $params['number'], 'Created callback: ' . $params['description'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
        } else {
            $error_msg = 'Failed to create callback: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'delete_callback') {
        $cb_id = $_POST['callback_id'] ?? '';
        $result = voip_client_api('delCallback', ['callback' => $cb_id]);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Callback deleted.';
        } else {
            $error_msg = 'Failed to delete callback: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'create_callerid_filter') {
        $params = [
            'callerid' => $_POST['filter_callerid'] ?? '',
            'did' => $_POST['filter_did'] ?? '',
            'routing' => $_POST['filter_routing'] ?? '',
            'failover_unreachable' => $_POST['filter_failover_unreachable'] ?? '',
            'failover_busy' => $_POST['filter_failover_busy'] ?? '',
            'failover_noanswer' => $_POST['filter_failover_noanswer'] ?? '',
            'note' => $_POST['filter_note'] ?? '',
        ];
        $result = voip_client_api('setCallerIDFiltering', $params);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Caller ID filter saved.';
            try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'voip_filter_created', 'voip_filter', $params['callerid'], 'Created CallerID filter for: ' . $params['callerid'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
        } else {
            $error_msg = 'Failed to save filter: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'delete_callerid_filter') {
        $filter_id = $_POST['filter_id'] ?? '';
        $result = voip_client_api('delCallerIDFiltering', ['filter' => $filter_id]);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Caller ID filter deleted.';
        } else {
            $error_msg = 'Failed to delete filter: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'create_forwarding') {
        $params = [
            'phone_number' => $_POST['fwd_number'] ?? '',
            'callerid' => $_POST['fwd_callerid'] ?? '',
            'description' => $_POST['fwd_description'] ?? '',
            'dtmf_digits' => $_POST['fwd_dtmf'] ?? '',
            'pause' => $_POST['fwd_pause'] ?? '0',
        ];
        $result = voip_client_api('createForwarding', $params);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Call forwarding entry created.';
            try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'voip_fwd_created', 'voip_forwarding', $params['phone_number'], 'Created forwarding to: ' . $params['phone_number'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
        } else {
            $error_msg = 'Failed to create forwarding: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'delete_forwarding') {
        $fwd_id = $_POST['forwarding_id'] ?? '';
        $result = voip_client_api('delForwarding', ['forwarding' => $fwd_id]);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Forwarding entry deleted.';
        } else {
            $error_msg = 'Failed to delete forwarding: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'create_voicemail') {
        $params = [
            'digits' => $_POST['vm_mailbox'] ?? '',
            'name' => $_POST['vm_name'] ?? '',
            'password' => $_POST['vm_password'] ?? '',
            'skip_password' => $_POST['vm_skip_password'] ?? 'no',
            'email' => $_POST['vm_email'] ?? '',
            'attach_message' => $_POST['vm_attach'] ?? 'yes',
            'delete_message' => $_POST['vm_delete'] ?? 'no',
            'say_time' => $_POST['vm_say_time'] ?? 'no',
            'timezone' => $_POST['vm_timezone'] ?? 'US/Central',
            'say_callerid' => $_POST['vm_say_callerid'] ?? 'no',
            'play_instructions' => $_POST['vm_play_instructions'] ?? 'no',
        ];
        $result = voip_client_api('createVoicemail', $params);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Voicemail box created.';
            try { getDB()->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'voip_vm_created', 'voip_voicemail', $params['digits'], 'Created voicemail: ' . $params['name'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']); } catch (\Exception $e) {}
        } else {
            $error_msg = 'Failed to create voicemail: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'delete_voicemail') {
        $vm_id = $_POST['voicemail_id'] ?? '';
        $result = voip_client_api('delVoicemail', ['mailbox' => $vm_id]);
        if (($result['status'] ?? '') === 'success') {
            $success_msg = 'Voicemail box deleted.';
        } else {
            $error_msg = 'Failed to delete voicemail: ' . ($result['status'] ?? 'unknown error');
        }
    }

    if ($action === 'order_did_search') {
        $params = ['state' => $_POST['order_state'] ?? '', 'type' => 'local'];
        if (!empty($_POST['order_ratecenter'])) $params['ratecenter'] = $_POST['order_ratecenter'];
        $result = voip_client_api('getDIDsUSA', $params);
        if (($result['status'] ?? '') === 'success') {
            $order_dids_results = $result['dids'] ?? [];
        } else {
            $error_msg = 'DID search failed: ' . ($result['status'] ?? 'unknown error');
        }
        $active_tab = 'orderdids';
    }

    if ($action === 'order_did_canada') {
        $params = ['province' => $_POST['order_province'] ?? '', 'type' => 'local'];
        $result = voip_client_api('getDIDsCAN', $params);
        if (($result['status'] ?? '') === 'success') {
            $order_dids_results = $result['dids'] ?? [];
        } else {
            $error_msg = 'DID search failed: ' . ($result['status'] ?? 'unknown error');
        }
        $active_tab = 'orderdids';
    }
}

if ($voip_connected) {
    $bal = voip_client_api('getBalance');
    if (($bal['status'] ?? '') === 'success') $balance = $bal['balance'] ?? null;

    if ($active_tab === 'myservices' || $active_tab === 'account_detail') {
        $sa = voip_client_api('getSubAccounts');
        if (($sa['status'] ?? '') === 'success') $subaccounts = $sa['accounts'] ?? [];
        $did_res = voip_client_api('getDIDsInfo');
        if (($did_res['status'] ?? '') === 'success') $dids = $did_res['dids'] ?? [];
        $srv = voip_client_api('getServersInfo');
        if (($srv['status'] ?? '') === 'success') $servers = $srv['servers'] ?? [];
    }

    if ($active_tab === 'callback') {
        $cb = voip_client_api('getCallbacks');
        if (($cb['status'] ?? '') === 'success') $callbacks = $cb['callbacks'] ?? [];
    }

    if ($active_tab === 'callerfilter') {
        $cf = voip_client_api('getCallerIDFiltering');
        if (($cf['status'] ?? '') === 'success') $callerid_filters = $cf['filtering'] ?? $cf['callerids'] ?? [];
        $did_res2 = voip_client_api('getDIDsInfo');
        if (($did_res2['status'] ?? '') === 'success') $dids = $did_res2['dids'] ?? [];
        $sa2 = voip_client_api('getSubAccounts');
        if (($sa2['status'] ?? '') === 'success') $subaccounts = $sa2['accounts'] ?? [];
        $ivr_res = voip_client_api('getIVRs');
        if (($ivr_res['status'] ?? '') === 'success') $ivrs = $ivr_res['ivrs'] ?? [];
        $rg_res = voip_client_api('getRingGroups');
        if (($rg_res['status'] ?? '') === 'success') $ring_groups = $rg_res['ring_groups'] ?? [];
        $q_res = voip_client_api('getQueues');
        if (($q_res['status'] ?? '') === 'success') $queues = $q_res['queues'] ?? [];
        $rec_res = voip_client_api('getRecordings');
        if (($rec_res['status'] ?? '') === 'success') $recordings = $rec_res['recordings'] ?? [];
        $tc_res = voip_client_api('getTimeConditions');
        if (($tc_res['status'] ?? '') === 'success') $time_conditions = $tc_res['time_conditions'] ?? [];
        $d_res = voip_client_api('getDISAs');
        if (($d_res['status'] ?? '') === 'success') $disas = $d_res['disas'] ?? [];
        $conf_res = voip_client_api('getConferences');
        if (($conf_res['status'] ?? '') === 'success') $conferences = $conf_res['conferences'] ?? [];
        $fwd_res = voip_client_api('getForwardings');
        if (($fwd_res['status'] ?? '') === 'success') $forwardings = $fwd_res['forwardings'] ?? [];
        $vm_res = voip_client_api('getVoicemails');
        if (($vm_res['status'] ?? '') === 'success') $voicemails = $vm_res['voicemails'] ?? [];
        $cb2 = voip_client_api('getCallbacks');
        if (($cb2['status'] ?? '') === 'success') $callbacks = $cb2['callbacks'] ?? [];
    }

    if ($active_tab === 'forwarding') {
        $fwd = voip_client_api('getForwardings');
        if (($fwd['status'] ?? '') === 'success') $forwardings = $fwd['forwardings'] ?? [];
    }

    if ($active_tab === 'voicemail') {
        $vm = voip_client_api('getVoicemails');
        if (($vm['status'] ?? '') === 'success') $voicemails = $vm['voicemails'] ?? [];
    }

    if ($active_tab === 'orderdids') {
        $did_res3 = voip_client_api('getDIDsInfo');
        if (($did_res3['status'] ?? '') === 'success') $dids = $did_res3['dids'] ?? [];
    }
}

$us_states = ['ALABAMA','ALASKA','ARIZONA','ARKANSAS','CALIFORNIA','COLORADO','CONNECTICUT','DELAWARE','FLORIDA','GEORGIA','HAWAII','IDAHO','ILLINOIS','INDIANA','IOWA','KANSAS','KENTUCKY','LOUISIANA','MAINE','MARYLAND','MASSACHUSETTS','MICHIGAN','MINNESOTA','MISSISSIPPI','MISSOURI','MONTANA','NEBRASKA','NEVADA','NEW HAMPSHIRE','NEW JERSEY','NEW MEXICO','NEW YORK','NORTH CAROLINA','NORTH DAKOTA','OHIO','OKLAHOMA','OREGON','PENNSYLVANIA','RHODE ISLAND','SOUTH CAROLINA','SOUTH DAKOTA','TENNESSEE','TEXAS','UTAH','VERMONT','VIRGINIA','WASHINGTON','WEST VIRGINIA','WISCONSIN','WYOMING'];
$ca_provinces = ['ALBERTA','BRITISH COLUMBIA','MANITOBA','NEW BRUNSWICK','NEWFOUNDLAND','NORTHWEST TERRITORIES','NOVA SCOTIA','NUNAVUT','ONTARIO','PRINCE EDWARD ISLAND','QUEBEC','SASKATCHEWAN','YUKON'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Services - Blue Mogul Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-voice-title"><i class="fas fa-phone-alt text-primary mr-2"></i>Voice Services</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage your Blue Mogul phone system</p>
                </div>
                <?php if ($balance !== null): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm" data-testid="text-voip-balance">
                    <span class="text-gray-500">Balance:</span>
                    <span class="font-semibold text-green-700">$<?php echo htmlspecialchars(number_format((float)$balance, 2)); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($voip_connected): ?>
            <div class="px-6 pb-3 flex gap-1 overflow-x-auto">
                <?php
                $tabs = [
                    'myservices' => ['My Services', 'fa-headset'],
                    'callerfilter' => ['Caller ID Filtering', 'fa-filter'],
                    'forwarding' => ['Call Forwarding', 'fa-share'],
                    'callback' => ['Callback', 'fa-phone-volume'],
                    'voicemail' => ['Voicemail', 'fa-voicemail'],
                    'orderdids' => ['Order DIDs', 'fa-cart-plus'],
                ];
                foreach ($tabs as $tk => $tv):
                    $active_class = ($active_tab === $tk || ($active_tab === 'account_detail' && $tk === 'myservices'))
                        ? 'bg-primary text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200';
                ?>
                    <a href="?tab=<?php echo $tk; ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium transition whitespace-nowrap <?php echo $active_class; ?>" data-testid="tab-<?php echo $tk; ?>">
                        <i class="fas <?php echo $tv[1]; ?> mr-1"></i><?php echo $tv[0]; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </header>

        <div class="p-6">
            <?php if ($error_msg): ?>
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="text-error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
            <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="text-success"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>

            <?php if (!$voip_connected): ?>
            <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                <i class="fas fa-phone-slash text-gray-300 text-5xl mb-4"></i>
                <h2 class="text-xl font-semibold text-gray-700 mb-2">Voice Services Not Available</h2>
                <p class="text-gray-500">Your voice services are not configured yet. Please contact Blue Mogul support to set up your phone system.</p>
            </div>

            <?php elseif ($active_tab === 'myservices'): ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-headset text-primary mr-2"></i>My Services</h2>
                    <p class="text-sm text-gray-500 mt-1">Your active sub-accounts and DIDs</p>
                </div>
                <div class="p-6">
                    <?php if (count($subaccounts) > 0): ?>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3"><i class="fas fa-users text-blue-500 mr-1"></i>Sub-Accounts</h3>
                    <div class="overflow-x-auto mb-6">
                        <table class="w-full text-left" data-testid="table-subaccounts">
                            <thead>
                                <tr class="bg-primary text-white text-xs">
                                    <th class="px-4 py-2.5 rounded-tl-lg font-medium">Account</th>
                                    <th class="px-4 py-2.5 font-medium">Protocol</th>
                                    <th class="px-4 py-2.5 font-medium">Description</th>
                                    <th class="px-4 py-2.5 font-medium">Device Type</th>
                                    <th class="px-4 py-2.5 rounded-tr-lg font-medium text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($subaccounts as $sa): ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="row-sa-<?php echo htmlspecialchars($sa['account'] ?? ''); ?>">
                                    <td class="px-4 py-3 text-sm font-mono font-medium text-gray-900"><?php echo htmlspecialchars($sa['username'] ?? $sa['account'] ?? ''); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars(strtoupper($sa['protocol'] ?? 'SIP')); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($sa['description'] ?? '--'); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($sa['device_type'] ?? '--'); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <a href="?tab=account_detail&account=<?php echo urlencode($sa['account'] ?? ''); ?>" class="px-3 py-1 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded text-xs font-medium transition" data-testid="button-edit-sa-<?php echo htmlspecialchars($sa['account'] ?? ''); ?>">
                                            <i class="fas fa-cog mr-1"></i>Account Details
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8 text-gray-400">
                        <i class="fas fa-headset text-3xl mb-2"></i>
                        <p class="text-sm">No sub-accounts found.</p>
                    </div>
                    <?php endif; ?>

                    <h3 class="text-sm font-semibold text-gray-800 mb-3 mt-4"><i class="fas fa-phone text-green-500 mr-1"></i>DIDs List</h3>
                    <?php if (count($dids) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left" data-testid="table-dids">
                            <thead>
                                <tr class="bg-primary text-white text-xs">
                                    <th class="px-4 py-2.5 rounded-tl-lg font-medium">DID Number</th>
                                    <th class="px-4 py-2.5 font-medium">Description</th>
                                    <th class="px-4 py-2.5 font-medium">Routing</th>
                                    <th class="px-4 py-2.5 font-medium">Billing</th>
                                    <th class="px-4 py-2.5 rounded-tr-lg font-medium">Next Billing</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($dids as $did): ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="row-did-<?php echo htmlspecialchars($did['did'] ?? ''); ?>">
                                    <td class="px-4 py-3 text-sm font-mono font-medium text-gray-900"><?php echo htmlspecialchars($did['did'] ?? ''); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($did['description'] ?? '--'); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($did['routing'] ?? '--'); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 <?php echo (($did['billing_type'] ?? '') === '2') ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'; ?> rounded text-xs font-medium">
                                            <?php echo (($did['billing_type'] ?? '') === '2') ? 'Annual' : 'Monthly'; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($did['next_billing'] ?? '--'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-6 text-gray-400">
                        <i class="fas fa-phone text-3xl mb-2"></i>
                        <p class="text-sm">No DIDs assigned to your account.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($active_tab === 'account_detail'): ?>

            <?php
                $edit_account = $_GET['account'] ?? '';
                $acct_data = null;
                foreach ($subaccounts as $sa) {
                    if (($sa['account'] ?? '') === $edit_account || ($sa['username'] ?? '') === $edit_account) {
                        $acct_data = $sa;
                        break;
                    }
                }
                $acct_dids = [];
                foreach ($dids as $d) {
                    if (strpos($d['routing'] ?? '', $edit_account) !== false) {
                        $acct_dids[] = $d;
                    }
                }
            ?>
            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-user-cog text-primary mr-2"></i>Account Details</h2>
                        <?php if ($acct_data): ?>
                        <div class="mt-1 bg-blue-50 border border-blue-200 text-blue-700 px-3 py-1 rounded text-xs inline-block">
                            Account Details from account <?php echo htmlspecialchars($edit_account); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <a href="?tab=myservices" class="text-sm text-gray-500 hover:text-gray-700"><i class="fas fa-arrow-left mr-1"></i>Back to My Services</a>
                </div>

                <?php if (!$acct_data): ?>
                <div class="p-8 text-center text-gray-400">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>Account not found.</p>
                </div>
                <?php else: ?>
                <form method="POST" class="p-6" data-testid="form-account-detail">
                            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_account">
                    <input type="hidden" name="account" value="<?php echo htmlspecialchars($edit_account); ?>">

                    <h3 class="text-base font-semibold text-gray-800 mb-4">Basic options</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
                            <input type="text" value="<?php echo htmlspecialchars($acct_data['username'] ?? $edit_account); ?>" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50" data-testid="input-sa-username">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Protocol</label>
                            <input type="text" value="<?php echo htmlspecialchars(strtoupper($acct_data['protocol'] ?? 'SIP')); ?>" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                            <input type="text" name="description" value="<?php echo htmlspecialchars($acct_data['description'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="My Description" data-testid="input-sa-description">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Authentication type</label>
                            <div class="space-y-1 text-sm text-gray-700">
                                <label class="flex items-center gap-2"><input type="radio" checked disabled class="text-blue-600"><span>User/Password Authentication (Recommended)</span></label>
                                <label class="flex items-center gap-2"><input type="radio" disabled class="text-blue-600"><span>Static IP Authentication (SIP only) (Advanced Users)</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Device type</label>
                            <div class="space-y-1 text-sm text-gray-700">
                                <label class="flex items-center gap-2"><input type="radio" name="device_type_display" <?php echo (($acct_data['device_type'] ?? '') !== '2') ? 'checked' : ''; ?> disabled class="text-blue-600"><span>Asterisk, IP PBX, Gateway or VoIP Switch</span></label>
                                <label class="flex items-center gap-2"><input type="radio" name="device_type_display" <?php echo (($acct_data['device_type'] ?? '') === '2') ? 'checked' : ''; ?> disabled class="text-blue-600"><span>ATA device, IP Phone or Softphone</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Password</label>
                            <input type="password" value="********" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50">
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-6 mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3">CallerID override</h4>
                        <p class="text-xs text-gray-500 mb-3">We provide the ability to make outbound calls using a CallerID number from outside our system (e.g. your personal mobile phone number), but it must first be verified.</p>
                        <div class="space-y-3">
                            <label class="flex items-center gap-2 text-sm"><input type="radio" name="callerid_mode" value="did" checked class="text-blue-600"><span>Use one of my DIDs</span></label>
                            <div class="ml-6">
                                <select class="px-3 py-2 border border-gray-300 rounded-lg text-sm w-full max-w-md" data-testid="select-callerid-did">
                                    <?php foreach ($dids as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['did'] ?? ''); ?>"><?php echo htmlspecialchars(($d['did'] ?? '') . ' - ' . strtoupper($d['description'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                    <?php if (empty($dids)): ?><option value="">No DIDs available</option><?php endif; ?>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-sm"><input type="radio" name="callerid_mode" value="custom" class="text-blue-600"><span>Custom CallerID Override</span></label>
                            <label class="flex items-center gap-2 text-sm"><input type="radio" name="callerid_mode" value="verified" class="text-blue-600"><span>Use a verified CallerID <span class="bg-blue-100 text-blue-600 text-[10px] px-1.5 py-0.5 rounded font-semibold">BETA</span></span></label>
                            <label class="flex items-center gap-2 text-sm"><input type="radio" name="callerid_mode" value="passthrough" class="text-blue-600"><span>I use a system capable of passing its own CallerID</span></label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Music On Hold</label>
                            <select name="music_on_hold" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-music-on-hold">
                                <option value="default" <?php echo (($acct_data['music_on_hold'] ?? '') === 'default') ? 'selected' : ''; ?>>No Music</option>
                                <option value="jazz" <?php echo (($acct_data['music_on_hold'] ?? '') === 'jazz') ? 'selected' : ''; ?>>Jazz</option>
                                <option value="classical" <?php echo (($acct_data['music_on_hold'] ?? '') === 'classical') ? 'selected' : ''; ?>>Classical</option>
                                <option value="rock" <?php echo (($acct_data['music_on_hold'] ?? '') === 'rock') ? 'selected' : ''; ?>>Rock</option>
                            </select>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-6 mb-6">
                        <h3 class="text-base font-semibold text-gray-800 mb-4">Advanced options</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-2">Allowed codecs</label>
                                <div class="space-y-1">
                                    <?php
                                    $current_codecs = explode(';', $acct_data['allowed_codecs'] ?? 'ulaw;g729a');
                                    $all_codecs = ['ulaw' => 'G.711U', 'g729a' => 'G.729a', 'gsm' => 'GSM'];
                                    foreach ($all_codecs as $cv => $cl):
                                    ?>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="allowed_codecs[]" value="<?php echo $cv; ?>" <?php echo in_array($cv, $current_codecs) ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" data-testid="check-codec-<?php echo $cv; ?>">
                                        <span class="text-gray-700"><?php echo $cl; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-2">DTMF Mode</label>
                                <div class="space-y-1">
                                    <?php
                                    $dtmf = $acct_data['dtmf_mode'] ?? 'auto';
                                    $dtmf_options = ['auto' => 'AUTO', 'rfc2833' => 'RFC2833 (AVT)', 'inband' => 'INBAND', 'info' => 'INFO'];
                                    foreach ($dtmf_options as $dv => $dl):
                                    ?>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="radio" name="dtmf_mode" value="<?php echo $dv; ?>" <?php echo ($dtmf === $dv) ? 'checked' : ''; ?> class="text-blue-600 focus:ring-blue-500" data-testid="radio-dtmf-<?php echo $dv; ?>">
                                        <span class="text-gray-700"><?php echo $dl; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-2">NAT (Network Address Translation)</label>
                                <select name="nat" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-nat">
                                    <option value="yes" <?php echo (($acct_data['nat'] ?? 'yes') === 'yes') ? 'selected' : ''; ?>>Yes</option>
                                    <option value="no" <?php echo (($acct_data['nat'] ?? '') === 'no') ? 'selected' : ''; ?>>No</option>
                                    <option value="route" <?php echo (($acct_data['nat'] ?? '') === 'route') ? 'selected' : ''; ?>>Route</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php
                    $acct_package = $acct_data['package'] ?? '';
                    $acct_monthly = $acct_data['monthly_fee'] ?? '';
                    $acct_minutes = $acct_data['free_minutes'] ?? '';
                    if ($acct_package || $acct_monthly):
                    ?>
                    <div class="border-t border-gray-200 pt-6 mb-6">
                        <h3 class="text-base font-semibold text-gray-800 mb-4">Package</h3>
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <span class="text-xs text-gray-500 block mb-1">Description</span>
                                <span class="font-medium text-gray-900"><?php echo htmlspecialchars($acct_package ?: 'US/Canada - Unlimited'); ?></span>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 block mb-1">Monthly Fee</span>
                                <span class="font-medium text-gray-900">$<?php echo htmlspecialchars($acct_monthly ?: '29.95'); ?></span>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 block mb-1">Free Minutes</span>
                                <span class="font-medium text-gray-900"><i class="fas fa-infinity text-blue-500"></i> <?php echo htmlspecialchars($acct_minutes ?: '0'); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="border-t border-gray-200 pt-6 mb-6">
                        <h3 class="text-base font-semibold text-gray-800 mb-4">Optional Internal Extension</h3>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Internal Extension Voicemail</label>
                            <select name="internal_voicemail" class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-internal-vm">
                                <option value=""></option>
                                <option value="novm">No Voicemail</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-start">
                        <button type="submit" class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-account">
                            <i class="fas fa-save mr-1"></i>Save Changes
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <?php elseif ($active_tab === 'callerfilter'): ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-filter text-primary mr-2"></i>CallerID Filtering</h2>
                </div>
                <div class="p-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-semibold text-blue-800 mb-1"><i class="fas fa-info-circle mr-1"></i>CallerID Filtering</h4>
                        <p class="text-xs text-blue-700">CallerID Filtering is a tool that lets you create incoming routing rules according to incoming CallerID number. You can create as many filters as you want. Each filter you create can filter according to one of the following criteria and change the routing if there's a match.</p>
                        <ul class="text-xs text-blue-600 mt-2 ml-4 list-disc space-y-0.5">
                            <li>Anonymous CallerID</li>
                            <li>Invalid North American CallerID (NPANXXXXXX)</li>
                            <li>Custom CallerID Number of your choice</li>
                        </ul>
                    </div>

                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-semibold text-amber-800 mb-1"><i class="fas fa-lightbulb mr-1"></i>Some usage scenarios</h4>
                        <p class="text-xs text-amber-700">An annoying telemarketing firm has been calling your number. You can block that specific number and configure your filter to play the following recording to this CallerID: "That number is no longer in service, please hang up and try again" followed by a busy tone.</p>
                    </div>

                    <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-semibold text-green-800 mb-1"><i class="fas fa-asterisk mr-1"></i>Using Wildcard for pattern matching</h4>
                        <p class="text-xs text-green-700">Some examples: for this example we'll assume CallerID Number is 2145550000. 2145550000, 214*, 214XXXX000, 21XXXXXXXX and 214XX00X* are examples that would match CallerID. 2145560000 while 214XXXX7* and 214XXXX are examples that would NOT match the CallerID.</p>
                    </div>

                    <div class="border-t border-gray-200 pt-6 mb-6">
                        <div class="flex items-center gap-3 mb-4">
                            <button onclick="document.getElementById('filter-list-section').classList.remove('hidden'); document.getElementById('add-filter-section').classList.add('hidden');" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition" data-testid="button-filter-list-tab">CallerID Filtering list</button>
                            <button onclick="document.getElementById('add-filter-section').classList.remove('hidden'); document.getElementById('filter-list-section').classList.add('hidden');" class="px-3 py-1.5 bg-green-50 hover:bg-green-100 text-green-700 text-xs font-medium rounded-lg transition" data-testid="button-add-filter-tab"><i class="fas fa-plus mr-1"></i>Add a new Caller ID Filtering</button>
                        </div>

                        <div id="filter-list-section">
                            <div class="bg-primary text-white px-4 py-2 rounded-t-lg text-sm font-medium">CallerID Filtering list</div>
                            <div class="overflow-x-auto border border-gray-200 border-t-0 rounded-b-lg">
                                <table class="w-full text-left" data-testid="table-callerid-filters">
                                    <thead>
                                        <tr class="bg-gray-100 text-xs text-gray-600">
                                            <th class="px-4 py-2.5 font-medium">Caller ID</th>
                                            <th class="px-4 py-2.5 font-medium">DID</th>
                                            <th class="px-4 py-2.5 font-medium">Routing</th>
                                            <th class="px-4 py-2.5 font-medium">Failover</th>
                                            <th class="px-4 py-2.5 font-medium">Note</th>
                                            <th class="px-4 py-2.5 font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php if (empty($callerid_filters)): ?>
                                        <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">No data available in table</td></tr>
                                        <?php else: ?>
                                        <?php foreach ($callerid_filters as $cf): ?>
                                        <tr class="hover:bg-gray-50" data-testid="row-filter-<?php echo htmlspecialchars($cf['filtering'] ?? ''); ?>">
                                            <td class="px-4 py-2.5 text-sm font-mono"><?php echo htmlspecialchars($cf['callerid'] ?? ''); ?></td>
                                            <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($cf['did'] ?? ''); ?></td>
                                            <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($cf['routing'] ?? ''); ?></td>
                                            <td class="px-4 py-2.5 text-sm text-gray-500"><?php echo htmlspecialchars($cf['failover_unreachable'] ?? ''); ?></td>
                                            <td class="px-4 py-2.5 text-sm text-gray-500"><?php echo htmlspecialchars($cf['note'] ?? ''); ?></td>
                                            <td class="px-4 py-2.5">
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this filter?')">
                            <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_callerid_filter">
                                                    <input type="hidden" name="filter_id" value="<?php echo htmlspecialchars($cf['filtering'] ?? ''); ?>">
                                                    <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 hover:bg-red-100 rounded text-xs font-medium transition" data-testid="button-delete-filter-<?php echo htmlspecialchars($cf['filtering'] ?? ''); ?>"><i class="fas fa-trash mr-1"></i>Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">Showing <?php echo count($callerid_filters); ?> entries</p>
                        </div>

                        <div id="add-filter-section" class="hidden">
                            <div class="bg-green-600 text-white px-4 py-2 rounded-t-lg text-sm font-medium"><i class="fas fa-plus mr-1"></i>Add a new Caller ID Filtering</div>
                            <form method="POST" class="border border-gray-200 border-t-0 rounded-b-lg p-6" data-testid="form-add-filter">
                            <?= csrf_field() ?>
                                <input type="hidden" name="action" value="create_callerid_filter">

                                <h4 class="text-sm font-semibold text-gray-800 mb-3">Select Type of Filter</h4>
                                <div class="space-y-2 mb-4">
                                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="filter_type" value="anonymous" class="text-blue-600"><span>Anonymous Caller ID number</span></label>
                                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="filter_type" value="invalid_npa" class="text-blue-600"><span>CallerID not matching the North American NPANXXXXXX format (Could block International Calls)</span></label>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="radio" name="filter_type" value="specific" checked class="text-blue-600">
                                        <span>Specific Caller ID Number / Specify</span>
                                        <input type="text" name="filter_callerid" class="ml-2 px-3 py-1.5 border border-gray-300 rounded text-sm w-40" placeholder="e.g. 2145500000" data-testid="input-filter-callerid">
                                    </label>
                                </div>

                                <h4 class="text-sm font-semibold text-gray-800 mb-3 mt-4">Select DID to apply Filter</h4>
                                <div class="flex items-center gap-3 mb-4">
                                    <span class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded text-xs font-medium">DID Numbers</span>
                                    <select name="filter_did" class="px-3 py-2 border border-gray-300 rounded-lg text-sm flex-1 max-w-md" data-testid="select-filter-did">
                                        <?php foreach ($dids as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d['did'] ?? ''); ?>"><?php echo htmlspecialchars(($d['did'] ?? '') . ' - ' . strtoupper($d['description'] ?? '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <h4 class="text-sm font-semibold text-gray-800 mb-3 mt-4">Routing</h4>
                                <div class="space-y-3 mb-4">
                                    <?php
                                    $routing_opts = [];
                                    foreach ($subaccounts as $sa) $routing_opts[] = ['value' => 'account:' . ($sa['account'] ?? ''), 'label' => ($sa['username'] ?? $sa['account'] ?? '')];
                                    ?>
                                    <div class="flex items-center gap-3">
                                        <span class="px-2 py-1 bg-gray-100 rounded text-xs font-medium w-32 text-center">SIP/IAX</span>
                                        <select name="filter_routing" class="px-3 py-2 border border-gray-300 rounded-lg text-sm flex-1 max-w-md" data-testid="select-filter-routing">
                                            <?php foreach ($routing_opts as $ro): ?>
                                            <option value="<?php echo htmlspecialchars($ro['value']); ?>"><?php echo htmlspecialchars($ro['label']); ?></option>
                                            <?php endforeach; ?>
                                            <?php foreach ($ivrs as $iv): ?><option value="ivr:<?php echo htmlspecialchars($iv['ivr'] ?? ''); ?>">IVR: <?php echo htmlspecialchars($iv['name'] ?? ''); ?></option><?php endforeach; ?>
                                            <?php foreach ($ring_groups as $rg): ?><option value="ring_group:<?php echo htmlspecialchars($rg['ring_group'] ?? ''); ?>">Ring Group: <?php echo htmlspecialchars($rg['name'] ?? ''); ?></option><?php endforeach; ?>
                                            <?php foreach ($queues as $q): ?><option value="queue:<?php echo htmlspecialchars($q['queue'] ?? ''); ?>">Queue: <?php echo htmlspecialchars($q['queue_name'] ?? ''); ?></option><?php endforeach; ?>
                                            <?php foreach ($forwardings as $fw): ?><option value="fwd:<?php echo htmlspecialchars($fw['forwarding'] ?? ''); ?>">Fwd: <?php echo htmlspecialchars($fw['phone_number'] ?? ''); ?></option><?php endforeach; ?>
                                            <?php foreach ($recordings as $rc): ?><option value="recording:<?php echo htmlspecialchars($rc['recording'] ?? ''); ?>">Recording: <?php echo htmlspecialchars($rc['name'] ?? $rc['file'] ?? ''); ?></option><?php endforeach; ?>
                                            <?php foreach ($voicemails as $vm): ?><option value="vm:<?php echo htmlspecialchars($vm['mailbox'] ?? ''); ?>">Voicemail: <?php echo htmlspecialchars($vm['name'] ?? ''); ?></option><?php endforeach; ?>
                                            <?php foreach ($callbacks as $cba): ?><option value="cb:<?php echo htmlspecialchars($cba['callback'] ?? ''); ?>">Callback: <?php echo htmlspecialchars($cba['description'] ?? ''); ?></option><?php endforeach; ?>
                                            <option value="sys:hangup">System: Hangup</option>
                                            <option value="sys:busy">System: Busy</option>
                                            <option value="sys:noservice">System: No Service</option>
                                            <option value="sys:disconnected">System: Disconnected</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Write a note for this filter</label>
                                    <textarea name="filter_note" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Note" data-testid="input-filter-note"></textarea>
                                </div>

                                <button type="submit" class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-filter">
                                    <i class="fas fa-save mr-1"></i>Save CallerID Filtering
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($active_tab === 'forwarding'): ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-share text-primary mr-2"></i>Call Forwarding</h2>
                </div>
                <div class="p-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-semibold text-blue-800 mb-1"><i class="fas fa-info-circle mr-1"></i>Call Forwarding</h4>
                        <p class="text-xs text-blue-700">A Call Forwarding allows an incoming call to be redirected to a mobile telephone or other telephone number where the desired called party is able to answer.</p>
                        <p class="text-xs text-blue-600 mt-1">Here you can create Call Forwarding entries. After creating a Call Forwarding entry, you can route a DID to it. Please note that when you forward a call, normal inbound charges apply according to your DID plan and the normal termination rate is also applied for the destination number for the duration of the call.</p>
                    </div>

                    <div class="flex items-center gap-3 mb-4">
                        <button onclick="document.getElementById('fwd-list-section').classList.remove('hidden'); document.getElementById('add-fwd-section').classList.add('hidden');" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition" data-testid="button-fwd-list-tab">Call Forwarding List</button>
                        <button onclick="document.getElementById('add-fwd-section').classList.remove('hidden'); document.getElementById('fwd-list-section').classList.add('hidden');" class="px-3 py-1.5 bg-green-50 hover:bg-green-100 text-green-700 text-xs font-medium rounded-lg transition" data-testid="button-add-fwd-tab"><i class="fas fa-plus mr-1"></i>Add New Call Forwarding</button>
                    </div>

                    <div id="fwd-list-section">
                        <div class="bg-primary text-white px-4 py-2 rounded-t-lg text-sm font-medium">Call Forwarding List</div>
                        <div class="overflow-x-auto border border-gray-200 border-t-0 rounded-b-lg">
                            <table class="w-full text-left" data-testid="table-forwardings">
                                <thead>
                                    <tr class="bg-gray-100 text-xs text-gray-600">
                                        <th class="px-4 py-2.5 font-medium">Phone Number</th>
                                        <th class="px-4 py-2.5 font-medium">Caller ID</th>
                                        <th class="px-4 py-2.5 font-medium">Description</th>
                                        <th class="px-4 py-2.5 font-medium">DTMF Digits</th>
                                        <th class="px-4 py-2.5 font-medium">Pause</th>
                                        <th class="px-4 py-2.5 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (empty($forwardings)): ?>
                                    <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">No data available in table</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($forwardings as $fw): ?>
                                    <tr class="hover:bg-gray-50" data-testid="row-fwd-<?php echo htmlspecialchars($fw['forwarding'] ?? ''); ?>">
                                        <td class="px-4 py-2.5 text-sm font-mono"><?php echo htmlspecialchars($fw['phone_number'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($fw['callerid'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($fw['description'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($fw['dtmf_digits'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($fw['pause'] ?? '0'); ?></td>
                                        <td class="px-4 py-2.5">
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this forwarding entry?')">
                            <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_forwarding">
                                                <input type="hidden" name="forwarding_id" value="<?php echo htmlspecialchars($fw['forwarding'] ?? ''); ?>">
                                                <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 hover:bg-red-100 rounded text-xs font-medium transition" data-testid="button-delete-fwd-<?php echo htmlspecialchars($fw['forwarding'] ?? ''); ?>"><i class="fas fa-trash mr-1"></i>Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">Showing <?php echo count($forwardings); ?> entries</p>
                    </div>

                    <div id="add-fwd-section" class="hidden">
                        <div class="bg-green-600 text-white px-4 py-2 rounded-t-lg text-sm font-medium"><i class="fas fa-plus mr-1"></i>Add New Call Forwarding</div>
                        <form method="POST" class="border border-gray-200 border-t-0 rounded-b-lg p-6" data-testid="form-add-fwd">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_forwarding">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Phone Number <span class="text-red-500">*</span></label>
                                    <input type="text" name="fwd_number" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="My Number" data-testid="input-fwd-number">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Caller ID</label>
                                    <input type="text" name="fwd_callerid" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="My CallerID" data-testid="input-fwd-callerid">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                                    <input type="text" name="fwd_description" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Description" data-testid="input-fwd-description">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">DTMF Digits</label>
                                    <input type="text" name="fwd_dtmf" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional DTMF digits" data-testid="input-fwd-dtmf">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Pause (seconds)</label>
                                    <select name="fwd_pause" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-fwd-pause">
                                        <?php for ($i = 0; $i <= 10; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> seconds</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mb-4"><span class="text-red-500">*</span> Field Mandatory</p>
                            <button type="submit" class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-fwd">
                                <i class="fas fa-save mr-1"></i>Save Call Forwarding
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php elseif ($active_tab === 'callback'): ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-phone-volume text-primary mr-2"></i>Callback</h2>
                </div>
                <div class="p-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-semibold text-blue-800 mb-1"><i class="fas fa-info-circle mr-1"></i>Callback</h4>
                        <p class="text-xs text-blue-700">Here you can create callback entries. When a DID is routed to a callback entry and a call is placed to it, the number will return the busy signal. The system will then callback the specified number and present the user with a dialtone.</p>
                        <p class="text-xs text-blue-600 mt-1">Please note that the number to callback can be an international number as well but we do not guarantee that the DTMF tones will work properly.</p>
                    </div>

                    <div class="flex items-center gap-3 mb-4">
                        <button onclick="document.getElementById('cb-list-section').classList.remove('hidden'); document.getElementById('add-cb-section').classList.add('hidden');" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition" data-testid="button-cb-list-tab">Callback List</button>
                        <button onclick="document.getElementById('add-cb-section').classList.remove('hidden'); document.getElementById('cb-list-section').classList.add('hidden');" class="px-3 py-1.5 bg-green-50 hover:bg-green-100 text-green-700 text-xs font-medium rounded-lg transition" data-testid="button-add-cb-tab"><i class="fas fa-plus mr-1"></i>Add New Callback</button>
                    </div>

                    <div id="cb-list-section">
                        <div class="bg-primary text-white px-4 py-2 rounded-t-lg text-sm font-medium">Callback List</div>
                        <div class="overflow-x-auto border border-gray-200 border-t-0 rounded-b-lg">
                            <table class="w-full text-left" data-testid="table-callbacks">
                                <thead>
                                    <tr class="bg-gray-100 text-xs text-gray-600">
                                        <th class="px-4 py-2.5 font-medium">Description</th>
                                        <th class="px-4 py-2.5 font-medium">Number</th>
                                        <th class="px-4 py-2.5 font-medium">Delay</th>
                                        <th class="px-4 py-2.5 font-medium">Time Out</th>
                                        <th class="px-4 py-2.5 font-medium">Digit Timeout</th>
                                        <th class="px-4 py-2.5 font-medium">Caller ID</th>
                                        <th class="px-4 py-2.5 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (empty($callbacks)): ?>
                                    <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-400">No data available in table</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($callbacks as $cb): ?>
                                    <tr class="hover:bg-gray-50" data-testid="row-cb-<?php echo htmlspecialchars($cb['callback'] ?? ''); ?>">
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($cb['description'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm font-mono"><?php echo htmlspecialchars($cb['number'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($cb['delay_before'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($cb['response_timeout'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($cb['digit_timeout'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($cb['callerid'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5">
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this callback?')">
                            <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_callback">
                                                <input type="hidden" name="callback_id" value="<?php echo htmlspecialchars($cb['callback'] ?? ''); ?>">
                                                <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 hover:bg-red-100 rounded text-xs font-medium transition" data-testid="button-delete-cb-<?php echo htmlspecialchars($cb['callback'] ?? ''); ?>"><i class="fas fa-trash mr-1"></i>Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">Showing <?php echo count($callbacks); ?> entries</p>
                    </div>

                    <div id="add-cb-section" class="hidden">
                        <div class="bg-green-600 text-white px-4 py-2 rounded-t-lg text-sm font-medium"><i class="fas fa-plus mr-1"></i>Add New Callback</div>
                        <form method="POST" class="border border-gray-200 border-t-0 rounded-b-lg p-6" data-testid="form-add-callback">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_callback">
                            <h4 class="text-sm font-semibold text-gray-800 mb-4">Callback</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Description <span class="text-red-500">*</span></label>
                                    <input type="text" name="cb_description" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="My Description" data-testid="input-cb-description">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Number to call <span class="text-red-500">*</span></label>
                                    <input type="text" name="cb_number" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="My Number" data-testid="input-cb-number">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Delay before callback</label>
                                    <select name="cb_delay" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-cb-delay">
                                        <?php for ($i = 1; $i <= 30; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($i === 5) ? 'selected' : ''; ?>><?php echo $i; ?> seconds</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Response timeout</label>
                                    <select name="cb_response_timeout" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-cb-response-timeout">
                                        <?php for ($i = 5; $i <= 60; $i += 5): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($i === 10) ? 'selected' : ''; ?>><?php echo $i; ?> seconds</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Digit Timeout</label>
                                    <select name="cb_digit_timeout" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-cb-digit-timeout">
                                        <?php for ($i = 1; $i <= 30; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($i === 5) ? 'selected' : ''; ?>><?php echo $i; ?> seconds</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Caller ID number</label>
                                    <input type="text" name="cb_callerid" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="My CallerID" data-testid="input-cb-callerid">
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mb-4"><span class="text-red-500">*</span> Field Mandatory</p>
                            <button type="submit" class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-callback">
                                <i class="fas fa-save mr-1"></i>Save Callback
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php elseif ($active_tab === 'voicemail'): ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-voicemail text-primary mr-2"></i>Voicemail</h2>
                </div>
                <div class="p-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                        <h4 class="text-sm font-semibold text-blue-800 mb-1"><i class="fas fa-info-circle mr-1"></i>Voicemail</h4>
                        <p class="text-xs text-blue-700">You can configure your Ring Group to go to a specific Mailbox you have created here.</p>
                        <ol class="text-xs text-blue-600 mt-2 ml-4 list-decimal space-y-0.5">
                            <li>Create a Mailbox</li>
                            <li>Go to "Services > My Services"</li>
                            <li>In "Services > My Services", click on your DID Number and change "Voicemail" to the appropriate Mailbox account.</li>
                        </ol>
                    </div>

                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-semibold text-amber-800 mb-1"><i class="fas fa-key mr-1"></i>Voicemail Access Code</h4>
                        <p class="text-xs text-amber-700">*97 to access directly the Mailbox associated to the account you are dialing from. (Will prompt for Password only)</p>
                        <p class="text-xs text-amber-700">*98 to access your Voicemail and choose one of your Mailbox accounts. You will be asked to enter your Mailbox ID and Password.</p>
                    </div>

                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-semibold text-yellow-800 mb-1"><i class="fas fa-exclamation-triangle mr-1"></i>If you don't have access to our VoIP network</h4>
                        <p class="text-xs text-yellow-700">If you don't have access to our VoIP network and would like to check your Voicemail, you can simply dial your number. Once the Voicemail system answers your call, press the asterisk key (*). When logged in to your voicemail, press 0 for options. You can record your greeting and temporary greeting from there.</p>
                    </div>

                    <div class="flex items-center gap-3 mb-4">
                        <button onclick="document.getElementById('vm-list-section').classList.remove('hidden'); document.getElementById('add-vm-section').classList.add('hidden');" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition" data-testid="button-vm-list-tab">Voicemail List</button>
                        <button onclick="document.getElementById('add-vm-section').classList.remove('hidden'); document.getElementById('vm-list-section').classList.add('hidden');" class="px-3 py-1.5 bg-green-50 hover:bg-green-100 text-green-700 text-xs font-medium rounded-lg transition" data-testid="button-add-vm-tab"><i class="fas fa-plus mr-1"></i>Add new Voicemail</button>
                    </div>

                    <div id="vm-list-section">
                        <div class="bg-primary text-white px-4 py-2 rounded-t-lg text-sm font-medium">Voicemail List</div>
                        <div class="overflow-x-auto border border-gray-200 border-t-0 rounded-b-lg">
                            <table class="w-full text-left" data-testid="table-voicemails">
                                <thead>
                                    <tr class="bg-gray-100 text-xs text-gray-600">
                                        <th class="px-4 py-2.5 font-medium">Mailbox</th>
                                        <th class="px-4 py-2.5 font-medium">Access</th>
                                        <th class="px-4 py-2.5 font-medium">Name</th>
                                        <th class="px-4 py-2.5 font-medium">Email</th>
                                        <th class="px-4 py-2.5 font-medium">New</th>
                                        <th class="px-4 py-2.5 font-medium">Urgent</th>
                                        <th class="px-4 py-2.5 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (empty($voicemails)): ?>
                                    <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-400">No voicemail boxes configured</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($voicemails as $vm): ?>
                                    <tr class="hover:bg-gray-50" data-testid="row-vm-<?php echo htmlspecialchars($vm['mailbox'] ?? ''); ?>">
                                        <td class="px-4 py-2.5 text-sm font-mono font-medium"><?php echo htmlspecialchars($vm['mailbox'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm text-gray-600"><?php echo htmlspecialchars($vm['password'] ?? '*97'); ?></td>
                                        <td class="px-4 py-2.5 text-sm font-medium"><?php echo htmlspecialchars($vm['name'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm text-gray-600"><?php echo htmlspecialchars($vm['email'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm text-center"><?php echo htmlspecialchars($vm['new_messages'] ?? $vm['messages_new'] ?? '0'); ?></td>
                                        <td class="px-4 py-2.5 text-sm text-center"><?php echo htmlspecialchars($vm['urgent_messages'] ?? $vm['messages_urgent'] ?? '0'); ?></td>
                                        <td class="px-4 py-2.5">
                                            <div class="flex flex-wrap gap-1">
                                                <span class="px-2 py-1 bg-blue-50 text-blue-600 rounded text-xs font-medium"><i class="fas fa-voicemail mr-1"></i>Voicemail</span>
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this voicemail box?')">
                            <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_voicemail">
                                                    <input type="hidden" name="voicemail_id" value="<?php echo htmlspecialchars($vm['mailbox'] ?? ''); ?>">
                                                    <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 hover:bg-red-100 rounded text-xs font-medium transition" data-testid="button-delete-vm-<?php echo htmlspecialchars($vm['mailbox'] ?? ''); ?>"><i class="fas fa-trash mr-1"></i>Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">Showing <?php echo count($voicemails); ?> entries</p>
                    </div>

                    <div id="add-vm-section" class="hidden">
                        <div class="bg-green-600 text-white px-4 py-2 rounded-t-lg text-sm font-medium"><i class="fas fa-plus mr-1"></i>Add new Voicemail</div>
                        <form method="POST" class="border border-gray-200 border-t-0 rounded-b-lg p-6" data-testid="form-add-voicemail">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_voicemail">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Mailbox <span class="text-red-500">*</span></label>
                                    <input type="text" name="vm_mailbox" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. 1000" data-testid="input-vm-mailbox">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="vm_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="BILLING-VM" data-testid="input-vm-name">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                                    <input type="text" name="vm_password" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Numeric password" data-testid="input-vm-password">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Email</label>
                                    <input type="email" name="vm_email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="admin@bluemogul.biz" data-testid="input-vm-email">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Attach message</label>
                                    <select name="vm_attach" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-vm-attach">
                                        <option value="yes" selected>Yes</option>
                                        <option value="no">No</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Timezone</label>
                                    <select name="vm_timezone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-vm-timezone">
                                        <option value="US/Central" selected>US/Central</option>
                                        <option value="US/Eastern">US/Eastern</option>
                                        <option value="US/Pacific">US/Pacific</option>
                                        <option value="US/Mountain">US/Mountain</option>
                                        <option value="Canada/Atlantic">Canada/Atlantic</option>
                                        <option value="Canada/Eastern">Canada/Eastern</option>
                                        <option value="Canada/Central">Canada/Central</option>
                                        <option value="Canada/Mountain">Canada/Mountain</option>
                                        <option value="Canada/Pacific">Canada/Pacific</option>
                                    </select>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mb-4"><span class="text-red-500">*</span> Field Mandatory</p>
                            <button type="submit" class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-save-voicemail">
                                <i class="fas fa-save mr-1"></i>Save Voicemail
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php elseif ($active_tab === 'orderdids'): ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cart-plus text-primary mr-2"></i>Order DID's</h2>
                </div>
                <div class="p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <button onclick="document.getElementById('order-us').classList.remove('hidden'); document.getElementById('order-ca').classList.add('hidden');" class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-medium rounded-lg transition" data-testid="button-order-us">United States</button>
                        <button onclick="document.getElementById('order-ca').classList.remove('hidden'); document.getElementById('order-us').classList.add('hidden');" class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 text-xs font-medium rounded-lg transition" data-testid="button-order-ca">Canada</button>
                    </div>

                    <div id="order-us">
                        <h3 class="text-base font-semibold text-gray-800 mb-3">Local Numbers in United States</h3>
                        <div class="bg-primary text-white px-4 py-2 rounded-t-lg text-sm font-medium">Search Criteria</div>
                        <form method="POST" class="border border-gray-200 border-t-0 rounded-b-lg p-4" data-testid="form-search-did-us">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="order_did_search">
                            <div class="flex items-center gap-3 mb-3">
                                <button type="button" class="px-3 py-1.5 bg-gray-100 text-gray-700 text-xs font-medium rounded transition">Browse DID's by State</button>
                            </div>
                            <div class="mb-3">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Select State</label>
                                <select name="order_state" class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-order-state">
                                    <?php foreach ($us_states as $st): ?>
                                    <option value="<?php echo $st; ?>"><?php echo ucwords(strtolower($st)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-search-dids">
                                <i class="fas fa-search mr-1"></i>Search
                            </button>
                        </form>
                    </div>

                    <div id="order-ca" class="hidden">
                        <h3 class="text-base font-semibold text-gray-800 mb-3">Local Numbers in Canada</h3>
                        <div class="bg-red-600 text-white px-4 py-2 rounded-t-lg text-sm font-medium">Search Criteria</div>
                        <form method="POST" class="border border-gray-200 border-t-0 rounded-b-lg p-4" data-testid="form-search-did-ca">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="order_did_canada">
                            <div class="mb-3">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Select Province</label>
                                <select name="order_province" class="w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-order-province">
                                    <?php foreach ($ca_provinces as $pr): ?>
                                    <option value="<?php echo $pr; ?>"><?php echo ucwords(strtolower($pr)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-search-dids-ca">
                                <i class="fas fa-search mr-1"></i>Search
                            </button>
                        </form>
                    </div>

                    <?php if (!empty($order_dids_results) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($action ?? '', ['order_did_search', 'order_did_canada'])): ?>
                    <div class="mt-6">
                        <h3 class="text-base font-semibold text-gray-800 mb-3"><i class="fas fa-search text-gray-500 mr-1"></i>Search Results</h3>
                        <div class="bg-primary text-white px-4 py-2 rounded-t-lg text-sm font-medium">Search Result</div>
                        <div class="overflow-x-auto border border-gray-200 border-t-0 rounded-b-lg">
                            <table class="w-full text-left" data-testid="table-order-results">
                                <thead>
                                    <tr class="bg-gray-100 text-xs text-gray-600">
                                        <th class="px-4 py-2.5 font-medium">Ratecenter</th>
                                        <th class="px-4 py-2.5 font-medium">Availability</th>
                                        <th class="px-4 py-2.5 font-medium">Order</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (empty($order_dids_results)): ?>
                                    <tr><td colspan="3" class="px-4 py-6 text-center text-sm text-gray-400">No data available in table</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($order_dids_results as $od): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($od['ratecenter'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5 text-sm"><?php echo htmlspecialchars($od['available'] ?? ''); ?></td>
                                        <td class="px-4 py-2.5">
                                            <span class="px-2 py-1 bg-blue-50 text-blue-600 rounded text-xs font-medium"><i class="fas fa-shopping-cart mr-1"></i>Contact Support</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">Showing <?php echo count($order_dids_results); ?> entries</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
