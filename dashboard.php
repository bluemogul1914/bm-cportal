<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$last_login = $_SESSION['last_login'] ?? date('Y-m-d H:i:s');
$last_login_formatted = date('l \a\t g:i A', strtotime($last_login));

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE client_id = ? AND status != 'closed'");
    $stmt->execute([$user_id]);
    $tickets_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM invoices WHERE client_id = ? AND status = 'unpaid'");
    $stmt->execute([$user_id]);
    $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $invoices_count = $invoice_data['count'];
    $invoices_total = $invoice_data['total'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM subscriptions WHERE client_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $services_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $voip_count = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM client_voip_accounts WHERE client_id = ? AND status = 'active'");
        $stmt->execute([$user_id]);
        $voip_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (PDOException $e) {
        // table may not exist
    }
    $services_count += $voip_count;

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE client_id = ? AND status != 'closed' ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE client_id = ? AND status = 'unpaid' ORDER BY due_date ASC LIMIT 5");
    $stmt->execute([$user_id]);
    $unpaid_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT s.*, p.name as product_name, p.price, p.category
        FROM subscriptions s 
        JOIN products p ON s.product_id = p.id 
        WHERE s.client_id = ? AND s.status = 'active' 
        ORDER BY s.created_at DESC 
        LIMIT 6
    ");
    $stmt->execute([$user_id]);
    $active_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = false ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notif_count = count($notifications);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'mark_notifications_read') {
    require_csrf();
    
        $pdo->prepare("UPDATE notifications SET is_read = true WHERE user_id = ?")->execute([$user_id]);
        portal_redirect('dashboard.php');
    }

} catch (PDOException $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $tickets_count = 0;
    $invoices_count = 0;
    $invoices_total = 0;
    $services_count = 0;
    $recent_tickets = [];
    $unpaid_invoices = [];
    $active_services = [];
    $notifications = [];
    $notif_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Blue Mogul Client Portal</title>

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

        <?php include 'includes/client-sidebar.php'; ?>

        <div class="flex-1 overflow-y-auto">

            <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>
                            <p class="text-sm text-gray-600 mt-1">Welcome back, <?php echo htmlspecialchars($user_name); ?></p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="relative">
                                <button onclick="toggleNotifications()" class="relative p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-md transition" data-testid="button-notifications">
                                    <i class="fas fa-bell text-lg"></i>
                                    <?php if ($notif_count > 0): ?>
                                        <span class="absolute top-1 right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center font-semibold"><?php echo $notif_count; ?></span>
                                    <?php endif; ?>
                                </button>
                                <div id="notifications-dropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                                    <div class="p-3 border-b border-gray-200 flex items-center justify-between">
                                        <h3 class="font-semibold text-gray-900 text-sm">Notifications</h3>
                                        <?php if ($notif_count > 0): ?>
                                            <form method="POST" class="inline">
                            <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_notifications_read">
                                                <button type="submit" class="text-xs text-blue-600 hover:text-blue-800">Mark all read</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <div class="max-h-80 overflow-y-auto">
                                        <?php if (empty($notifications)): ?>
                                            <div class="p-6 text-center text-gray-500 text-sm">
                                                <i class="fas fa-bell-slash text-gray-300 text-xl mb-2 block"></i>
                                                No new notifications
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($notifications as $notif): ?>
                                                <?php
                                                    $type_config = match($notif['type'] ?? 'info') {
                                                        'success' => ['bg-green-100', 'fa-check-circle text-green-600'],
                                                        'warning' => ['bg-yellow-100', 'fa-exclamation-triangle text-yellow-600'],
                                                        'error' => ['bg-red-100', 'fa-times-circle text-red-600'],
                                                        'ticket' => ['bg-blue-100', 'fa-ticket-alt text-blue-600'],
                                                        'invoice' => ['bg-yellow-100', 'fa-file-invoice-dollar text-yellow-600'],
                                                        'service' => ['bg-purple-100', 'fa-server text-purple-600'],
                                                        default => ['bg-blue-100', 'fa-info-circle text-blue-600'],
                                                    };
                                                    $notif_link = '#';
                                                    if ($notif['entity_type'] === 'ticket' && $notif['entity_id']) $notif_link = 'ticket-detail.php?id=' . $notif['entity_id'];
                                                    elseif ($notif['entity_type'] === 'invoice' && $notif['entity_id']) $notif_link = 'billing.php';
                                                ?>
                                                <a href="<?php echo $notif_link; ?>" class="block p-3 hover:bg-gray-50 border-b border-gray-100 transition">
                                                    <div class="flex items-start space-x-3">
                                                        <div class="flex-shrink-0 <?php echo $type_config[0]; ?> rounded p-1.5">
                                                            <i class="fas <?php echo $type_config[1]; ?> text-sm"></i>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($notif['title']); ?></p>
                                                            <p class="text-xs text-gray-600 mt-0.5"><?php echo htmlspecialchars($notif['message']); ?></p>
                                                            <p class="text-xs text-gray-400 mt-1"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></p>
                                                        </div>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="relative">
                                <button onclick="toggleProfile()" class="flex items-center space-x-2 text-gray-700 hover:bg-gray-100 rounded-md px-3 py-2 transition">
                                    <div class="bg-blue-600 text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm">
                                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                                    </div>
                                    <span class="text-sm font-medium"><?php echo htmlspecialchars($user_name); ?></span>
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </button>
                                <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-20">
                                    <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <i class="fas fa-user w-4 mr-2"></i>My Profile
                                    </a>
                                    <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <i class="fas fa-cog w-4 mr-2"></i>Settings
                                    </a>
                                    <a href="billing.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <i class="fas fa-credit-card w-4 mr-2"></i>Billing
                                    </a>
                                    <?php if ($is_admin): ?>
                                        <div class="border-t border-gray-200"></div>
                                        <a href="admin-dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                            <i class="fas fa-shield-alt w-4 mr-2"></i>Admin Panel
                                        </a>
                                    <?php endif; ?>
                                    <div class="border-t border-gray-200"></div>
                                    <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-50">
                                        <i class="fas fa-sign-out-alt w-4 mr-2"></i>Sign Out
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-blue-100 rounded-lg p-3">
                                <i class="fas fa-ticket-alt text-blue-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Open Tickets</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $tickets_count; ?></p>
                        <a href="tickets.php" class="text-sm text-blue-600 hover:text-blue-700 mt-2 inline-block">View All &rarr;</a>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-purple-100 rounded-lg p-3">
                                <i class="fas fa-file-invoice-dollar text-purple-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Unpaid Invoices</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $invoices_count; ?></p>
                        <p class="text-sm text-gray-900 font-semibold mt-2">$<?php echo number_format($invoices_total, 2); ?></p>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-green-100 rounded-lg p-3">
                                <i class="fas fa-server text-green-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Active Services</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $services_count; ?></p>
                        <a href="services.php" class="text-sm text-blue-600 hover:text-blue-700 mt-2 inline-block">Manage &rarr;</a>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-orange-100 rounded-lg p-3">
                                <i class="fas fa-clock text-orange-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Last Login</p>
                        <p class="text-sm font-semibold text-gray-900 mt-2"><?php echo $last_login_formatted; ?></p>
                    </div>

                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Open Tickets</h2>
                            <a href="tickets.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All &rarr;</a>
                        </div>
                        <div class="p-6">
                            <?php if (empty($recent_tickets)): ?>
                                <div class="text-center py-10">
                                    <div class="inline-flex items-center justify-center w-14 h-14 bg-green-100 rounded-full mb-3">
                                        <i class="fas fa-check-circle text-green-500 text-2xl"></i>
                                    </div>
                                    <p class="text-gray-900 font-semibold">No open tickets</p>
                                    <p class="text-sm text-gray-500 mt-1">All caught up!</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($recent_tickets as $ticket): ?>
                                        <div class="border border-gray-200 rounded-lg p-4 hover:border-gray-300 hover:shadow-sm transition">
                                            <div class="flex items-start justify-between">
                                                <div class="flex-1">
                                                    <h3 class="font-medium text-gray-900 mb-1">
                                                        <?php echo htmlspecialchars($ticket['subject']); ?>
                                                    </h3>
                                                    <p class="text-sm text-gray-500 mb-2">
                                                        <?php echo htmlspecialchars(substr($ticket['description'] ?? '', 0, 80)); ?>...
                                                    </p>
                                                    <div class="flex items-center gap-3 text-xs">
                                                        <span class="text-gray-400">
                                                            <i class="far fa-clock mr-1"></i>
                                                            <?php echo date('M d, Y', strtotime($ticket['created_at'])); ?>
                                                        </span>
                                                        <span class="px-2 py-0.5 rounded-full font-medium <?php
                                                            echo match($ticket['priority'] ?? 'medium') {
                                                                'high' => 'bg-red-100 text-red-700',
                                                                'medium' => 'bg-yellow-100 text-yellow-700',
                                                                'low' => 'bg-green-100 text-green-700',
                                                                default => 'bg-gray-100 text-gray-700'
                                                            };
                                                        ?>">
                                                            <?php echo ucfirst($ticket['priority'] ?? 'Medium'); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <a href="ticket-detail.php?id=<?php echo $ticket['id']; ?>" class="ml-3 text-blue-500 hover:text-blue-700">
                                                    <i class="fas fa-arrow-right"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Outstanding Invoices</h2>
                            <a href="billing.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All &rarr;</a>
                        </div>
                        <div class="p-6">
                            <?php if (empty($unpaid_invoices)): ?>
                                <div class="text-center py-10">
                                    <div class="inline-flex items-center justify-center w-14 h-14 bg-green-100 rounded-full mb-3">
                                        <i class="fas fa-check-circle text-green-500 text-2xl"></i>
                                    </div>
                                    <p class="text-gray-900 font-semibold">No outstanding invoices</p>
                                    <p class="text-sm text-gray-500 mt-1">You're all paid up!</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($unpaid_invoices as $invoice): ?>
                                        <div class="border border-gray-200 rounded-lg p-4 hover:border-gray-300 hover:shadow-sm transition">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-3 mb-1">
                                                        <span class="font-semibold text-gray-900">
                                                            Invoice #<?php echo str_pad($invoice['id'], 4, '0', STR_PAD_LEFT); ?>
                                                        </span>
                                                        <span class="text-lg font-bold text-gray-900">
                                                            $<?php echo number_format($invoice['amount'], 2); ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm text-gray-500">
                                                        <i class="far fa-calendar mr-1"></i>
                                                        Due <?php echo $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : 'N/A'; ?>
                                                    </p>
                                                </div>
                                                <button onclick="payInvoice(<?php echo $invoice['id']; ?>)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition">
                                                    Pay Now
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">Active Services</h2>
                        <a href="services.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All &rarr;</a>
                    </div>
                    <div class="p-6">
                        <?php if (empty($active_services)): ?>
                            <div class="text-center py-10">
                                <div class="inline-flex items-center justify-center w-14 h-14 bg-gray-100 rounded-full mb-3">
                                    <i class="fas fa-shopping-cart text-gray-400 text-2xl"></i>
                                </div>
                                <p class="text-gray-900 font-semibold mb-3">No active services</p>
                                <a href="products.php" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-md font-medium text-sm transition">
                                    Browse Services
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($active_services as $service): ?>
                                    <div class="border border-gray-200 rounded-lg p-4 hover:border-gray-300 hover:shadow-sm transition">
                                        <div class="flex items-start justify-between mb-3">
                                            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-server text-blue-600"></i>
                                            </div>
                                            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                                Active
                                            </span>
                                        </div>
                                        <h3 class="font-semibold text-gray-900 mb-2 text-sm">
                                            <?php echo htmlspecialchars($service['product_name']); ?>
                                        </h3>
                                        <p class="text-2xl font-bold text-gray-900 mb-3">
                                            $<?php echo number_format($service['price'], 2); ?><span class="text-sm text-gray-500 font-normal">/mo</span>
                                        </p>
                                        <a href="service-detail.php?id=<?php echo $service['id']; ?>" class="block text-center bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md font-medium text-sm transition">
                                            Manage
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mt-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Quick Actions</h2>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <a href="tickets.php" class="flex items-center gap-4 px-6 py-4 hover:bg-gray-50 transition group">
                            <div class="w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center flex-shrink-0 group-hover:bg-orange-100 transition">
                                <i class="fas fa-ticket-alt text-orange-600"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 text-sm">Open Tickets</p>
                                <p class="text-xs text-gray-500">View and manage your support tickets</p>
                            </div>
                        </a>
                        <a href="products.php" class="flex items-center gap-4 px-6 py-4 hover:bg-gray-50 transition group">
                            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-100 transition">
                                <i class="fas fa-shopping-cart text-blue-600"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 text-sm">Order Products</p>
                                <p class="text-xs text-gray-500">Browse and order available products</p>
                            </div>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        function toggleNotifications() {
            document.getElementById('profile-dropdown')?.classList.add('hidden');
            document.getElementById('notifications-dropdown')?.classList.toggle('hidden');
        }

        function toggleProfile() {
            document.getElementById('notifications-dropdown')?.classList.add('hidden');
            document.getElementById('profile-dropdown')?.classList.toggle('hidden');
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('[onclick*="toggleNotifications"]') && !event.target.closest('#notifications-dropdown')) {
                document.getElementById('notifications-dropdown')?.classList.add('hidden');
            }
            if (!event.target.closest('[onclick*="toggleProfile"]') && !event.target.closest('#profile-dropdown')) {
                document.getElementById('profile-dropdown')?.classList.add('hidden');
            }
        });

        function payInvoice(id) {
            alert('Payment processing for invoice #' + id);
        }
    </script>

</body>
</html>