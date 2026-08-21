<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$user_name = $_SESSION['user_name'] ?? 'Admin';
$pdo = getDB();

$success = ''; $error = '';

// Approve / change status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $did    = (int)$_POST['dealer_id'];
    $status = in_array($_POST['status'], ['active','suspended','pending']) ? $_POST['status'] : 'active';
    $pdo->prepare("UPDATE dealers SET status=? WHERE id=?")->execute([$status,$did]);
    $success = 'Dealer status updated.';
}
// Set commission rate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_commission'])) {
    $did  = (int)$_POST['dealer_id'];
    $rate = max(0, min(100, (float)($_POST['commission_rate']??10)));
    $pdo->prepare("UPDATE dealers SET commission_rate=? WHERE id=?")->execute([$rate,$did]);
    $success = 'Commission rate updated.';
}
// Approve commission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_commission'])) {
    $cid = (int)$_POST['commission_id'];
    $pdo->prepare("UPDATE dealer_commissions SET status='approved', approved_at=NOW() WHERE id=?")->execute([$cid]);
    $success = 'Commission approved.';
}
// Mark payout paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $pid = (int)$_POST['payout_id'];
    $pdo->prepare("UPDATE dealer_payout_requests SET status='paid' WHERE id=?" )->execute([$pid]);
    // Also mark related commissions as paid
    $success = 'Payout marked as paid.';
}
// Add dealer directly from the admin dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_dealer'])) {
    require_csrf();
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $company   = trim($_POST['company'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $tier      = in_array($_POST['tier'] ?? '', ['base','silver','gold']) ? $_POST['tier'] : 'base';
    if (!$full_name || !$email) {
        $error = 'Name and email are required to add a dealer.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $check = $pdo->prepare("SELECT id FROM dealers WHERE email=? LIMIT 1");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'A dealer with that email already exists.';
            } else {
                $dealer_code = 'DLR-' . str_pad(random_int(10000,99999),5,'0',STR_PAD_LEFT);
                $pdo->prepare(
                    "INSERT INTO dealers (dealer_code, referral_code, full_name, email, phone, company, status, tier, created_at)
                     VALUES (?,?,?,?,?,?,'active',?,NOW())"
                )->execute([$dealer_code, $dealer_code, $full_name, $email, $phone ?: null, $company ?: null, $tier]);
                $success = "Dealer added ($dealer_code).";
            }
        } catch (PDOException $e) {
            $error = 'Failed to add dealer: ' . $e->getMessage();
        }
    }
}
// Delete dealer (cascades to orders, commissions, payouts, and linked user via FK)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_dealer'])) {
    require_csrf();
    $did = (int)$_POST['dealer_id'];
    if ($did <= 0) { $error = 'Invalid dealer.'; }
    else {
        try {
            $pdo->prepare("DELETE FROM dealers WHERE id=?")->execute([$did]);
            $success = 'Dealer deleted.';
        } catch (PDOException $e) {
            $error = 'Failed to delete dealer: ' . $e->getMessage();
        }
    }
}

$search = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$page = max(1,(int)($_GET['page']??1)); $per=20;
$where = ["1=1"]; $params = [];
if ($search) { $where[]="(u.name ILIKE ? OR u.email ILIKE ? OR d.full_name ILIKE ? OR d.email ILIKE ? OR d.company_name ILIKE ? OR d.referral_code ILIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }
if ($filter_status) { $where[]="d.status=?"; $params[]=$filter_status; }
$wsql = implode(' AND ',$where);
$t=$pdo->prepare("SELECT COUNT(*) FROM dealers d LEFT JOIN users u ON d.user_id=u.id WHERE $wsql"); $t->execute($params); $total=(int)$t->fetchColumn();
$total_pages=max(1,ceil($total/$per)); $offset=($page-1)*$per;
$q=$pdo->prepare("SELECT d.*,COALESCE(u.name,d.full_name) as user_name,COALESCE(u.email,d.email) as email,
    (SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=d.id) as total_orders,
    (SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=d.id AND status='completed') as completed_orders,
    (SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=d.id AND status='paid') as total_paid,
    (SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=d.id AND status='pending') as pending_amount,
    (SELECT COALESCE(SUM(amount),0) FROM dealer_payout_requests WHERE dealer_id=d.id AND status='pending') as pending_payout
    FROM dealers d LEFT JOIN users u ON d.user_id=u.id WHERE $wsql ORDER BY d.created_at DESC LIMIT $per OFFSET $offset");
$q->execute($params); $dealers=$q->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$s=$pdo->query("SELECT COUNT(*) FROM dealers WHERE status='active'"); $active_dealers=(int)$s->fetchColumn();
$s=$pdo->query("SELECT COUNT(*) FROM dealer_commissions WHERE status='pending'"); $pending_commissions=(int)$s->fetchColumn();
$s=$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dealer_payout_requests WHERE status='pending'"); $pending_payouts=(float)$s->fetchColumn();

// Pending items for admin action
$pending_comm = $pdo->query("SELECT dc.*,d.company_name,d.referral_code,u.name as dealer_name,dord.product_line,dord.customer_name FROM dealer_commissions dc JOIN dealers d ON dc.dealer_id=d.id JOIN users u ON d.user_id=u.id LEFT JOIN dealer_orders dord ON dc.order_id=dord.id WHERE dc.status='pending' ORDER BY dc.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$pending_pay  = $pdo->query("SELECT dpr.*,d.company_name,d.referral_code,u.name as dealer_name,d.ach_routing,d.ach_account,d.bank_name FROM dealer_payout_requests dpr JOIN dealers d ON dpr.dealer_id=d.id JOIN users u ON d.user_id=u.id WHERE dpr.status='pending' ORDER BY dpr.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$status_badge = ['active'=>'bg-green-100 text-green-800','pending'=>'bg-yellow-100 text-yellow-800','suspended'=>'bg-red-100 text-red-800'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dealers — Blue Mogul Admin</title>
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
    <div class="px-6 py-4 flex items-center justify-between flex-wrap gap-3">
        <div>
            <p class="text-xs text-gray-400">Admin /</p>
            <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-handshake text-blue-600 mr-2"></i>Partner Dealers</h1>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" onclick="document.getElementById('add-dealer-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition flex items-center gap-2" data-testid="button-add-dealer">
                <i class="fas fa-plus"></i> Add Dealer
            </button>
            <a href="dealer-register.php" target="_blank" class="border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium px-4 py-2 rounded-lg transition flex items-center gap-2">
                <i class="fas fa-external-link-alt text-blue-500"></i> Dealer Registration Page
            </a>
        </div>
    </div>
</header>

<div class="p-6 space-y-6">

<!-- Add Dealer modal -->
<div id="add-dealer-modal" class="hidden fixed inset-0 z-50 bg-black bg-opacity-40 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Add Dealer</h3>
            <button type="button" onclick="document.getElementById('add-dealer-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="post" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="add_dealer" value="1">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Full Name *</label>
                <input type="text" name="full_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-add-full-name">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Email *</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-add-email">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Company</label>
                    <input type="text" name="company" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="input-add-company">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
                    <input type="text" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="input-add-phone">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Tier</label>
                <select name="tier" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" data-testid="select-add-tier">
                    <option value="base">Base</option>
                    <option value="silver">Silver</option>
                    <option value="gold">Gold</option>
                </select>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('add-dealer-modal').classList.add('hidden')" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm">Cancel</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium" data-testid="button-submit-add-dealer"><i class="fas fa-plus mr-1"></i>Add Dealer</button>
            </div>
        </form>
    </div>
</div>

<?php if ($success): ?><div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3 text-green-800" data-testid="alert-success"><i class="fas fa-check-circle text-green-500"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-center gap-3 text-red-800" data-testid="alert-error"><i class="fas fa-exclamation-triangle text-red-500"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-3 gap-4">
    <div class="bg-white rounded-xl border border-gray-200 p-5 text-center" data-testid="card-active-dealers">
        <p class="text-3xl font-bold text-gray-900"><?= $active_dealers ?></p>
        <p class="text-sm text-gray-500">Active Dealers</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-5 text-center" data-testid="card-pending-commissions">
        <p class="text-3xl font-bold text-yellow-600"><?= $pending_commissions ?></p>
        <p class="text-sm text-gray-500">Commissions Awaiting Approval</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-5 text-center" data-testid="card-pending-payouts">
        <p class="text-3xl font-bold text-emerald-600">$<?= number_format($pending_payouts,2) ?></p>
        <p class="text-sm text-gray-500">Payouts Pending</p>
    </div>
</div>

<!-- Pending Commissions (Admin Action Required) -->
<?php if ($pending_comm): ?>
<div class="bg-white rounded-xl border border-yellow-200">
    <div class="px-5 py-4 border-b border-yellow-100 bg-yellow-50 flex items-center gap-2">
        <i class="fas fa-clock text-yellow-600"></i>
        <h3 class="font-semibold text-yellow-900">Commissions Awaiting Approval <span class="bg-yellow-200 text-yellow-800 text-xs px-2 py-0.5 rounded-full ml-1"><?= count($pending_comm) ?></span></h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Dealer</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client / Product</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Submitted</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($pending_comm as $c): ?>
            <tr data-testid="row-pending-comm-<?= $c['id'] ?>">
                <td class="px-5 py-3">
                    <p class="font-medium text-gray-900"><?= htmlspecialchars($c['dealer_name']) ?></p>
                    <p class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($c['referral_code']) ?></p>
                </td>
                <td class="px-5 py-3">
                    <p class="text-gray-800"><?= htmlspecialchars($c['customer_name']??'—') ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($c['product_line']??'') ?></p>
                </td>
                <td class="px-5 py-3 font-bold text-yellow-700">$<?= number_format($c['amount'],2) ?></td>
                <td class="px-5 py-3 text-gray-500 text-xs"><?= date('M j', strtotime($c['created_at'])) ?></td>
                <td class="px-5 py-3">
                    <form method="post" class="inline">
                        <input type="hidden" name="approve_commission" value="1">
                        <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition" data-testid="button-approve-comm-<?= $c['id'] ?>">
                            <i class="fas fa-check mr-1"></i>Approve
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Pending Payouts -->
<?php if ($pending_pay): ?>
<div class="bg-white rounded-xl border border-emerald-200">
    <div class="px-5 py-4 border-b border-emerald-100 bg-emerald-50 flex items-center gap-2">
        <i class="fas fa-money-bill-wave text-emerald-600"></i>
        <h3 class="font-semibold text-emerald-900">Payout Requests <span class="bg-emerald-200 text-emerald-800 text-xs px-2 py-0.5 rounded-full ml-1"><?= count($pending_pay) ?></span></h3>
    </div>
    <div class="divide-y divide-gray-50">
    <?php foreach ($pending_pay as $p): ?>
    <div class="px-5 py-4 flex items-center justify-between gap-4 flex-wrap" data-testid="row-payout-<?= $p['id'] ?>">
        <div>
            <p class="font-medium text-gray-900"><?= htmlspecialchars($p['dealer_name']) ?> <span class="text-gray-400 font-mono text-xs ml-1"><?= htmlspecialchars($p['referral_code']) ?></span></p>
            <p class="text-xs text-gray-500"><?= htmlspecialchars($p['bank_name']??'No bank') ?> · Routing: <?= htmlspecialchars($p['ach_routing']??'N/A') ?> · Acct: ****<?= htmlspecialchars(substr($p['ach_account']??'',- 4)) ?></p>
        </div>
        <div class="flex items-center gap-3">
            <span class="font-bold text-emerald-700 text-lg">$<?= number_format($p['amount'],2) ?></span>
            <form method="post" class="inline">
                <input type="hidden" name="mark_paid" value="1">
                <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition" data-testid="button-mark-paid-<?= $p['id'] ?>">
                    <i class="fas fa-check mr-1"></i>Mark Paid
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Dealer List -->
<div class="bg-white rounded-xl border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-semibold text-gray-900">All Dealers <span class="text-gray-400 font-normal text-sm">(<?= $total ?>)</span></h3>
        <form method="get" class="flex flex-wrap gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search dealers..." class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-search">
            <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()" data-testid="select-filter-status">
                <option value="">All Statuses</option>
                <option value="active" <?= $filter_status==='active'?'selected':'' ?>>Active</option>
                <option value="pending" <?= $filter_status==='pending'?'selected':'' ?>>Pending</option>
                <option value="suspended" <?= $filter_status==='suspended'?'selected':'' ?>>Suspended</option>
            </select>
            <button type="submit" class="bg-gray-100 hover:bg-gray-200 px-3 py-1.5 rounded-lg text-sm"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <?php if ($dealers): ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Dealer</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Code</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Orders</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Commission %</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Total Paid</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Pending</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($dealers as $d): ?>
            <tr class="hover:bg-gray-50 transition" data-testid="row-dealer-<?= $d['id'] ?>">
                <td class="px-5 py-3">
                    <a href="admin-dealer-detail.php?id=<?= $d['id'] ?>" class="font-medium text-gray-900 hover:text-blue-600" data-testid="link-dealer-<?= $d['id'] ?>"><?= htmlspecialchars($d['user_name']) ?></a>
                    <p class="text-xs text-gray-400"><?= htmlspecialchars($d['company_name']??'') ?> · <?= htmlspecialchars($d['email']) ?></p>
                </td>
                <td class="px-5 py-3"><span class="font-mono text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded"><?= htmlspecialchars($d['referral_code']) ?></span></td>
                <td class="px-5 py-3 text-gray-700"><?= $d['total_orders'] ?> <span class="text-xs text-gray-400">(<?= $d['completed_orders'] ?> done)</span></td>
                <td class="px-5 py-3">
                    <form method="post" class="flex items-center gap-1">
                        <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                        <input type="hidden" name="set_commission" value="1">
                        <input type="number" name="commission_rate" value="<?= $d['commission_rate'] ?>" step="0.5" min="0" max="100" class="w-16 border border-gray-300 rounded-md px-2 py-1 text-xs text-center" data-testid="input-commission-<?= $d['id'] ?>">
                        <span class="text-xs text-gray-400">%</span>
                        <button type="submit" class="text-xs bg-blue-100 text-blue-700 hover:bg-blue-200 px-1.5 py-1 rounded transition" title="Save"><i class="fas fa-save"></i></button>
                    </form>
                </td>
                <td class="px-5 py-3 text-green-700 font-medium">$<?= number_format($d['total_paid'],2) ?></td>
                <td class="px-5 py-3 text-yellow-700">$<?= number_format($d['pending_amount'],2) ?></td>
                <td class="px-5 py-3">
                    <form method="post" class="flex items-center gap-1">
                        <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                        <input type="hidden" name="set_status" value="1">
                        <select name="status" class="border border-gray-300 rounded-md px-2 py-1 text-xs" onchange="this.form.submit()" data-testid="select-status-<?= $d['id'] ?>">
                            <option value="active" <?= $d['status']==='active'?'selected':'' ?>>Active</option>
                            <option value="pending" <?= $d['status']==='pending'?'selected':'' ?>>Pending</option>
                            <option value="suspended" <?= $d['status']==='suspended'?'selected':'' ?>>Suspended</option>
                        </select>
                    </form>
                </td>
                <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <a href="admin-dealer-detail.php?id=<?= $d['id'] ?>" class="text-blue-600 hover:underline text-xs font-medium" data-testid="link-view-dealer-<?= $d['id'] ?>"><i class="fas fa-eye mr-1"></i>View/Edit</a>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this dealer and all their orders/commissions/payouts?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="delete_dealer" value="1">
                                                    <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                                                    <button type="submit" class="text-red-600 hover:underline text-xs font-medium" data-testid="button-delete-dealer-<?= $d['id'] ?>"><i class="fas fa-trash mr-1"></i>Delete</button>
                                                </form>
                    </div>
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
            <?php if ($page>1): ?><a href="?page=<?=$page-1?>&q=<?=urlencode($search)?>&status=<?=urlencode($filter_status)?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">← Prev</a><?php endif; ?>
            <?php if ($page<$total_pages): ?><a href="?page=<?=$page+1?>&q=<?=urlencode($search)?>&status=<?=urlencode($filter_status)?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">Next →</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="px-5 py-16 text-center text-gray-400">
        <i class="fas fa-handshake text-4xl mb-3 block"></i>
        <p class="text-sm">No dealers yet. Share the <a href="dealer-register.php" target="_blank" class="text-blue-600 hover:underline">registration link</a> to get started.</p>
    </div>
    <?php endif; ?>
</div>

</div>
</div>
</div>
</body>
</html>
