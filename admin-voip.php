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
$api_auth = $voip_password_set ? $voip_password : $voip_token;

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
    $params = array_merge([
        'api_username' => VOIP_API_USERNAME,
        'api_password' => !empty(VOIP_API_PASSWORD) ? VOIP_API_PASSWORD : VOIP_API_TOKEN,
        'action' => $action,
    ], $extra_params);
    $url = VOIP_API_URL . '?' . http_build_query($params);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return ['status' => 'error', 'message' => 'Connection failed'];
    $data = json_decode($response, true);
    if (!$data) return ['status' => 'error', 'message' => 'Invalid response'];
    return $data;
}

if ($voip_connected) {
    if ($active_tab === 'overview') {
        $balance_result = voip_api_call('getBalance');
        if (($balance_result['status'] ?? '') === 'success') {
            $balance = $balance_result['balance'] ?? null;
        }

        $ip_result = voip_api_call('getIP');
        if (($ip_result['status'] ?? '') === 'success') {
            $ip_info = $ip_result['ip'] ?? null;
        }

        $servers_result = voip_api_call('getServersInfo');
        if (($servers_result['status'] ?? '') === 'success') {
            $servers = $servers_result['servers'] ?? [];
            if (!is_array($servers)) $servers = [];
        }
    }

    if ($active_tab === 'dids') {
        $dids_result = voip_api_call('getDIDsInfo');
        if (($dids_result['status'] ?? '') === 'success') {
            $dids = $dids_result['dids'] ?? [];
            if (!is_array($dids)) $dids = [];
        } elseif (($dids_result['status'] ?? '') === 'no_did') {
            $dids = [];
        } else {
            $error_msg = 'DIDs: ' . ($dids_result['message'] ?? $dids_result['status'] ?? 'Unknown error');
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
            $error_msg = 'Sub-accounts: ' . ($sub_result['message'] ?? $sub_result['status'] ?? 'Unknown error');
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
            $error_msg = 'CDR: ' . ($cdr_result['message'] ?? $cdr_result['status'] ?? 'Unknown error');
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
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-phone text-blue-500 mr-2"></i>VoIP.ms Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Phone system &mdash; CDR lookup, DID management, sub-accounts, and call routing</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($voip_connected): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                    <?php if ($balance !== null): ?>
                        <span class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-full text-xs font-medium" data-testid="text-balance"><i class="fas fa-wallet mr-1"></i>$<?php echo number_format((float)$balance, 2); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm mb-4" data-testid="alert-error"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm mb-4"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?></div>
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
                    <a href="?tab=config" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" data-testid="button-configure">
                        <i class="fas fa-cog mr-2"></i>Configure
                    </a>
                </div>

            <?php elseif ($active_tab === 'overview'): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-balance">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Account Balance</p>
                        <?php if ($balance !== null): ?>
                            <p class="text-2xl font-bold text-green-600">$<?php echo number_format((float)$balance, 2); ?></p>
                            <p class="text-xs text-gray-400 mt-1"><?php echo ((float)$balance < 5) ? '<span class="text-red-500 font-medium">Low balance warning</span>' : 'Sufficient funds'; ?></p>
                        <?php else: ?>
                            <p class="text-lg font-bold text-gray-400">--</p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">API v1 REST</p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-ip">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Your IP</p>
                        <?php if ($ip_info): ?>
                            <p class="text-sm font-bold text-gray-900 font-mono"><?php echo htmlspecialchars($ip_info); ?></p>
                            <p class="text-xs text-gray-400 mt-1">Registered with VoIP.ms</p>
                        <?php else: ?>
                            <p class="text-sm font-bold text-gray-400">--</p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-api-url">
                        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">API Endpoint</p>
                        <p class="text-xs font-medium text-gray-900 break-all"><?php echo htmlspecialchars($voip_url); ?></p>
                        <p class="text-xs text-gray-400 mt-1">REST/JSON</p>
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
                                <div><p class="text-sm font-medium text-gray-900">View DIDs</p><p class="text-[10px] text-gray-500">Phone numbers</p></div>
                            </a>
                            <a href="?tab=subaccounts" class="flex items-center gap-3 p-3 bg-purple-50 hover:bg-purple-100 rounded-lg transition" data-testid="action-subaccounts">
                                <div class="w-9 h-9 bg-purple-500 rounded-lg flex items-center justify-center"><i class="fas fa-headset text-white text-sm"></i></div>
                                <div><p class="text-sm font-medium text-gray-900">Sub-Accounts</p><p class="text-[10px] text-gray-500">SIP accounts</p></div>
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
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-server text-green-500 mr-2"></i>POP Servers</h2>
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
                            <p><i class="fas fa-check text-green-500 mr-2"></i>Call forwarding & routing rules</p>
                            <p><i class="fas fa-check text-green-500 mr-2"></i>IVR & ring group config</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'dids'): ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-phone-volume text-blue-500 mr-2"></i>DID Numbers (<?php echo count($dids); ?>)</h2>
                        <a href="https://voip.ms/m/orderdid.php" target="_blank" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition" data-testid="button-order-did"><i class="fas fa-plus mr-1"></i>Order New DID</a>
                    </div>
                    <?php if (empty($dids)): ?>
                        <div class="p-12 text-center text-gray-500">
                            <i class="fas fa-phone-slash text-gray-300 text-4xl mb-3"></i>
                            <p class="text-sm">No DIDs found on this account.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-dids">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">DID Number</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Routing</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Failover</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">CallerID</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SMS</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($dids as $did): ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="row-did-<?php echo htmlspecialchars($did['did'] ?? ''); ?>">
                                        <td class="px-4 py-3">
                                            <span class="text-sm font-medium text-gray-900 font-mono"><?php echo htmlspecialchars($did['did'] ?? ''); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($did['description'] ?? '--'); ?></td>
                                        <td class="px-4 py-3">
                                            <?php
                                                $routing = $did['routing'] ?? '';
                                                $routing_label = $routing;
                                                if (strpos($routing, 'account:') === 0) $routing_label = '<span class="text-blue-600"><i class="fas fa-headset mr-1"></i>' . htmlspecialchars(substr($routing, 8)) . '</span>';
                                                elseif (strpos($routing, 'sys:') === 0) $routing_label = '<span class="text-purple-600"><i class="fas fa-cog mr-1"></i>' . htmlspecialchars(substr($routing, 4)) . '</span>';
                                                elseif (strpos($routing, 'ivr:') === 0) $routing_label = '<span class="text-green-600"><i class="fas fa-sitemap mr-1"></i>IVR ' . htmlspecialchars(substr($routing, 4)) . '</span>';
                                                elseif (strpos($routing, 'ring_group:') === 0) $routing_label = '<span class="text-orange-600"><i class="fas fa-users mr-1"></i>Ring Group ' . htmlspecialchars(substr($routing, 11)) . '</span>';
                                                else $routing_label = '<span class="text-gray-500">' . htmlspecialchars($routing) . '</span>';
                                            ?>
                                            <span class="text-xs"><?php echo $routing_label; ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($did['failover_busy'] ?? '--'); ?></td>
                                        <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($did['callerid_prefix'] ?? '--'); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if (($did['sms_available'] ?? '') === '1' || ($did['sms_enabled'] ?? '') === '1'): ?>
                                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-[10px] font-medium"><i class="fas fa-check mr-1"></i>SMS</span>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400">--</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($did['order_date'] ?? '--'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'subaccounts'): ?>
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-headset text-purple-500 mr-2"></i>Sub-Accounts (<?php echo count($subaccounts); ?>)</h2>
                        <a href="https://voip.ms/m/managesubaccount.php" target="_blank" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded-lg transition" data-testid="button-manage-subaccounts"><i class="fas fa-external-link-alt mr-1"></i>Manage in VoIP.ms</a>
                    </div>
                    <?php if (empty($subaccounts)): ?>
                        <div class="p-12 text-center text-gray-500">
                            <i class="fas fa-headset text-gray-300 text-4xl mb-3"></i>
                            <p class="text-sm">No sub-accounts found.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-subaccounts">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Username</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Protocol</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Auth Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">CallerID</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Codecs</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($subaccounts as $sa): ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="row-subaccount-<?php echo htmlspecialchars($sa['account'] ?? $sa['username'] ?? ''); ?>">
                                        <td class="px-4 py-3">
                                            <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($sa['username'] ?? $sa['account'] ?? ''); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($sa['description'] ?? '--'); ?></td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-[10px] font-medium"><?php echo htmlspecialchars(strtoupper($sa['protocol'] ?? 'SIP')); ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($sa['auth_type'] ?? '--'); ?></td>
                                        <td class="px-4 py-3 text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($sa['callerid_number'] ?? '--'); ?></td>
                                        <td class="px-4 py-3 text-xs text-gray-500">
                                            <?php
                                                $codecs_str = '';
                                                $codec_fields = ['codec_ulaw', 'codec_alaw', 'codec_g729', 'codec_gsm', 'codec_opus'];
                                                $codec_names = ['uLaw', 'aLaw', 'G.729', 'GSM', 'Opus'];
                                                foreach ($codec_fields as $i => $cf) {
                                                    if (($sa[$cf] ?? '') === 'yes') $codecs_str .= $codec_names[$i] . ' ';
                                                }
                                                echo htmlspecialchars(trim($codecs_str) ?: '--');
                                            ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if (($sa['lock_international'] ?? '') !== 'yes'): ?>
                                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-[10px] font-medium"><i class="fas fa-globe mr-1"></i>Intl</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-[10px] font-medium"><i class="fas fa-lock mr-1"></i>Domestic</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'cdr'): ?>
                <?php
                    $cdr_from = $_GET['cdr_from'] ?? date('Y-m-d', strtotime('-7 days'));
                    $cdr_to = $_GET['cdr_to'] ?? date('Y-m-d');
                    $cdr_type = $_GET['cdr_type'] ?? '1';
                ?>
                <div class="bg-white rounded-lg border border-gray-200 mb-6">
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
                                $total_duration = 0;
                                $total_cost = 0;
                                foreach ($cdr_records as $c) {
                                    $total_duration += intval($c['seconds'] ?? $c['duration'] ?? 0);
                                    $total_cost += floatval($c['total'] ?? $c['cost'] ?? 0);
                                }
                            ?>
                            <span class="text-sm text-blue-600">Total: <?php echo gmdate("H:i:s", $total_duration); ?> | $<?php echo number_format($total_cost, 4); ?></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-cdr">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date/Time</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">From (CallerID)</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">To (Destination)</th>
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
                                        $mins = floor($seconds / 60);
                                        $secs = $seconds % 60;
                                        $disposition = strtolower($cdr['disposition'] ?? '');
                                        $disp_class = $disposition === 'answered' ? 'bg-green-100 text-green-700' : ($disposition === 'no answer' ? 'bg-yellow-100 text-yellow-700' : ($disposition === 'busy' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'));
                                    ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-4 py-2 text-xs text-gray-900 whitespace-nowrap"><?php echo htmlspecialchars($cdr['date'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-900 font-mono"><?php echo htmlspecialchars($cdr['callerid'] ?? $cdr['caller_id'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-900 font-mono"><?php echo htmlspecialchars($cdr['destination'] ?? ''); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-700"><?php echo $mins . 'm ' . $secs . 's'; ?></td>
                                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-[10px] font-medium <?php echo $disp_class; ?>"><?php echo htmlspecialchars(ucfirst($cdr['disposition'] ?? '')); ?></span></td>
                                        <td class="px-4 py-2 text-xs text-gray-700">$<?php echo number_format(floatval($cdr['total'] ?? $cdr['cost'] ?? 0), 4); ?></td>
                                        <td class="px-4 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($cdr['account'] ?? ''); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($cdr_records) > 100): ?>
                            <div class="px-6 py-3 text-center text-xs text-gray-500 border-t border-gray-100">
                                Showing first 100 of <?php echo count($cdr_records); ?> records. Narrow your date range for full results.
                            </div>
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
                                        <td class="px-4 py-3">
                                            <span class="w-2.5 h-2.5 rounded-full bg-green-500 inline-block mr-1"></span>
                                            <span class="text-xs text-green-600 font-medium">Online</span>
                                        </td>
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
                        <?php if ($voip_username_set): ?>
                            <p class="text-sm font-medium text-gray-900"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs"><?php echo htmlspecialchars($voip_username); ?></code></p>
                        <?php else: ?>
                            <p class="text-sm text-gray-500">Not configured</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cog text-gray-500 mr-2"></i>Environment Variables</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between py-3 border-b border-gray-100">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">VOIP_USERNAME</p>
                                    <p class="text-xs text-gray-500">Your VoIP.ms account email (Set in Replit Secrets)</p>
                                </div>
                                <?php if ($voip_username_set): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-voip-username"><i class="fas fa-check mr-1"></i>Set</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-voip-username"><i class="fas fa-times mr-1"></i>Not Set</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-gray-100">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">VOIP_PASSWORD</p>
                                    <p class="text-xs text-gray-500">API password from VoIP.ms SOAP/REST API settings</p>
                                </div>
                                <?php if ($voip_password_set): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-voip-password"><i class="fas fa-check mr-1"></i>Set</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-voip-password"><i class="fas fa-times mr-1"></i>Not Set</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-gray-100">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">VOIP_TOKEN</p>
                                    <p class="text-xs text-gray-500">Alternative: API token (used if password not set)</p>
                                </div>
                                <?php if ($voip_token_set): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-voip-token"><i class="fas fa-check mr-1"></i>Set</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-voip-token"><i class="fas fa-times mr-1"></i>Not Set</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between py-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">VOIP_API_URL</p>
                                    <p class="text-xs text-gray-500">VoIP.ms REST API endpoint (default)</p>
                                </div>
                                <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium" data-testid="env-voip-url"><?php echo htmlspecialchars($voip_url); ?></span>
                            </div>
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
                        <p><strong>4.</strong> Add your server's IP address to the allowed IPs list (or use 0.0.0.0/0 for development)</p>
                        <p><strong>5.</strong> Set the following secrets in Replit:</p>
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
</body>
</html>