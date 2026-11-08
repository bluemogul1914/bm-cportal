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
                        <p class="text-sm text-gray-600">Client ID: INT-15467787</p>
                        <p class="text-sm text-gray-600">Credentials stored in vault (ID: <?php echo $contabo_cred['id']; ?>)</p>
                        <p class="text-sm text-gray-600 mt-2">API Docs: <a href="https://api.contabo.com/#section/Introduction/Getting-Started" class="text-blue-600 hover:underline" target="_blank">Contabo API</a></p>
                    <?php else: ?>
                        <p class="text-sm text-gray-600">No Contabo credentials found. Add them in <a href="admin-credentials.php" class="text-blue-600 hover:underline">Credentials</a>.</p>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">Create New Instance</h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Instance Name</label>
                            <input type="text" id="instance-name" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="my-server">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Region</label>
                            <select id="instance-region" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                                <option value="EU">EU (Europe)</option>
                                <option value="US-central">US Central</option>
                                <option value="US-east">US East</option>
                                <option value="US-west">US West</option>
                                <option value="SIN">Singapore</option>
                                <option value="JPN">Japan</option>
                                <option value="AUS">Australia</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Product (VPS Plan)</label>
                            <select id="instance-product" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                                <option value="">Loading products...</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Image / 1-Click App</label>
                        <select id="instance-image" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            <option value="">Loading images...</option>
                        </select>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Assign to Client (optional)</label>
                        <select id="instance-client" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            <option value="">No client (unassigned)</option>
                        </select>
                    </div>
                    <div class="mt-4">
                        <button onclick="createInstance()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">Create Instance</button>
                        <span id="create-status" class="ml-3 text-sm text-gray-500"></span>
                    </div>
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
        const res = await fetch('/portal/api/admin/contabo/instances/db');
        const data = await res.json();
        if (data.error) {
            container.innerHTML = '<p class="text-sm text-red-600 py-6 text-center">' + data.error + '</p>';
            return;
        }
        if (!data.instances || data.instances.length === 0) {
            container.innerHTML = '<p class="text-sm text-blue-mogul-dim py-6 text-center">No instances tracked yet. Create one above or add manually.</p>';
            return;
        }
        let html = '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="border-b border-gray-200">';
        html += '<th class="text-left py-2 px-3 font-medium text-gray-700">Name</th>';
        html += '<th class="text-left py-2 px-3 font-medium text-gray-700">Status</th>';
        html += '<th class="text-left py-2 px-3 font-medium text-gray-700">IP</th>';
        html += '<th class="text-left py-2 px-3 font-medium text-gray-700">App</th>';
        html += '<th class="text-left py-2 px-3 font-medium text-gray-700">Client</th>';
        html += '<th class="text-left py-2 px-3 font-medium text-gray-700">Cost</th>';
        html += '<th class="text-left py-2 px-3 font-medium text-gray-700">Actions</th>';
        html += '</tr></thead><tbody>';
        for (const inst of data.instances) {
            html += '<tr class="border-b border-gray-100 hover:bg-gray-50">';
            html += '<td class="py-2 px-3 font-medium text-gray-900">' + (inst.name || 'Unnamed') + '</td>';
            html += '<td class="py-2 px-3"><span class="px-2 py-0.5 rounded text-xs ' + (inst.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700') + '">' + (inst.status || 'N/A') + '</span></td>';
            html += '<td class="py-2 px-3 text-gray-600">' + (inst.ip_address || '-') + '</td>';
            html += '<td class="py-2 px-3 text-gray-600">' + (inst.app_type || 'os') + '</td>';
            html += '<td class="py-2 px-3 text-gray-600">' + (inst.client_name || 'Unassigned') + '</td>';
            html += '<td class="py-2 px-3 text-gray-600">' + (inst.monthly_cost ? '$' + inst.monthly_cost + '/mo' : '-') + '</td>';
            html += '<td class="py-2 px-3 space-x-1">';
            html += '<button onclick="installApp(' + inst.id + ')" class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">Install</button>';
            html += '<button onclick="assignClient(' + inst.id + ')" class="px-2 py-1 bg-gray-600 text-white rounded text-xs hover:bg-gray-700">Assign</button>';
            html += '</td>';
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = '<p class="text-sm text-red-600 py-6 text-center">Error: ' + e.message + '</p>';
    }
}

async function installApp(id) {
    const appType = prompt('App to install: nextcloud, n8n, hermes, deepseek', 'nextcloud');
    if (!appType) return;
    const status = document.getElementById('create-status');
    status.textContent = 'Installing ' + appType + '... (may take several minutes)';
    status.className = 'ml-3 text-sm text-gray-500';
    try {
        const res = await fetch('/portal/api/admin/contabo/instances/' + id + '/install', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ appType: appType })
        });
        const data = await res.json();
        if (data.error) {
            status.textContent = 'Install error: ' + data.error;
            status.className = 'ml-3 text-sm text-red-600';
        } else {
            status.textContent = appType + ' installed successfully!';
            status.className = 'ml-3 text-sm text-green-600';
            loadInstances();
        }
    } catch (e) {
        status.textContent = 'Error: ' + e.message;
        status.className = 'ml-3 text-sm text-red-600';
    }
}

async function assignClient(id) {
    const clientId = prompt('Enter client ID to assign (or empty to unassign):');
    if (clientId === null) return;
    try {
        await fetch('/portal/api/admin/contabo/instances/' + id + '/assign', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ clientId: clientId || null })
        });
        loadInstances();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function createInstance() {
    const status = document.getElementById('create-status');
    status.textContent = 'Creating...';
    status.className = 'ml-3 text-sm text-gray-500';
    try {
        const res = await fetch('/portal/api/admin/contabo/instances/create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                displayName: document.getElementById('instance-name').value,
                region: document.getElementById('instance-region').value,
                productId: document.getElementById('instance-product').value,
                imageId: document.getElementById('instance-image').value,
                clientId: document.getElementById('instance-client').value || null,
            })
        });
        const data = await res.json();
        if (data.error) {
            status.textContent = 'Error: ' + data.error;
            status.className = 'ml-3 text-sm text-red-600';
        } else {
            // Save to portal DB
            const inst = data.instance || data;
            await fetch('/portal/api/admin/contabo/instances/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contaboInstanceId: inst.instanceId || inst.id || '',
                    name: inst.displayName || document.getElementById('instance-name').value,
                    region: document.getElementById('instance-region').value,
                    productId: document.getElementById('instance-product').value,
                    imageId: document.getElementById('instance-image').value,
                    imageName: document.getElementById('instance-image').selectedOptions[0]?.text || '',
                    appType: 'os',
                    status: 'provisioning',
                    clientId: document.getElementById('instance-client').value || null,
                    monthlyCost: null,
                })
            });
            status.textContent = 'Instance created & saved!';
            status.className = 'ml-3 text-sm text-green-600';
            loadInstances();
        }
    } catch (e) {
        status.textContent = 'Error: ' + e.message;
        status.className = 'ml-3 text-sm text-red-600';
    }
}

async function loadClients() {
    const select = document.getElementById('instance-client');
    try {
        const res = await fetch('/portal/api/admin/clients/list');
        const data = await res.json();
        if (data.error || !data.clients) return;
        let html = '<option value="">No client (unassigned)</option>';
        for (const c of data.clients) {
            html += '<option value="' + c.id + '">' + c.name + ' (' + (c.email || '') + ')</option>';
        }
        select.innerHTML = html;
    } catch (e) {}
}

async function loadImages() {
    const select = document.getElementById('instance-image');
    try {
        const res = await fetch('/portal/api/admin/contabo/images');
        const data = await res.json();
        if (data.error) {
            select.innerHTML = '<option value="">Error: ' + data.error + '</option>';
            return;
        }
        if (!data.images || data.images.length === 0) {
            select.innerHTML = '<option value="">No images available</option>';
            return;
        }
        let html = '<option value="">Select an image or 1-Click App...</option>';
        for (const img of data.images) {
            const name = img.name || img.description || img.imageId || 'Unknown';
            const id = img.imageId || img.id || '';
            html += '<option value="' + id + '">' + name + '</option>';
        }
        select.innerHTML = html;
    } catch (e) {
        select.innerHTML = '<option value="">Error: ' + e.message + '</option>';
    }
}

async function loadProducts() {
    const select = document.getElementById('instance-product');
    try {
        const res = await fetch('/portal/api/admin/contabo/products');
        const data = await res.json();
        if (data.error || !data.products || data.products.length === 0) {
            select.innerHTML = '<option value="">Error: ' + (data.error || 'no products') + '</option>';
            return;
        }
        let html = '<option value="">Select a VPS plan...</option>';
        for (const p of data.products) {
            const price = p.priceUsd ? ' - $' + p.priceUsd + '/mo' : '';
            html += '<option value="' + p.productId + '">' + p.productId + ' - ' + p.name + price + '</option>';
        }
        select.innerHTML = html;
    } catch (e) {
        select.innerHTML = '<option value="">Error: ' + e.message + '</option>';
    }
}

checkStatus();
loadInstances();
loadImages();
loadProducts();
loadClients();
</script>
<?php include 'includes/admin-footer.php'; ?>
