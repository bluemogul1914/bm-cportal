<?php
require_once 'config.php';
require_once 'includes/email.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    echo '<script>window.location="/portal";</script>';
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$invoice_id = intval($_GET['id'] ?? 0);
if ($invoice_id <= 0) {
    echo '<script>window.location="/portal/admin-invoices.php";</script>';
    exit();
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

            $stmt = $pdo->prepare("SELECT amount, client_id FROM invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($inv) {
                $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, client_id, amount, method, status, created_at) VALUES (?, ?, ?, 'manual', 'completed', NOW())");
                $stmt->execute([$invoice_id, $inv['client_id'], $inv['amount']]);
            }
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'invoice_updated', 'invoice', $invoice_id, 'Marked invoice #' . $invoice_id . ' as paid', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            if ($inv) {
                $inv_stmt = $pdo->prepare("SELECT i.invoice_number, c.name, c.email FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
                $inv_stmt->execute([$invoice_id]);
                $inv_info = $inv_stmt->fetch(PDO::FETCH_ASSOC);
                if ($inv_info && !empty($inv_info['email'])) {
                    notify_invoice_paid($inv_info['invoice_number'], $inv['amount'], $inv_info['email'], $inv_info['name'] ?? 'Client');
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
    }
}

try {
    $stmt = $pdo->prepare("SELECT i.*, c.name as client_name, c.email as client_email, c.company as client_company, c.phone as client_phone FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        echo '<script>window.location="/portal/admin-invoices.php";</script>';
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY created_at DESC");
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Invoice detail error: " . $e->getMessage());
    echo '<script>window.location="/portal/admin-invoices.php";</script>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> - Admin</title>
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
            <div class="px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="admin-invoices.php" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-2xl font-semibold text-gray-900">Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                        echo match($invoice['status']) {
                            'paid' => 'bg-green-100 text-green-700',
                            'unpaid' => 'bg-yellow-100 text-yellow-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>"><?php echo ucfirst($invoice['status']); ?></span>
                </div>
                <div class="flex items-center gap-2">
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
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                        <div class="px-8 py-6 bg-gray-50 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></h2>
                                    <p class="text-sm text-gray-500 mt-1">Created <?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-4xl font-bold text-gray-900">$<?php echo number_format($invoice['amount'], 2); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="px-8 py-6">
                            <div class="grid grid-cols-2 gap-8 mb-6">
                                <div>
                                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Bill To</h3>
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($invoice['client_name'] ?? 'N/A'); ?></p>
                                    <?php if ($invoice['client_company']): ?>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['client_company']); ?></p>
                                    <?php endif; ?>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($invoice['client_email'] ?? ''); ?></p>
                                </div>
                                <div>
                                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Invoice Info</h3>
                                    <div class="space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Due Date:</span>
                                            <span class="font-medium"><?php echo $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : 'N/A'; ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Status:</span>
                                            <span class="font-medium"><?php echo ucfirst($invoice['status']); ?></span>
                                        </div>
                                        <?php if ($invoice['paid_date']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Paid Date:</span>
                                            <span class="font-medium"><?php echo date('M d, Y', strtotime($invoice['paid_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-gray-200 pt-6">
                                <table class="w-full">
                                    <thead>
                                        <tr class="text-xs font-semibold text-gray-500 uppercase">
                                            <th class="text-left pb-3">Description</th>
                                            <th class="text-right pb-3">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-t border-gray-200">
                                        <tr>
                                            <td class="py-4 text-gray-900">Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                            <td class="py-4 text-right font-semibold text-gray-900">$<?php echo number_format($invoice['amount'], 2); ?></td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="border-t-2 border-gray-300">
                                        <tr>
                                            <td class="py-4 font-bold text-gray-900">Total</td>
                                            <td class="py-4 text-right font-bold text-gray-900 text-lg">$<?php echo number_format($invoice['amount'], 2); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-900">Payment History</h3>
                        </div>
                        <?php if (empty($payments)): ?>
                            <div class="p-6 text-center text-gray-500 text-sm">
                                No payments recorded yet.
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($payments as $p): ?>
                                    <div class="px-6 py-4">
                                        <div class="flex items-center justify-between mb-1">
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
</body>
</html>