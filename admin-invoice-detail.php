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

$invoice_id = intval($_GET['id'] ?? 0);
if ($invoice_id <= 0) {
    portal_redirect('/portal/admin-invoices.php');
}

$success_msg = '';
$error_msg = '';
$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    require_csrf();
    
    if ($_POST['action'] === 'mark_paid') {
        try {
            $stmt = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_date = CURRENT_DATE WHERE id = ?");
            $stmt->execute([$invoice_id]);

            $stmt = $pdo->prepare("SELECT amount, total, client_id FROM invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($inv) {
                $pay_amount = floatval($inv['total'] ?? $inv['amount']);
                $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, client_id, amount, method, status, created_at) VALUES (?, ?, ?, 'manual', 'completed', NOW())");
                $stmt->execute([$invoice_id, $inv['client_id'], $pay_amount]);
            }
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'invoice_updated', 'invoice', $invoice_id, 'Marked invoice #' . $invoice_id . ' as paid', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            if ($inv) {
                $inv_stmt = $pdo->prepare("SELECT i.invoice_number, c.name, c.email FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
                $inv_stmt->execute([$invoice_id]);
                $inv_info = $inv_stmt->fetch(PDO::FETCH_ASSOC);
                if ($inv_info && !empty($inv_info['email'])) {
                    notify_invoice_paid($inv_info['invoice_number'], $pay_amount, $inv_info['email'], $inv_info['name'] ?? 'Client');
                }
            }
            $success_msg = 'Invoice marked as paid.';
        } catch (PDOException $e) {
            error_log("Mark paid error: " . $e->getMessage());
            $error_msg = 'Failed to update invoice.';
        }
    } elseif ($_POST['action'] === 'mark_unpaid') {
        try {
            $stmt = $pdo->prepare("UPDATE invoices SET status = 'unpaid', paid_date = NULL WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'invoice_updated', 'invoice', $invoice_id, 'Marked invoice #' . $invoice_id . ' as unpaid', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_msg = 'Invoice marked as unpaid.';
        } catch (PDOException $e) {
            $error_msg = 'Failed to update invoice.';
        }
    } elseif ($_POST['action'] === 'email_invoice') {
        try {
            $inv_stmt = $pdo->prepare("SELECT i.invoice_number, i.amount, i.total, i.due_date, c.name, c.email FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
            $inv_stmt->execute([$invoice_id]);
            $inv_info = $inv_stmt->fetch(PDO::FETCH_ASSOC);
            if ($inv_info && !empty($inv_info['email'])) {
                $email_amount = floatval($inv_info['total'] ?? $inv_info['amount']);
                $due_str = $inv_info['due_date'] ? date('M d, Y', strtotime($inv_info['due_date'])) : 'N/A';
                $subject = 'Invoice ' . $inv_info['invoice_number'] . ' from Blue Mogul';
                $body = "Hello " . ($inv_info['name'] ?? 'Client') . ",\n\n";
                $body .= "You have a new invoice from Blue Mogul.\n\n";
                $body .= "Invoice: " . $inv_info['invoice_number'] . "\n";
                $body .= "Amount: $" . number_format($email_amount, 2) . "\n";
                $body .= "Due Date: " . $due_str . "\n\n";
                $body .= "Please log in to your client portal to view and pay this invoice.\n\n";
                $body .= "Thank you,\nBlue Mogul";
                @mail($inv_info['email'], $subject, $body, "From: " . ADMIN_EMAIL . "\r\nContent-Type: text/plain; charset=UTF-8");
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'invoice_emailed', 'invoice', $invoice_id, 'Emailed invoice #' . $inv_info['invoice_number'] . ' to ' . $inv_info['email'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = 'Invoice email sent to ' . htmlspecialchars($inv_info['email']) . '.';
            } else {
                $error_msg = 'Client email not found.';
            }
        } catch (PDOException $e) {
            error_log("Email invoice error: " . $e->getMessage());
            $error_msg = 'Failed to send invoice email.';
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT i.*, c.name as client_name, c.email as client_email, c.company as client_company, c.phone as client_phone FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        portal_redirect('/portal/admin-invoices.php');
    }

    $stmt = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY created_at DESC");
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Invoice detail error: " . $e->getMessage());
    portal_redirect('/portal/admin-invoices.php');
}

$items_json = $invoice['items'] ?? '[]';
$items = json_decode($items_json, true);
if (!is_array($items)) {
    $items = [];
}
$has_line_items = !empty($items);

$subtotal = 0;
$tax_total = 0;
if ($has_line_items) {
    foreach ($items as $item) {
        $qty = floatval($item['qty'] ?? 1);
        $unit_price = floatval($item['unit_price'] ?? 0);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        $line_amount = $qty * $unit_price;
        $line_tax = $line_amount * ($tax_rate / 100);
        $subtotal += $line_amount;
        $tax_total += $line_tax;
    }
}
$total = $has_line_items ? ($subtotal + $tax_total) : floatval($invoice['amount']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
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
            <div class="px-6 py-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 flex-wrap">
                    <a href="admin-invoices.php" class="text-gray-400 hover:text-gray-600 transition" data-testid="link-back-invoices"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-invoice-number">Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                        echo match($invoice['status']) {
                            'paid' => 'bg-green-100 text-green-700',
                            'unpaid' => 'bg-yellow-100 text-yellow-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>" data-testid="text-invoice-status"><?php echo ucfirst($invoice['status']); ?></span>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <?php if ($invoice['status'] === 'unpaid'): ?>
                        <button type="button" onclick="sendStripeInvoice()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-stripe-pay">
                            <i class="fab fa-stripe-s mr-2"></i>Send via Stripe
                        </button>
                    <?php endif; ?>
                    <form method="POST" action="admin-invoice-detail.php?id=<?php echo $invoice_id; ?>" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="email_invoice">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-email-invoice">
                            <i class="fas fa-envelope mr-2"></i>Email Invoice
                        </button>
                    </form>
                    <?php if ($invoice['status'] === 'unpaid'): ?>
                        <form method="POST" action="admin-invoice-detail.php?id=<?php echo $invoice_id; ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_paid">
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-mark-paid">
                                <i class="fas fa-check mr-2"></i>Mark as Paid
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="admin-invoice-detail.php?id=<?php echo $invoice_id; ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_unpaid">
                            <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-mark-unpaid">
                                <i class="fas fa-undo mr-2"></i>Mark as Unpaid
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="text-success-message">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="text-error-message">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <div id="stripe-result" class="hidden mb-6"></div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                        <div class="px-8 py-6 bg-gray-50 border-b border-gray-200">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></h2>
                                    <p class="text-sm text-gray-500 mt-1">Created <?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-4xl font-bold text-gray-900" data-testid="text-invoice-total">$<?php echo number_format($total, 2); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="px-8 py-6">
                            <div class="grid grid-cols-2 gap-8 mb-6">
                                <div>
                                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Bill To</h3>
                                    <p class="font-medium text-gray-900" data-testid="text-client-name"><?php echo htmlspecialchars($invoice['client_name'] ?? 'N/A'); ?></p>
                                    <?php if ($invoice['client_company']): ?>
                                        <p class="text-sm text-gray-600" data-testid="text-client-company"><?php echo htmlspecialchars($invoice['client_company']); ?></p>
                                    <?php endif; ?>
                                    <p class="text-sm text-gray-600" data-testid="text-client-email"><?php echo htmlspecialchars($invoice['client_email'] ?? ''); ?></p>
                                </div>
                                <div>
                                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Invoice Info</h3>
                                    <div class="space-y-1 text-sm">
                                        <div class="flex justify-between gap-2">
                                            <span class="text-gray-600">Due Date:</span>
                                            <span class="font-medium" data-testid="text-due-date"><?php echo $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : 'N/A'; ?></span>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <span class="text-gray-600">Status:</span>
                                            <span class="font-medium"><?php echo ucfirst($invoice['status']); ?></span>
                                        </div>
                                        <?php if ($invoice['paid_date']): ?>
                                        <div class="flex justify-between gap-2">
                                            <span class="text-gray-600">Paid Date:</span>
                                            <span class="font-medium" data-testid="text-paid-date"><?php echo date('M d, Y', strtotime($invoice['paid_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-gray-200 pt-6">
                                <table class="w-full" data-testid="table-line-items">
                                    <thead>
                                        <tr class="text-xs font-semibold text-gray-500 uppercase">
                                            <?php if ($has_line_items): ?>
                                                <th class="text-left pb-3">Item</th>
                                                <th class="text-left pb-3">Description</th>
                                                <th class="text-right pb-3">Qty</th>
                                                <th class="text-right pb-3">Unit Price</th>
                                                <th class="text-right pb-3">Tax</th>
                                                <th class="text-right pb-3">Amount</th>
                                            <?php else: ?>
                                                <th class="text-left pb-3">Description</th>
                                                <th class="text-right pb-3">Amount</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody class="border-t border-gray-200">
                                        <?php if ($has_line_items): ?>
                                            <?php foreach ($items as $idx => $item):
                                                $qty = floatval($item['qty'] ?? 1);
                                                $unit_price = floatval($item['unit_price'] ?? 0);
                                                $tax_rate = floatval($item['tax_rate'] ?? 0);
                                                $line_amount = $qty * $unit_price;
                                                $line_tax = $line_amount * ($tax_rate / 100);
                                            ?>
                                                <tr class="border-b border-gray-100" data-testid="row-line-item-<?php echo $idx; ?>">
                                                    <td class="py-3 text-gray-900 font-medium"><?php echo htmlspecialchars($item['name'] ?? ''); ?></td>
                                                    <td class="py-3 text-gray-600 text-sm"><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                                                    <td class="py-3 text-right text-gray-900"><?php echo $qty; ?></td>
                                                    <td class="py-3 text-right text-gray-900">$<?php echo number_format($unit_price, 2); ?></td>
                                                    <td class="py-3 text-right text-gray-600 text-sm"><?php echo $tax_rate > 0 ? number_format($tax_rate, 2) . '%' : 'No Tax'; ?></td>
                                                    <td class="py-3 text-right font-semibold text-gray-900">$<?php echo number_format($line_amount + $line_tax, 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td class="py-4 text-gray-900"><?php echo htmlspecialchars($invoice['description'] ?? 'Invoice ' . $invoice['invoice_number']); ?></td>
                                                <td class="py-4 text-right font-semibold text-gray-900">$<?php echo number_format($invoice['amount'], 2); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="border-t-2 border-gray-300">
                                        <?php if ($has_line_items): ?>
                                            <tr>
                                                <td colspan="5" class="py-2 text-right text-gray-600 text-sm">Subtotal</td>
                                                <td class="py-2 text-right font-medium text-gray-900" data-testid="text-subtotal">$<?php echo number_format($subtotal, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td colspan="5" class="py-2 text-right text-gray-600 text-sm">Tax</td>
                                                <td class="py-2 text-right font-medium text-gray-900" data-testid="text-tax">$<?php echo number_format($tax_total, 2); ?></td>
                                            </tr>
                                            <tr class="border-t border-gray-200">
                                                <td colspan="5" class="py-3 text-right font-bold text-gray-900">Total</td>
                                                <td class="py-3 text-right font-bold text-gray-900 text-lg" data-testid="text-total">$<?php echo number_format($total, 2); ?></td>
                                            </tr>
                                        <?php else: ?>
                                            <tr>
                                                <td class="py-4 font-bold text-gray-900">Total</td>
                                                <td class="py-4 text-right font-bold text-gray-900 text-lg" data-testid="text-total">$<?php echo number_format($invoice['amount'], 2); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tfoot>
                                </table>
                            </div>

                            <?php if (!empty($invoice['notes'])): ?>
                            <div class="border-t border-gray-200 pt-6 mt-4">
                                <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Notes</h3>
                                <p class="text-sm text-gray-700 whitespace-pre-wrap" data-testid="text-invoice-notes"><?php echo htmlspecialchars($invoice['notes']); ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($invoice['footer'])): ?>
                            <div class="border-t border-gray-200 pt-6 mt-4">
                                <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Invoice Footer</h3>
                                <p class="text-sm text-gray-600 whitespace-pre-wrap italic" data-testid="text-invoice-footer"><?php echo htmlspecialchars($invoice['footer']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-900">Payment History</h3>
                        </div>
                        <?php if (empty($payments)): ?>
                            <div class="p-6 text-center text-gray-500 text-sm" data-testid="text-no-payments">
                                No payments recorded yet.
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($payments as $idx => $p): ?>
                                    <div class="px-6 py-4" data-testid="row-payment-<?php echo $idx; ?>">
                                        <div class="flex items-center justify-between gap-2 mb-1">
                                            <span class="font-medium text-gray-900 text-sm">$<?php echo number_format($p['amount'], 2); ?></span>
                                            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium"><?php echo ucfirst($p['status']); ?></span>
                                        </div>
                                        <p class="text-xs text-gray-500">
                                            <?php echo date('M d, Y g:i A', strtotime($p['created_at'])); ?> via <?php echo ucfirst($p['method'] ?? 'stripe'); ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function sendStripeInvoice() {
    const resultDiv = document.getElementById('stripe-result');
    resultDiv.className = 'mb-6 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-md flex items-center';
    resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Creating Stripe payment link...';

    fetch('/api/create-checkout-session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invoice_id: <?php echo $invoice_id; ?> })
    })
    .then(r => r.json())
    .then(data => {
        if (data.url) {
            resultDiv.className = 'mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md';
            resultDiv.innerHTML = '<div class="flex items-center gap-2 mb-2"><i class="fas fa-check-circle mr-1"></i> <strong>Stripe payment link created!</strong></div>' +
                '<div class="flex items-center gap-2 flex-wrap">' +
                '<input type="text" readonly value="' + data.url + '" class="flex-1 min-w-0 bg-white border border-green-300 rounded px-3 py-1 text-sm text-gray-800" data-testid="input-payment-link" />' +
                '<button onclick="copyPaymentLink(this)" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm font-medium" data-testid="button-copy-link"><i class="fas fa-copy mr-1"></i>Copy</button>' +
                '<a href="' + data.url + '" target="_blank" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-sm font-medium" data-testid="link-open-stripe"><i class="fas fa-external-link-alt mr-1"></i>Open</a>' +
                '</div>';
        } else if (data.demo) {
            resultDiv.className = 'mb-6 bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-md flex items-center';
            resultDiv.innerHTML = '<i class="fas fa-info-circle mr-2"></i> ' + data.message;
        } else if (data.error) {
            resultDiv.className = 'mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md flex items-center';
            resultDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i> ' + data.error;
        }
    })
    .catch(err => {
        resultDiv.className = 'mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md flex items-center';
        resultDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i> Failed to create payment link.';
    });
}

function copyPaymentLink(btn) {
    const input = btn.parentElement.querySelector('input');
    navigator.clipboard.writeText(input.value).then(() => {
        btn.innerHTML = '<i class="fas fa-check mr-1"></i>Copied!';
        setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy mr-1"></i>Copy'; }, 2000);
    });
}
</script>
</body>
</html>