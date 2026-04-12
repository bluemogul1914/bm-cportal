<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_role = $_SESSION['user_role'] ?? 'admin';
$pdo = getDB();
$success_msg = '';
$error_msg = '';

$staff_roles = [
    'super-admin' => [
        'label' => 'Super Admin',
        'color' => 'red',
        'icon' => 'fa-crown',
        'description' => 'Full unrestricted access to all portal features, user management, and system configuration.',
        'permissions' => ['dashboard', 'clients', 'tickets', 'invoices', 'billing', 'services', 'products', 'documents', 'network', 'knowledge', 'ai_agents', 'automation', 'integrations', 'reports', 'settings', 'audit', 'roles', 'user_management']
    ],
    'admin' => [
        'label' => 'Admin',
        'color' => 'purple',
        'icon' => 'fa-shield-alt',
        'description' => 'Full access to admin panel features. Cannot manage roles or system-level settings.',
        'permissions' => ['dashboard', 'clients', 'tickets', 'invoices', 'billing', 'services', 'products', 'documents', 'network', 'knowledge', 'ai_agents', 'automation', 'integrations', 'reports', 'settings', 'audit']
    ],
    'sales' => [
        'label' => 'Sales',
        'color' => 'green',
        'icon' => 'fa-handshake',
        'description' => 'Access to clients, products, services, invoices, and reports. Focused on revenue generation.',
        'permissions' => ['dashboard', 'clients', 'invoices', 'billing', 'services', 'products', 'reports']
    ],
    'it-support' => [
        'label' => 'IT Support',
        'color' => 'blue',
        'icon' => 'fa-headset',
        'description' => 'Access to tickets, network documentation, knowledge base, and client details for support tasks.',
        'permissions' => ['dashboard', 'clients', 'tickets', 'documents', 'network', 'knowledge']
    ],
    'billing' => [
        'label' => 'Billing',
        'color' => 'yellow',
        'icon' => 'fa-file-invoice-dollar',
        'description' => 'Access to invoices, payments, billing, and financial reports only.',
        'permissions' => ['dashboard', 'clients', 'invoices', 'billing', 'services', 'reports']
    ],
    'wholesaler' => [
        'label' => 'Wholesaler',
        'color' => 'indigo',
        'icon' => 'fa-warehouse',
        'description' => 'Wholesale partner access. Can view products, pricing, bulk orders, and partner reports.',
        'permissions' => ['dashboard', 'products', 'services', 'invoices', 'billing', 'reports']
    ],
    'dealer' => [
        'label' => 'Dealer',
        'color' => 'teal',
        'icon' => 'fa-store',
        'description' => 'Authorized dealer access. Can manage their own clients, orders, and commissions.',
        'permissions' => ['dashboard', 'clients', 'products', 'services', 'invoices', 'billing']
    ],
];

$roles = $staff_roles;

$all_permissions = [
    'dashboard' => 'Admin Dashboard',
    'clients' => 'Client Management',
    'tickets' => 'Ticket Management',
    'invoices' => 'Invoice Management',
    'billing' => 'Billing & Payments',
    'services' => 'Service Management',
    'products' => 'Product Catalog',
    'documents' => 'Document Management',
    'network' => 'Network Documentation',
    'knowledge' => 'Knowledge Base',
    'ai_agents' => 'AI Agent Army',
    'automation' => 'Automation Tools',
    'integrations' => 'Integrations',
    'reports' => 'Reports & Analytics',
    'settings' => 'System Settings',
    'audit' => 'Audit Trail',
    'roles' => 'Role Management',
    'user_management' => 'User Management',
    'client_dashboard' => 'Client Dashboard',
    'client_tickets' => 'Client Tickets',
    'client_billing' => 'Client Billing',
    'client_services' => 'Client Services',
    'client_documents' => 'Client Documents',
    'client_profile' => 'Client Profile'
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_role') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_role = $_POST['role'] ?? '';
        
        if ($target_user_id && array_key_exists($new_role, $staff_roles)) {
            if ($target_user_id === $_SESSION['user_id']) {
                $error_msg = 'You cannot change your own role.';
            } else {
                $is_admin_role = in_array($new_role, ['super-admin', 'admin', 'sales', 'it-support', 'billing', 'wholesaler', 'dealer']);
                $stmt = $pdo->prepare("UPDATE users SET role = ?, is_admin = ? WHERE id = ?");
                $stmt->execute([$new_role, $is_admin_role ? true : false, $target_user_id]);
                $success_msg = 'User role updated successfully.';
                
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'role_changed', 'user', $target_user_id, "Changed role to: {$new_role}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            }
        } else {
            $error_msg = 'Invalid user or role selected.';
        }
    }
    
    if ($action === 'update_status') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        
        if ($target_user_id && in_array($new_status, ['active', 'inactive'])) {
            if ($target_user_id === $_SESSION['user_id']) {
                $error_msg = 'You cannot deactivate your own account.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $target_user_id]);
                $success_msg = "User account " . ($new_status === 'active' ? 'activated' : 'deactivated') . " successfully.";
                
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'status_changed', 'user', $target_user_id, "Changed status to: {$new_status}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            }
        }
    }

    if ($action === 'create_user') {
        $new_name = trim($_POST['new_name'] ?? '');
        $new_email = trim($_POST['new_email'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $new_role_val = $_POST['new_role'] ?? 'user';

        if (empty($new_name) || empty($new_email) || empty($new_password)) {
            $error_msg = 'Name, email, and password are required.';
        } elseif (strlen($new_password) < 6) {
            $error_msg = 'Password must be at least 6 characters.';
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$new_email]);
            if ($check->fetch()) {
                $error_msg = 'A user with that email already exists.';
            } else {
                $is_admin_role = in_array($new_role_val, ['super-admin', 'admin', 'sales', 'it-support', 'billing', 'wholesaler', 'dealer']);
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, is_admin, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                $stmt->execute([$new_name, $new_email, $hashed, $new_role_val, $is_admin_role ? true : false]);
                $success_msg = "User '{$new_name}' created successfully with role '{$new_role_val}'.";

                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], 'user_created', 'user', $pdo->lastInsertId(), "Created user: {$new_name} ({$new_role_val})", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            }
        }
    }
}

$staff_role_keys = array_keys($staff_roles);
$placeholders = implode(',', array_fill(0, count($staff_role_keys), '?'));
$stmt = $pdo->prepare("SELECT id, name, email, role, status, is_admin, last_login, created_at FROM users WHERE role IN ({$placeholders}) OR is_admin = TRUE ORDER BY CASE role WHEN 'super-admin' THEN 1 WHEN 'admin' THEN 2 WHEN 'sales' THEN 3 WHEN 'it-support' THEN 4 WHEN 'billing' THEN 5 WHEN 'wholesaler' THEN 6 WHEN 'dealer' THEN 7 ELSE 8 END, name");
$stmt->execute($staff_role_keys);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$role_counts = [];
foreach ($users as $u) {
    $r = $u['role'] ?? 'user';
    $role_counts[$r] = ($role_counts[$r] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles & Access Control - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#1a56db', secondary: '#0d1b3e' },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>

    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900" data-testid="page-title">Roles & Access Control</h1>
                        <p class="text-sm text-gray-600 mt-1">Manage staff, wholesaler, and dealer roles and permissions. Client accounts are managed under <a href="admin-clients.php" class="text-blue-600 hover:underline">Clients</a>.</p>
                    </div>
                    <button onclick="document.getElementById('create-user-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-create-user">
                        <i class="fas fa-user-plus mr-2"></i>Create User
                    </button>
                </div>
            </div>
        </header>

        <div class="p-6">

            <?php if ($success_msg): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-3"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-3"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4 mb-8">
                <?php foreach ($roles as $role_key => $role_info): ?>
                    <?php
                    $count = $role_counts[$role_key] ?? 0;
                    $colors = [
                        'red' => 'bg-red-100 text-red-700 border-red-200',
                        'purple' => 'bg-purple-100 text-purple-700 border-purple-200',
                        'green' => 'bg-green-100 text-green-700 border-green-200',
                        'blue' => 'bg-blue-100 text-blue-700 border-blue-200',
                        'yellow' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                        'gray' => 'bg-gray-100 text-gray-700 border-gray-200',
                        'indigo' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                        'teal' => 'bg-teal-100 text-teal-700 border-teal-200',
                    ];
                    $colorClass = $colors[$role_info['color']] ?? $colors['gray'];
                    ?>
                    <div class="bg-white rounded-lg border border-gray-200 p-4" data-testid="card-role-<?php echo $role_key; ?>">
                        <div class="flex items-center space-x-2 mb-2">
                            <div class="rounded-lg p-2 <?php echo $colorClass; ?>">
                                <i class="fas <?php echo $role_info['icon']; ?> text-sm"></i>
                            </div>
                            <span class="font-semibold text-sm text-gray-900"><?php echo $role_info['label']; ?></span>
                        </div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $count; ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?php echo $count === 1 ? 'user' : 'users'; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-8">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Role Permissions Matrix</h2>
                    <p class="text-sm text-gray-600 mt-1">Overview of what each role can access</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-permissions-matrix">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Permission</th>
                                <?php foreach ($roles as $role_key => $role_info): ?>
                                    <th class="px-3 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider"><?php echo $role_info['label']; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($all_permissions as $perm_key => $perm_label): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 text-sm text-gray-700 font-medium"><?php echo $perm_label; ?></td>
                                    <?php foreach ($roles as $role_key => $role_info): ?>
                                        <td class="px-3 py-2.5 text-center">
                                            <?php if (in_array($perm_key, $role_info['permissions'])): ?>
                                                <i class="fas fa-check-circle text-green-500" data-testid="perm-<?php echo $role_key; ?>-<?php echo $perm_key; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-minus-circle text-gray-300"></i>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Staff & Partner Accounts</h2>
                            <p class="text-sm text-gray-600 mt-1"><?php echo count($users); ?> staff/partner accounts (client accounts managed under <a href="admin-clients.php" class="text-blue-600 hover:underline">Clients</a>)</p>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full" data-testid="table-users">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Current Role</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last Login</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Change Role</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($users as $u): ?>
                                <?php
                                $r = $u['role'] ?? 'user';
                                $unknown_role = ['label' => ucfirst($r), 'color' => 'gray', 'icon' => 'fa-question-circle', 'description' => 'Unknown role', 'permissions' => []];
                                $role_def = $roles[$r] ?? $unknown_role;
                                $badge_colors = [
                                    'red' => 'bg-red-100 text-red-700',
                                    'purple' => 'bg-purple-100 text-purple-700',
                                    'green' => 'bg-green-100 text-green-700',
                                    'blue' => 'bg-blue-100 text-blue-700',
                                    'yellow' => 'bg-yellow-100 text-yellow-700',
                                    'gray' => 'bg-gray-100 text-gray-700',
                                    'indigo' => 'bg-indigo-100 text-indigo-700',
                                    'teal' => 'bg-teal-100 text-teal-700',
                                ];
                                $badge = $badge_colors[$role_def['color']] ?? 'bg-gray-100 text-gray-700';
                                $is_self = ($u['id'] == $_SESSION['user_id']);
                                ?>
                                <tr class="hover:bg-gray-50" data-testid="row-user-<?php echo $u['id']; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="bg-blue-100 rounded-full h-9 w-9 flex items-center justify-center font-semibold text-sm text-blue-600 mr-3 flex-shrink-0">
                                                <?php echo strtoupper(substr($u['name'] ?? 'U', 0, 1)); ?>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900 text-sm" data-testid="text-user-name-<?php echo $u['id']; ?>">
                                                    <?php echo htmlspecialchars($u['name'] ?? 'Unknown'); ?>
                                                    <?php if ($is_self): ?><span class="text-xs text-blue-600 ml-1">(you)</span><?php endif; ?>
                                                </p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($u['email']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badge; ?>" data-testid="badge-role-<?php echo $u['id']; ?>">
                                            <i class="fas <?php echo $role_def['icon']; ?> mr-1.5 text-xs"></i>
                                            <?php echo $role_def['label']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (($u['status'] ?? 'active') === 'active'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700" data-testid="badge-status-<?php echo $u['id']; ?>">
                                                <span class="h-1.5 w-1.5 bg-green-500 rounded-full mr-1.5"></span>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700" data-testid="badge-status-<?php echo $u['id']; ?>">
                                                <span class="h-1.5 w-1.5 bg-red-500 rounded-full mr-1.5"></span>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo $u['last_login'] ? date('M d, Y g:ia', strtotime($u['last_login'])) : 'Never'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <form method="POST" class="flex items-center space-x-2" data-testid="form-change-role-<?php echo $u['id']; ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <select name="role" class="text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:ring-blue-500 focus:border-blue-500" data-testid="select-role-<?php echo $u['id']; ?>" <?php echo $is_self ? 'disabled' : ''; ?>>
                                                <?php foreach ($roles as $rk => $rv): ?>
                                                    <option value="<?php echo $rk; ?>" <?php echo ($r === $rk) ? 'selected' : ''; ?>><?php echo $rv['label']; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-xs font-medium transition <?php echo $is_self ? 'opacity-50 cursor-not-allowed' : ''; ?>" <?php echo $is_self ? 'disabled' : ''; ?> data-testid="button-save-role-<?php echo $u['id']; ?>">
                                                Save
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <?php if (!$is_self): ?>
                                            <?php if (($u['status'] ?? 'active') === 'active'): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Deactivate this user? They will no longer be able to log in.')">
                            <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <input type="hidden" name="status" value="inactive">
                                                    <button type="submit" class="text-red-600 hover:text-red-700 text-sm font-medium" data-testid="button-deactivate-<?php echo $u['id']; ?>">
                                                        <i class="fas fa-ban mr-1"></i>Deactivate
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="inline">
                            <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <input type="hidden" name="status" value="active">
                                                    <button type="submit" class="text-green-600 hover:text-green-700 text-sm font-medium" data-testid="button-activate-<?php echo $u['id']; ?>">
                                                        <i class="fas fa-check mr-1"></i>Activate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">Current user</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="create-user-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4" data-testid="modal-create-user">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Create New User</h3>
                <button onclick="document.getElementById('create-user-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <form method="POST" class="p-6 space-y-4" data-testid="form-create-user">
                            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_user">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                <input type="text" name="new_name" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="John Smith" data-testid="input-new-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="new_email" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="john@bluemogul.biz" data-testid="input-new-email">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="new_password" required minlength="6" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Minimum 6 characters" data-testid="input-new-password">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select name="new_role" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500" data-testid="select-new-role">
                    <?php foreach ($roles as $rk => $rv): ?>
                        <option value="<?php echo $rk; ?>" <?php echo $rk === 'user' ? 'selected' : ''; ?>><?php echo $rv['label']; ?> — <?php echo $rv['description']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end space-x-3 pt-2">
                <button type="button" onclick="document.getElementById('create-user-modal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-submit-create-user">Create User</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
