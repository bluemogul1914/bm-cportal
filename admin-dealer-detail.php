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

// ── Dealer payouts: PayPal / Stripe Connect ──────────────────────────────────
// Money movement lives in server/dealer-payouts.ts; this page drives it through
// the loopback API with this admin session (same pattern as admin-accounting.php).
$internalOrigin = 'http://127.0.0.1:' . (getenv('PORT') ?: '3000');

function dp_api(string $origin, string $path, ?array $body = null): array {
    $ch = curl_init($origin . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_COOKIE         => 'connect.sid=' . ($_COOKIE['connect.sid'] ?? ''),
    ];
    if ($body !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err, 'http_code' => 0];
    $j = json_decode((string)$resp, true);
    return is_array($j) ? ($j + ['http_code' => $code])
                        : ['error' => 'Invalid JSON (HTTP ' . $code . ')', 'raw' => substr((string)$resp, 0, 300), 'http_code' => $code];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['payout_set_destination'])) {
        $r = dp_api($internalOrigin, '/portal/api/admin/dealer-payouts/destination', [
            'dealer_id'         => $did,
            'method'            => $_POST['payout_method'] ?? 'manual',
            'paypal_email'      => trim((string)($_POST['paypal_email'] ?? '')),
            'stripe_account_id' => trim((string)($_POST['stripe_account_id'] ?? '')),
        ]);
        if (!empty($r['ok'])) $success = 'Payout method saved.';
        else $error = 'Could not save payout method: ' . ($r['error'] ?? 'unknown error');
    }

    if (isset($_POST['payout_record'])) {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['commission_ids'] ?? []))));
        $r = dp_api($internalOrigin, '/portal/api/admin/dealer-payouts/create', [
            'dealer_id'      => $did,
            'method'         => $_POST['payout_method'] ?? 'manual',
            'amount'         => trim((string)($_POST['payout_amount'] ?? '0')),
            'commission_ids' => $ids,
            'reference'      => trim((string)($_POST['payout_reference'] ?? '')),
            'note'           => trim((string)($_POST['payout_note'] ?? '')),
        ]);
        if (!empty($r['ok'])) {
            $success = 'Payout #' . (int)$r['payoutId'] . ' recorded ($' . number_format(((int)($r['payoutId'] ?? 0) ? (float)($_POST['payout_amount'] ?? 0) : 0), 2)
                     . ') — ' . (int)$r['commissionsMarked'] . ' commission(s) marked paid. Use Send to move the money.';
        } else {
            $error = 'Could not record payout: ' . ($r['error'] ?? 'unknown error');
        }
    }

    if (isset($_POST['payout_send'])) {
        $r = dp_api($internalOrigin, '/portal/api/admin/dealer-payouts/send', ['payout_id' => (int)($_POST['payout_id'] ?? 0)]);
        if (!empty($r['ok'])) $success = 'Payout sent. Provider ref: ' . ($r['providerRef'] ?? 'n/a');
        else $error = 'Payout not sent: ' . ($r['error'] ?? 'unknown error');
    }

    if (isset($_POST['update_dealer'])) {
        $pdo->prepare("UPDATE dealers SET company_name=?,commission_rate=?,status=?,notes=?,full_name=? WHERE id=?")
            ->execute([trim($_POST['company_name'] ?? ''??''), max(0,min(100,(float)($_POST['commission_rate'] ?? 10??10))), $_POST['status']??'active', trim($_POST['notes'] ?? ''??''), trim($_POST['user_name']??''), $did]);
        if (!empty($d['user_id'])) {
            $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([trim($_POST['user_name']??''), $d['user_id']]);
        }
        $success = 'Dealer updated.';
        $d = $pdo->prepare("SELECT d.*,COALESCE(u.name, d.full_name) AS user_name,u.email,u.created_at as user_created FROM dealers d LEFT JOIN users u ON d.user_id=u.id WHERE d.id=?");
        $d->execute([$did]); $d = $d->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_POST['approve_commission'])) {
        $cid = (int)($_POST['commission_id'] ?? 0);
        $pdo->prepare("UPDATE dealer_commissions SET status='approved',approved_at=NOW() WHERE id=? AND dealer_id=?")->execute([$cid,$did]);
        $success = 'Commission approved.';
    }
    if (isset($_POST['mark_paid'])) {
        $pid = (int)($_POST['payout_id'] ?? 0);
        $pdo->prepare("UPDATE dealer_payout_requests SET status='paid' WHERE id=? AND dealer_id=?")->execute([$pid,$did]);
        $success = 'Payout marked paid.';
    }
    if (isset($_POST['set_comm_paid'])) {
        $cid = (int)($_POST['commission_id'] ?? 0);
        $pdo->prepare("UPDATE dealer_commissions SET status='paid',paid_at=NOW() WHERE id=? AND dealer_id=?")->execute([$cid,$did]);
        $success = 'Commission marked paid.';
    }
}

// Load data
$orders=$pdo->prepare("SELECT * FROM dealer_orders WHERE dealer_id=? ORDER BY created_at DESC LIMIT 20"); $orders->execute([$did]); $orders=$orders->fetchAll(PDO::FETCH_ASSOC);
$commissions=$pdo->prepare("SELECT dc.*,dord.product_line,dord.customer_name FROM dealer_commissions dc LEFT JOIN dealer_orders dord ON dc.order_id=dord.id WHERE dc.dealer_id=? ORDER BY dc.created_at DESC LIMIT 20"); $commissions->execute([$did]); $commissions=$commissions->fetchAll(PDO::FETCH_ASSOC);
$payouts=$pdo->prepare("SELECT * FROM dealer_payout_requests WHERE dealer_id=? ORDER BY created_at DESC LIMIT 10"); $payouts->execute([$did]); $payouts=$payouts->fetchAll(PDO::FETCH_ASSOC);
$smtp=$pdo->prepare("SELECT * FROM dealer_smtp_settings WHERE dealer_id=?"); $smtp->execute([$did]); $smtp=$smtp->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Payout rails (PayPal / Stripe Connect) ───────────────────────────────────
$payoutCaps    = dp_api($internalOrigin, '/portal/api/admin/dealer-payouts/capabilities');
$payable       = [];
$dpayouts      = [];
$payableTotal  = 0.0;
try {
    $q = $pdo->prepare("SELECT dc.id,dc.amount,dc.status,dc.created_at,o.product_line,o.customer_name,o.order_ref
                          FROM dealer_commissions dc LEFT JOIN dealer_orders o ON o.id = dc.order_id
                         WHERE dc.dealer_id=? AND dc.status IN ('pending','approved') ORDER BY dc.created_at ASC");
    $q->execute([$did]); $payable = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($payable as $c) $payableTotal += (float)$c['amount'];
    $q = $pdo->prepare("SELECT * FROM dealer_payouts WHERE dealer_id=? ORDER BY id DESC LIMIT 12");
    $q->execute([$did]); $dpayouts = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $payable = []; $dpayouts = []; }

$currentMethod = $d['payout_method'] ?? 'manual';
$currentDest   = $currentMethod === 'paypal'         ? ($d['paypal_email'] ?? '')
               : ($currentMethod === 'stripe_connect' ? ($d['stripe_account_id'] ?? '')
               : ($currentMethod === 'ach'            ? (!empty($d['ach_account']) ? '****' . substr((string)$d['ach_account'], -4) : '')
               : 'manual / no destination needed'));

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
<link rel="stylesheet" href="/assets/css/tailwind.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script src="/assets/js/tailwind-shared.js"></script>
<script>tailwind.config = window.bmTailwindConfig;</script>
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
        <?= csrf_field() ?>
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
            <p><i class="fas fa-calendar mr-1"></i>Joined: <?= fmt_date($d['created_at'] ?? null, 'M j, Y') ?></p>
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
                            <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="approve_commission" value="1"><input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                            <button class="text-xs bg-blue-100 text-blue-700 hover:bg-blue-200 px-2 py-0.5 rounded transition" data-testid="button-approve-<?= $c['id'] ?>">Approve</button></form>
                            <?php elseif ($c['status']==='approved'): ?>
                            <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="set_comm_paid" value="1"><input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
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
                        <td class="px-4 py-2 text-gray-500"><?= fmt_date($o['created_at'] ?? null, 'M j') ?></td>
                        <td class="px-4 py-2 text-green-700 font-bold">$<?= number_format($o['commission_amount'],2) ?></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full font-medium <?= $ord_cfg[$o['status']]??'bg-gray-100 text-gray-600' ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><p class="px-5 py-8 text-center text-gray-400 text-sm">No orders yet.</p><?php endif; ?>
        </div>

        <!-- Pay this dealer: PayPal / Stripe Connect -->
        <div class="bg-white rounded-xl border border-gray-200 mb-4" data-testid="card-dealer-payouts">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 text-sm"><i class="fas fa-money-bill-transfer text-emerald-500 mr-2"></i>Pay this dealer</h3>
                <span class="text-xs text-gray-400">PayPal · Stripe Connect · ACH · Manual</span>
            </div>

            <div class="p-5 space-y-4">
                <?php
                $caps = is_array($payoutCaps) && empty($payoutCaps['error']) ? $payoutCaps : [];
                ?>
                <div class="text-xs rounded-lg px-3 py-2 <?= !empty($caps['paypal_configured']) ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' ?>">
                    <span class="font-medium">Rails:</span>
                    PayPal <?= !empty($caps['paypal_configured']) ? 'configured ('.htmlspecialchars((string)($caps['paypal_env'] ?? 'sandbox')).')' : 'NOT configured' ?>
                    · Stripe <?= !empty($caps['stripe_configured']) ? 'key present' : 'NOT configured' ?>
                    <?php if (!empty($caps['notes'])): ?><span class="block mt-1 text-[11px] opacity-80"><?= htmlspecialchars(implode(' ', (array)$caps['notes'])) ?></span><?php endif; ?>
                </div>

                <form method="post" class="grid grid-cols-1 md:grid-cols-4 gap-2 items-end" data-testid="form-payout-destination">
                    <?= csrf_field() ?><input type="hidden" name="payout_set_destination" value="1">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Payout method</label>
                        <select name="payout_method" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                            <?php foreach (['manual'=>'Manual / wire','paypal'=>'PayPal','stripe_connect'=>'Stripe Connect','ach'=>'Bank ACH'] as $v=>$lbl): ?>
                            <option value="<?= $v ?>" <?= $currentMethod === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">PayPal email</label>
                        <input name="paypal_email" value="<?= htmlspecialchars((string)($d['paypal_email'] ?? '')) ?>" placeholder="dealer@example.com" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Stripe acct_ id</label>
                        <input name="stripe_account_id" value="<?= htmlspecialchars((string)($d['stripe_account_id'] ?? '')) ?>" placeholder="acct_XXXX" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-lg text-sm font-medium">Save method</button>
                        <span class="text-[11px] text-gray-500">current: <span class="font-mono"><?= htmlspecialchars((string)$currentDest) ?></span></span>
                    </div>
                </form>

                <form method="post" data-testid="form-payout-create">
                    <?= csrf_field() ?><input type="hidden" name="payout_record" value="1">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">Commissions owed</p>
                        <p class="text-xs text-gray-500">selected total: <span id="dp-total" class="font-semibold text-gray-900">$0.00</span> · available: $<?= number_format($payableTotal, 2) ?></p>
                    </div>
                    <?php if ($payable): ?>
                    <div class="border border-gray-200 rounded-lg divide-y divide-gray-50 max-h-56 overflow-y-auto mb-3">
                        <?php foreach ($payable as $c): ?>
                        <label class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="commission_ids[]" value="<?= (int)$c['id'] ?>" class="dp-comm rounded" data-amount="<?= number_format((float)$c['amount'], 2, '.', '') ?>">
                            <span class="flex-1 truncate"><?= htmlspecialchars((string)($c['product_line'] ?: 'Commission')) ?><?= $c['customer_name'] ? ' · ' . htmlspecialchars((string)$c['customer_name']) : '' ?><?= $c['order_ref'] ? ' · ' . htmlspecialchars((string)$c['order_ref']) : '' ?></span>
                            <span class="text-xs px-2 py-0.5 rounded-full <?= $c['status'] === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= htmlspecialchars((string)$c['status']) ?></span>
                            <span class="font-medium w-16 text-right">$<?= number_format((float)$c['amount'], 2) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-gray-400 border border-dashed border-gray-200 rounded-lg px-3 py-4 mb-3 text-center">No unpaid commissions — nothing owed right now.</p>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-2 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Amount (USD)</label>
                            <input id="dp-amount" name="payout_amount" value="<?= $payableTotal > 0 ? number_format($payableTotal, 2, '.', '') : '' ?>" placeholder="0.00" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Pay via</label>
                            <select name="payout_method" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                                <?php foreach (['paypal'=>'PayPal','stripe_connect'=>'Stripe Connect','ach'=>'Bank ACH','manual'=>'Manual / wire'] as $v=>$lbl): ?>
                                <option value="<?= $v ?>" <?= $currentMethod === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Reference / note</label>
                            <input name="payout_reference" placeholder="check #, wire ref" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div class="flex items-center gap-2">
                            <input name="payout_note" placeholder="note to dealer" class="w-40 px-2 py-2 border border-gray-300 rounded-lg text-sm">
                            <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg text-sm font-medium whitespace-nowrap" data-testid="button-record-payout">
                                <i class="fas fa-file-invoice-dollar mr-1"></i>Record payout
                            </button>
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-2">Recording marks the ticked commissions paid and creates the payout record. Money moves only when you press <span class="font-medium">Send</span> on the row below.</p>
                </form>

                <?php if ($dpayouts): ?>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                            <tr><th class="text-left px-3 py-2">Date</th><th class="text-left px-3 py-2">Amount</th><th class="text-left px-3 py-2">Method</th><th class="text-left px-3 py-2">Destination</th><th class="text-left px-3 py-2">Status</th><th class="text-left px-3 py-2">Provider ref</th><th class="px-3 py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                        <?php foreach ($dpayouts as $p): ?>
                            <tr data-testid="row-dpayout-<?= (int)$p['id'] ?>">
                                <td class="px-3 py-2 text-xs text-gray-500"><?= fmt_date($p['created_at'] ?? null, 'M j, Y') ?></td>
                                <td class="px-3 py-2 font-medium">$<?= number_format(((int)$p['amount_cents']) / 100, 2) ?></td>
                                <td class="px-3 py-2 text-xs"><?= htmlspecialchars((string)($p['method'] ?? '—')) ?></td>
                                <td class="px-3 py-2 text-xs font-mono truncate max-w-[160px]"><?= htmlspecialchars((string)($p['destination'] ?? '—')) ?></td>
                                <td class="px-3 py-2">
                                    <?php $st = (string)($p['status'] ?? 'pending'); $cls = $st === 'sent' ? 'bg-emerald-100 text-emerald-700' : ($st === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'); ?>
                                    <span class="text-xs px-2 py-0.5 rounded-full <?= $cls ?>"><?= htmlspecialchars($st) ?></span>
                                    <?php if (!empty($p['failure_reason'])): ?><span class="block text-[11px] text-red-600 mt-0.5"><?= htmlspecialchars(substr((string)$p['failure_reason'], 0, 120)) ?></span><?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-xs font-mono text-gray-500"><?= htmlspecialchars((string)($p['provider_ref'] ?? '—')) ?></td>
                                <td class="px-3 py-2 text-right">
                                    <?php if (in_array($st, ['pending','failed'], true)): ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Move real money for payout #<?= (int)$p['id'] ?>?');">
                                        <?= csrf_field() ?><input type="hidden" name="payout_send" value="1"><input type="hidden" name="payout_id" value="<?= (int)$p['id'] ?>">
                                        <button class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-2.5 py-1 rounded font-medium" data-testid="button-send-payout-<?= (int)$p['id'] ?>"><i class="fas fa-paper-plane mr-1"></i>Send</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-xs text-gray-400 text-center py-2">No PayPal / Stripe Connect payouts recorded yet.</p>
                <?php endif; ?>
            </div>
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
                    <p class="text-xs text-gray-500"><?= fmt_date($p['created_at'] ?? null, 'M j, Y') ?><?= $p['notes'] ? ' · '.htmlspecialchars($p['notes']) : '' ?></p>
                </div>
                <?php if ($p['status']==='pending'): ?>
                <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="mark_paid" value="1"><input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
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
<script>
/* Tick commissions → the amount field follows the selection. */
(function () {
  var boxes  = document.querySelectorAll('.dp-comm');
  var amount = document.getElementById('dp-amount');
  var total  = document.getElementById('dp-total');
  function recalc() {
    var sum = 0, n = 0;
    boxes.forEach(function (b) { if (b.checked) { sum += parseFloat(b.dataset.amount || '0'); n++; } });
    if (total)  total.textContent = '$' + sum.toFixed(2);
    if (amount && n > 0) amount.value = sum.toFixed(2);
  }
  boxes.forEach(function (b) { b.addEventListener('change', recalc); });
  recalc();
})();
</script>
</body>
</html>
