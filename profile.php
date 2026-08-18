<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$success_msg = '';
$error_msg = '';
$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip = trim($_POST['zip'] ?? '');

        if (empty($name)) {
            $error_msg = 'Name is required.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
                $stmt->execute([$name, $user_id]);

                $stmt = $pdo->prepare("UPDATE clients SET name = ?, phone = ?, company = ?, address = ?, city = ?, state = ?, zip = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->execute([$name, $phone, $company, $address, $city, $state, $zip, $user_id]);

                $_SESSION['user_name'] = $name;
                $user_name = $name;
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'profile_updated', 'user', $user_id, 'Updated profile', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_msg = 'Profile updated successfully!';
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                $error_msg = 'Failed to update profile.';
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new_pass)) {
            $error_msg = 'All password fields are required.';
        } elseif ($new_pass !== $confirm) {
            $error_msg = 'New passwords do not match.';
        } elseif (strlen($new_pass) < 6) {
            $error_msg = 'Password must be at least 6 characters.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($current, $user['password'])) {
                    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed, $user_id]);
                    $success_msg = 'Password changed successfully!';
                } else {
                    $error_msg = 'Current password is incorrect.';
                }
            } catch (PDOException $e) {
                error_log("Password change error: " . $e->getMessage());
                $error_msg = 'Failed to change password.';
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT email, created_at, avatar_path, avatar_data FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Profile fetch error: " . $e->getMessage());
    $client_data = [];
    $user_data = [];
}
$avatar_path = $user_data['avatar_path'] ?? '';
$avatar_data = $user_data['avatar_data'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Blue Mogul Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <h1 class="text-2xl font-semibold text-gray-900">My Profile</h1>
                <p class="text-sm text-gray-600 mt-1">Manage your account information</p>
            </div>
        </header>

        <div class="p-6 max-w-3xl">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center gap-4">
                    <div class="relative group">
                        <?php if ($avatar_data): ?>
                            <img src="<?php echo htmlspecialchars($avatar_data); ?>" alt="Avatar" class="h-16 w-16 rounded-full object-cover border-2 border-blue-200" data-testid="img-avatar" id="avatar-preview">
                        <?php elseif ($avatar_path): ?>
                            <img src="/<?php echo htmlspecialchars($avatar_path); ?>" alt="Avatar" class="h-16 w-16 rounded-full object-cover border-2 border-blue-200" data-testid="img-avatar" id="avatar-preview">
                        <?php else: ?>
                            <div class="bg-blue-600 text-white rounded-full h-16 w-16 flex items-center justify-center font-bold text-2xl" id="avatar-initials">
                                <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <label for="avatar-input" class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 rounded-full flex items-center justify-center cursor-pointer transition" title="Change avatar">
                            <i class="fas fa-camera text-white opacity-0 group-hover:opacity-100 transition text-lg"></i>
                        </label>
                        <input type="file" id="avatar-input" accept=".jpg,.jpeg,.gif,.png" class="hidden" data-testid="input-avatar">
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($user_name); ?></h2>
                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($user_email); ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">Member since <?php echo ($user_data && !empty($user_data['created_at'])) ? date('M Y', strtotime($user_data['created_at'])) : 'N/A'; ?></p>
                        <p id="avatar-status" class="text-xs mt-1"></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Personal Information</h2>
                </div>
                <form method="POST" action="profile.php" class="p-6 space-y-4">
                            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($client_data['name'] ?? $user_name); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-name">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" value="<?php echo htmlspecialchars($user_email); ?>" disabled class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-gray-50 text-gray-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($client_data['phone'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-phone">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                            <input type="text" name="company" value="<?php echo htmlspecialchars($client_data['company'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-company">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($client_data['address'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-address">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($client_data['city'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-city">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">State</label>
                            <input type="text" name="state" value="<?php echo htmlspecialchars($client_data['state'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-state">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ZIP Code</label>
                            <input type="text" name="zip" value="<?php echo htmlspecialchars($client_data['zip'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-zip">
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-md font-medium text-sm transition" data-testid="button-save-profile">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Change Password</h2>
                </div>
                <form method="POST" action="profile.php" class="p-6 space-y-4">
                            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                        <input type="password" name="current_password" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-current-password">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                            <input type="password" name="new_password" required minlength="6" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-new-password">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                            <input type="password" name="confirm_password" required minlength="6" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-confirm-password">
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white px-5 py-2 rounded-md font-medium text-sm transition" data-testid="button-change-password">
                            <i class="fas fa-lock mr-2"></i>Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('avatar-input').addEventListener('change', async function() {
    if (!this.files || !this.files[0]) return;
    const status = document.getElementById('avatar-status');
    status.textContent = 'Uploading...';
    status.className = 'text-xs mt-1 text-blue-600';
    const fd = new FormData();
    fd.append('avatar', this.files[0]);
    try {
        const resp = await fetch('/api/upload/avatar', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            const existing = document.getElementById('avatar-preview');
            const initials = document.getElementById('avatar-initials');
            if (existing) {
                existing.src = data.path + '?t=' + Date.now();
            } else if (initials) {
                const img = document.createElement('img');
                img.src = data.path + '?t=' + Date.now();
                img.id = 'avatar-preview';
                img.className = 'h-16 w-16 rounded-full object-cover border-2 border-blue-200';
                img.alt = 'Avatar';
                initials.replaceWith(img);
            }
            status.textContent = '✓ Avatar updated!';
            status.className = 'text-xs mt-1 text-green-600 font-medium';
        } else {
            status.textContent = 'Error: ' + (data.error || 'Upload failed');
            status.className = 'text-xs mt-1 text-red-600';
        }
    } catch(e) {
        status.textContent = 'Network error. Please try again.';
        status.className = 'text-xs mt-1 text-red-600';
    }
});
</script>
</body>
</html>