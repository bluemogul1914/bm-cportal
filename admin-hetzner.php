<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();

// Save API token
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_token') {
    require_csrf();
    $token = trim($_POST['hetzner_api_token'] ?? '');
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('hetzner_api_token', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value");
    $stmt->execute([$token]);
    portal_redirect('admin-hetzner.php?saved=1');
}

$token = '';
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='hetzner_api_token' LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $token = $row['setting_value'] ?? '';
} catch (PDOException $e) {}

// Test connection (only if token present)
$test_status = null; $test_error = null;
if ($token && ($_GET['test'] ?? '') === '1') {
    $ch = curl_init('https://api.hetzner.cloud/v1/servers?per_page=5');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_HTTPHEADER=>["Authorization: Bearer $token", "Accept: application/json"]]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($code === 200) {
        $test_status = 'Connected — API returned HTTP 200';
    } else {
        $test_status = "API returned HTTP $code";
        $test_error = $cerr ?: (is_string($resp) ? substr($resp, 0, 300) : '');
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
                <h1 class="text-2xl font-bold text-gray-900">Hetzner</h1>
                <p class="text-gray-500 text-sm mt-1">Hetzner Cloud API integration</p>
            </div>

            <?php if (isset($_GET['saved'])): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">API token saved.</div>
            <?php endif; ?>

            <div class="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <h2 class="text-base font-semibold text-gray-800 mb-4">API Configuration</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="save_token">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hetzner API Token</label>
                        <input type="password" name="hetzner_api_token" value="<?php echo htmlspecialchars($token); ?>" placeholder="Paste your Hetzner API token" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Stored in system_settings (hetzner_api_token).</p>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Save Token</button>
                    <?php if ($token): ?>
                        <a href="admin-hetzner.php?test=1" class="inline-block px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">Test Connection</a>
                    <?php endif; ?>
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
