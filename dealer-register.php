<?php
require_once 'config.php';

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    portal_redirect('/portal/dealer-dashboard.php');
}

$errors = [];
$success = false;

function generate_dealer_code(string $company): string {
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $company));
    $base = substr($base, 0, 5) ?: 'DEAL';
    return $base . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $pass2   = $_POST['password2'] ?? '';

        if (!$name)    $errors[] = 'Full name is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!$company) $errors[] = 'Company name is required.';
        if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($pass !== $pass2) $errors[] = 'Passwords do not match.';

        if (!$errors) {
            try {
                $pdo = getDB();
                // Check duplicate email
                $exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $exists->execute([strtolower($email)]);
                if ($exists->fetchColumn()) {
                    $errors[] = 'An account with this email already exists.';
                } else {
                    $hash = password_hash($pass, PASSWORD_BCRYPT);
                    // Create user with dealer role
                    $ins = $pdo->prepare("INSERT INTO users (email, password, name, is_admin, role, status) VALUES (?, ?, ?, false, 'dealer', 'active') RETURNING id");
                    $ins->execute([strtolower($email), $hash, $name]);
                    $uid = $ins->fetchColumn();

                    // Generate unique referral code
                    do {
                        $code = generate_dealer_code($company);
                        $ck = $pdo->prepare("SELECT id FROM dealers WHERE referral_code = ?");
                        $ck->execute([$code]);
                    } while ($ck->fetchColumn());

                    $pdo->prepare("INSERT INTO dealers (user_id, company_name, referral_code, commission_rate, status) VALUES (?, ?, ?, 10.00, 'active')")
                        ->execute([$uid, $company, $code]);

                    $success = true;
                }
            } catch (PDOException $e) {
                error_log("dealer-register error: " . $e->getMessage());
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dealer Registration — Blue Mogul Partner Portal</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gradient-to-br from-blue-950 via-blue-900 to-indigo-900 min-h-screen flex items-center justify-center p-4 font-sans">
<div class="w-full max-w-lg">
    <div class="text-center mb-8">
        <img src="/assets/img/bluemogul-logo.png" alt="Blue Mogul" class="h-14 mx-auto mb-4">
        <h1 class="text-3xl font-bold text-white">Become a Partner</h1>
        <p class="text-blue-200 mt-2">Join the Blue Mogul dealer network and earn commissions</p>
    </div>

    <?php if ($success): ?>
    <div class="bg-white rounded-2xl shadow-2xl p-8 text-center">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-check-circle text-3xl text-green-500"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 mb-2">Registration Successful!</h2>
        <p class="text-gray-600 mb-6">Your dealer account has been created. You can now log in to access your partner dashboard.</p>
        <a href="index.php" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-8 py-3 rounded-xl transition" data-testid="link-go-to-login">
            <i class="fas fa-sign-in-alt"></i> Go to Login
        </a>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl shadow-2xl p-8">
        <?php if ($errors): ?>
        <div class="mb-5 bg-red-50 border border-red-200 rounded-xl p-4">
            <?php foreach ($errors as $e): ?>
            <p class="text-sm text-red-700 flex items-center gap-2"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($e) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf() ?>">

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                    <div class="relative">
                        <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="John Smith" data-testid="input-dealer-name" required>
                    </div>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Name *</label>
                    <div class="relative">
                        <i class="fas fa-building absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="company" value="<?= htmlspecialchars($_POST['company'] ?? '') ?>"
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="Acme Solutions LLC" data-testid="input-dealer-company" required>
                    </div>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
                    <div class="relative">
                        <i class="fas fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="john@company.com" data-testid="input-dealer-email" required>
                    </div>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <div class="relative">
                        <i class="fas fa-phone absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                               placeholder="(555) 000-0000" data-testid="input-dealer-phone">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                    <input type="password" name="password" class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" placeholder="Min. 8 characters" data-testid="input-dealer-password" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password *</label>
                    <input type="password" name="password2" class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" placeholder="Repeat password" data-testid="input-dealer-password2" required>
                </div>
            </div>

            <div class="bg-blue-50 rounded-xl p-4 text-sm text-blue-700">
                <i class="fas fa-info-circle mr-2"></i>
                A unique <strong>dealer referral code</strong> will be generated automatically upon registration. Use it to track orders and commissions.
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl transition text-sm" data-testid="button-dealer-register">
                <i class="fas fa-handshake mr-2"></i>Register as a Dealer
            </button>
        </form>

        <p class="text-center text-sm text-gray-500 mt-5">Already have an account? <a href="index.php" class="text-blue-600 hover:underline">Sign in</a></p>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
