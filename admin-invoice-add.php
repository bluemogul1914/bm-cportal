<?php
require_once 'config.php';
require_once 'includes/email.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$success_msg = '';
$error_msg = '';
$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    require_csrf();

    $client_id = intval($_POST['client_id'] ?? 0);
    $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $footer = trim($_POST['footer'] ?? '');
    $items_json = $_POST['items_json'] ?? '[]';

    $items = json_decode($items_json, true);
    if (!is_array($items)) {
        $items = [];
    }

    if ($client_id <= 0 || empty($items)) {
        $error_msg = 'Client and at least one line item are required.';
    } else {
        $subtotal = 0;
        $tax_amount = 0;
        foreach ($items as &$item) {
            $qty = floatval($item['qty'] ?? 0);
            $unit_price = floatval($item['unit_price'] ?? 0);
            $tax_rate = floatval($item['tax_rate'] ?? 0);
            $line_amount = $qty * $unit_price;
            $line_tax = $line_amount * ($tax_rate / 100);
            $item['amount'] = round($line_amount, 2);
            $item['tax_amount'] = round($line_tax, 2);
            $subtotal += $line_amount;
            $tax_amount += $line_tax;
        }
        unset($item);

        $subtotal = round($subtotal, 2);
        $tax_amount = round($tax_amount, 2);
        $total = round($subtotal + $tax_amount, 2);

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(REPLACE(invoice_number, 'INV-', '') AS INTEGER)), 0) + 1 as next_num FROM invoices WHERE invoice_number ~ '^INV-[0-9]{3,5}$'");
            $stmt->execute();
            $next = $stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
            $invoice_number = 'INV-' . str_pad($next, 5, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO invoices (client_id, invoice_number, amount, tax, total, status, due_date, notes, footer, items, created_at) VALUES (?, ?, ?, ?, ?, 'unpaid', ?, ?, ?, ?::jsonb, NOW())");
            $stmt->execute([
                $client_id,
                $invoice_number,
                $subtotal,
                $tax_amount,
                $total,
                !empty($due_date) ? $due_date : null,
                !empty($notes) ? $notes : null,
                !empty($footer) ? $footer : null,
                json_encode($items)
            ]);
            $new_invoice_id = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$user_id, 'invoice_created', 'invoice', $new_invoice_id, 'Created invoice ' . $invoice_number, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

            $c_stmt = $pdo->prepare("SELECT name, email FROM clients WHERE id = ?");
            $c_stmt->execute([$client_id]);
            $c_info = $c_stmt->fetch(PDO::FETCH_ASSOC);
            if ($c_info && !empty($c_info['email'])) {
                notify_invoice_created($invoice_number, $total, !empty($due_date) ? $due_date : 'Upon receipt', $c_info['email'], $c_info['name'] ?? 'Client');
            }
            $success_msg = "Invoice $invoice_number created successfully!";
        } catch (PDOException $e) {
            error_log("Invoice creation error: " . $e->getMessage());
            $error_msg = 'Failed to create invoice.';
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, company FROM clients ORDER BY name");
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clients = [];
}

try {
    $stmt = $pdo->prepare("SELECT id, name, description, price, category FROM products WHERE active = true ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

$footer_templates = [];
try {
    $footer_templates = $pdo->query("SELECT id, name, content, is_default FROM invoice_footer_templates ORDER BY is_default DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_footer_template') {
    require_csrf();
    $tpl_name = trim($_POST['template_name'] ?? '');
    $tpl_content = trim($_POST['template_content'] ?? '');
    $tpl_default = isset($_POST['template_default']) && $_POST['template_default'] ? 't' : 'f';
    if ($tpl_name && $tpl_content) {
        if ($tpl_default === 't') {
            $pdo->prepare("UPDATE invoice_footer_templates SET is_default = false WHERE is_default = true")->execute();
        }
        $pdo->prepare("INSERT INTO invoice_footer_templates (name, content, is_default) VALUES (?, ?, ?)")->execute([$tpl_name, $tpl_content, $tpl_default]);
        $footer_templates = $pdo->query("SELECT id, name, content, is_default FROM invoice_footer_templates ORDER BY is_default DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $success_msg = "Footer template '$tpl_name' saved.";
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete_footer_template') {
    require_csrf();
    $del_id = (int)($_POST['template_id'] ?? 0);
    if ($del_id) {
        $pdo->prepare("DELETE FROM invoice_footer_templates WHERE id = ?")->execute([$del_id]);
        $footer_templates = $pdo->query("SELECT id, name, content, is_default FROM invoice_footer_templates ORDER BY is_default DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $success_msg = "Footer template deleted.";
    }
}

$default_footer = '';
foreach ($footer_templates as $ft) {
    if ($ft['is_default']) { $default_footer = $ft['content']; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Invoice - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <a href="admin-invoices.php" class="text-gray-400 hover:text-gray-600 transition" data-testid="link-back-invoices"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-2xl font-semibold text-gray-900">Create Invoice</h1>
                </div>
            </div>
        </header>

        <div class="p-6 max-w-5xl">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                    <a href="admin-invoices.php" class="ml-auto text-green-700 underline text-sm" data-testid="link-view-invoices">View Invoices</a>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="admin-invoice-add.php" id="invoiceForm" onsubmit="return updateHiddenItems()" data-testid="form-create-invoice">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_invoice">
                <input type="hidden" name="items_json" id="items_json" value="[]">

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Invoice Details</h2>
                    </div>
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                            <select name="client_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-client">
                                <option value="">Select a client...</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> <?php echo $c['company'] ? '(' . htmlspecialchars($c['company']) . ')' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Invoice Date</label>
                                <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-invoice-date">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                                <input type="date" name="due_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-due-date">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6 overflow-visible">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-gray-900">Line Items</h2>
                        <button type="button" onclick="addLineItem()" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-add-line-item">
                            <i class="fas fa-plus mr-1"></i>Add Line Item
                        </button>
                    </div>
                    <div class="overflow-visible">
                        <table class="w-full text-sm" id="lineItemsTable" data-testid="table-line-items">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200">
                                    <th class="text-left px-4 py-3 font-medium text-gray-600 w-48">Item</th>
                                    <th class="text-left px-4 py-3 font-medium text-gray-600">Description</th>
                                    <th class="text-center px-4 py-3 font-medium text-gray-600 w-20">Qty</th>
                                    <th class="text-right px-4 py-3 font-medium text-gray-600 w-32">Unit Price</th>
                                    <th class="text-center px-4 py-3 font-medium text-gray-600 w-40">Tax</th>
                                    <th class="text-right px-4 py-3 font-medium text-gray-600 w-28">Amount</th>
                                    <th class="px-4 py-3 w-10"></th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                            </tbody>
                        </table>
                    </div>

                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex justify-end">
                            <div class="w-72 space-y-2">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Subtotal</span>
                                    <span class="font-medium text-gray-900" id="subtotalDisplay" data-testid="text-subtotal">$0.00</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Tax</span>
                                    <span class="font-medium text-gray-900" id="taxDisplay" data-testid="text-tax">$0.00</span>
                                </div>
                                <div class="flex justify-between text-base pt-2 border-t border-gray-200">
                                    <span class="font-semibold text-gray-900">Total (Balance)</span>
                                    <span class="font-bold text-gray-900" id="totalDisplay" data-testid="text-total">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Notes</h2>
                    </div>
                    <div class="p-6">
                        <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional internal invoice notes..." data-testid="textarea-notes"></textarea>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Invoice Footer</h2>
                            <p class="text-sm text-gray-500 mt-1">This message will appear at the bottom of the customer-facing invoice.</p>
                        </div>
                        <?php if (!empty($footer_templates)): ?>
                        <div class="flex items-center gap-2">
                            <select id="footerTemplateSelect" onchange="loadFooterTemplate()" class="px-3 py-1.5 border border-gray-300 rounded-md text-sm" data-testid="select-footer-template">
                                <option value="">Load Template...</option>
                                <?php foreach ($footer_templates as $ft): ?>
                                    <option value="<?= htmlspecialchars($ft['content']) ?>"><?= htmlspecialchars($ft['name']) ?><?= $ft['is_default'] ? ' (Default)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-6">
                        <textarea name="footer" id="footerTextarea" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 mb-3" placeholder="e.g. Thank you for your business! Payment is due within 30 days..." data-testid="textarea-footer"><?= htmlspecialchars($default_footer) ?></textarea>
                        <div class="flex items-center justify-between">
                            <button type="button" onclick="document.getElementById('saveTemplateModal').classList.remove('hidden')" class="text-sm text-blue-600 hover:text-blue-800 font-medium" data-testid="button-save-template">
                                <i class="fas fa-save mr-1"></i>Save as Template
                            </button>
                            <?php if (!empty($footer_templates)): ?>
                            <button type="button" onclick="document.getElementById('manageTemplatesModal').classList.remove('hidden')" class="text-sm text-gray-500 hover:text-gray-700" data-testid="button-manage-templates">
                                <i class="fas fa-cog mr-1"></i>Manage Templates
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pb-6">
                    <a href="admin-invoices.php" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition" data-testid="link-cancel">Cancel</a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-create-invoice">
                        <i class="fas fa-plus mr-2"></i>Create Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="saveTemplateModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg w-full max-w-md mx-4 shadow-xl">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="text-lg font-semibold">Save Footer Template</h3>
            <button onclick="document.getElementById('saveTemplateModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_footer_template">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Template Name *</label>
                <input type="text" name="template_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="e.g. Net 30 Terms" data-testid="input-template-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Footer Content *</label>
                <textarea name="template_content" id="templateContentField" required rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Footer text..." data-testid="textarea-template-content"></textarea>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="template_default" id="templateDefault" class="rounded border-gray-300" data-testid="checkbox-template-default">
                <label for="templateDefault" class="text-sm text-gray-700">Set as default footer for new invoices</label>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('saveTemplateModal').classList.add('hidden')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium" data-testid="button-submit-template">Save Template</button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($footer_templates)): ?>
<div id="manageTemplatesModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg w-full max-w-lg mx-4 shadow-xl">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="text-lg font-semibold">Manage Footer Templates</h3>
            <button onclick="document.getElementById('manageTemplatesModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 divide-y divide-gray-200 max-h-96 overflow-y-auto">
            <?php foreach ($footer_templates as $ft): ?>
            <div class="py-3 flex items-start justify-between gap-3" data-testid="template-row-<?= $ft['id'] ?>">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($ft['name']) ?><?= $ft['is_default'] ? ' <span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded ml-1">Default</span>' : '' ?></p>
                    <p class="text-xs text-gray-500 mt-1 truncate"><?= htmlspecialchars(substr($ft['content'], 0, 100)) ?></p>
                </div>
                <form method="POST" class="inline flex-shrink-0" onsubmit="return confirm('Delete this template?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_footer_template">
                    <input type="hidden" name="template_id" value="<?= $ft['id'] ?>">
                    <button type="submit" class="text-red-500 hover:text-red-700 text-sm" data-testid="button-delete-template-<?= $ft['id'] ?>"><i class="fas fa-trash"></i></button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const products = <?php echo json_encode($products); ?>;
let lineItemIndex = 0;

function loadFooterTemplate() {
    var sel = document.getElementById('footerTemplateSelect');
    if (sel.value) {
        document.getElementById('footerTextarea').value = sel.value;
    }
    sel.selectedIndex = 0;
}

document.getElementById('saveTemplateModal')?.addEventListener('show', function() {});
document.querySelector('[data-testid="button-save-template"]')?.addEventListener('click', function() {
    var footerText = document.getElementById('footerTextarea').value;
    var tplField = document.getElementById('templateContentField');
    if (tplField && footerText) tplField.value = footerText;
});

function addLineItem() {
    const tbody = document.getElementById('lineItemsBody');
    const idx = lineItemIndex++;
    const tr = document.createElement('tr');
    tr.id = 'line-item-' + idx;
    tr.className = 'border-b border-gray-100 relative';
    tr.setAttribute('data-testid', 'row-line-item-' + idx);
    tr.innerHTML = `
        <td class="px-4 py-2">
            <div class="relative">
                <input type="text" class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Item name..."
                    id="item-name-${idx}"
                    oninput="showProductSuggestions(${idx})"
                    onfocus="showProductSuggestions(${idx})"
                    onblur="setTimeout(() => hideProductSuggestions(${idx}), 200)"
                    autocomplete="off"
                    data-testid="input-item-name-${idx}">
                <div id="suggestions-${idx}" class="absolute z-50 left-0 right-0 top-full bg-white border border-gray-300 rounded-md shadow-lg max-h-40 overflow-y-auto hidden"></div>
            </div>
        </td>
        <td class="px-4 py-2">
            <input type="text" class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder="Description..."
                id="item-desc-${idx}"
                data-testid="input-item-desc-${idx}">
        </td>
        <td class="px-4 py-2">
            <input type="number" class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm text-center focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                value="1" min="0" step="1"
                id="item-qty-${idx}"
                oninput="recalculate()"
                data-testid="input-item-qty-${idx}">
        </td>
        <td class="px-4 py-2">
            <input type="number" class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm text-right focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                value="0.00" min="0" step="0.01"
                id="item-price-${idx}"
                oninput="recalculate()"
                data-testid="input-item-price-${idx}">
        </td>
        <td class="px-4 py-2">
            <select class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                id="item-tax-${idx}"
                onchange="recalculate()"
                data-testid="select-item-tax-${idx}">
                <option value="0">No Tax</option>
                <option value="8.25">8.25% Sales Tax</option>
            </select>
        </td>
        <td class="px-4 py-2 text-right">
            <span class="text-sm font-medium text-gray-900" id="item-amount-${idx}" data-testid="text-item-amount-${idx}">$0.00</span>
        </td>
        <td class="px-4 py-2 text-center">
            <button type="button" onclick="removeLineItem(${idx})" class="text-red-400 hover:text-red-600 transition" data-testid="button-remove-item-${idx}">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    recalculate();
}

function removeLineItem(idx) {
    const row = document.getElementById('line-item-' + idx);
    if (row) {
        row.remove();
        recalculate();
    }
}

function recalculate() {
    const tbody = document.getElementById('lineItemsBody');
    const rows = tbody.querySelectorAll('tr');
    let subtotal = 0;
    let taxTotal = 0;

    rows.forEach(row => {
        const idx = row.id.replace('line-item-', '');
        const qtyEl = document.getElementById('item-qty-' + idx);
        const priceEl = document.getElementById('item-price-' + idx);
        const taxEl = document.getElementById('item-tax-' + idx);
        const amountEl = document.getElementById('item-amount-' + idx);

        if (!qtyEl || !priceEl || !taxEl || !amountEl) return;

        const qty = parseFloat(qtyEl.value) || 0;
        const price = parseFloat(priceEl.value) || 0;
        const taxRate = parseFloat(taxEl.value) || 0;
        const lineAmount = qty * price;
        const lineTax = lineAmount * (taxRate / 100);

        amountEl.textContent = '$' + lineAmount.toFixed(2);
        subtotal += lineAmount;
        taxTotal += lineTax;
    });

    const total = subtotal + taxTotal;
    document.getElementById('subtotalDisplay').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('taxDisplay').textContent = '$' + taxTotal.toFixed(2);
    document.getElementById('totalDisplay').textContent = '$' + total.toFixed(2);
}

function showProductSuggestions(idx) {
    const input = document.getElementById('item-name-' + idx);
    const sugDiv = document.getElementById('suggestions-' + idx);
    const query = input.value.toLowerCase().trim();

    const matches = query.length === 0
        ? products.slice(0, 20)
        : products.filter(p => p.name.toLowerCase().includes(query));
    if (matches.length === 0) {
        sugDiv.classList.add('hidden');
        return;
    }

    sugDiv.innerHTML = '';
    matches.forEach(p => {
        const div = document.createElement('div');
        div.className = 'px-3 py-2 cursor-pointer hover:bg-blue-50 text-sm flex justify-between gap-2';
        div.setAttribute('data-testid', 'suggestion-product-' + p.id);
        div.innerHTML = `<span class="text-gray-900">${escapeHtml(p.name)}</span><span class="text-gray-500">$${parseFloat(p.price).toFixed(2)}</span>`;
        div.onmousedown = function(e) {
            e.preventDefault();
            selectProduct(idx, p);
        };
        sugDiv.appendChild(div);
    });
    sugDiv.classList.remove('hidden');
}

function hideProductSuggestions(idx) {
    const sugDiv = document.getElementById('suggestions-' + idx);
    if (sugDiv) sugDiv.classList.add('hidden');
}

function selectProduct(idx, product) {
    document.getElementById('item-name-' + idx).value = product.name;
    document.getElementById('item-desc-' + idx).value = product.description || '';
    document.getElementById('item-price-' + idx).value = parseFloat(product.price).toFixed(2);
    hideProductSuggestions(idx);
    recalculate();
}

function updateHiddenItems() {
    const tbody = document.getElementById('lineItemsBody');
    const rows = tbody.querySelectorAll('tr');
    const items = [];

    rows.forEach(row => {
        const idx = row.id.replace('line-item-', '');
        const name = document.getElementById('item-name-' + idx)?.value?.trim() || '';
        const description = document.getElementById('item-desc-' + idx)?.value?.trim() || '';
        const qty = parseFloat(document.getElementById('item-qty-' + idx)?.value) || 0;
        const unit_price = parseFloat(document.getElementById('item-price-' + idx)?.value) || 0;
        const tax_rate = parseFloat(document.getElementById('item-tax-' + idx)?.value) || 0;

        if (name && qty > 0 && unit_price > 0) {
            items.push({ name, description, qty, unit_price, tax_rate });
        }
    });

    if (items.length === 0) {
        alert('Please add at least one valid line item.');
        return false;
    }

    document.getElementById('items_json').value = JSON.stringify(items);
    return true;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

addLineItem();
</script>
</body>
</html>