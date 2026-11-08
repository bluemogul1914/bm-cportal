<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();
$user_name = $_SESSION['user_name'] ?? 'Admin';

$contabo_cred = null;
try {
    $stmt = $pdo->prepare("SELECT id, label, username, category, notes, url, created_at FROM credentials WHERE label = 'Contabo API' LIMIT 1");
    $stmt->execute();
    $contabo_cred = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$connected = $contabo_cred !== false && $contabo_cred !== null;

include 'includes/admin-header.php';
?>
    <div class="flex h-screen overflow-hidden">
        <?php include 'includes/admin-sidebar.php'; ?>
        <div class="flex-1 overflow-y-auto">
            <div class="p-6">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Contabo Cloud</h1>
                <p class="text-gray-600 mb-6">Contabo VPS and cloud hosting management</p>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6 shadow-sm">
                    <div class="flex items-center space-x-4 mb-4">
                        <div id="status-dot" class="w-3 h-3 rounded-full bg-gray-400"></div>
                        <span class="text-gray-900 font-medium">Status: <span id="status-text">Checking...</span></span>
                    </div>
                    <?php if ($connected): ?>
                        <p class="text-sm text-gray-600">Client ID: <?php echo htmlspecialchars($contabo_cred['username']); ?></p>
                        <p class="text-sm text-gray-600">Credentials stored in vault (ID: <?php echo $contabo_cred['id']; ?>)</p>
                        <p class="text-sm text-gray-600 mt-2">API Docs: <a href="https://api.contabo.com/#section/Introduction/Getting-Started" class="text-blue-600 hover:underline" target="_blank">Contabo API</a></p>
                    <?php else: ?>
                        <p class="text-sm text-gray-600">No Contabo credentials found. Add them in <a href="admin-credentials.php" class="text-blue-600 hover:underline">Credentials</a>.</p>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">VPS Instances</h2>
                        <button onclick="loadInstances()" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Refresh</button>
                    </div>
                    <div id="instances-container">
                        <p class="text-sm text-gray-500 py-6 text-center">Loading instances...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
async function checkStatus() {
    try {
        const res = await fetch('/portal/api/admin/contabo/test');
        const data = await res.json();
        const dot = document.getElementById('status-dot');
        const text = document.getElementById('status-text');
        if (data.ok) {
            dot.className = 'w-3 h-3 rounded-full bg-green-500';
            text.textContent = 'Connected';
        } else {
            dot.className = 'w-3 h-3 rounded-full bg-red-500';
            text.textContent = data.message || 'Disconnected';
        }
    } catch (e) {
        document.getElementById('status-dot').className = 'w-3 h-3 rounded-full bg-blue-mogul-red';
        document.getElementById('status-text').textContent = 'Error: ' + e.message;
    }
}

async function loadInstances() {
    const container = document.getElementById('instances-container');
    container.innerHTML = '<p class="text-sm text-blue-mogul-dim py-6 text-center">Loading...</p>';
    try {
        const res = await fetch('/portal/api/admin/contabo/instances');
        const data = await res.json();
        if (data.error) {
            container.innerHTML = '<p class="text-sm text-red-600 py-6 text-center">' + data.error + '</p>';
            return;
        }
        if (!data.instances || data.instances.length === 0) {
            container.innerHTML = '<p class="text-sm text-blue-mogul-dim py-6 text-center">No instances found.</p>';
            return;
        }
        let html = '<div class="space-y-3">';
        for (const inst of data.instances) {
            html += '<div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">';
            html += '<div><p class="font-medium text-gray-900">' + (inst.displayName || inst.name || 'Instance ' + inst.instanceId) + '</p>';
            html += '<p class="text-xs text-gray-500">ID: ' + (inst.instanceId || 'N/A') + ' | Status: ' + (inst.status || 'N/A') + '</p></div>';
            html += '<span class="text-sm text-gray-500">' + (inst.ipConfig?.v4?.ip || '') + '</span>';
            html += '</div>';
        }
        html += '</div>';
        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = '<p class="text-sm text-red-600 py-6 text-center">Error: ' + e.message + '</p>';
    }
}

checkStatus();
loadInstances();
</script>
<?php include 'includes/admin-footer.php'; ?>
