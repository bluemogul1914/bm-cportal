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

$success = ''; $error = '';

// Update ACH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ach'])) {
    $routing = preg_replace('/\D/','',$_POST['ach_routing']??'');
    $account = preg_replace('/\D/','',$_POST['ach_account']??'');
    $ach_name= trim($_POST['ach_name']??'');
    $bank    = trim($_POST['bank_name']??'');
    if (strlen($routing) !== 9) { $error = 'Routing number must be 9 digits.'; }
    elseif (strlen($account) < 4) { $error = 'Account number is too short.'; }
    else {
        $pdo->prepare("UPDATE dealers SET ach_routing=?,ach_account=?,ach_name=?,bank_name=? WHERE id=?")
            ->execute([$routing,$account,$ach_name,$bank,$dealer_id]);
        $success = 'Bank info updated successfully.';
        $dealer = $pdo->prepare("SELECT * FROM dealers WHERE id=?"); $dealer->execute([$dealer_id]); $dealer = $dealer->fetch(PDO::FETCH_ASSOC);
    }
}

// Request payout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_payout'])) {
    $amt = (float)($_POST['amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='approved'"); $s->execute([$dealer_id]); $available = (float)$s->fetchColumn();
    if ($amt <= 0)         { $error = 'Please enter a valid amount.'; }
    elseif ($amt > $available) { $error = "Requested amount exceeds available balance ($" . number_format($available,2) . ")."; }
    elseif (!$dealer['ach_routing']) { $error = 'Please add your bank / ACH info first.'; }
    else {
        $pdo->prepare("INSERT INTO dealer_payout_requests (dealer_id,amount,status,notes) VALUES (?,?,'pending',?)")
            ->execute([$dealer_id,$amt,$notes]);
        $success = 'Payout request submitted! Funds will be sent via ACH within 1-2 business days.';
    }
}

// Stats
$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_commissions WHERE dealer_id=? AND status='approved'"); $s->execute([$dealer_id]); $available=(float)$s->fetchColumn();
$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_payout_requests WHERE dealer_id=? AND status='pending'"); $s->execute([$dealer_id]); $pending_payout=(float)$s->fetchColumn();
$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM dealer_payout_requests WHERE dealer_id=? AND status='paid'"); $s->execute([$dealer_id]); $total_paid=(float)$s->fetchColumn();

// Next Friday
$next_friday = new DateTime(); $next_friday->modify('next Friday');
$next_friday_str = $next_friday->format('l, M j');

// Payout history
$hist = $pdo->prepare("SELECT * FROM dealer_payout_requests WHERE dealer_id=? ORDER BY created_at DESC LIMIT 20"); $hist->execute([$dealer_id]); $payouts = $hist->fetchAll(PDO::FETCH_ASSOC);

$show_payout = isset($_GET['new']) || $error;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Payouts & ACH — Blue Mogul Partner</title>
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
        <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-money-bill-wave text-emerald-500 mr-2"></i>Payouts & ACH</h1>
    </div>
</header>

<div class="p-6 space-y-6">

<?php if ($success): ?><div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3 text-green-800" data-testid="alert-success"><i class="fas fa-check-circle text-green-500 text-lg"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm" data-testid="alert-error"><i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Left: Balance + Payout Request -->
    <div class="space-y-5">

        <!-- Balance card -->
        <div class="bg-gradient-to-br from-emerald-600 to-green-700 rounded-2xl p-6 text-white">
            <p class="text-green-200 text-sm font-medium mb-1">Available for Payout</p>
            <p class="text-4xl font-bold" data-testid="text-available-balance">$<?= number_format($available, 2) ?></p>
            <p class="text-green-200 text-sm mt-2"><i class="fas fa-calendar-alt mr-1"></i>Next auto-payout: <?= $next_friday_str ?></p>
            <?php if ($pending_payout > 0): ?>
            <p class="text-yellow-200 text-xs mt-1"><i class="fas fa-clock mr-1"></i>$<?= number_format($pending_payout,2) ?> payout in progress</p>
            <?php endif; ?>
            <button onclick="document.getElementById('payout-form').classList.toggle('hidden')" class="mt-4 bg-white text-green-700 font-semibold px-5 py-2 rounded-xl text-sm hover:bg-green-50 transition flex items-center gap-2" data-testid="button-request-early-payout">
                <i class="fas fa-bolt"></i> Request Early Payout
            </button>
        </div>

        <!-- Early payout form -->
        <div id="payout-form" class="<?= $show_payout ? '' : 'hidden' ?>">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-4"><i class="fas fa-money-bill-transfer text-emerald-500 mr-2"></i>Request Payout</h3>
            <form method="post" class="space-y-4">
                <input type="hidden" name="request_payout" value="1">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount ($) *</label>
                    <input type="number" name="amount" step="0.01" min="1" max="<?= $available ?>" value="<?= number_format($available,2,'.','') ?>"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500" data-testid="input-payout-amount" required>
                    <p class="text-xs text-gray-400 mt-1">Max: $<?= number_format($available,2) ?></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                    <input type="text" name="notes" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500" placeholder="e.g. urgent" data-testid="input-payout-notes">
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-5 py-2.5 rounded-xl transition text-sm" data-testid="button-submit-payout">Submit Request</button>
                    <button type="button" onclick="document.getElementById('payout-form').classList.add('hidden')" class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-5 py-2.5 rounded-xl transition text-sm">Cancel</button>
                </div>
            </form>
        </div>
        </div>

        <!-- ACH Bank Info -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="font-semibold text-gray-900 mb-4"><i class="fas fa-university text-blue-500 mr-2"></i>ACH Bank Info</h3>
            <form method="post" class="space-y-4">
                <input type="hidden" name="update_ach" value="1">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Account Holder Name</label>
                    <input type="text" name="ach_name" value="<?= htmlspecialchars($dealer['ach_name']??'') ?>"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="Name on bank account" data-testid="input-ach-name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                    <input type="text" name="bank_name" value="<?= htmlspecialchars($dealer['bank_name']??'') ?>"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="e.g. Chase, Wells Fargo" data-testid="input-bank-name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Routing Number (9 digits) *</label>
                    <input type="text" name="ach_routing" value="<?= htmlspecialchars($dealer['ach_routing']??'') ?>" maxlength="9"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 font-mono" placeholder="123456789" data-testid="input-ach-routing">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Account Number *</label>
                    <input type="text" name="ach_account" value="<?= htmlspecialchars($dealer['ach_account']??'') ?>"
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 font-mono" placeholder="Account number" data-testid="input-ach-account">
                </div>
                <div class="bg-blue-50 rounded-xl p-3 text-xs text-blue-700">
                    <i class="fas fa-lock mr-1"></i>Your banking info is encrypted and only used for ACH payouts.
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-xl transition text-sm" data-testid="button-update-ach">Update Bank Info</button>
            </form>
        </div>
    </div>

    <!-- Right: Payout History -->
    <div class="bg-white rounded-xl border border-gray-200">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Payout History</h3>
            <span class="text-sm text-gray-500">Total paid: <strong class="text-gray-800">$<?= number_format($total_paid,2) ?></strong></span>
        </div>
        <?php if ($payouts): ?>
        <div class="divide-y divide-gray-50">
            <?php foreach ($payouts as $p): ?>
            <?php
                $pbadge = match($p['status']) {
                    'paid'     => 'bg-green-100 text-green-800',
                    'approved' => 'bg-blue-100 text-blue-800',
                    'rejected' => 'bg-red-100 text-red-800',
                    default    => 'bg-yellow-100 text-yellow-800',
                };
            ?>
            <div class="px-5 py-4 flex items-center justify-between" data-testid="row-payout-<?= $p['id'] ?>">
                <div>
                    <p class="text-sm font-medium text-gray-900"><?= date('M j, Y', strtotime($p['created_at'])) ?></p>
                    <?php if ($p['notes']): ?><p class="text-xs text-gray-400"><?= htmlspecialchars($p['notes']) ?></p><?php endif; ?>
                </div>
                <div class="text-right flex items-center gap-3">
                    <p class="font-bold text-gray-900">$<?= number_format($p['amount'],2) ?></p>
                    <span class="text-xs px-2.5 py-1 rounded-full font-semibold <?= $pbadge ?>"><?= ucfirst($p['status']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="px-5 py-16 text-center text-gray-400">
            <i class="fas fa-money-bill-wave text-4xl mb-3 block"></i>
            <p class="text-sm">No payouts yet. Build up commissions and request your first payout!</p>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>
</div>
</body>
</html>
