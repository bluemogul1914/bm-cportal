<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$pdo = getDB();
$success_message = '';
$error_message = '';

// Handle form submissions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        $product_id = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $interval_days = (int)($_POST['interval_days'] ?? 30);
        $next_run_date = $_POST['next_run_date'] ?? date('Y-m-d');
        $invoice_due_days = (int)($_POST['invoice_due_days'] ?? 30);
        $status = $_POST['status'] ?? 'active';

        if (!$client_id || !$name) {
            $error_message = "Client and name are required.";
        } else {
            try {
                if ($action === 'create') {
                    $stmt = $pdo->prepare("INSERT INTO recurring_invoice_configs (client_id, product_id, name, description, interval_days, next_run_date, invoice_due_days, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$client_id, $product_id, $name, $description, $interval_days, $next_run_date, $invoice_due_days, $status]);
                    $success_message = "Recurring invoice schedule created!";
                } else {
                    $stmt = $pdo->prepare("UPDATE recurring_invoice_configs SET client_id=?, product_id=?, name=?, description=?, interval_days=?, next_run_date=?, invoice_due_days=?, status=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$client_id, $product_id, $name, $description, $interval_days, $next_run_date, $invoice_due_days, $status, $id]);
                    $success_message = "Recurring invoice schedule updated!";
                }
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("UPDATE recurring_invoice_configs SET status='archived', updated_at=NOW() WHERE id=?")->execute([$id]);
            $success_message = "Schedule archived.";
        } catch (PDOException $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'generate_now') {
        $id = (int)($_POST['id'] ?? 0);
        // Call the API endpoint internally
        $api_url = rtrim(APP_URL, '/') . "/api/recurring-configs/$id/generate-now";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($resp, true);
        if ($http >= 200 && $http < 300 && isset($data['invoice'])) {
            $success_message = "Invoice #{$data['invoice']['invoice_number']} generated!";
        } else {
            $error_message = "Generation failed: " . ($data['error'] ?? 'Unknown error');
        }
    }
}

// Fetch clients for dropdown
$clients = $pdo->query("SELECT id, name, company FROM clients WHERE status='active' OR status IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch products for dropdown
$products = $pdo->query("SELECT id, name, price FROM products WHERE active=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recurring configs
$configs = $pdo->query("
    SELECT rc.*, c.name AS client_name, c.company AS client_company, p.name AS product_name, p.price AS product_price
    FROM recurring_invoice_configs rc
    LEFT JOIN clients c ON rc.client_id = c.id
    LEFT JOIN products p ON rc.product_id = p.id
    ORDER BY rc.next_run_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/admin-header.php';
require_once 'includes/admin-sidebar.php';
require_once 'includes/admin-topbar.php';
?>

<div class="p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-white">Recurring Invoices</h1>
        <button onclick="openModal('create')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center space-x-2 transition">
            <i class="fas fa-plus"></i>
            <span>Add Schedule</span>
        </button>
    </div>

    <?php if ($success_message): ?>
    <div class="bg-green-900/40 border border-green-700 text-green-300 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="bg-red-900/40 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-900/50">
                    <tr>
                        <th class="text-left px-4 py-3 text-gray-400 font-medium">Client</th>
                        <th class="text-left px-4 py-3 text-gray-400 font-medium">Name</th>
                        <th class="text-left px-4 py-3 text-gray-400 font-medium">Product</th>
                        <th class="text-center px-4 py-3 text-gray-400 font-medium">Interval (Days)</th>
                        <th class="text-center px-4 py-3 text-gray-400 font-medium">Next Run</th>
                        <th class="text-center px-4 py-3 text-gray-400 font-medium">Last Run</th>
                        <th class="text-center px-4 py-3 text-gray-400 font-medium">Due Days</th>
                        <th class="text-center px-4 py-3 text-gray-400 font-medium">Status</th>
                        <th class="text-right px-4 py-3 text-gray-400 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    <?php if (empty($configs)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-500">No recurring invoice schedules yet. Click "Add Schedule" to create one.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($configs as $c): ?>
                    <tr class="hover:bg-gray-700/50 transition">
                        <td class="px-4 py-3">
                            <div class="text-white"><?= htmlspecialchars($c['client_name'] ?: 'Unknown') ?></div>
                            <?php if ($c['client_company']): ?>
                            <div class="text-xs text-gray-400"><?= htmlspecialchars($c['client_company']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-white font-medium"><?= htmlspecialchars($c['name']) ?></td>
                        <td class="px-4 py-3 text-gray-300">
                            <?php if ($c['product_name']): ?>
                                <?= htmlspecialchars($c['product_name']) ?>
                                <span class="text-xs text-gray-500">($<?= number_format((float)$c['product_price'], 2) ?>)</span>
                            <?php else: ?>
                                <span class="text-gray-500">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-white"><?= (int)$c['interval_days'] ?>d</td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($c['next_run_date'] && $c['next_run_date'] <= date('Y-m-d')): ?>
                            <span class="text-amber-400 font-medium"><?= htmlspecialchars(fmt_date($c['next_run_date'] ?? null, 'M j, Y')) ?></span>
                            <?php else: ?>
                            <span class="text-gray-300"><?= htmlspecialchars(fmt_date($c['next_run_date'] ?? null, 'M j, Y')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-400"><?= $c['last_run_date'] ? htmlspecialchars(fmt_date($c['last_run_date'] ?? null, 'M j, Y')) : '—' ?></td>
                        <td class="px-4 py-3 text-center text-gray-300"><?= (int)$c['invoice_due_days'] ?>d</td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($c['status'] === 'active'): ?>
                            <span class="bg-green-900/40 text-green-400 text-xs px-2 py-1 rounded-full">Active</span>
                            <?php elseif ($c['status'] === 'paused'): ?>
                            <span class="bg-yellow-900/40 text-yellow-400 text-xs px-2 py-1 rounded-full">Paused</span>
                            <?php else: ?>
                            <span class="bg-gray-700 text-gray-400 text-xs px-2 py-1 rounded-full"><?= htmlspecialchars(ucfirst($c['status'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end space-x-2">
                                <?php if ($c['status'] === 'active'): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Generate invoice now for <?= htmlspecialchars(addslashes($c['name'])) ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                    <input type="hidden" name="action" value="generate_now">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="text-green-400 hover:text-green-300 transition p-1" title="Generate Invoice Now">
                                        <i class="fas fa-file-invoice"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <button onclick="openModal('edit', <?= htmlspecialchars(json_encode($c)) ?>)" class="text-blue-400 hover:text-blue-300 transition p-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($c['status'] === 'active'): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Archive this schedule?')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="text-red-400 hover:text-red-300 transition p-1" title="Archive">
                                        <i class="fas fa-archive"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="configModal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-xl border border-gray-700 w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between p-5 border-b border-gray-700">
            <h2 id="modalTitle" class="text-lg font-semibold text-white">Add Schedule</h2>
            <button onclick="closeModal()" class="text-gray-400 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="0">

            <div>
                <label class="block text-sm text-gray-400 mb-1">Client *</label>
                <select name="client_id" id="formClientId" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Select client...</option>
                    <?php foreach ($clients as $cl): ?>
                    <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name'] ?: $cl['company']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">Schedule Name *</label>
                <input type="text" name="name" id="formName" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Monthly Managed Services">
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">Product (Optional)</label>
                <select name="product_id" id="formProductId" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">No product linked</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> ($<?= number_format((float)$p['price'], 2) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">Description</label>
                <textarea name="description" id="formDescription" rows="2" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Monthly managed IT services invoice"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Interval (Days) *</label>
                    <input type="number" name="interval_days" id="formIntervalDays" value="30" min="1" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Invoice Due (Days)</label>
                    <input type="number" name="invoice_due_days" id="formInvoiceDueDays" value="30" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-400 mb-1">Next Run Date *</label>
                <input type="date" name="next_run_date" id="formNextRunDate" value="<?= date('Y-m-d') ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div id="statusField" class="hidden">
                <label class="block text-sm text-gray-400 mb-1">Status</label>
                <select name="status" id="formStatus" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="archived">Archived</option>
                </select>
            </div>

            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-gray-300 rounded-lg transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(action, data) {
    document.getElementById('configModal').classList.remove('hidden');
    if (action === 'create') {
        document.getElementById('modalTitle').textContent = 'Add Schedule';
        document.getElementById('formAction').value = 'create';
        document.getElementById('formId').value = '0';
        document.getElementById('formClientId').value = '';
        document.getElementById('formName').value = '';
        document.getElementById('formProductId').value = '';
        document.getElementById('formDescription').value = '';
        document.getElementById('formIntervalDays').value = '30';
        document.getElementById('formInvoiceDueDays').value = '30';
        document.getElementById('formNextRunDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('statusField').classList.add('hidden');
    } else if (action === 'edit' && data) {
        document.getElementById('modalTitle').textContent = 'Edit Schedule';
        document.getElementById('formAction').value = 'update';
        document.getElementById('formId').value = data.id;
        document.getElementById('formClientId').value = data.client_id;
        document.getElementById('formName').value = data.name;
        document.getElementById('formProductId').value = data.product_id || '';
        document.getElementById('formDescription').value = data.description || '';
        document.getElementById('formIntervalDays').value = data.interval_days;
        document.getElementById('formInvoiceDueDays').value = data.invoice_due_days || 30;
        document.getElementById('formNextRunDate').value = data.next_run_date;
        document.getElementById('formStatus').value = data.status || 'active';
        document.getElementById('statusField').classList.remove('hidden');
    }
}

function closeModal() {
    document.getElementById('configModal').classList.add('hidden');
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
