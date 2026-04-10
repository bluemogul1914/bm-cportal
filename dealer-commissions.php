<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'dealer' && !($_SESSION['is_admin'] ?? false))) {
    portal_redirect('/portal');
}
$user_name  = $_SESSION['user_name'] ?? 'Dealer';
$user_email = $_SESSION['user_email'] ?? '';
$user_id    = $_SESSION['user_id'];
$pdo = getDB();

$dealer = $pdo->prepare("SELECT * FROM dealers WHERE user_id=?"); $dealer->execute([$user_id]); $dealer = $dealer->fetch(PDO::FETCH_ASSOC);
if (!$dealer) portal_redirect('/portal/dealer-dashboard.php');
$dealer_id = $dealer['id'];

// Stats
$s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='approved'"); $s->execute([$dealer_id]); $approved = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_commissions WHERE dealer_id=? AND status='approved'"); $s->execute([$dealer_id]); $approved_cnt = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='pending'"); $s->execute([$dealer_id]); $pending = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_commissions WHERE dealer_id=? AND status='pending'"); $s->execute([$dealer_id]); $pending_cnt = (int)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='paid'"); $s->execute([$dealer_id]); $paid = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=? AND status='completed'"); $s->execute([$dealer_id]); $activations = (int)$s->fetchColumn();

// Tier calc
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=? AND status='completed' AND created_at >= date_trunc('month', CURRENT_DATE)"); $s->execute([$dealer_id]); $month_activations = (int)$s->fetchColumn();
$tier = $month_activations >= 10 ? 'gold' : ($month_activations >= 5 ? 'silver' : 'base');
$next_tier = $tier === 'gold' ? null : ($tier === 'silver' ? 10 : 5);
$tier_progress_pct = $tier !== 'gold' ? min(100, round($month_activations / $next_tier * 100)) : 100;

// Filter
$filter_status = $_GET['status'] ?? '';
$filter_prod   = $_GET['product'] ?? '';
$page = max(1,(int)($_GET['page']??1)); $per=20;
$where = ["dc.dealer_id=?"]; $params = [$dealer_id];
if ($filter_status) { $where[]="dc.status=?"; $params[]=$filter_status; }
if ($filter_prod)   { $where[]="do.product_line=?"; $params[]=$filter_prod; }
$wsql = implode(' AND ',$where);
$t=$pdo->prepare("SELECT COUNT(*) FROM dealer_commissions dc LEFT JOIN dealer_orders do ON dc.order_id=do.id WHERE $wsql");
$t->execute($params); $total=(int)$t->fetchColumn();
$total_pages=max(1,ceil($total/$per)); $offset=($page-1)*$per;
$c=$pdo->prepare("SELECT dc.*,do.product_line,do.customer_name,do.customer_email FROM dealer_commissions dc LEFT JOIN dealer_orders do ON dc.order_id=do.id WHERE $wsql ORDER BY dc.created_at DESC LIMIT $per OFFSET $offset");
$c->execute($params); $commissions=$c->fetchAll(PDO::FETCH_ASSOC);

// Distinct products for filter
$prods_q=$pdo->prepare("SELECT DISTINCT do.product_line FROM dealer_commissions dc LEFT JOIN dealer_orders do ON dc.order_id=do.id WHERE dc.dealer_id=? AND do.product_line IS NOT NULL");
$prods_q->execute([$dealer_id]); $prod_options=$prods_q->fetchAll(PDO::FETCH_COLUMN);

$status_cfg = [
    'paid'     =>['label'=>'Paid',       'badge'=>'bg-green-100 text-green-800',  'dot'=>'bg-green-500'],
    'approved' =>['label'=>'Approved',   'badge'=>'bg-blue-100 text-blue-800',    'dot'=>'bg-blue-500'],
    'pending'  =>['label'=>'Pending',    'badge'=>'bg-yellow-100 text-yellow-800','dot'=>'bg-yellow-500'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Commissions — Blue Mogul Partner</title>
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
    <div class="px-6 py-4">
        <p class="text-xs text-gray-400">Partner Portal /</p>
        <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-dollar-sign text-yellow-500 mr-2"></i>Commissions</h1>
    </div>
</header>

<div class="p-6 space-y-6">

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5" data-testid="card-approved">
            <div class="flex items-center justify-between mb-2">
                <div class="w-9 h-9 bg-blue-100 rounded-xl flex items-center justify-center"><i class="fas fa-thumbs-up text-blue-600 text-sm"></i></div>
                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Approved</span>
            </div>
            <p class="text-2xl font-bold text-blue-700">$<?= number_format($approved,2) ?></p>
            <p class="text-sm text-gray-500"><?= $approved_cnt ?> order<?= $approved_cnt!=1?'s':'' ?></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5" data-testid="card-pending">
            <div class="flex items-center justify-between mb-2">
                <div class="w-9 h-9 bg-yellow-100 rounded-xl flex items-center justify-center"><i class="fas fa-clock text-yellow-600 text-sm"></i></div>
                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">Pending</span>
            </div>
            <p class="text-2xl font-bold text-yellow-700">$<?= number_format($pending,2) ?></p>
            <p class="text-sm text-gray-500"><?= $pending_cnt ?> order<?= $pending_cnt!=1?'s':'' ?> under review</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5" data-testid="card-paid-total">
            <div class="flex items-center justify-between mb-2">
                <div class="w-9 h-9 bg-green-100 rounded-xl flex items-center justify-center"><i class="fas fa-check-circle text-green-600 text-sm"></i></div>
                <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">Paid</span>
            </div>
            <p class="text-2xl font-bold text-green-700">$<?= number_format($paid,2) ?></p>
            <p class="text-sm text-gray-500"><?= $activations ?> total activations</p>
        </div>
    </div>

    <!-- Tier Progress -->
    <?php if ($tier !== 'gold'): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="font-semibold text-gray-900">Bonus Tier Progress</h3>
                <p class="text-sm text-gray-500">
                    <?php if ($tier === 'silver'): ?><span class="text-gray-600 font-medium">Silver</span> → <span class="text-yellow-600 font-medium">Gold</span> (10 activations/month)
                    <?php else: ?><span class="text-blue-600 font-medium">Base</span> → <span class="text-gray-600 font-medium">Silver</span> (5 activations/month)
                    <?php endif; ?>
                </p>
            </div>
            <div class="text-right">
                <p class="text-xl font-bold text-gray-900"><?= $month_activations ?>/<?= $next_tier ?></p>
                <p class="text-xs text-gray-400">this month</p>
            </div>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-2.5">
            <div class="h-2.5 rounded-full bg-blue-600 transition-all" style="width:<?= $tier_progress_pct ?>%"></div>
        </div>
        <p class="text-xs text-gray-500 mt-2"><i class="fas fa-star text-yellow-400 mr-1"></i>Gold tier unlocks <strong>+20% spiff</strong> on all activations</p>
    </div>
    <?php else: ?>
    <div class="bg-gradient-to-r from-yellow-400 to-yellow-600 rounded-xl p-5 text-white flex items-center gap-4">
        <i class="fas fa-star text-3xl"></i>
        <div>
            <p class="font-bold text-lg">Gold Tier Active</p>
            <p class="text-yellow-100 text-sm">You're earning +20% spiff on all activations this month!</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Commission Log -->
    <div class="bg-white rounded-xl border border-gray-200">
        <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900">Commission Log <span class="text-gray-400 font-normal text-sm">(<?= $total ?>)</span></h3>
            <form method="get" class="flex flex-wrap items-center gap-2">
                <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()" data-testid="select-filter-status">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $filter_status==='pending'?'selected':'' ?>>Pending</option>
                    <option value="approved" <?= $filter_status==='approved'?'selected':'' ?>>Approved</option>
                    <option value="paid" <?= $filter_status==='paid'?'selected':'' ?>>Paid</option>
                </select>
                <?php if ($prod_options): ?>
                <select name="product" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()" data-testid="select-filter-product">
                    <option value="">All Products</option>
                    <?php foreach ($prod_options as $pn): ?>
                    <option value="<?= htmlspecialchars($pn) ?>" <?= $filter_prod===$pn?'selected':'' ?>><?= htmlspecialchars($pn) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </form>
        </div>
        <?php if ($commissions): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Spiff</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($commissions as $c): ?>
                    <?php $cfg = $status_cfg[$c['status']] ?? ['label'=>ucfirst($c['status']),'badge'=>'bg-gray-100 text-gray-600','dot'=>'bg-gray-400']; ?>
                    <tr class="hover:bg-gray-50" data-testid="row-commission-<?= $c['id'] ?>">
                        <td class="px-5 py-3">
                            <p class="font-medium text-gray-900"><?= htmlspecialchars($c['customer_name'] ?? '—') ?></p>
                            <?php if ($c['customer_email']): ?><p class="text-xs text-gray-400"><?= htmlspecialchars($c['customer_email']) ?></p><?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-gray-700"><?= htmlspecialchars($c['product_line'] ?? '—') ?></td>
                        <td class="px-5 py-3 text-gray-500 text-xs"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                        <td class="px-5 py-3">
                            <span class="font-bold <?= $c['status']==='pending' ? 'text-yellow-700' : 'text-green-700' ?>">$<?= number_format($c['amount'],2) ?></span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full font-semibold <?= $cfg['badge'] ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?= $cfg['dot'] ?>"></span>
                                <?= $cfg['label'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
            <p class="text-sm text-gray-500">Page <?= $page ?> of <?= $total_pages ?></p>
            <div class="flex gap-2">
                <?php if ($page>1): ?><a href="?page=<?=$page-1?>&status=<?=urlencode($filter_status)?>&product=<?=urlencode($filter_prod)?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">← Prev</a><?php endif; ?>
                <?php if ($page<$total_pages): ?><a href="?page=<?=$page+1?>&status=<?=urlencode($filter_status)?>&product=<?=urlencode($filter_prod)?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">Next →</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="px-5 py-16 text-center text-gray-400">
            <i class="fas fa-dollar-sign text-4xl mb-3 block"></i>
            <p class="text-sm">No commissions yet. <a href="dealer-orders.php?new=1" class="text-blue-600 hover:underline">Submit an order</a> to start earning.</p>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>
</div>
</body>
</html>
