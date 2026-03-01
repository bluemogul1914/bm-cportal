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
$recent_payments = [];

try {
    $pdo = getDB();

    $total_paid = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='paid'")->fetchColumn();
    $total_unpaid = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='unpaid'")->fetchColumn();

    $stmt = $pdo->query("SELECT p.*, i.invoice_number FROM payments p JOIN invoices i ON p.invoice_id = i.id ORDER BY p.payment_date DESC LIMIT 10");
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // tables may not exist yet
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-connection-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Connection Status</p>
                    <?php if ($stripe_secret_set): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">Secret key configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Secret key not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-public-key-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Public Key Status</p>
                    <?php if ($stripe_public_set): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Set</p>
                        <p class="text-xs text-gray-400 mt-1">Public key configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Not Set</p>
                        <p class="text-xs text-gray-400 mt-1">Public key missing</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-webhook-status">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Webhook Status</p>
                    <?php if ($stripe_webhook_set): ?>
                        <p class="text-lg font-bold text-green-600"><i class="fas fa-check-circle mr-1"></i>Active</p>
                        <p class="text-xs text-gray-400 mt-1">Webhook secret configured</p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600"><i class="fas fa-times-circle mr-1"></i>Inactive</p>
                        <p class="text-xs text-gray-400 mt-1">Webhook secret not set</p>
                    <?php endif; ?>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-total-revenue">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Revenue</p>
                    <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($total_paid, 2); ?></p>
                    <p class="text-xs text-gray-400 mt-1">From paid invoices</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-paid-invoices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Paid</p>
                    <p class="text-2xl font-bold text-green-600">$<?php echo number_format($total_paid, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-unpaid-invoices">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Unpaid</p>
                    <p class="text-2xl font-bold text-red-600">$<?php echo number_format($total_unpaid, 2); ?></p>
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

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-history text-primary mr-2"></i>Recent Payments</h2>
                </div>
                <?php if (!empty($recent_payments)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-recent-payments">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Invoice #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Method</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($recent_payments as $index => $payment): ?>
                                <tr class="hover:bg-gray-50 transition" data-testid="payment-row-<?php echo $index; ?>">
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['invoice_number'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900">$<?php echo number_format($payment['amount'] ?? 0, 2); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars(ucfirst($payment['payment_method'] ?? 'N/A')); ?></td>
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

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-bolt text-primary mr-2"></i>Features</h2>
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
        </div>
    </div>
</div>
</body>
</html>