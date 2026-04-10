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
$cid = (int)($_GET['id'] ?? 0);
$cust = $pdo->prepare("SELECT * FROM dealer_customers WHERE id=? AND dealer_id=?"); $cust->execute([$cid,$dealer_id]); $cust = $cust->fetch(PDO::FETCH_ASSOC);
if (!$cust) { portal_redirect('/portal/dealer-customers.php'); }

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $pdo->prepare("UPDATE dealer_customers SET type=?,name=?,email=?,phone=?,company=?,address=?,notes=?,updated_at=NOW() WHERE id=? AND dealer_id=?")
        ->execute([$_POST['type']??$cust['type'],trim($_POST['name']??''),trim($_POST['email']??''),trim($_POST['phone']??''),trim($_POST['company']??''),trim($_POST['address']??''),trim($_POST['notes']??''),$cid,$dealer_id]);
    $cust = $pdo->prepare("SELECT * FROM dealer_customers WHERE id=?"); $cust->execute([$cid]); $cust = $cust->fetch(PDO::FETCH_ASSOC);
    $success = 'Customer updated.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($cust['name']) ?> — Blue Mogul Partner</title>
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

<div class="mt-4 bg-white rounded-xl border border-gray-200 p-4 text-sm text-gray-500 flex items-center gap-4">
    <span><i class="fas fa-clock mr-1"></i>Added: <?= date('M j, Y', strtotime($cust['created_at'])) ?></span>
    <span><i class="fas fa-edit mr-1"></i>Updated: <?= date('M j, Y', strtotime($cust['updated_at'])) ?></span>
</div>
</div>
</div>
</div>
</body>
</html>
