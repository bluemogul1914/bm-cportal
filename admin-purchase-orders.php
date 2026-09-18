<?php
/**
 * admin-purchase-orders.php — PO approval flow (P2 #7).
 * Purchase orders with line items and a draft → pending → approved/rejected workflow.
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
        if ($action === 'create_po') {
            $supplier = trim($_POST['supplier'] ?? '');
            if (empty($supplier)) { $error_message = 'Supplier is required.'; }
            else {
                $pdo->prepare("INSERT INTO purchase_orders (client_id, supplier, po_number, total, status, requested_by, notes) VALUES (?, ?, ?, ?, 'pending', ?, ?)")
                    ->execute([
                        (int)($_POST['client_id'] ?? 0) ?: null,
                        $supplier,
                        trim($_POST['po_number'] ?? '') ?: null,
                        (float)($_POST['total'] ?? 0),
                        $user_name,
                        trim($_POST['notes'] ?? '') ?: null,
                    ]);
                $po_id = (int)$pdo->lastInsertId();
                // line items
                $descs = $_POST['item_desc'] ?? [];
                $qtys = $_POST['item_qty'] ?? [];
                $prices = $_POST['item_price'] ?? [];
                $st = $pdo->prepare("INSERT INTO po_line_items (po_id, description, qty, unit_price) VALUES (?, ?, ?, ?)");
                foreach ($descs as $i => $d) {
                    $d = trim($d ?? '');
                    if ($d === '') continue;
                    $st->execute([$po_id, $d, (int)($qtys[$i] ?? 1), (float)($prices[$i] ?? 0)]);
                }
                $success_message = 'Purchase order created and submitted for approval.';
            }
        } elseif ($action === 'approve_po') {
            $id = (int)($_POST['po_id'] ?? 0);
            $pdo->prepare("UPDATE purchase_orders SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$user_name, $id]);
            $success_message = 'Purchase order approved.';
        } elseif ($action === 'reject_po') {
            $id = (int)($_POST['po_id'] ?? 0);
            $pdo->prepare("UPDATE purchase_orders SET status='rejected', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$user_name, $id]);
            $success_message = 'Purchase order rejected.';
        } elseif ($action === 'update_po') {
            $id = (int)($_POST['po_id'] ?? 0);
            $supplier = trim($_POST['supplier'] ?? '');
            if (empty($supplier)) { $error_message = 'Supplier is required.'; }
            else {
                $pdo->prepare("UPDATE purchase_orders SET client_id=?, supplier=?, po_number=?, total=?, notes=? WHERE id=?")
                    ->execute([
                        (int)($_POST['client_id'] ?? 0) ?: null,
                        $supplier,
                        trim($_POST['po_number'] ?? '') ?: null,
                        (float)($_POST['total'] ?? 0),
                        trim($_POST['notes'] ?? '') ?: null,
                        $id,
                    ]);
                $pdo->prepare("DELETE FROM po_line_items WHERE po_id = ?")->execute([$id]);
                $descs = $_POST['item_desc'] ?? [];
                $qtys = $_POST['item_qty'] ?? [];
                $prices = $_POST['item_price'] ?? [];
                $st = $pdo->prepare("INSERT INTO po_line_items (po_id, description, qty, unit_price) VALUES (?, ?, ?, ?)");
                foreach ($descs as $i => $d) {
                    $d = trim($d ?? '');
                    if ($d === '') continue;
                    $st->execute([$id, $d, (int)($qtys[$i] ?? 1), (float)($prices[$i] ?? 0)]);
                }
                $success_message = 'Purchase order updated.';
            }
        } elseif ($action === 'delete_po') {
            $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([(int)($_POST['po_id'] ?? 0)]);
            $success_message = 'Purchase order deleted.';
        }
    } catch (Exception $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// ── Data ────────────────────────────────────────────────────────────────
$pos = $pdo->query("SELECT po.*, c.name AS client_name FROM purchase_orders po
    LEFT JOIN clients c ON po.client_id = c.id ORDER BY po.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// line items keyed by po_id
$items_map = [];
foreach ($pdo->query("SELECT * FROM po_line_items")->fetchAll(PDO::FETCH_ASSOC) as $li) {
    $items_map[$li['po_id']][] = $li;
}

// PO data for the edit modal (JSON, keyed by po id)
$po_data = [];
foreach ($pos as $po) {
    $po_data[(int)$po['id']] = [
        'client_id' => $po['client_id'] !== null ? (int)$po['client_id'] : '',
        'supplier'  => $po['supplier'] ?? '',
        'po_number' => $po['po_number'] ?? '',
        'total'     => (float)$po['total'],
        'notes'     => $po['notes'] ?? '',
        'items'     => array_map(fn($i) => ['description' => $i['description'], 'qty' => (int)$i['qty'], 'unit_price' => (float)$i['unit_price']], $items_map[$po['id']] ?? []),
    ];
}

function fmt_money($m) { return '$' . number_format((float)$m, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders — Blue Mogul</title>
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
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-clipboard-list text-blue-500 mr-2"></i>Purchase Orders</h1>
                    <p class="text-sm text-gray-500 mt-0.5">PO approval workflow</p>
                </div>
                <button onclick="document.getElementById('poModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition"><i class="fas fa-plus mr-2"></i>New PO</button>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total POs</p><p class="text-3xl font-bold text-gray-900"><?php echo count($pos); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Pending</p><p class="text-3xl font-bold text-yellow-600"><?php echo count(array_filter($pos, fn($p) => $p['status']==='pending')); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Approved</p><p class="text-3xl font-bold text-green-600"><?php echo count(array_filter($pos, fn($p) => $p['status']==='approved')); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">PO Value</p><p class="text-3xl font-bold text-blue-600"><?php echo fmt_money(array_sum(array_map(fn($p) => (float)$p['total'], $pos))); ?></p></div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Purchase Orders</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">PO #</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Items</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Total</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($pos)): ?>
                                <tr><td colspan="7" class="px-6 py-12 text-center text-gray-500">No purchase orders yet.</td></tr>
                            <?php else: foreach ($pos as $po): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($po['po_number'] ?: '#' . $po['id']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($po['client_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($po['supplier'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo count($items_map[$po['id']] ?? []); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_money($po['total']); ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo $po['status']==='approved'?'bg-green-100 text-green-700':($po['status']==='rejected'?'bg-red-100 text-red-700':($po['status']==='pending'?'bg-yellow-100 text-yellow-700':'bg-gray-200 text-gray-600')); ?>"><?php echo ucfirst($po['status']); ?></span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-right whitespace-nowrap">
                                        <?php if ($po['status'] === 'pending'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="approve_po">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                                <button class="text-green-600 hover:text-green-800 text-xs mr-2">Approve</button>
                                            </form>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="reject_po">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                                <button class="text-red-500 hover:text-red-700 text-xs mr-2">Reject</button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" onclick="editPO(<?php echo (int)$po['id']; ?>)" class="text-blue-600 hover:text-blue-800 text-xs mr-2">Edit</button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this PO?');">
                                            <input type="hidden" name="action" value="delete_po">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                                            <button class="text-gray-400 hover:text-gray-700 text-xs">Delete</button>
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

<!-- New PO modal -->
<div id="poModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">New Purchase Order</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_po">
            <?php echo csrf_field(); ?>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">— No client —</option>
                        <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                    <input type="text" name="supplier" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. D&H, Ubiquiti">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO number</label>
                    <input type="text" name="po_number" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. PO-2026-001">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total ($)</label>
                    <input type="number" step="0.01" name="total" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Line items</label>
                <div id="lineItems">
                    <div class="grid grid-cols-12 gap-2 mb-2">
                        <input type="text" name="item_desc[]" placeholder="Description" class="col-span-6 px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <input type="number" name="item_qty[]" value="1" min="1" class="col-span-2 px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <input type="number" step="0.01" name="item_price[]" placeholder="Price" class="col-span-3 px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <button type="button" onclick="this.parentElement.remove()" class="col-span-1 text-red-500 text-sm">✕</button>
                    </div>
                </div>
                <button type="button" onclick="addLineItem()" class="text-blue-600 hover:underline text-sm"><i class="fas fa-plus mr-1"></i>Add line item</button>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('poModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Submit for approval</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit PO modal -->
<div id="poEditModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Edit Purchase Order</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_po">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="po_id" id="pe_id">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select name="client_id" id="pe_client" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">— No client —</option>
                        <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                    <input type="text" name="supplier" id="pe_supplier" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO number</label>
                    <input type="text" name="po_number" id="pe_po_number" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total ($)</label>
                    <input type="number" step="0.01" name="total" id="pe_total" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Line items</label>
                <div id="pe_lineItems"></div>
                <button type="button" onclick="addLineItem('pe_lineItems')" class="text-blue-600 hover:underline text-sm"><i class="fas fa-plus mr-1"></i>Add line item</button>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="pe_notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('poEditModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-md">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">Save changes</button>
            </div>
        </form>
    </div>
</div>

<script>
const PO_DATA = <?php echo json_encode($po_data); ?>;
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
function lineItemRow(d, q, p) {
    const wrap = document.createElement('div');
    wrap.className = 'grid grid-cols-12 gap-2 mb-2';
    wrap.innerHTML = '<input type="text" name="item_desc[]" placeholder="Description" value="' + esc(d) + '" class="col-span-6 px-3 py-2 border border-gray-300 rounded-md text-sm">' +
        '<input type="number" name="item_qty[]" min="1" value="' + (q || 1) + '" class="col-span-2 px-3 py-2 border border-gray-300 rounded-md text-sm">' +
        '<input type="number" step="0.01" name="item_price[]" placeholder="Price" value="' + (p == null ? '' : p) + '" class="col-span-3 px-3 py-2 border border-gray-300 rounded-md text-sm">' +
        '<button type="button" onclick="this.parentElement.remove()" class="col-span-1 text-red-500 text-sm">✕</button>';
    return wrap;
}
function addLineItem(containerId) {
    document.getElementById(containerId || 'lineItems').appendChild(lineItemRow('', 1, ''));
}
function editPO(id) {
    const d = PO_DATA[id];
    if (!d) return;
    document.getElementById('pe_id').value = id;
    document.getElementById('pe_client').value = d.client_id || '';
    document.getElementById('pe_supplier').value = d.supplier || '';
    document.getElementById('pe_po_number').value = d.po_number || '';
    document.getElementById('pe_total').value = d.total || '';
    document.getElementById('pe_notes').value = d.notes || '';
    const box = document.getElementById('pe_lineItems');
    box.innerHTML = '';
    (d.items && d.items.length ? d.items : [{description:'', qty:1, unit_price:''}])
        .forEach(it => box.appendChild(lineItemRow(it.description, it.qty, it.unit_price)));
    document.getElementById('poEditModal').classList.remove('hidden');
}
</script>
</body>
</html>
