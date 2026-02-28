<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /portal');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$last_login = $_SESSION['last_login'] ?? date('Y-m-d H:i:s');
$last_login_formatted = date('l \a\t g:i A', strtotime($last_login));

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE client_id = ? AND status != 'Closed'");
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

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE client_id = ? AND status != 'Closed' ORDER BY created_at DESC LIMIT 5");
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

} catch (PDOException $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $tickets_count = 0;
    $invoices_count = 0;
    $invoices_total = 0;
    $services_count = 0;
    $recent_tickets = [];
    $unpaid_invoices = [];
    $active_services = [];
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
    <link rel="stylesheet" href="/assets/css/dashboard.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1a56db',
                        secondary: '#0d1b3e'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">

    <?php include 'includes/header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                <i class="fas fa-tachometer-alt text-primary"></i>
                Dashboard
            </h1>
            <p class="text-sm text-gray-500">
                Logged in as <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($user_name); ?></span>
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-10">

            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-500 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-ticket-alt text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium mb-1">Open Tickets</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $tickets_count; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-purple-500 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-dollar-sign text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium mb-1">Unpaid Invoices</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $invoices_count; ?> <span class="text-base font-semibold text-gray-500">($<?php echo number_format($invoices_total, 2); ?>)</span></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-green-500 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-server text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium mb-1">Active Services</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $services_count; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-orange-400 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-clock text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium mb-1">Last Login</p>
                        <p class="text-sm font-semibold text-gray-900"><?php echo $last_login_formatted; ?></p>
                    </div>
                </div>
            </div>

        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-ticket-alt text-blue-500"></i>
                        Open Tickets
                    </h2>
                    <a href="tickets.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                        View All &rarr;
                    </a>
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

            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-file-invoice-dollar text-purple-500"></i>
                        Outstanding Invoices
                    </h2>
                    <a href="billing.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                        View All &rarr;
                    </a>
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
                                                Due <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
                                            </p>
                                        </div>
                                        <button onclick="payInvoice(<?php echo $invoice['id']; ?>)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-sm transition">
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

        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-server text-green-500"></i>
                    Active Services
                </h2>
                <a href="services.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                    View All &rarr;
                </a>
            </div>

            <div class="p-6">
                <?php if (empty($active_services)): ?>
                    <div class="text-center py-10">
                        <div class="inline-flex items-center justify-center w-14 h-14 bg-gray-100 rounded-full mb-3">
                            <i class="fas fa-shopping-cart text-gray-400 text-2xl"></i>
                        </div>
                        <p class="text-gray-900 font-semibold mb-3">No active services</p>
                        <a href="products.php" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition">
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
                                <a href="service-detail.php?id=<?php echo $service['id']; ?>" class="block text-center bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium text-sm transition">
                                    Manage
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 mt-6">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-external-link-alt text-gray-400"></i>
                    Quick Actions
                </h2>
            </div>
            <div class="divide-y divide-gray-100">
                <a href="<?php echo defined('ITFLOW_URL') ? ITFLOW_URL : '#'; ?>" target="_blank" class="flex items-center gap-4 px-6 py-4 hover:bg-gray-50 transition group">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-100 transition">
                        <i class="fas fa-external-link-alt text-blue-600"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 text-sm">Open ITFlow</p>
                        <p class="text-xs text-gray-500">Access full ITFlow management system</p>
                    </div>
                </a>
                <a href="<?php echo defined('UISP_URL') ? UISP_URL : '#'; ?>" target="_blank" class="flex items-center gap-4 px-6 py-4 hover:bg-gray-50 transition group">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-100 transition">
                        <i class="fas fa-external-link-alt text-blue-600"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 text-sm">Open UISP</p>
                        <p class="text-xs text-gray-500">Access UISP network management</p>
                    </div>
                </a>
                <a href="settings.php" class="flex items-center gap-4 px-6 py-4 hover:bg-gray-50 transition group">
                    <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 group-hover:bg-gray-200 transition">
                        <i class="fas fa-cog text-gray-600"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900 text-sm">Portal Settings</p>
                        <p class="text-xs text-gray-500">Configure API connections</p>
                    </div>
                </a>
            </div>
        </div>

    </div>

    <script src="/assets/js/dashboard.js"></script>

</body>
</html>