<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    header('Location: /portal');
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$stripe_secret_set = !empty(STRIPE_SECRET_KEY);
$stripe_public_set = !empty(STRIPE_PUBLIC_KEY);
$stripe_webhook_set = !empty(STRIPE_WEBHOOK_SECRET);
$stripe_connected = $stripe_secret_set && $stripe_public_set;

$pdo = getDB();

$recent_payments = [];
$total_revenue = 0;
$payments_this_month = 0;
$payments_count = 0;

try {
    $stmt = $pdo->query("SELECT p.*, c.name as client_name, c.company as client_company FROM payments p LEFT JOIN clients c ON p.client_id = c.id ORDER BY p.created_at DESC LIMIT 10");
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_revenue = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'succeeded'")->fetchColumn();
    $payments_this_month = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'succeeded' AND created_at >= date_trunc('month', CURRENT_DATE)")->fetchColumn();
    $payments_count = $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
} catch (PDOException $e) {
    // payments table may not exist yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stripe Integration - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-credit-card text-blue-500 mr-2"></i>Stripe Integration</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Payment processing &mdash; Billing, invoices, and payment tracking</p>
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($stripe_connected): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-revenue">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Revenue</p>
                    <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($total_revenue / 100, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-monthly-revenue">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">This Month</p>
                    <p class="text-2xl font-bold text-blue-600">$<?php echo number_format($payments_this_month / 100, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-payments">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Payments</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format($payments_count); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cog text-gray-500 mr-2"></i>Configuration</h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between py-3 border-b border-gray-100">
                            <div>
                                <p class="text-sm font-medium text-gray-900">STRIPE_SECRET_KEY</p>
                                <p class="text-xs text-gray-500">Set in Replit Secrets</p>
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
                                <p class="text-xs text-gray-500">Set in Replit Secrets</p>
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
                                <p class="text-xs text-gray-500">Set in Replit Secrets</p>
                            </div>
                            <?php if ($stripe_webhook_set): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-medium" data-testid="env-stripe-webhook"><i class="fas fa-check mr-1"></i>Set</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs font-medium" data-testid="env-stripe-webhook"><i class="fas fa-times mr-1"></i>Not Set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-history text-blue-500 mr-2"></i>Recent Payment Activity</h2>
                </div>
                <?php if (!empty($recent_payments)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Stripe ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($recent_payments as $payment): ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="payment-row-<?php echo $payment['id']; ?>">
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo date('M d, Y g:i A', strtotime($payment['created_at'])); ?></td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['client_company'] ?: $payment['client_name'] ?: 'N/A'); ?></td>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900">$<?php echo number_format($payment['amount'] / 100, 2); ?></td>
                                    <td class="px-4 py-3">
                                        <?php
                                            $status = $payment['status'] ?? 'unknown';
                                            $status_classes = match($status) {
                                                'succeeded' => 'bg-green-100 text-green-700',
                                                'pending' => 'bg-yellow-100 text-yellow-700',
                                                'failed' => 'bg-red-100 text-red-700',
                                                default => 'bg-gray-100 text-gray-700',
                                            };
                                        ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $status_classes; ?>"><?php echo ucfirst($status); ?></span>
                                    </td>
                                    <td class="px-4 py-3"><code class="text-xs font-mono text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($payment['stripe_payment_intent_id'] ?? 'N/A'); ?></code></td>
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
    </div>
</div>
</body>
</html>