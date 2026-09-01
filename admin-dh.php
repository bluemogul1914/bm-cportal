<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_creds') {
    require_csrf();
    $fields = ['dh_client_id' => trim($_POST['dh_client_id'] ?? ''),
               'dh_client_secret' => trim($_POST['dh_client_secret'] ?? ''),
               'dh_account' => trim($_POST['dh_account'] ?? '3054540000')];
    foreach ($fields as $k => $v) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value");
        $stmt->execute([$k, $v]);
    }
    portal_redirect('admin-dh.php?saved=1');
}

$creds = ['dh_client_id'=>'','dh_client_secret'=>'','dh_account'=>'3054540000'];
foreach (array_keys($creds) as $k) {
    try {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='$k' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $creds[$k] = $row['setting_value'];
    } catch (PDOException $e) {}
}

// Test connection: OAuth client-credentials token, then GET /customers/{acct}/carriers
$test_status = null; $test_error = null;
if ($creds['dh_client_id'] && $creds['dh_client_secret'] && ($_GET['test'] ?? '') === '1') {
    // 1) token
    $tokBody = http_build_query(['grant_type'=>'client_credentials','client_id'=>$creds['dh_client_id'],'client_secret'=>$creds['dh_client_secret']]);
    $ch = curl_init('https://auth.dandh.com/api/oauth/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$tokBody]);
    $tresp = curl_exec($ch); $tcode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $tok = json_decode($tresp, true);
    if ($tcode === 200 && !empty($tok['access_token'])) {
        // 2) carriers (auth check only; no item needed)
        $ch = curl_init('https://api.dandh.com/customerOrderManagement/v2/customers/' . rawurlencode($creds['dh_account']) . '/carriers');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $tok['access_token'], 'Accept: application/json']]);
        $cresp = curl_exec($ch); $ccode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $test_status = "Token OK + carriers API HTTP $ccode";
        if ($ccode !== 200) $test_error = substr((string)$cresp, 0, 300);
    } else {
        $test_status = "Token request failed (HTTP $tcode)";
        $test_error = substr((string)$tresp, 0, 300);
    }
}

include 'includes/admin-header.php';
?>
<div class="flex h-screen bg-gray-50">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/admin-topbar.php'; ?>
        <main class="flex-1 overflow-y-auto p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">D&amp;H API</h1>
                <p class="text-gray-500 text-sm mt-1">Customer Order Management REST API (api.dandh.com)</p>
            </div>

            <?php if (isset($_GET['saved'])): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">Credentials saved.</div>
            <?php endif; ?>

            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <h2 class="text-base font-semibold text-gray-800 mb-4">API Credentials</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="save_creds">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client ID</label>
                        <input type="text" name="dh_client_id" value="<?php echo htmlspecialchars($creds['dh_client_id']); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client Secret</label>
                        <input type="password" name="dh_client_secret" value="<?php echo htmlspecialchars($creds['dh_client_secret']); ?>" placeholder="Paste your D&H client secret" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Account Number</label>
                        <input type="text" name="dh_account" value="<?php echo htmlspecialchars($creds['dh_account']); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                        <p class="text-xs text-gray-400 mt-1">On file: Blue Mogul Enterprise, LLC — 3054540000.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Save Credentials</button>
                        <?php if ($creds['dh_client_id'] && $creds['dh_client_secret']): ?>
                            <a href="admin-dh.php?test=1" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">Test Connection</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($test_status !== null): ?>
                    <div class="mt-4 p-4 rounded-lg text-sm <?php echo $test_error === null ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                        <strong><?php echo htmlspecialchars($test_status); ?></strong>
                        <?php if ($test_error): ?><pre class="mt-2 text-xs whitespace-pre-wrap"><?php echo htmlspecialchars($test_error); ?></pre><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
<?php include 'includes/admin-footer.php'; ?>