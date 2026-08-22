<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$stripe_secret_set = !empty(getenv('STRIPE_SECRET_KEY'));
$stripe_public_set = !empty(getenv('STRIPE_PUBLIC_KEY'));
$stripe_webhook_set = !empty(getenv('STRIPE_WEBHOOK_SECRET'));
$stripe_connected = $stripe_secret_set && $stripe_public_set;

$total_paid = 0;
$total_unpaid = 0;
$paid_count = 0;
$unpaid_count = 0;
$total_revenue = 0;
$recent_payments = [];
$recent_invoices = [];
$monthly_revenue = [];

try {
    $pdo = getDB();

    $stmt = $pdo->query("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM invoices WHERE status='paid'");
    $row = $stmt->fetch();
    $total_paid = $row['total'] ?? 0;
    $paid_count = $row['cnt'] ?? 0;

    $stmt = $pdo->query("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM invoices WHERE status='unpaid'");
    $row = $stmt->fetch();
    $total_unpaid = $row['total'] ?? 0;
    $unpaid_count = $row['cnt'] ?? 0;

    $total_revenue = $total_paid;

    $stmt = $pdo->query("SELECT p.*, i.invoice_number, c.name as client_name, c.company as client_company FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.id LEFT JOIN clients c ON i.client_id = c.id ORDER BY p.payment_date DESC LIMIT 10");
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT i.*, c.name as client_name, c.company as client_company FROM invoices i LEFT JOIN clients c ON i.client_id = c.id ORDER BY i.created_at DESC LIMIT 10");
    $recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT TO_CHAR(payment_date, 'YYYY-MM') as month, SUM(amount) as total FROM payments GROUP BY TO_CHAR(payment_date, 'YYYY-MM') ORDER BY month DESC LIMIT 6");
    $monthly_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // tables may not exist yet
}

$total_invoices = $paid_count + $unpaid_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stripe Integration - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-credit-card text-primary mr-2"></i>Stripe Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Payment processing, billing, and invoice management</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($stripe_connected): ?>
                        <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="status-connected"><i class="fas fa-circle text-[8px] mr-1"></i>Connected</span>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-medium" data-testid="status-disconnected"><i class="fas fa-circle text-[8px] mr-1"></i>Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-revenue">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Revenue</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-revenue">$<?php echo number_format($total_revenue, 2); ?></p>
                    <p class="text-xs text-gray-400 mt-1">From <?php echo (int)$paid_count; ?> paid invoices</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-pending-amount">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Pending Amount</p>
                    <p class="text-2xl font-bold text-yellow-600" data-testid="text-pending-amount">$<?php echo number_format($total_unpaid, 2); ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo (int)$unpaid_count; ?> unpaid invoices</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-paid-invoices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Paid Invoices</p>
                    <p class="text-2xl font-bold text-green-600" data-testid="text-paid-count"><?php echo (int)$paid_count; ?></p>
                    <p class="text-xs text-gray-400 mt-1">$<?php echo number_format($total_paid, 2); ?> collected</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-invoices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Invoices</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-invoices"><?php echo (int)$total_invoices; ?></p>
                    <p class="text-xs text-gray-400 mt-1">All time</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Secret Key</p>
                    <?php if ($stripe_secret_set): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Configured</p>
                        <p class="text-xs text-gray-400 mt-1">STRIPE_SECRET_KEY is set</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Not Set</p>
                        <p class="text-xs text-gray-400 mt-1">Required for payments</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-public-key-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Public Key</p>
                    <?php if ($stripe_public_set): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Configured</p>
                        <p class="text-xs text-gray-400 mt-1">STRIPE_PUBLIC_KEY is set</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Not Set</p>
                        <p class="text-xs text-gray-400 mt-1">Required for checkout</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-webhook-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Webhook Secret</p>
                    <?php if ($stripe_webhook_set): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Configured</p>
                        <p class="text-xs text-gray-400 mt-1">STRIPE_WEBHOOK_SECRET is set</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-yellow-600"><i class="fas fa-exclamation-circle mr-1"></i>Not Set</p>
                        <p class="text-xs text-gray-400 mt-1">Recommended for production</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-recent-payments-title"><i class="fas fa-history text-primary mr-2"></i>Recent Payments</h2>
                            <span class="text-xs text-gray-400">Last 10 transactions</span>
                        </div>
                        <?php if (!empty($recent_payments)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full" data-testid="table-recent-payments">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Invoice #</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Method</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($recent_payments as $index => $payment): ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="payment-row-<?php echo $index; ?>">
                                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['invoice_number'] ?? 'N/A'); ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($payment['client_company'] ?: ($payment['client_name'] ?? 'N/A')); ?></td>
                                            <td class="px-4 py-3 text-sm font-semibold text-gray-900">$<?php echo number_format($payment['amount'] ?? 0, 2); ?></td>
                                            <td class="px-4 py-3">
                                                <?php
                                                    $method = strtolower($payment['payment_method'] ?? '');
                                                    $method_icon = match(true) {
                                                        str_contains($method, 'card') || str_contains($method, 'stripe') => 'fa-credit-card text-blue-500',
                                                        str_contains($method, 'bank') || str_contains($method, 'ach') => 'fa-university text-green-500',
                                                        str_contains($method, 'paypal') => 'fa-paypal text-indigo-500',
                                                        default => 'fa-money-bill text-green-500',
                                                    };
                                                ?>
                                                <span class="text-xs text-gray-600"><i class="fas <?php echo $method_icon; ?> mr-1"></i><?php echo htmlspecialchars(ucfirst($payment['payment_method'] ?? 'N/A')); ?></span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">Completed</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="p-8 text-center text-gray-500 text-sm">
                                <i class="fas fa-credit-card text-gray-300 text-2xl mb-2 block"></i>
                                No payment records found
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <?php if (!empty($monthly_revenue)): ?>
                    <div class="bg-white rounded-lg border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-monthly-revenue-title"><i class="fas fa-chart-bar text-primary mr-2"></i>Monthly Revenue</h2>
                        </div>
                        <div class="p-6">
                            <div class="space-y-3">
                                <?php
                                    $max_monthly = max(array_column($monthly_revenue, 'total'));
                                    foreach ($monthly_revenue as $mr):
                                        $pct = $max_monthly > 0 ? round(($mr['total'] / $max_monthly) * 100) : 0;
                                        $month_label = date('M Y', strtotime($mr['month'] . '-01'));
                                ?>
                                    <div data-testid="monthly-revenue-<?php echo $mr['month']; ?>">
                                        <div class="flex items-center justify-between text-sm mb-1">
                                            <span class="text-gray-700 font-medium"><?php echo $month_label; ?></span>
                                            <span class="text-gray-900 font-semibold">$<?php echo number_format($mr['total'], 2); ?></span>
                                        </div>
                                        <div class="w-full bg-gray-100 rounded-full h-2">
                                            <div class="bg-primary rounded-full h-2" style="width: <?php echo $pct; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <h2 class="text-lg font-semibold text-gray-900" data-testid="text-billing-summary-title"><i class="fas fa-file-invoice-dollar text-primary mr-2"></i>Billing Summary</h2>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <span class="text-sm text-gray-600">Total Paid</span>
                                <span class="text-sm font-semibold text-green-600" data-testid="text-summary-paid">$<?php echo number_format($total_paid, 2); ?></span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <span class="text-sm text-gray-600">Total Unpaid</span>
                                <span class="text-sm font-semibold text-red-600" data-testid="text-summary-unpaid">$<?php echo number_format($total_unpaid, 2); ?></span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <span class="text-sm text-gray-600">Paid Invoices</span>
                                <span class="text-sm font-semibold text-gray-900" data-testid="text-summary-paid-count"><?php echo (int)$paid_count; ?></span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <span class="text-sm text-gray-600">Unpaid Invoices</span>
                                <span class="text-sm font-semibold text-gray-900" data-testid="text-summary-unpaid-count"><?php echo (int)$unpaid_count; ?></span>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <span class="text-sm text-gray-600 font-medium">Collection Rate</span>
                                <span class="text-sm font-bold text-primary" data-testid="text-collection-rate"><?php echo $total_invoices > 0 ? round(($paid_count / $total_invoices) * 100, 1) : 0; ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-recent-invoices-title"><i class="fas fa-file-invoice text-primary mr-2"></i>Recent Invoices</h2>
                    <a href="admin-invoices.php" class="text-sm text-primary hover:underline" data-testid="link-view-all-invoices">View All <i class="fas fa-arrow-right text-xs ml-1"></i></a>
                </div>
                <?php if (!empty($recent_invoices)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-recent-invoices">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Invoice #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Due Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($recent_invoices as $index => $inv): ?>
                                <?php
                                    $status_class = match($inv['status'] ?? '') {
                                        'paid' => 'bg-green-100 text-green-700',
                                        'unpaid' => 'bg-red-100 text-red-700',
                                        'overdue' => 'bg-orange-100 text-orange-700',
                                        'cancelled' => 'bg-gray-100 text-gray-500',
                                        default => 'bg-gray-100 text-gray-600',
                                    };
                                ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="invoice-row-<?php echo $index; ?>">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inv['invoice_number'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($inv['client_company'] ?: ($inv['client_name'] ?? 'N/A')); ?></td>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900">$<?php echo number_format($inv['amount'] ?? 0, 2); ?></td>
                                    <td class="px-4 py-3"><span class="px-2 py-0.5 <?php echo $status_class; ?> rounded text-xs font-medium"><?php echo ucfirst(htmlspecialchars($inv['status'] ?? 'unknown')); ?></span></td>
                                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo isset($inv['due_date']) ? date('M d, Y', strtotime($inv['due_date'])) : 'N/A'; ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo isset($inv['created_at']) ? date('M d, Y', strtotime($inv['created_at'])) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="p-8 text-center text-gray-500 text-sm">
                        <i class="fas fa-file-invoice text-gray-300 text-2xl mb-2 block"></i>
                        No invoices found
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-webhook-title"><i class="fas fa-satellite-dish text-primary mr-2"></i>Webhook Configuration</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Webhook Endpoint</p>
                                    <p class="text-xs text-gray-500">Receives Stripe event notifications</p>
                                </div>
                                <code class="text-xs bg-gray-100 px-2 py-1 rounded text-gray-700" data-testid="text-webhook-url">/api/stripe/webhook</code>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Webhook Secret</p>
                                    <p class="text-xs text-gray-500">Validates incoming webhook signatures</p>
                                </div>
                                <?php if ($stripe_webhook_set): ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="text-webhook-status"><i class="fas fa-check mr-1"></i>Configured</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium" data-testid="text-webhook-status"><i class="fas fa-exclamation-triangle mr-1"></i>Not Set</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Supported Events</p>
                                    <p class="text-xs text-gray-500">Events processed by the webhook handler</p>
                                </div>
                                <div class="flex flex-wrap gap-1 justify-end">
                                    <span class="px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded text-[10px] font-medium">checkout.session.completed</span>
                                    <span class="px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded text-[10px] font-medium">payment_intent.succeeded</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900" data-testid="text-payment-methods-title"><i class="fas fa-wallet text-primary mr-2"></i>Payment Methods</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center gap-4 py-2 border-b border-gray-100" data-testid="method-credit-card">
                                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-credit-card text-blue-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">Credit / Debit Cards</p>
                                    <p class="text-xs text-gray-500">Visa, Mastercard, Amex, Discover</p>
                                </div>
                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">Active</span>
                            </div>
                            <div class="flex items-center gap-4 py-2 border-b border-gray-100" data-testid="method-ach">
                                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-university text-green-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">ACH Bank Transfer</p>
                                    <p class="text-xs text-gray-500">Direct bank debit payments</p>
                                </div>
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-xs font-medium">Available</span>
                            </div>
                            <div class="flex items-center gap-4 py-2" data-testid="method-link">
                                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-link text-purple-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">Stripe Link</p>
                                    <p class="text-xs text-gray-500">One-click checkout for returning customers</p>
                                </div>
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-xs font-medium">Available</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-features-title"><i class="fas fa-bolt text-primary mr-2"></i>Features</h2>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    <div class="text-center p-4 rounded-lg border border-gray-100" data-testid="feature-payment-processing">
                        <i class="fas fa-credit-card text-primary text-2xl mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Payment Processing</p>
                        <p class="text-xs text-gray-500 mt-1">Accept online payments</p>
                    </div>
                    <div class="text-center p-4 rounded-lg border border-gray-100" data-testid="feature-subscription-billing">
                        <i class="fas fa-sync-alt text-primary text-2xl mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Subscription Billing</p>
                        <p class="text-xs text-gray-500 mt-1">Recurring payment plans</p>
                    </div>
                    <div class="text-center p-4 rounded-lg border border-gray-100" data-testid="feature-invoice-generation">
                        <i class="fas fa-file-invoice text-primary text-2xl mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Invoice Generation</p>
                        <p class="text-xs text-gray-500 mt-1">Automated invoicing</p>
                    </div>
                    <div class="text-center p-4 rounded-lg border border-gray-100" data-testid="feature-webhook-events">
                        <i class="fas fa-satellite-dish text-primary text-2xl mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Webhook Events</p>
                        <p class="text-xs text-gray-500 mt-1">Real-time notifications</p>
                    </div>
                    <div class="text-center p-4 rounded-lg border border-gray-100" data-testid="feature-customer-portal">
                        <i class="fas fa-user-circle text-primary text-2xl mb-2"></i>
                        <p class="text-sm font-medium text-gray-900">Customer Portal</p>
                        <p class="text-xs text-gray-500 mt-1">Self-service billing</p>
                    </div>
                </div>
            </div>

            <?php if (!$stripe_connected): ?>
            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-setup-title"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Setup Instructions</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-start gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
                            <div>
                                <p class="text-sm font-medium text-gray-900">Create a Stripe Account</p>
                                <p class="text-xs text-gray-500 mt-0.5">Sign up at <code class="bg-gray-100 px-1 rounded text-xs">dashboard.stripe.com</code> and complete your business profile verification.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
                            <div>
                                <p class="text-sm font-medium text-gray-900">Get your API Keys</p>
                                <p class="text-xs text-gray-500 mt-0.5">Navigate to Developers &rarr; API Keys in your Stripe Dashboard. Copy both the publishable and secret keys.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
                            <div>
                                <p class="text-sm font-medium text-gray-900">Add Keys to Replit Secrets</p>
                                <p class="text-xs text-gray-500 mt-0.5">Add <code class="bg-gray-100 px-1 rounded text-xs">STRIPE_SECRET_KEY</code>, <code class="bg-gray-100 px-1 rounded text-xs">STRIPE_PUBLIC_KEY</code>, and <code class="bg-gray-100 px-1 rounded text-xs">STRIPE_WEBHOOK_SECRET</code> to Replit Secrets.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-6 h-6 rounded-full bg-primary text-white text-xs font-bold flex items-center justify-center flex-shrink-0">4</span>
                            <div>
                                <p class="text-sm font-medium text-gray-900">Configure Webhooks</p>
                                <p class="text-xs text-gray-500 mt-0.5">In Stripe Dashboard, go to Developers &rarr; Webhooks and add your endpoint URL. Select events like <code class="bg-gray-100 px-1 rounded text-xs">checkout.session.completed</code>.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900" data-testid="text-config-title"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">STRIPE_SECRET_KEY</p>
                                <p class="text-xs text-gray-500">Server-side API key &mdash; Set in Replit Secrets</p>
                            </div>
                            <?php if ($stripe_secret_set): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-stripe-secret"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-stripe-secret"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">STRIPE_PUBLIC_KEY</p>
                                <p class="text-xs text-gray-500">Client-side publishable key &mdash; Set in Replit Secrets</p>
                            </div>
                            <?php if ($stripe_public_set): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-stripe-public"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-stripe-public"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">STRIPE_WEBHOOK_SECRET</p>
                                <p class="text-xs text-gray-500">Webhook signature verification &mdash; Set in Replit Secrets</p>
                            </div>
                            <?php if ($stripe_webhook_set): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-stripe-webhook"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-stripe-webhook"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">API Version</p>
                                <p class="text-xs text-gray-500">Stripe API version used for requests</p>
                            </div>
                            <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-medium" data-testid="text-api-version">2023-10-16</span>
                        </div>
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">Payment Mode</p>
                                <p class="text-xs text-gray-500">Current Stripe environment</p>
                            </div>
                            <?php if ($stripe_connected): ?>
                                <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-medium" data-testid="text-payment-mode"><i class="fas fa-lock mr-1"></i>Live Mode</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium" data-testid="text-payment-mode"><i class="fas fa-flask mr-1"></i>Not Configured</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>