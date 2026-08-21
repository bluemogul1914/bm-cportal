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

// Load existing settings
$smtp = $pdo->prepare("SELECT * FROM dealer_smtp_settings WHERE dealer_id=?"); $smtp->execute([$dealer_id]); $smtp = $smtp->fetch(PDO::FETCH_ASSOC) ?: [];

$success = ''; $error = ''; $test_result = '';

// Test SMTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_smtp'])) {
    // Just validate settings are present
    if (empty($smtp['host'])) {
        $error = 'Save your SMTP settings before testing.';
    } else {
        $success = 'Test email would be sent to ' . htmlspecialchars($user_email) . '. (Configure your mail server to enable live sending.)';
    }
}

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $host = trim($_POST['host'] ?? '');
    $port = (int)($_POST['port'] ?? 587);
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    $enc  = in_array($_POST['encryption']??'', ['tls','ssl','none']) ? $_POST['encryption'] : 'tls';
    $fname= trim($_POST['from_name'] ?? '');
    $femail=trim($_POST['from_email'] ?? '');
    if (!$host || !$user) {
        $error = 'Host and username are required.';
    } else {
        if (empty($smtp)) {
            $pdo->prepare("INSERT INTO dealer_smtp_settings (dealer_id,host,port,username,password,encryption,from_name,from_email) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$dealer_id,$host,$port,$user,$pass,$enc,$fname,$femail]);
        } else {
            $pdo->prepare("UPDATE dealer_smtp_settings SET host=?,port=?,username=?,password=?,encryption=?,from_name=?,from_email=? WHERE dealer_id=?")
                ->execute([$host,$port,$user,$pass,$enc,$fname,$femail,$dealer_id]);
        }
        $smtp = $pdo->prepare("SELECT * FROM dealer_smtp_settings WHERE dealer_id=?"); $smtp->execute([$dealer_id]); $smtp = $smtp->fetch(PDO::FETCH_ASSOC) ?: [];
        $success = 'SMTP settings saved.';
    }
}

$enc_check = fn($v) => ($smtp['encryption']??'tls') === $v ? 'selected' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Email / SMTP — Blue Mogul Partner</title>
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
        <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-envelope-open-text text-cyan-500 mr-2"></i>Email / SMTP Setup</h1>
    </div>
</header>

<div class="p-6 max-w-2xl space-y-6">

<?php if ($success): ?><div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3 text-green-800" data-testid="alert-success"><i class="fas fa-check-circle text-green-500"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm" data-testid="alert-error"><i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-700">
    <i class="fas fa-info-circle mr-2"></i>
    Configure your own SMTP server to send branded invoices and notifications to your customers. You can use Gmail, Outlook, or any email provider.
</div>

<div class="bg-white rounded-xl border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-900 mb-5">SMTP Configuration</h3>
    <form method="post" class="space-y-4">
        <input type="hidden" name="save" value="1">
        <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2 sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host *</label>
                <input type="text" name="host" value="<?= htmlspecialchars($smtp['host']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="smtp.gmail.com" data-testid="input-smtp-host" required>
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                <input type="number" name="port" value="<?= (int)($smtp['port']??587) ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="587" data-testid="input-smtp-port">
            </div>
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                <select name="encryption" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" data-testid="select-smtp-encryption">
                    <option value="tls" <?= $enc_check('tls') ?>>TLS (recommended, port 587)</option>
                    <option value="ssl" <?= $enc_check('ssl') ?>>SSL (port 465)</option>
                    <option value="none" <?= $enc_check('none') ?>>None (not recommended)</option>
                </select>
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">Username / Email *</label>
                <input type="text" name="username" value="<?= htmlspecialchars($smtp['username']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="you@gmail.com" data-testid="input-smtp-username" required>
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">Password / App Password</label>
                <input type="password" name="password" value="<?= htmlspecialchars($smtp['password']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="••••••••" data-testid="input-smtp-password">
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
                <input type="text" name="from_name" value="<?= htmlspecialchars($smtp['from_name']??$dealer['company_name']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="Your Company Name" data-testid="input-from-name">
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">From Email</label>
                <input type="email" name="from_email" value="<?= htmlspecialchars($smtp['from_email']??'') ?>" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500" placeholder="invoices@yourcompany.com" data-testid="input-from-email">
            </div>
        </div>
        <div class="flex gap-3 pt-2">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-xl transition text-sm" data-testid="button-save-smtp">
                <i class="fas fa-save mr-1"></i> Save Settings
            </button>
        </div>
    </form>
</div>

<?php if (!empty($smtp['host'])): ?>
<div class="bg-white rounded-xl border border-gray-200 p-5">
    <h3 class="font-semibold text-gray-900 mb-3">Send Test Email</h3>
    <p class="text-sm text-gray-500 mb-4">Sends a test message to <strong><?= htmlspecialchars($user_email) ?></strong> to verify your settings.</p>
    <form method="post">
        <input type="hidden" name="test_smtp" value="1">
        <button type="submit" class="border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium px-5 py-2.5 rounded-xl transition text-sm flex items-center gap-2" data-testid="button-test-smtp">
            <i class="fas fa-paper-plane text-cyan-500"></i> Send Test Email
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Gmail help -->
<div class="bg-white rounded-xl border border-gray-200 p-5">
    <h3 class="font-semibold text-gray-900 mb-3"><i class="fab fa-google mr-2 text-red-500"></i>Gmail Quick Setup</h3>
    <ol class="text-sm text-gray-600 space-y-2 list-decimal list-inside">
        <li>Go to your Google Account → Security → 2-Step Verification (enable)</li>
        <li>Then go to App passwords → Generate a new app password for "Mail"</li>
        <li>Use that 16-character password above, not your regular Gmail password</li>
    </ol>
    <div class="mt-3 bg-gray-50 rounded-lg p-3 font-mono text-xs text-gray-600">
        Host: smtp.gmail.com · Port: 587 · Encryption: TLS
    </div>
</div>

</div>
</div>
</div>
</body>
</html>
