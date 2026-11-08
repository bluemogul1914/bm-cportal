<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}
$pdo = getDB();

$contabo_cred = null;
try {
    $stmt = $pdo->prepare("SELECT id, label, username, category, notes, url, created_at FROM credentials WHERE label = 'Contabo API' LIMIT 1");
    $stmt->execute();
    $contabo_cred = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$connected = $contabo_cred !== false && $contabo_cred !== null;

include 'includes/admin-header.php';
?>
<div class="p-6">
    <h1 class="text-2xl font-bold text-white mb-2">Contabo Cloud</h1>
    <p class="text-blue-mogul-dim mb-6">Contabo VPS and cloud hosting management</p>

    <div class="bg-blue-mogul-surface rounded-lg border border-blue-mogul-dim/30 p-6 mb-6">
        <div class="flex items-center space-x-4 mb-4">
            <div id="status-dot" class="w-3 h-3 rounded-full bg-gray-500"></div>
            <span class="text-white font-medium">Status: <span id="status-text">Checking...</span></span>
        </div>
        <?php if ($connected): ?>
            <p class="text-sm text-blue-mogul-dim">Client ID: <?php echo htmlspecialchars($contabo_cred['username']); ?></p>
            <p class="text-sm text-blue-mogul-dim">Credentials stored in vault (ID: <?php echo $contabo_cred['id']; ?>)</p>
            <p class="text-sm text-blue-mogul-dim mt-2">API Docs: <a href="https://api.contabo.com/#section/Introduction/Getting-Started" class="text-blue-mogul-primary hover:underline" target="_blank">Contabo API</a></p>
        <?php else: ?>
            <p class="text-sm text-blue-mogul-dim">No Contabo credentials found. Add them in <a href="admin-credentials.php" class="text-blue-mogul-primary hover:underline">Credentials</a>.</p>
        <?php endif; ?>
    </div>

    <div class="bg-blue-mogul-surface rounded-lg border border-blue-mogul-dim/30 p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-white">VPS Instances</h2>
            <button onclick="loadInstances()" class="text-sm text-blue-mogul-primary hover:text-blue-mogul-blue-bright font-medium">Refresh</button>
        </div>
        <div id="instances-container">
            <p class="text-sm text-blue-mogul-dim py-6 text-center">Loading instances...</p>
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
            dot.className = 'w-3 h-3 rounded-full bg-blue-mogul-green';
            text.textContent = 'Connected';
        } else {
            dot.className = 'w-3 h-3 rounded-full bg-blue-mogul-red';
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
            container.innerHTML = '<p class="text-sm text-blue-mogul-red py-6 text-center">' + data.error + '</p>';
            return;
        }
        if (!data.instances || data.instances.length === 0) {
            container.innerHTML = '<p class="text-sm text-blue-mogul-dim py-6 text-center">No instances found.</p>';
            return;
        }
        let html = '<div class="space-y-3">';
        for (const inst of data.instances) {
            html += '<div class="flex items-center justify-between p-4 bg-blue-mogul-secondary rounded-lg border border-blue-mogul-dim/30">';
            html += '<div><p class="font-medium text-white">' + (inst.displayName || inst.name || 'Instance ' + inst.instanceId) + '</p>';
            html += '<p class="text-xs text-blue-mogul-dim">ID: ' + (inst.instanceId || 'N/A') + ' | Status: ' + (inst.status || 'N/A') + '</p></div>';
            html += '<span class="text-sm text-blue-mogul-dim">' + (inst.ipConfig?.v4?.ip || '') + '</span>';
            html += '</div>';
        }
        html += '</div>';
        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = '<p class="text-sm text-blue-mogul-red py-6 text-center">Error: ' + e.message + '</p>';
    }
}

checkStatus();
loadInstances();
</script>
<?php include 'includes/admin-footer.php'; ?>
