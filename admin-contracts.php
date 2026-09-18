<?php
/**
 * admin-contracts.php — Service contracts + SLAs (P2 #6).
 * Contract records per client with per-priority SLA terms (response/resolution hours).
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$success_message = '';
$error_message = '';

$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_contract') {
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) { $error_message = 'Contract name is required.'; }
            else {
                $pdo->prepare("INSERT INTO service_contracts (client_id, name, start_date, end_date, status, monthly_value, notes) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        (int)($_POST['client_id'] ?? 0) ?: null,
                        $name,
                        trim($_POST['start_date'] ?? '') ?: null,
                        trim($_POST['end_date'] ?? '') ?: null,
                        trim($_POST['status'] ?? 'active') ?: 'active',
                        (float)($_POST['monthly_value'] ?? 0),
                        trim($_POST['notes'] ?? '') ?: null,
                    ]);
                $success_message = 'Contract created.';
            }
        } elseif ($action === 'update_contract') {
            $id = (int)($_POST['contract_id'] ?? 0);
            $pdo->prepare("UPDATE service_contracts SET client_id=?, name=?, start_date=?, end_date=?, status=?, monthly_value=?, notes=? WHERE id=?")
                ->execute([
                    (int)($_POST['client_id'] ?? 0) ?: null,
                    trim($_POST['name'] ?? ''),
                    trim($_POST['start_date'] ?? '') ?: null,
                    trim($_POST['end_date'] ?? '') ?: null,
                    trim($_POST['status'] ?? 'active') ?: 'active',
                    (float)($_POST['monthly_value'] ?? 0),
                    trim($_POST['notes'] ?? '') ?: null,
                    $id,
                ]);
            $success_message = 'Contract updated.';
        } elseif ($action === 'delete_contract') {
            $pdo->prepare("DELETE FROM service_contracts WHERE id = ?")->execute([(int)($_POST['contract_id'] ?? 0)]);
            $success_message = 'Contract deleted.';
        } elseif ($action === 'save_sla') {
            $contract_id = (int)($_POST['contract_id'] ?? 0);
            $pdo->prepare("DELETE FROM sla_terms WHERE contract_id = ?")->execute([$contract_id]);
            $priorities = ['low', 'medium', 'high', 'critical'];
            $st = $pdo->prepare("INSERT INTO sla_terms (contract_id, priority, response_hours, resolution_hours) VALUES (?, ?, ?, ?)");
            foreach ($priorities as $p) {
                $resp = (float)($_POST['resp_' . $p] ?? 0);
                $resol = (float)($_POST['resol_' . $p] ?? 0);
                if ($resp > 0 || $resol > 0) $st->execute([$contract_id, $p, $resp, $resol]);
            }
            $success_message = 'SLA terms saved.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// ── Data ────────────────────────────────────────────────────────────────
$contracts = $pdo->query("SELECT sc.*, c.name AS client_name FROM service_contracts sc
    LEFT JOIN clients c ON sc.client_id = c.id ORDER BY sc.status ASC, sc.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// SLA terms keyed by contract_id
$sla_map = [];
foreach ($pdo->query("SELECT * FROM sla_terms")->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $sla_map[$s['contract_id']][$s['priority']] = $s;
}
$priorities = ['low', 'medium', 'high', 'critical'];

function fmt_money($m) { return '$' . number_format((float)$m, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Contracts — Blue Mogul</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/tailwind-shared.js"></script>
    <script>tailwind.config = window.bmTailwindConfig;</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-file-signature text-blue-500 mr-2"></i>Service Contracts</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Contracts &amp; SLA terms per client</p>
                </div>
                <button onclick="document.getElementById('contractModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition"><i class="fas fa-plus mr-2"></i>New Contract</button>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Contracts</p><p class="text-3xl font-bold text-gray-900"><?php echo count($contracts); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active</p><p class="text-3xl font-bold text-green-600"><?php echo count(array_filter($contracts, fn($c) => $c['status']==='active')); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Monthly Value</p><p class="text-3xl font-bold text-blue-600"><?php echo fmt_money(array_sum(array_map(fn($c) => (float)$c['monthly_value'], $contracts))); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">With SLA</p><p class="text-3xl font-bold text-gray-600"><?php echo count($sla_map); ?></p></div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Contracts</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Contract</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Term</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Monthly</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SLA</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($contracts)): ?>
                                <tr><td colspan="7" class="px-6 py-12 text-center text-gray-500">No contracts yet. Create one to get started.</td></tr>
                            <?php else: foreach ($contracts as $c): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($c['client_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($c['name']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo $c['start_date'] ? date('M j, Y', strtotime($c['start_date'])) : '—'; ?><?php echo $c['end_date'] ? ' → ' . date('M j, Y', strtotime($c['end_date'])) : ''; ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_money($c['monthly_value']); ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo $c['status']==='active'?'bg-green-100 text-green-700':($c['status']==='expired'?'bg-gray-200 text-gray-600':'bg-yellow-100 text-yellow-700'); ?>"><?php echo ucfirst($c['status']); ?></span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo isset($sla_map[$c['id']]) ? count($sla_map[$c['id']]) . ' priorities' : '—'; ?></td>
                                    <td class="px-6 py-3 text-sm text-right whitespace-nowrap">
                                        <button onclick="openSla(<?php echo (int)$c['id']; ?>)" class="text-blue-600 hover:underline text-xs mr-3">SLA</button>
                                        <button onclick="openEdit(<?php echo (int)$c['id']; ?>)" class="text-gray-500 hover:text-gray-800 text-xs mr-3">Edit</button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this contract?');">
                                            <input type="hidden" name="action" value="delete_contract">
                                            <input type="hidden" name="contract_id" value="<?php echo (int)$c['id']; ?>">
                                            <button class="text-red-500 hover:text-red-700 text-xs">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Contract modal -->
<div id="contractModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">New Contract</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_contract">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">— No client —</option>
                    <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Contract name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. Managed IT — 12 month">
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start date</label>
                    <input type="date" name="start_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End date</label>
                    <input type="date" name="end_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Monthly value ($)</label>
                    <input type="number" step="0.01" name="monthly_value" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('contractModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Contract modal -->
<div id="editModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Edit Contract</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_contract">
            <input type="hidden" name="contract_id" id="edit_id">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" id="edit_client" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">— No client —</option>
                    <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Contract name</label>
                <input type="text" name="name" id="edit_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Start</label><input type="date" name="start_date" id="edit_start" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">End</label><input type="date" name="end_date" id="edit_end" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></div>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Monthly ($)</label><input type="number" step="0.01" name="monthly_value" id="edit_value" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Status</label><select name="status" id="edit_status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"><option value="active">Active</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select></div>
            </div>
            <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">Notes</label><textarea name="notes" id="edit_notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea></div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- SLA modal -->
<div id="slaModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-1">SLA Terms</h3>
        <p class="text-sm text-gray-500 mb-4">Response &amp; resolution hours by priority. Tickets auto-set their SLA due time from these.</p>
        <form method="POST">
            <input type="hidden" name="action" value="save_sla">
            <input type="hidden" name="contract_id" id="sla_contract_id">
            <table class="w-full mb-4">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Priority</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Response (hrs)</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Resolution (hrs)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($priorities as $p): ?>
                    <tr>
                        <td class="px-3 py-2 text-sm text-gray-700 capitalize"><?php echo $p; ?></td>
                        <td class="px-3 py-2"><input type="number" step="0.5" name="resp_<?php echo $p; ?>" id="resp_<?php echo $p; ?>" class="w-full px-2 py-1 border border-gray-300 rounded-md text-sm"></td>
                        <td class="px-3 py-2"><input type="number" step="0.5" name="resol_<?php echo $p; ?>" id="resol_<?php echo $p; ?>" class="w-full px-2 py-1 border border-gray-300 rounded-md text-sm"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('slaModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Save SLA</button>
            </div>
        </form>
    </div>
</div>

<script>
const contracts = <?php echo json_encode($contracts); ?>;
const slaMap = <?php echo json_encode($sla_map); ?>;
function openEdit(id) {
    const c = contracts.find(x => x.id === id);
    if (!c) return;
    document.getElementById('edit_id').value = c.id;
    document.getElementById('edit_client').value = c.client_id || '';
    document.getElementById('edit_name').value = c.name;
    document.getElementById('edit_start').value = c.start_date || '';
    document.getElementById('edit_end').value = c.end_date || '';
    document.getElementById('edit_value').value = c.monthly_value;
    document.getElementById('edit_status').value = c.status;
    document.getElementById('edit_notes').value = c.notes || '';
    document.getElementById('editModal').classList.remove('hidden');
}
function openSla(id) {
    document.getElementById('sla_contract_id').value = id;
    const terms = slaMap[id] || {};
    ['low','medium','high','critical'].forEach(p => {
        document.getElementById('resp_' + p).value = terms[p] ? terms[p].response_hours : '';
        document.getElementById('resol_' + p).value = terms[p] ? terms[p].resolution_hours : '';
    });
    document.getElementById('slaModal').classList.remove('hidden');
}
</script>
</body>
</html>
