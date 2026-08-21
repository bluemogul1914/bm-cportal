<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$user_name = $_SESSION['user_name'] ?? 'Admin';
$pdo = getDB();
$did = (int)($_GET['id'] ?? 0);
$d = $pdo->prepare("SELECT d.*,COALESCE(u.name, d.full_name) AS user_name,u.email,u.created_at as user_created FROM dealers d LEFT JOIN users u ON d.user_id=u.id WHERE d.id=?");
$d->execute([$did]); $d = $d->fetch(PDO::FETCH_ASSOC);
if (!$d) portal_redirect('/portal/admin-dealers.php');

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_dealer'])) {
        $pdo->prepare("UPDATE dealers SET company_name=?,commission_rate=?,status=?,notes=?,full_name=? WHERE id=?")
            ->execute([trim($_POST['company_name']??''), max(0,min(100,(float)($_POST['commission_rate']??10))), $_POST['status']??'active', trim($_POST['notes']??''), trim($_POST['user_name']??''), $did]);
        if (!empty($d['user_id'])) {
            $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([trim($_POST['user_name']??''), $d['user_id']]);
        }
        $success = 'Dealer updated.';
        $d = $pdo->prepare("SELECT d.*,COALESCE(u.name, d.full_name) AS user_name,u.email,u.created_at as user_created FROM dealers d LEFT JOIN users u ON d.user_id=u.id WHERE d.id=?");
        $d->execute([$did]); $d = $d->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_POST['approve_commission'])) {
        $cid = (int)$_POST['commission_id'];
        $pdo->prepare("UPDATE dealer_commissions SET status='approved',approved_at=NOW() WHERE id=? AND dealer_id=?")->execute([$cid,$did]);
        $success = 'Commission approved.';
    }
    if (isset($_POST['mark_paid'])) {
        $pid = (int)$_POST['payout_id'];
        $pdo->prepare("UPDATE dealer_payout_requests SET status='paid' WHERE id=? AND dealer_id=?")->execute([$pid,$did]);
        $success = 'Payout marked paid.';
    }
    if (isset($_POST['set_comm_paid'])) {
        $cid = (int)$_POST['commission_id'];
        $pdo->prepare("UPDATE dealer_commissions SET status='paid',paid_at=NOW() WHERE id=? AND dealer_id=?")->execute([$cid,$did]);
        $success = 'Commission marked paid.';
    }
}

// Load data
$orders=$pdo->prepare("SELECT * FROM dealer_orders WHERE dealer_id=? ORDER BY created_at DESC LIMIT 20"); $orders->execute([$did]); $orders=$orders->fetchAll(PDO::FETCH_ASSOC);
$commissions=$pdo->prepare("SELECT dc.*,dord.product_line,dord.customer_name FROM dealer_commissions dc LEFT JOIN dealer_orders dord ON dc.order_id=dord.id WHERE dc.dealer_id=? ORDER BY dc.created_at DESC LIMIT 20"); $commissions->execute([$did]); $commissions=$commissions->fetchAll(PDO::FETCH_ASSOC);
$payouts=$pdo->prepare("SELECT * FROM dealer_payout_requests WHERE dealer_id=? ORDER BY created_at DESC LIMIT 10"); $payouts->execute([$did]); $payouts=$payouts->fetchAll(PDO::FETCH_ASSOC);
$smtp=$pdo->prepare("SELECT * FROM dealer_smtp_settings WHERE dealer_id=?"); $smtp->execute([$did]); $smtp=$smtp->fetch(PDO::FETCH_ASSOC) ?: [];

$s=$pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=?"); $s->execute([$did]); $total_orders=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=? AND status='completed'"); $s->execute([$did]); $completed=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='paid'"); $s->execute([$did]); $total_paid=(float)$s->fetchColumn();
$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='pending'"); $s->execute([$did]); $pending=(float)$s->fetchColumn();
$s=$pdo->prepare("SELECT COUNT(*) FROM dealer_customers WHERE dealer_id=?"); $s->execute([$did]); $total_customers=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=? AND status='completed' AND created_at >= date_trunc('month', CURRENT_DATE)"); $s->execute([$did]); $month_acts=(int)$s->fetchColumn();
$tier = $month_acts >= 10 ? 'Gold' : ($month_acts >= 5 ? 'Silver' : 'Base');

$status_cfg=['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-blue-100 text-blue-800','paid'=>'bg-green-100 text-green-800'];
$ord_cfg=['pending'=>'bg-yellow-100 text-yellow-800','in_progress'=>'bg-blue-100 text-blue-800','completed'=>'bg-green-100 text-green-800','cancelled'=>'bg-red-100 text-red-800'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($d['user_name']) ?> — Dealer — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center gap-4">
        <a href="admin-dealers.php" class="text-gray-400 hover:text-gray-700"><i class="fas fa-arrow-left"></i></a>
        <div>
            <p class="text-xs text-gray-400">Dealers /</p>
            <h1 class="text-xl font-semibold text-gray-900"><?= htmlspecialchars($d['user_name']) ?>
                <span class="ml-2 text-sm font-mono bg-blue-50 text-blue-700 px-2 py-0.5 rounded"><?= htmlspecialchars($d['referral_code']) ?></span>
            </h1>
        </div>
        <span class="ml-2 text-xs px-2.5 py-1 rounded-full font-semibold <?= $d['status']==='active' ? 'bg-green-100 text-green-800' : ($d['status']==='suspended'?'bg-red-100 text-red-800':'bg-yellow-100 text-yellow-800') ?>"><?= ucfirst($d['status']) ?></span>
    </div>
</header>

<div class="p-6 space-y-6">
<?php if ($success): ?><div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3 text-green-800"><i class="fas fa-check-circle text-green-500"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="grid grid-cols-5 gap-4">
    <?php foreach ([['Total Orders',$total_orders,'text-gray-900'],['Completed',$completed,'text-green-700'],['Month Acts.',$month_acts,'text-blue-700'],['Total Paid','$'.number_format($total_paid,2),'text-green-700'],['Pending','$'.number_format($pending,2),'text-yellow-700']] as [$lbl,$val,$col]): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xl font-bold <?= $col ?>"><?= $val ?></p>
        <p class="text-xs text-gray-500"><?= $lbl ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Edit dealer -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="font-semibold text-gray-900 mb-4">Dealer Settings</h3>
        <form method="post" class="space-y-3">
            <input type="hidden" name="update_dealer" value="1">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                <input type="text" name="user_name" value="<?= htmlspecialchars($d['user_name']) ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-dealer-name">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Company</label>
                <input type="text" name="company_name" value="<?= htmlspecialchars($d['company_name']??'') ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-company">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                <input type="text" value="<?= htmlspecialchars($d['email']) ?>" class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded-lg text-sm text-gray-500 cursor-not-allowed" disabled>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Commission %</label>
                    <input type="number" name="commission_rate" value="<?= $d['commission_rate'] ?>" step="0.5" min="0" max="100" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="input-commission-rate">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-status">
                        <option value="active" <?= $d['status']==='active'?'selected':'' ?>>Active</option>
                        <option value="pending" <?= $d['status']==='pending'?'selected':'' ?>>Pending</option>
                        <option value="suspended" <?= $d['status']==='suspended'?'selected':'' ?>>Suspended</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Admin Notes</label>
                <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="input-notes"><?= htmlspecialchars($d['notes']??'') ?></textarea>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-lg transition text-sm" data-testid="button-update-dealer">Save Changes</button>
        </form>
        <div class="mt-4 pt-4 border-t border-gray-100 space-y-1 text-xs text-gray-500">
            <p><i class="fas fa-calendar mr-1"></i>Joined: <?= date('M j, Y', strtotime($d['created_at'])) ?></p>
            <p><i class="fas fa-users mr-1"></i>Customers: <?= $total_customers ?></p>
            <p><i class="fas fa-star mr-1 text-yellow-500"></i>Tier: <?= $tier ?> (<?= $month_acts ?> this month)</p>
            <?php if ($smtp): ?><p class="text-green-600"><i class="fas fa-check-circle mr-1"></i>SMTP configured: <?= htmlspecialchars($smtp['from_email']??'') ?></p><?php endif; ?>
            <?php if ($d['ach_routing']): ?><p class="text-green-600"><i class="fas fa-university mr-1"></i>ACH: <?= htmlspecialchars($d['bank_name']??'') ?> ****<?= substr($d['ach_account']??'',-4) ?></p><?php endif; ?>
        </div>
    </div>

    <!-- Commissions & Orders (right 2 cols) -->
    <div class="lg:col-span-2 space-y-5">
        <!-- Commissions -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 text-sm">Commission Log</h3>
            </div>
            <?php if ($commissions): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Client</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Product</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Amount</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Status</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Action</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                    <?php foreach ($commissions as $c): ?>
                    <tr data-testid="row-comm-<?= $c['id'] ?>">
                        <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars($c['customer_name']??'—') ?></td>
                        <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($c['product_line']??'') ?></td>
                        <td class="px-4 py-2 font-bold <?= $c['status']==='paid'?'text-green-700':'text-yellow-700' ?>">$<?= number_format($c['amount'],2) ?></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full font-medium <?= $status_cfg[$c['status']]??'bg-gray-100 text-gray-600' ?>"><?= ucfirst($c['status']) ?></span></td>
                        <td class="px-4 py-2">
                            <?php if ($c['status']==='pending'): ?>
                            <form method="post" class="inline"><input type="hidden" name="approve_commission" value="1"><input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                            <button class="text-xs bg-blue-100 text-blue-700 hover:bg-blue-200 px-2 py-0.5 rounded transition" data-testid="button-approve-<?= $c['id'] ?>">Approve</button></form>
                            <?php elseif ($c['status']==='approved'): ?>
                            <form method="post" class="inline"><input type="hidden" name="set_comm_paid" value="1"><input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                            <button class="text-xs bg-green-100 text-green-700 hover:bg-green-200 px-2 py-0.5 rounded transition" data-testid="button-paid-<?= $c['id'] ?>">Mark Paid</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><p class="px-5 py-8 text-center text-gray-400 text-sm">No commissions yet.</p><?php endif; ?>
        </div>

        <!-- Orders -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900 text-sm">Orders</h3>
            </div>
            <?php if ($orders): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Customer</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Product</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Date</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Commission</th>
                        <th class="px-4 py-2 text-left text-gray-500 font-semibold">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                    <?php foreach ($orders as $o): ?>
                    <tr data-testid="row-order-<?= $o['id'] ?>">
                        <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars($o['customer_name']) ?></td>
                        <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($o['product_line']) ?></td>
                        <td class="px-4 py-2 text-gray-500"><?= date('M j', strtotime($o['created_at'])) ?></td>
                        <td class="px-4 py-2 text-green-700 font-bold">$<?= number_format($o['commission_amount'],2) ?></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full font-medium <?= $ord_cfg[$o['status']]??'bg-gray-100 text-gray-600' ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><p class="px-5 py-8 text-center text-gray-400 text-sm">No orders yet.</p><?php endif; ?>
        </div>

        <!-- Payouts -->
        <?php if ($payouts): ?>
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900 text-sm">Payout Requests</h3>
            </div>
            <div class="divide-y divide-gray-50">
            <?php foreach ($payouts as $p): ?>
            <div class="px-5 py-3 flex items-center justify-between gap-3" data-testid="row-payout-<?= $p['id'] ?>">
                <div>
                    <p class="text-sm font-medium text-gray-900">$<?= number_format($p['amount'],2) ?></p>
                    <p class="text-xs text-gray-500"><?= date('M j, Y', strtotime($p['created_at'])) ?><?= $p['notes'] ? ' · '.htmlspecialchars($p['notes']) : '' ?></p>
                </div>
                <?php if ($p['status']==='pending'): ?>
                <form method="post" class="inline"><input type="hidden" name="mark_paid" value="1"><input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                <button class="text-xs bg-emerald-100 text-emerald-700 hover:bg-emerald-200 px-2.5 py-1 rounded transition font-medium">Mark Paid</button></form>
                <?php else: ?>
                <span class="text-xs px-2.5 py-1 rounded-full bg-green-100 text-green-800 font-semibold"><?= ucfirst($p['status']) ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>
</div>
</div>
</body>
</html>
