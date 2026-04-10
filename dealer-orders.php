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

// Product catalog (base / gold spiff)
$products = [
    ['name'=>'Frontier Fiber',          'base'=>100,'gold'=>120,'markets'=>'TX, LA, NC',       'notes'=>'Per install, prepaid monthly',
     'plans'=>['500 Mbps — $55/mo','1 Gig — $75/mo','2 Gig — $95/mo']],
    ['name'=>'Xfinity Prepaid Internet','base'=>35, 'gold'=>42, 'markets'=>'Xfinity markets',  'notes'=>'No credit check required','plans'=>[]],
    ['name'=>'Verizon Prepaid Wireless','base'=>30, 'gold'=>36, 'markets'=>'Nationwide',        'notes'=>'Per activation','plans'=>[]],
    ['name'=>'Black Wireless',          'base'=>20, 'gold'=>24, 'markets'=>'Nationwide',        'notes'=>'Per activation','plans'=>[]],
    ['name'=>'TravelSim / eSIM',        'base'=>15, 'gold'=>18, 'markets'=>'Worldwide',         'notes'=>'Digital fulfillment','plans'=>[]],
    ['name'=>'Sling TV',                'base'=>12, 'gold'=>14, 'markets'=>'Nationwide',        'notes'=>'Per new subscriber','plans'=>[]],
];

$tier = $dealer['tier'] ?? 'base';
// Calculate current month activations for tier
$tier_count = (int)$pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=? AND status='completed' AND created_at >= date_trunc('month', now())")
    ->execute([$dealer_id]) ? 0 : 0;
$s = $pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE dealer_id=? AND status='completed' AND created_at >= date_trunc('month', CURRENT_DATE)");
$s->execute([$dealer_id]); $tier_count = (int)$s->fetchColumn();
$tier = $tier_count >= 10 ? 'gold' : ($tier_count >= 5 ? 'silver' : 'base');

$success = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    $cname   = trim($_POST['customer_name'] ?? '');
    $cemail  = trim($_POST['customer_email'] ?? '');
    $cphone  = trim($_POST['customer_phone'] ?? '');
    $caddr   = trim($_POST['customer_address'] ?? '');
    $prod    = trim($_POST['product_line'] ?? '');
    $plan    = trim($_POST['plan'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');

    if (!$cname || !$prod) {
        $error = 'Customer name and product line are required.';
    } else {
        // Find spiff amount
        $spiff = 0;
        foreach ($products as $p) {
            if ($p['name'] === $prod) { $spiff = $tier === 'gold' ? $p['gold'] : $p['base']; break; }
        }
        $details = $plan ? "Plan: $plan\n\n$notes" : $notes;
        $pdo->prepare("INSERT INTO dealer_orders (dealer_id,product_line,customer_name,customer_email,customer_phone,customer_address,details,status,commission_amount) VALUES (?,?,?,?,?,?,?,'pending',?)")
            ->execute([$dealer_id,$prod,$cname,$cemail,$cphone,$caddr,$details,$spiff]);
        // Create commission record
        $last_id = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dealer_commissions (dealer_id,order_id,amount,status) VALUES (?,?,?,'pending')")
            ->execute([$dealer_id,$last_id,$spiff]);
        $success = true;
    }
}

// Filter & paginate
$filter_status = $_GET['status'] ?? '';
$filter_prod   = $_GET['product'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 15;
$where = ["dealer_id=?"];
$params = [$dealer_id];
if ($filter_status) { $where[] = "status=?"; $params[] = $filter_status; }
if ($filter_prod)   { $where[] = "product_line=?"; $params[] = $filter_prod; }
$where_sql = implode(' AND ', $where);
$total_s = $pdo->prepare("SELECT COUNT(*) FROM dealer_orders WHERE $where_sql");
$total_s->execute($params); $total = (int)$total_s->fetchColumn();
$total_pages = max(1, ceil($total / $per));
$offset = ($page - 1) * $per;
$orders_s = $pdo->prepare("SELECT * FROM dealer_orders WHERE $where_sql ORDER BY created_at DESC LIMIT $per OFFSET $offset");
$orders_s->execute($params); $orders = $orders_s->fetchAll(PDO::FETCH_ASSOC);

$status_badges = ['pending'=>'bg-yellow-100 text-yellow-800','in_progress'=>'bg-blue-100 text-blue-800','completed'=>'bg-green-100 text-green-800','cancelled'=>'bg-red-100 text-red-800'];
$show_form = isset($_GET['new']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Submit Order — Blue Mogul Partner</title>
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
            <p class="text-xs text-gray-400">Partner Portal / </p>
            <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-clipboard-list text-green-600 mr-2"></i>Orders</h1>
        </div>
        <button onclick="document.getElementById('order-form').classList.toggle('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition flex items-center gap-2" data-testid="button-new-order">
            <i class="fas fa-plus"></i> New Order
        </button>
    </div>
</header>

<div class="p-6 space-y-6">

<?php if ($success): ?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3" data-testid="alert-order-success">
    <i class="fas fa-check-circle text-green-500 text-lg"></i>
    <div>
        <p class="font-medium text-green-800">Order submitted successfully!</p>
        <p class="text-sm text-green-700">Your commission will be released within 24 hrs of confirmed activation.</p>
    </div>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm" data-testid="alert-order-error">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Order Form -->
<div id="order-form" class="<?= ($show_form || $error) ? '' : 'hidden' ?>">
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 bg-blue-50">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-gray-900"><i class="fas fa-plus-circle text-blue-600 mr-2"></i>Submit New Order</h3>
            <div class="flex items-center gap-2">
                <?php if ($tier === 'gold'): ?>
                <span class="text-xs bg-yellow-100 text-yellow-800 font-bold px-3 py-1 rounded-full"><i class="fas fa-star mr-1"></i>Gold Tier — +20% Spiff</span>
                <?php elseif ($tier === 'silver'): ?>
                <span class="text-xs bg-gray-200 text-gray-700 font-bold px-3 py-1 rounded-full"><i class="fas fa-medal mr-1"></i>Silver Tier</span>
                <?php else: ?>
                <span class="text-xs bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded-full"><i class="fas fa-user mr-1"></i>Base Tier</span>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-xs text-blue-700 mt-1"><i class="fas fa-info-circle mr-1"></i>All services are prepaid. Client must pay before activation. Commission releases within 24 hrs of confirmed activation.</p>
    </div>
    <form method="post" class="p-6">
        <input type="hidden" name="submit_order" value="1">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="space-y-4">
                <h4 class="font-medium text-gray-700 flex items-center gap-2"><i class="fas fa-user text-gray-400"></i> Client Info</h4>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                    <input type="text" name="customer_name" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="First Last" required data-testid="input-customer-name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <input type="email" name="customer_email" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="client@email.com" data-testid="input-customer-email">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="customer_phone" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="(713) 000-0000" data-testid="input-customer-phone">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Service Address</label>
                    <input type="text" name="customer_address" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Street, City, State, ZIP" data-testid="input-customer-address">
                </div>
            </div>
            <div class="space-y-4">
                <h4 class="font-medium text-gray-700 flex items-center gap-2"><i class="fas fa-box text-gray-400"></i> Service Selection</h4>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Product Line *</label>
                    <select name="product_line" id="product_select" onchange="updateSpiff()" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-product-line" required>
                        <option value="">— Select product —</option>
                        <?php foreach ($products as $p): ?>
                        <?php $spiff_amt = $tier === 'gold' ? $p['gold'] : $p['base']; ?>
                        <option value="<?= htmlspecialchars($p['name']) ?>" data-spiff="<?= $spiff_amt ?>" data-plans='<?= json_encode($p['plans']) ?>'>
                            <?= htmlspecialchars($p['name']) ?> — $<?= $spiff_amt ?> spiff
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="plan-row" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Plan / Speed Tier</label>
                    <select name="plan" id="plan_select" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-plan"></select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Dealer Notes (optional)</label>
                    <textarea name="notes" rows="3" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. client needs same-day setup" data-testid="input-dealer-notes"></textarea>
                </div>
                <!-- Commission preview -->
                <div id="commission-preview" class="hidden bg-green-50 border border-green-200 rounded-xl p-4">
                    <p class="text-xs text-green-600 font-medium uppercase tracking-wider">Your commission on this order</p>
                    <p id="commission-amount" class="text-3xl font-bold text-green-700 mt-1">$0.00</p>
                    <p class="text-xs text-green-600 mt-1">Paid after confirmed activation</p>
                </div>
            </div>
        </div>
        <div class="mt-6 flex items-center gap-3 pt-4 border-t border-gray-100">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-xl transition text-sm flex items-center gap-2" data-testid="button-submit-order">
                <i class="fas fa-paper-plane"></i> Submit Order
            </button>
            <button type="button" onclick="document.getElementById('order-form').classList.add('hidden')" class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-5 py-2.5 rounded-xl transition text-sm">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Order History -->
<div class="bg-white rounded-xl border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-semibold text-gray-900">Order History <span class="text-gray-400 font-normal text-sm">(<?= $total ?>)</span></h3>
        <form method="get" class="flex flex-wrap items-center gap-2">
            <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm" data-testid="select-filter-status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending" <?= $filter_status==='pending' ? 'selected':'' ?>>Pending</option>
                <option value="in_progress" <?= $filter_status==='in_progress' ? 'selected':'' ?>>In Progress</option>
                <option value="completed" <?= $filter_status==='completed' ? 'selected':'' ?>>Completed</option>
                <option value="cancelled" <?= $filter_status==='cancelled' ? 'selected':'' ?>>Cancelled</option>
            </select>
            <select name="product" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm" data-testid="select-filter-product" onchange="this.form.submit()">
                <option value="">All Products</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= htmlspecialchars($p['name']) ?>" <?= $filter_prod===$p['name'] ? 'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if ($orders): ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">#</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Customer</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Spiff</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($orders as $o): ?>
                <tr class="hover:bg-gray-50 transition" data-testid="row-order-<?= $o['id'] ?>">
                    <td class="px-5 py-3 text-gray-400 text-xs font-mono">#<?= $o['id'] ?></td>
                    <td class="px-5 py-3">
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($o['customer_name']) ?></p>
                        <?php if ($o['customer_email']): ?><p class="text-xs text-gray-400"><?= htmlspecialchars($o['customer_email']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-gray-700"><?= htmlspecialchars($o['product_line']) ?></td>
                    <td class="px-5 py-3 text-gray-500 text-xs"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                    <td class="px-5 py-3 font-bold text-green-700">$<?= number_format($o['commission_amount'], 2) ?></td>
                    <td class="px-5 py-3"><span class="text-xs px-2.5 py-1 rounded-full font-semibold <?= $status_badges[$o['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
        <p class="text-sm text-gray-500">Page <?= $page ?> of <?= $total_pages ?></p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&status=<?= urlencode($filter_status) ?>&product=<?= urlencode($filter_prod) ?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">← Prev</a><?php endif; ?>
            <?php if ($page < $total_pages): ?><a href="?page=<?= $page+1 ?>&status=<?= urlencode($filter_status) ?>&product=<?= urlencode($filter_prod) ?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-50">Next →</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="px-5 py-16 text-center text-gray-400">
        <i class="fas fa-clipboard-list text-4xl mb-3 block"></i>
        <p class="text-sm">No orders yet.</p>
        <button onclick="document.getElementById('order-form').classList.remove('hidden');document.getElementById('order-form').scrollIntoView({behavior:'smooth'})" class="mt-3 text-sm text-blue-600 hover:underline">Submit your first order →</button>
    </div>
    <?php endif; ?>
</div>
</div>
</div>
</div>

<script>
const products = <?= json_encode(array_map(fn($p)=>['name'=>$p['name'],'base'=>$p['base'],'gold'=>$p['gold'],'plans'=>$p['plans']], $products)) ?>;
const tier = '<?= $tier ?>';
function updateSpiff() {
    const sel = document.getElementById('product_select');
    const opt = sel.options[sel.selectedIndex];
    const spiff = opt.dataset.spiff;
    const plans = JSON.parse(opt.dataset.plans || '[]');
    const preview = document.getElementById('commission-preview');
    const planRow = document.getElementById('plan-row');
    const planSel = document.getElementById('plan_select');
    if (spiff) {
        preview.classList.remove('hidden');
        document.getElementById('commission-amount').textContent = '$' + parseFloat(spiff).toFixed(2);
    } else {
        preview.classList.add('hidden');
    }
    planSel.innerHTML = '';
    if (plans.length > 0) {
        planRow.classList.remove('hidden');
        plans.forEach(pl => { const o = document.createElement('option'); o.value = pl; o.textContent = pl; planSel.appendChild(o); });
    } else {
        planRow.classList.add('hidden');
    }
}
<?php if ($show_form || $error): ?>document.getElementById('order-form').classList.remove('hidden');<?php endif; ?>
</script>
</body>
</html>
