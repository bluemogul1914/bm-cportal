<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$pdo = getDB();

try {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_id = $client ? $client['id'] : $user_id;

    $status_filter = $_GET['status'] ?? 'all';

    $where = "WHERE i.client_id = ?";
    $params = [$client_id];
    if ($status_filter !== 'all') {
        $where .= " AND i.status = ?";
        $params[] = $status_filter;
    }

    $stmt = $pdo->prepare("SELECT i.* FROM invoices i $where ORDER BY i.created_at DESC");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM invoices WHERE client_id = ? AND status = 'unpaid'");
    $stmt->execute([$client_id]);
    $outstanding = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM invoices WHERE client_id = ? AND status = 'paid'");
    $stmt->execute([$client_id]);
    $total_paid = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $pdo->prepare("SELECT p.* FROM payments p WHERE p.client_id = ? ORDER BY p.created_at DESC LIMIT 10");
    $stmt->execute([$client_id]);
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE client_id = ? AND status = 'unpaid' AND due_date < CURRENT_DATE");
    $stmt->execute([$client_id]);
    $overdue_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

} catch (PDOException $e) {
    error_log("Billing error: " . $e->getMessage());
    $invoices = [];
    $outstanding = 0;
    $total_paid = 0;
    $recent_payments = [];
    $overdue_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing - Blue Mogul Client Portal</title>
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
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <h1 class="text-2xl font-semibold text-gray-900">Billing & Invoices</h1>
                <p class="text-sm text-gray-600 mt-1">View invoices and manage payments</p>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="bg-red-100 rounded-lg p-3"><i class="fas fa-exclamation-triangle text-red-600 text-xl"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Outstanding Balance</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="text-outstanding">$<?php echo number_format($outstanding, 2); ?></p>
                    <?php if ($overdue_count > 0): ?>
                        <p class="text-xs text-red-600 mt-1"><i class="fas fa-clock mr-1"></i><?php echo $overdue_count; ?> overdue</p>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="bg-green-100 rounded-lg p-3"><i class="fas fa-check-circle text-green-600 text-xl"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total Paid</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="text-total-paid">$<?php echo number_format($total_paid, 2); ?></p>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="bg-blue-100 rounded-lg p-3"><i class="fas fa-file-invoice text-blue-600 text-xl"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total Invoices</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="text-total-invoices"><?php echo count($invoices); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Invoices</h2>
                    <div class="flex items-center gap-2">
                        <a href="billing.php" class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $status_filter === 'all' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition" data-testid="filter-all">All</a>
                        <a href="billing.php?status=unpaid" class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $status_filter === 'unpaid' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition" data-testid="filter-unpaid">Unpaid</a>
                        <a href="billing.php?status=paid" class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $status_filter === 'paid' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition" data-testid="filter-paid">Paid</a>
                    </div>
                </div>

                <?php if (empty($invoices)): ?>
                    <div class="text-center py-16">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                            <i class="fas fa-file-invoice text-gray-400 text-2xl"></i>
                        </div>
                        <p class="text-gray-900 font-semibold">No invoices found</p>
                        <p class="text-sm text-gray-500 mt-1">Your invoices will appear here</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Invoice</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Due Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($invoices as $invoice): ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="invoice-row-<?php echo $invoice['id']; ?>">
                                        <td class="px-6 py-4">
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></p>
                                        </td>
                                        <td class="px-6 py-4 font-semibold text-gray-900">$<?php echo number_format($invoice['amount'], 2); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            <?php echo $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : 'N/A'; ?>
                                            <?php if ($invoice['status'] === 'unpaid' && $invoice['due_date'] && strtotime($invoice['due_date']) < time()): ?>
                                                <span class="text-red-600 text-xs font-medium ml-1">(Overdue)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                                echo match($invoice['status']) {
                                                    'paid' => 'bg-green-100 text-green-700',
                                                    'unpaid' => 'bg-yellow-100 text-yellow-700',
                                                    'overdue' => 'bg-red-100 text-red-700',
                                                    default => 'bg-gray-100 text-gray-700'
                                                };
                                            ?>"><?php echo ucfirst($invoice['status']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if ($invoice['status'] === 'unpaid'): ?>
                                                <a href="pay-invoice.php?id=<?php echo $invoice['id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md font-medium text-sm transition" data-testid="button-pay-<?php echo $invoice['id']; ?>">
                                                    Pay Now
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm"><i class="fas fa-check mr-1"></i>Paid</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($recent_payments)): ?>
            <div class="bg-white rounded-lg border border-gray-200 mt-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Recent Payments</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($recent_payments as $payment): ?>
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="bg-green-100 rounded-lg p-2">
                                    <i class="fas fa-check text-green-600"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 text-sm">Payment of $<?php echo number_format($payment['amount'], 2); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo date('M d, Y g:i A', strtotime($payment['created_at'])); ?> via <?php echo ucfirst($payment['method'] ?? 'stripe'); ?></p>
                                </div>
                            </div>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium"><?php echo ucfirst($payment['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>