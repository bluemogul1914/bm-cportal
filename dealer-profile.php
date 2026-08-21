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
$user_row = $pdo->prepare("SELECT * FROM users WHERE id=?"); $user_row->execute([$user_id]); $user_row = $user_row->fetch(PDO::FETCH_ASSOC);

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name    = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $pass    = $_POST['new_password'] ?? '';
    $pass2   = $_POST['confirm_password'] ?? '';
    if (!$name) { $error = 'Name is required.'; }
    elseif ($pass && strlen($pass) < 8) { $error = 'New password must be at least 8 characters.'; }
    elseif ($pass && $pass !== $pass2) { $error = 'Passwords do not match.'; }
    else {
        $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name,$user_id]);
        $pdo->prepare("UPDATE dealers SET company_name=? WHERE id=?")->execute([$company,$dealer_id]);
        if ($pass) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash,$user_id]);
        }
        $_SESSION['user_name'] = $name;
        $user_name = $name;
        $dealer = $pdo->prepare("SELECT * FROM dealers WHERE id=?"); $dealer->execute([$dealer_id]); $dealer = $dealer->fetch(PDO::FETCH_ASSOC);
        $success = 'Profile updated successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Profile — Blue Mogul Partner</title>
<script src="https://cdn.tailwindcss.com"></script>
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
    <div class="px-6 py-4">
        <p class="text-xs text-gray-400">Partner Portal /</p>
        <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-user-circle text-blue-500 mr-2"></i>My Profile</h1>
    </div>
</header>

<div class="p-6 max-w-2xl space-y-6">

<?php if ($success): ?><div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3 text-green-800" data-testid="alert-success"><i class="fas fa-check-circle text-green-500"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm" data-testid="alert-error"><i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Dealer ID Card -->
<div class="bg-gradient-to-br from-blue-900 to-indigo-900 rounded-2xl p-6 text-white">
    <div class="flex items-center gap-5">
        <div class="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center text-2xl font-bold flex-shrink-0">
            <?= strtoupper(substr($user_name,0,1)) ?>
        </div>
        <div>
            <h2 class="text-xl font-bold"><?= htmlspecialchars($user_name) ?></h2>
            <p class="text-blue-200 text-sm"><?= htmlspecialchars($dealer['company_name'] ?? '') ?></p>
            <p class="text-blue-300 text-xs mt-1"><?= htmlspecialchars($user_email) ?></p>
        </div>
    </div>
    <div class="mt-5 flex flex-wrap gap-4">
        <div class="bg-white/10 rounded-xl px-4 py-2">
            <p class="text-blue-200 text-xs">Referral Code</p>
            <p class="font-mono font-bold text-yellow-300 text-lg"><?= htmlspecialchars($dealer['referral_code']) ?></p>
        </div>
        <div class="bg-white/10 rounded-xl px-4 py-2">
            <p class="text-blue-200 text-xs">Commission Rate</p>
            <p class="font-bold text-white text-lg"><?= number_format($dealer['commission_rate']??10, 1) ?>%</p>
        </div>
        <div class="bg-white/10 rounded-xl px-4 py-2">
            <p class="text-blue-200 text-xs">Status</p>
            <p class="font-bold text-green-300 text-sm capitalize"><?= htmlspecialchars($dealer['status'] ?? 'active') ?></p>
        </div>
        <div class="bg-white/10 rounded-xl px-4 py-2">
            <p class="text-blue-200 text-xs">Member Since</p>
            <p class="font-bold text-white text-sm"><?= date('M Y', strtotime($dealer['created_at'])) ?></p>
        </div>
    </div>
</div>

<!-- Edit Profile -->
<div class="bg-white rounded-xl border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-900 mb-5">Edit Profile</h3>
    <form method="post" class="space-y-4">
        <input type="hidden" name="update_profile" value="1">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user_name) ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" required data-testid="input-name">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
            <input type="email" value="<?= htmlspecialchars($user_email) ?>" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl text-sm text-gray-500 cursor-not-allowed" disabled>
            <p class="text-xs text-gray-400 mt-1">Contact support to change your email address.</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
            <input type="text" name="company" value="<?= htmlspecialchars($dealer['company_name']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-company">
        </div>
        <div class="border-t border-gray-100 pt-4 mt-4">
            <p class="text-sm font-medium text-gray-700 mb-3">Change Password <span class="text-gray-400 font-normal">(leave blank to keep current)</span></p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <input type="password" name="new_password" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="Min. 8 chars" data-testid="input-new-password">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                    <input type="password" name="confirm_password" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="Repeat" data-testid="input-confirm-password">
                </div>
            </div>
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-xl transition text-sm mt-2" data-testid="button-save-profile">
            <i class="fas fa-save mr-1"></i> Save Changes
        </button>
    </form>
</div>

</div>
</div>
</div>
</body>
</html>
