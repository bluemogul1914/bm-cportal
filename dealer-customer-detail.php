<?php
require_once 'config.php';
require_once __DIR__ . '/includes/dealer-functions.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'dealer' && !($_SESSION['is_admin'] ?? false))) {
    portal_redirect('/portal');
}
$user_name  = $_SESSION['user_name'] ?? 'Dealer';
$user_email = $_SESSION['user_email'] ?? '';
$user_id    = $_SESSION['user_id'];
$pdo = getDB();
// Tenant resolution: prefer the session's dealer (works for team members added via
// dealer_users), and fall back to the legacy single-login link on dealers.user_id.
$dealer = null;
if (!empty($_SESSION['dealer_id'])) {
    $__d = $pdo->prepare("SELECT * FROM dealers WHERE id = ? LIMIT 1");
    $__d->execute([(int)$_SESSION['dealer_id']]);
    $dealer = $__d->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$dealer) {
    $__d = $pdo->prepare("SELECT * FROM dealers WHERE user_id = ? LIMIT 1");
    $__d->execute([$user_id]);
    $dealer = $__d->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$dealer) portal_redirect('/portal/dealer-dashboard.php');
$dealer_id = $dealer['id'];
$cid = (int)($_GET['id'] ?? 0);
$cust = $pdo->prepare("SELECT id, dealer_id, type, name, email, phone, company, address, notes, created_at, updated_at, client_id, assigned_user_id FROM dealer_customers WHERE id=? AND dealer_id=?"); $cust->execute([$cid,$dealer_id]); $cust = $cust->fetch(PDO::FETCH_ASSOC);
if (!$cust) { portal_redirect('/portal/dealer-customers.php'); }

$success = ''; $error = '';

// CSRF guard for all POST actions on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $pdo->prepare("UPDATE dealer_customers SET type=?,name=?,email=?,phone=?,company=?,address=?,notes=?,updated_at=NOW() WHERE id=? AND dealer_id=?")
        ->execute([$_POST['type']??$cust['type'],trim($_POST['name']??''),trim($_POST['email']??''),trim($_POST['phone']??''),trim($_POST['company']??''),trim($_POST['address']??''),trim($_POST['notes']??''),$cid,$dealer_id]);
    $cust = $pdo->prepare("SELECT id, dealer_id, type, name, email, phone, company, address, notes, created_at, updated_at, client_id, assigned_user_id FROM dealer_customers WHERE id=?"); $cust->execute([$cid]); $cust = $cust->fetch(PDO::FETCH_ASSOC);
    $success = 'Customer updated.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($cust['name']) ?> — Blue Mogul Partner</title>
<link rel="stylesheet" href="/assets/css/tailwind.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/dealer-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center gap-4">
        <a href="dealer-customers.php" class="text-gray-400 hover:text-gray-700 transition"><i class="fas fa-arrow-left"></i></a>
        <div>
            <p class="text-xs text-gray-400">Customers /</p>
            <h1 class="text-xl font-semibold text-gray-900"><?= htmlspecialchars($cust['name']) ?></h1>
        </div>
        <span class="ml-2 text-xs px-3 py-1 rounded-full font-semibold <?= $cust['type']==='client' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>"><?= ucfirst($cust['type']) ?></span>
    </div>
</header>

<div class="p-6 max-w-2xl">
<?php if ($success): ?><div class="mb-5 bg-green-50 border border-green-200 rounded-xl p-3 text-green-800 text-sm flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="bg-white rounded-xl border border-gray-200 p-6">
    <form method="post" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="update" value="1">
        <div class="flex gap-6 mb-2">
            <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="type" value="lead" <?= $cust['type']==='lead'?'checked':'' ?> class="accent-blue-600"> <span class="text-sm font-medium text-gray-700">Lead</span></label>
            <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="type" value="client" <?= $cust['type']==='client'?'checked':'' ?> class="accent-blue-600"> <span class="text-sm font-medium text-gray-700">Client</span></label>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($cust['name']) ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" required data-testid="input-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($cust['email']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-email">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($cust['phone']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-phone">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                <input type="text" name="company" value="<?= htmlspecialchars($cust['company']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-company">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars($cust['address']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-address">
            </div>
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="4" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-notes"><?= htmlspecialchars($cust['notes']??'') ?></textarea>
            </div>
        </div>
        <div class="flex items-center gap-3 pt-2 border-t border-gray-100">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-xl transition text-sm" data-testid="button-save">Save Changes</button>
            <a href="dealer-orders.php?new=1" class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-5 py-2.5 rounded-xl transition text-sm flex items-center gap-2" data-testid="link-submit-order-for-customer">
                <i class="fas fa-clipboard-list text-green-500"></i> Submit Order for This Client
            </a>
        </div>
    </form>
</div>

<?php
// ── Read-only snapshot of this customer's PORTAL CLIENT account ──────────────
// Tenant gate: the client must be attributed to THIS dealer, so a dealer can only
// ever see their own customers' records. Read-only — nothing here mutates state.
$snap = null; $snap_services = []; $snap_denied = false;
if (!empty($cust['client_id'])) {
    $gate = $pdo->prepare("SELECT id, name, status FROM clients WHERE id = ? AND dealer_id = ? LIMIT 1");
    $gate->execute([(int)$cust['client_id'], $dealer_id]);
    $c_row = $gate->fetch(PDO::FETCH_ASSOC);
    if ($c_row) {
        $s1 = $pdo->prepare("SELECT service_name, service_type, price, billing_period, status
                               FROM client_services WHERE client_id = ? ORDER BY service_name");
        $s1->execute([(int)$cust['client_id']]);
        $snap_services = $s1->fetchAll(PDO::FETCH_ASSOC);
        $s2 = $pdo->prepare("SELECT COUNT(*) AS total,
                                    COUNT(*) FILTER (WHERE status = 'unpaid') AS unpaid,
                                    COALESCE(SUM(total) FILTER (WHERE status = 'unpaid'), 0) AS outstanding,
                                    MAX(paid_at) AS last_paid
                               FROM invoices WHERE client_id = ?");
        $s2->execute([(int)$cust['client_id']]);
        $s3 = $pdo->prepare("SELECT COUNT(*) AS total,
                                    COUNT(*) FILTER (WHERE status NOT IN ('closed','resolved')) AS open,
                                    MAX(created_at) AS last_at
                               FROM tickets WHERE client_id = ?");
        $s3->execute([(int)$cust['client_id']]);
        $snap = ['client' => $c_row, 'inv' => $s2->fetch(PDO::FETCH_ASSOC), 'tk' => $s3->fetch(PDO::FETCH_ASSOC)];
    } else {
        $snap_denied = true;
    }
}
?>
<?php if ($snap): ?>
<div class="mt-4 bg-white rounded-xl border border-gray-200 overflow-hidden" data-testid="card-portal-account">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <div class="font-semibold text-gray-900 text-sm">
            <i class="fas fa-link text-blue-500 mr-1"></i> Portal account
            <span class="text-gray-400 font-normal">client #<?= (int)$cust['client_id'] ?></span>
        </div>
        <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">read-only</span>
    </div>
    <div class="grid grid-cols-3 divide-x divide-gray-100 border-b border-gray-100">
        <div class="p-4">
            <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Services</div>
            <div class="text-2xl font-semibold text-gray-900"><?= count($snap_services) ?></div>
            <div class="text-[11px] text-gray-400">on this account</div>
        </div>
        <div class="p-4">
            <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Invoices</div>
            <div class="text-2xl font-semibold <?= ((int)$snap['inv']['unpaid'] > 0) ? 'text-amber-600' : 'text-gray-900' ?>"><?= (int)$snap['inv']['unpaid'] ?></div>
            <div class="text-[11px] text-gray-400">
                unpaid of <?= (int)$snap['inv']['total'] ?>
                <?php if ((float)$snap['inv']['outstanding'] > 0): ?>
                    &middot; $<?= number_format((float)$snap['inv']['outstanding'], 2) ?> outstanding
                <?php endif; ?>
            </div>
        </div>
        <div class="p-4">
            <div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Tickets</div>
            <div class="text-2xl font-semibold <?= ((int)$snap['tk']['open'] > 0) ? 'text-blue-600' : 'text-gray-900' ?>"><?= (int)$snap['tk']['open'] ?></div>
            <div class="text-[11px] text-gray-400">open of <?= (int)$snap['tk']['total'] ?></div>
        </div>
    </div>
    <div class="p-4">
        <?php if ($snap_services): ?>
        <table class="w-full text-sm">
            <thead><tr>
                <th class="text-left text-[11px] uppercase tracking-wide text-gray-400 font-semibold pb-2">Service</th>
                <th class="text-left text-[11px] uppercase tracking-wide text-gray-400 font-semibold pb-2">Billing</th>
                <th class="text-left text-[11px] uppercase tracking-wide text-gray-400 font-semibold pb-2">Status</th>
            </tr></thead>
            <tbody>
                <?php foreach ($snap_services as $sv): ?>
                <tr class="border-t border-gray-100">
                    <td class="py-2 text-gray-800"><?= htmlspecialchars($sv['service_name'] ?: ($sv['service_type'] ?: 'Service')) ?></td>
                    <td class="py-2 text-gray-500"><?= $sv['price'] !== null ? '$' . number_format((float)$sv['price'], 2) : '—' ?><?= $sv['billing_period'] ? ' / ' . htmlspecialchars($sv['billing_period']) : '' ?></td>
                    <td class="py-2"><?= status_badge($sv['status'] ?? 'unknown') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-sm text-gray-400">No services on this account yet.</p>
        <?php endif; ?>
        <p class="text-[11px] text-gray-400 mt-3">Read-only view of your customer's own portal record. Their login shows them the full detail; you can add services by submitting an order.</p>
    </div>
</div>
<?php elseif ($snap_denied): ?>
<div class="mt-4 bg-white rounded-xl border border-gray-200 p-4 text-sm text-gray-500" data-testid="card-portal-account-unattributed">
    Linked to client #<?= (int)$cust['client_id'] ?>, which isn't attributed to your dealership — no account detail is shown.
</div>
<?php else: ?>
<div class="mt-4 bg-white rounded-xl border border-gray-200 p-4 text-sm text-gray-500" data-testid="card-portal-account-none">
    Not a portal client yet. Use <span class="font-medium text-gray-700">Sync to portal</span> on the Customers list to create their account.
</div>
<?php endif; ?>

<div class="mt-4 bg-white rounded-xl border border-gray-200 p-4 text-sm text-gray-500 flex items-center gap-4">
    <span><i class="fas fa-clock mr-1"></i>Added: <?= dealer_fmt_date($cust['created_at'] ?? null) ?></span>
    <span><i class="fas fa-edit mr-1"></i>Updated: <?= dealer_fmt_date($cust['updated_at'] ?? null) ?></span>
</div>
</div>
</div>
</div>
</body>
</html>
