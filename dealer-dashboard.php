<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'dealer' && !($_SESSION['is_admin'] ?? false))) {
    portal_redirect('/portal');
}
$user_name  = $_SESSION['user_name'] ?? 'Dealer';
$user_email = $_SESSION['user_email'] ?? '';
$user_id    = $_SESSION['user_id'];
$pdo = getDB();

// Load dealer record
$dealer = $pdo->prepare("SELECT * FROM dealers WHERE user_id = ?");
$dealer->execute([$user_id]);
$dealer = $dealer->fetch(PDO::FETCH_ASSOC);

if (!$dealer) {
    // Create dealer record if missing
    $code = strtoupper(substr(preg_replace('/[^A-Z0-9]/','',$user_name),0,5)) . strtoupper(substr(bin2hex(random_bytes(3)),0,4));
    $pdo->prepare("INSERT INTO dealers (user_id, company_name, referral_code, status) VALUES (?,?,?,'active')")
        ->execute([$user_id, $user_name, $code]);
    $dealer = $pdo->prepare("SELECT * FROM dealers WHERE user_id = ?");
    $dealer->execute([$user_id]);
    $dealer = $dealer->fetch(PDO::FETCH_ASSOC);
}
$dealer_id = $dealer['id'];

// Stats
$total_orders    = (int)$pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=?")->execute([$dealer_id]) ? $pdo->query("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=$dealer_id")->fetchColumn() : 0;
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=?"); $s->execute([$dealer_id]); $total_orders = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=? AND status='pending'"); $s->execute([$dealer_id]); $pending_orders = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='pending'"); $s->execute([$dealer_id]); $pending_commissions = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='paid'"); $s->execute([$dealer_id]); $paid_commissions = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='approved'"); $s->execute([$dealer_id]); $approved_commissions = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_customers WHERE dealer_id=?"); $s->execute([$dealer_id]); $total_customers = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_customers WHERE dealer_id=? AND type='lead'"); $s->execute([$dealer_id]); $total_leads = (int)$s->fetchColumn();

// Recent orders
$recent = $pdo->prepare("SELECT * FROM dealer_orders WHERE dealer_id=? ORDER BY created_at DESC LIMIT 5"); $recent->execute([$dealer_id]); $recent_orders = $recent->fetchAll(PDO::FETCH_ASSOC);

// Recent commissions
$rcomm = $pdo->prepare("SELECT dc.*, do.product_line, do.customer_name FROM dealer_commissions dc LEFT JOIN dealer_orders do ON dc.order_id=do.id WHERE dc.dealer_id=? ORDER BY dc.created_at DESC LIMIT 5"); $rcomm->execute([$dealer_id]); $recent_commissions = $rcomm->fetchAll(PDO::FETCH_ASSOC);

$status_colors = ['pending'=>'bg-yellow-100 text-yellow-800','in_progress'=>'bg-blue-100 text-blue-800','completed'=>'bg-green-100 text-green-800','cancelled'=>'bg-red-100 text-red-800'];
$comm_colors   = ['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-blue-100 text-blue-800','paid'=>'bg-green-100 text-green-800'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Partner Dashboard — Blue Mogul</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/dealer-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
        <div>
            <p class="text-xs text-gray-400">Partner Portal</p>
            <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-tachometer-alt text-blue-600 mr-2"></i>Dashboard</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-2 flex items-center gap-2">
                <i class="fas fa-fingerprint text-blue-500 text-sm"></i>
                <span class="text-xs text-blue-600 font-medium">Referral Code:</span>
                <span class="font-mono font-bold text-blue-800 text-sm" data-testid="text-referral-code"><?= htmlspecialchars($dealer['referral_code']) ?></span>
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($dealer['referral_code']) ?>');this.innerHTML='<i class=\'fas fa-check text-green-500\'></i>'" class="ml-1 text-gray-400 hover:text-blue-600" title="Copy code" data-testid="button-copy-referral-code">
                    <i class="fas fa-copy text-xs"></i>
                </button>
            </div>
            <a href="dealer-orders.php" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition flex items-center gap-2" data-testid="button-submit-order">
                <i class="fas fa-plus"></i> Submit Order
            </a>
        </div>
    </div>
</header>

<div class="p-6 space-y-6">

    <!-- Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-2xl p-6 text-white">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-xl font-bold">Welcome back, <?= htmlspecialchars($user_name) ?>!</h2>
                <p class="text-blue-200 text-sm mt-1"><?= htmlspecialchars($dealer['company_name'] ?? 'Your Company') ?> · <?= number_format($dealer['commission_rate'] ?? 10, 1) ?>% commission rate</p>
            </div>
            <div class="flex gap-4">
                <div class="text-center">
                    <p class="text-2xl font-bold"><?= $total_orders ?></p>
                    <p class="text-blue-200 text-xs">Total Orders</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold">$<?= number_format($paid_commissions + $approved_commissions + $pending_commissions, 0) ?></p>
                    <p class="text-blue-200 text-xs">Lifetime Commissions</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5" data-testid="card-pending-orders">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 bg-yellow-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-clock text-yellow-600"></i>
                </div>
                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full font-medium">Pending</span>
            </div>
            <p class="text-2xl font-bold text-gray-900"><?= $pending_orders ?></p>
            <p class="text-sm text-gray-500">Pending Orders</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5" data-testid="card-pending-commissions">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-dollar-sign text-orange-600"></i>
                </div>
                <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full font-medium">Pending</span>
            </div>
            <p class="text-2xl font-bold text-gray-900">$<?= number_format($pending_commissions, 2) ?></p>
            <p class="text-sm text-gray-500">Pending Commission</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5" data-testid="card-approved-commissions">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-thumbs-up text-blue-600"></i>
                </div>
                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-medium">Approved</span>
            </div>
            <p class="text-2xl font-bold text-gray-900">$<?= number_format($approved_commissions, 2) ?></p>
            <p class="text-sm text-gray-500">Approved Commission</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5" data-testid="card-paid-commissions">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-600"></i>
                </div>
                <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Paid</span>
            </div>
            <p class="text-2xl font-bold text-gray-900">$<?= number_format($paid_commissions, 2) ?></p>
            <p class="text-sm text-gray-500">Total Paid Out</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Orders -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900"><i class="fas fa-clipboard-list text-blue-500 mr-2"></i>Recent Orders</h3>
                <a href="dealer-orders.php" class="text-sm text-blue-600 hover:underline">View all</a>
            </div>
            <?php if ($recent_orders): ?>
            <div class="divide-y divide-gray-50">
                <?php foreach ($recent_orders as $o): ?>
                <div class="px-5 py-3 flex items-center justify-between" data-testid="row-order-<?= $o['id'] ?>">
                    <div>
                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($o['customer_name']) ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars($o['product_line']) ?> · <?= date('M j', strtotime($o['created_at'])) ?></p>
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full font-medium <?= $status_colors[$o['status']] ?? 'bg-gray-100 text-gray-700' ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="px-5 py-10 text-center text-gray-400">
                <i class="fas fa-clipboard-list text-3xl mb-3 block"></i>
                <p class="text-sm">No orders yet. <a href="dealer-orders.php" class="text-blue-600 hover:underline">Submit your first order</a></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Commissions -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900"><i class="fas fa-dollar-sign text-yellow-500 mr-2"></i>Commission Activity</h3>
                <a href="dealer-commissions.php" class="text-sm text-blue-600 hover:underline">View all</a>
            </div>
            <?php if ($recent_commissions): ?>
            <div class="divide-y divide-gray-50">
                <?php foreach ($recent_commissions as $c): ?>
                <div class="px-5 py-3 flex items-center justify-between" data-testid="row-commission-<?= $c['id'] ?>">
                    <div>
                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($c['customer_name'] ?? 'Commission') ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars($c['product_line'] ?? '') ?> · <?= date('M j', strtotime($c['created_at'])) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-gray-900">$<?= number_format($c['amount'], 2) ?></p>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $comm_colors[$c['status']] ?? 'bg-gray-100 text-gray-700' ?>"><?= ucfirst($c['status']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="px-5 py-10 text-center text-gray-400">
                <i class="fas fa-dollar-sign text-3xl mb-3 block"></i>
                <p class="text-sm">No commissions yet.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-900 mb-4"><i class="fas fa-bolt text-yellow-500 mr-2"></i>Quick Actions</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <a href="dealer-orders.php?new=1" class="flex flex-col items-center gap-2 p-4 bg-blue-50 hover:bg-blue-100 rounded-xl transition text-center" data-testid="button-quick-order">
                <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center"><i class="fas fa-plus text-white"></i></div>
                <span class="text-sm font-medium text-blue-800">New Order</span>
            </a>
            <a href="dealer-customers.php?new=1" class="flex flex-col items-center gap-2 p-4 bg-purple-50 hover:bg-purple-100 rounded-xl transition text-center" data-testid="button-quick-customer">
                <div class="w-10 h-10 bg-purple-600 rounded-xl flex items-center justify-center"><i class="fas fa-user-plus text-white"></i></div>
                <span class="text-sm font-medium text-purple-800">Add Customer</span>
            </a>
            <a href="dealer-payouts.php?new=1" class="flex flex-col items-center gap-2 p-4 bg-green-50 hover:bg-green-100 rounded-xl transition text-center" data-testid="button-quick-payout">
                <div class="w-10 h-10 bg-green-600 rounded-xl flex items-center justify-center"><i class="fas fa-money-bill-wave text-white"></i></div>
                <span class="text-sm font-medium text-green-800">Request Payout</span>
            </a>
            <a href="dealer-training.php" class="flex flex-col items-center gap-2 p-4 bg-orange-50 hover:bg-orange-100 rounded-xl transition text-center" data-testid="button-quick-training">
                <div class="w-10 h-10 bg-orange-600 rounded-xl flex items-center justify-center"><i class="fas fa-book-open text-white"></i></div>
                <span class="text-sm font-medium text-orange-800">Training Docs</span>
            </a>
        </div>
    </div>

</div>
</div>
</div>
</body>
</html>
