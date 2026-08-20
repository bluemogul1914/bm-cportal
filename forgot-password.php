<?php require_once __DIR__ . '/config.php';

$success_msg = '';
$error_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid email address.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $token  = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 172800); // 48 hours

            $db->prepare("UPDATE users SET remember_token = ?, remember_token_expires = ? WHERE id = ?")
               ->execute([hash('sha256', $token), $expiry, $user['id']]);

            $reset_link = portal_base_url() . '/portal/reset-password.php?token=' . $token;
            
            require_once __DIR__ . '/includes/email.php';
            if (function_exists('send_email')) {
                $body = email_template(
                    'Password Reset Request',
                    '<p>Hi ' . htmlspecialchars($user['name']) . ',</p>
                    <p>We received a request to reset your password. Click the button below to create a new password:</p>
                    <p style="text-align:center;margin:30px 0;">
                        <a href="' . $reset_link . '" style="background-color:#5271FD;color:#ffffff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:600;">Reset Password</a>
                    </p>
                    <p style="font-size:13px;color:#A0A0A0;">This link expires in 48 hours. If you didn\'t request this, you can safely ignore this email.</p>'
                );
                send_email($user['email'], 'Password Reset - Blue Mogul Portal', $body);
            }
            
            $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$user['id'], 'password_reset_requested', 'user', $user['id'], 'Password reset requested', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
        }
        
        $success_msg = 'If an account with that email exists, you will receive a password reset link shortly. Please check your inbox and spam folder.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Blue Mogul Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/brand-design-system.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'blue-mogul-primary': '#5271FD',
                        'blue-mogul-secondary': '#0A0A0A',
                        'blue-mogul-accent': '#5271FD',
                        'blue-mogul-surface': '#111111',
                        'blue-mogul-dim': '#A0A0A0',
                        'blue-mogul-border': '#1A1A1A'
                    },
                    fontFamily: { 'inter': ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="font-inter min-h-screen bg-[#0A0A0A] flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="/portal">
                <img src="/assets/img/bluemogul-logo.png" alt="Blue Mogul" class="h-16 mx-auto mb-4" onerror="this.style.display='none'">
            </a>
            <h1 class="text-2xl font-bold text-white">Reset Your Password</h1>
            <p class="text-blue-200 mt-2">Enter your email address and we'll send you a reset link</p>
        </div>

        <div class="bg-[#0A0A0A] rounded-2xl shadow-2xl p-8 mb-6">

            <?php if ($success_msg): ?>
                <div class="bg-[#3ECF8E]/10 border border-[#3ECF8E]/30 text-[#3ECF8E] p-4 rounded-lg mb-6" data-testid="alert-success">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-[#3ECF8E] mt-0.5 mr-3"></i>
                        <p class="text-sm"><?php echo htmlspecialchars($success_msg); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="bg-[#E05A4E]/10 border border-[#E05A4E]/30 text-[#E05A4E] p-4 rounded-lg mb-6" data-testid="alert-error">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-[#E05A4E] mt-0.5 mr-3"></i>
                        <p class="text-sm"><?php echo htmlspecialchars($error_msg); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($success_msg)): ?>
                <form method="POST" action="/portal/forgot-password.php" data-testid="form-forgot-password">
                    <?= csrf_field() ?>
                    <div class="mb-6">
                        <label for="email" class="block text-sm font-semibold text-[#A0A0A0] mb-2">Email Address</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-envelope text-[#A0A0A0]"></i>
                            </span>
                            <input 
                                type="email" 
                                name="email" 
                                id="email"
                                required
                                placeholder="Enter your email address"
                                class="w-full pl-10 pr-4 py-3 border-2 border-[#1A1A1A] rounded-lg focus-visible:ring-2 focus-visible:ring-[#5271FD] focus-visible:border-[#5271FD] transition-all text-white bg-[#0A0A0A]"
                                data-testid="input-email"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            >
                        </div>
                    </div>

                    <button 
                        type="submit" 
                        class="w-full bg-[#5271FD] hover:bg-[#6B8AFF] text-white font-semibold py-3 rounded-lg transition-all duration-300 transform hover:scale-[1.02]"
                        data-testid="button-submit"
                    >
                        <i class="fas fa-paper-plane mr-2"></i>
                        Send Reset Link
                    </button>
                </form>
            <?php endif; ?>

            <div class="mt-6 text-center">
                <a href="/portal" class="text-sm text-[#5271FD] hover:text-[#6B8AFF] transition-colors" data-testid="link-back-login">
                    <i class="fas fa-arrow-left mr-1"></i>
                    Back to Login
                </a>
            </div>
        </div>

        <div class="text-center text-white">
            <p class="mb-2">
                <i class="fas fa-question-circle mr-2"></i>
                Need help?
            </p>
            <div class="flex items-center justify-center space-x-6">
                <a href="tel:3463095514" class="hover:text-blue-200 transition-colors">
                    <i class="fas fa-phone mr-2"></i>346-309-5514
                </a>
                <a href="mailto:contact@bluemogul.biz" class="hover:text-blue-200 transition-colors">
                    <i class="fas fa-envelope mr-2"></i>contact@bluemogul.biz
                </a>
            </div>
        </div>
    </div>

</body>
</html>
