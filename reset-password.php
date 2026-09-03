<?php require_once __DIR__ . '/config.php';

$success_msg = '';
$error_msg = '';
$valid_token = false;
$token = $_GET['token'] ?? '';

if (!empty($token)) {
    $db = getDB();
    $hashed = hash('sha256', $token);
    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE remember_token = ? AND remember_token_expires > NOW() LIMIT 1");
    $stmt->execute([$hashed]);
    $user = $stmt->fetch();
    
    if ($user) {
        $valid_token = true;
    } else {
        $error_msg = 'This reset link is invalid or has expired. Please request a new one.';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || strlen($password) < 8) {
        $error_msg = 'Password must be at least 8 characters long.';
        $valid_token = true;
    } elseif ($password !== $confirm) {
        $error_msg = 'Passwords do not match.';
        $valid_token = true;
    } else {
        $db = getDB();
        $hashed_token = hash('sha256', $token);
        $stmt = $db->prepare("SELECT id, email FROM users WHERE remember_token = ? AND remember_token_expires > NOW() LIMIT 1");
        $stmt->execute([$hashed_token]);
        $user = $stmt->fetch();
        
        if ($user) {
            $hashed_pw = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password = ?, remember_token = NULL, remember_token_expires = NULL WHERE id = ?")
               ->execute([$hashed_pw, $user['id']]);
            
            $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$user['id'], 'password_reset', 'user', $user['id'], 'Password reset completed', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            
            $success_msg = 'Your password has been reset successfully! You can now log in with your new password.';
            $valid_token = false;
        } else {
            $error_msg = 'This reset link is invalid or has expired. Please request a new one.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Blue Mogul Suite</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'blue-mogul-primary': '#1a56db',
                        'blue-mogul-secondary': '#0d1b3e',
                        'blue-mogul-accent': '#3b82f6'
                    },
                    fontFamily: { 'inter': ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="font-inter min-h-screen bg-gradient-to-br from-blue-mogul-secondary via-blue-900 to-blue-mogul-primary flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="/portal">
                <img src="/assets/img/bluemogul-logo.png" alt="Blue Mogul" class="h-16 mx-auto mb-4" onerror="this.style.display='none'">
            </a>
            <h1 class="text-2xl font-bold text-white">Create New Password</h1>
            <p class="text-blue-200 mt-2">Enter your new password below</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8 mb-6">

            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-800 p-4 rounded-lg mb-6" data-testid="alert-success">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-green-600 mt-0.5 mr-3"></i>
                        <div>
                            <p class="text-sm"><?php echo htmlspecialchars($success_msg); ?></p>
                            <a href="/portal" class="inline-block mt-3 bg-blue-mogul-primary hover:bg-blue-mogul-accent text-white font-semibold py-2 px-6 rounded-lg transition-all text-sm" data-testid="link-login">
                                <i class="fas fa-sign-in-alt mr-2"></i>Go to Login
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg mb-6" data-testid="alert-error">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-red-600 mt-0.5 mr-3"></i>
                        <p class="text-sm"><?php echo htmlspecialchars($error_msg); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
                <form method="POST" action="/portal/reset-password.php" data-testid="form-reset-password">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="mb-4">
                        <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">New Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-lock text-gray-400"></i>
                            </span>
                            <input 
                                type="password" 
                                name="password" 
                                id="password"
                                required
                                minlength="8"
                                placeholder="Minimum 8 characters"
                                class="w-full pl-10 pr-4 py-3 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-mogul-primary focus:border-blue-mogul-primary transition-all text-gray-800"
                                data-testid="input-password"
                            >
                        </div>
                    </div>

                    <div class="mb-6">
                        <label for="confirm_password" class="block text-sm font-semibold text-gray-700 mb-2">Confirm Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-lock text-gray-400"></i>
                            </span>
                            <input 
                                type="password" 
                                name="confirm_password" 
                                id="confirm_password"
                                required
                                minlength="8"
                                placeholder="Re-enter your password"
                                class="w-full pl-10 pr-4 py-3 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-mogul-primary focus:border-blue-mogul-primary transition-all text-gray-800"
                                data-testid="input-confirm-password"
                            >
                        </div>
                    </div>

                    <button 
                        type="submit" 
                        class="w-full bg-blue-mogul-primary hover:bg-blue-mogul-accent text-white font-semibold py-3 rounded-lg transition-all duration-300 transform hover:scale-[1.02]"
                        data-testid="button-reset"
                    >
                        <i class="fas fa-key mr-2"></i>
                        Reset Password
                    </button>
                </form>
            <?php elseif (empty($success_msg)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-link-slash text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-600 mb-4">This reset link is invalid or has expired.</p>
                    <a href="/portal/forgot-password.php" class="text-blue-mogul-primary hover:text-blue-mogul-accent font-medium" data-testid="link-request-new">
                        Request a new reset link
                    </a>
                </div>
            <?php endif; ?>

            <div class="mt-6 text-center">
                <a href="/portal" class="text-sm text-blue-mogul-primary hover:text-blue-mogul-accent transition-colors" data-testid="link-back-login">
                    <i class="fas fa-arrow-left mr-1"></i>
                    Back to Login
                </a>
            </div>
        </div>
    </div>

</body>
</html>
