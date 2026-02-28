<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /portal');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';

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

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <div class="mb-6">
            <h1 class="text-2xl font-semibold text-gray-900 mb-1">
                Welcome back, <?php echo htmlspecialchars($user_name); ?>!
            </h1>
            <p class="text-sm text-gray-600">
                <i class="far fa-clock mr-1"></i>Last login: <?php echo $last_login_formatted; ?>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">

            <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-blue-100 rounded-lg p-3">
                                <i class="fas fa-ticket-alt text-blue-600 text-xl"></i>
                            </div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Open Tickets</p>
                        </div>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $tickets_count; ?></p>
                        <p class="text-sm text-gray-600 mt-1">Active</p>
                    </div>
                </div>
                <a href="tickets.php" class="block mt-4 text-sm text-blue-600 hover:text-blue-700 font-medium">
                    View All &rarr;
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition">
                <div class="flex items-center justify-between">
                    <div class="w-full">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-yellow-100 rounded-lg p-3">
                                <i class="fas fa-file-invoice-dollar text-yellow-600 text-xl"></i>
                            </div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Unpaid Invoices</p>
                        </div>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $invoices_count; ?></p>
                        <p class="text-sm text-gray-900 font-semibold mt-1">
                            ($<?php echo number_format($invoices_total, 2); ?>)
                        </p>
                    </div>
                </div>
                <a href="billing.php" class="block mt-4 text-sm text-blue-600 hover:text-blue-700 font-medium">
                    View All &rarr;
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="bg-green-100 rounded-lg p-3">
                                <i class="fas fa-server text-green-600 text-xl"></i>
                            </div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Services</p>
                        </div>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $services_count; ?></p>
                        <p class="text-sm text-gray-600 mt-1">Active</p>
                    </div>
                </div>
                <a href="services.php" class="block mt-4 text-sm text-blue-600 hover:text-blue-700 font-medium">
                    View All &rarr;
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-ticket-alt text-blue-600 mr-2"></i>
                            Open Tickets
                        </h2>
                        <a href="tickets.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                            View All &rarr;
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    <?php if (empty($recent_tickets)): ?>
                        <div class="text-center py-12">
                            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                                <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                            </div>
                            <p class="text-gray-900 font-medium">No open tickets</p>
                            <p class="text-sm text-gray-600 mt-1">All caught up!</p>
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
                                            <p class="text-sm text-gray-600 mb-2">
                                                <?php echo htmlspecialchars(substr($ticket['description'] ?? '', 0, 80)); ?>...
                                            </p>
                                            <div class="flex items-center gap-3 text-xs">
                                                <span class="text-gray-500">
                                                    <i class="far fa-clock mr-1"></i>
                                                    <?php echo date('M d, Y', strtotime($ticket['created_at'])); ?>
                                                </span>
                                                <span class="px-2 py-1 rounded-full font-medium <?php 
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
                                        <a href="ticket-detail.php?id=<?php echo $ticket['id']; ?>" class="ml-3 text-blue-600 hover:text-blue-700">
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
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-file-invoice-dollar text-yellow-600 mr-2"></i>
                            Outstanding Invoices
                        </h2>
                        <a href="billing.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                            View All &rarr;
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    <?php if (empty($unpaid_invoices)): ?>
                        <div class="text-center py-12">
                            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                                <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                            </div>
                            <p class="text-gray-900 font-medium">No outstanding invoices</p>
                            <p class="text-sm text-gray-600 mt-1">You're all paid up!</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($unpaid_invoices as $invoice): ?>
                                <div class="border border-gray-200 rounded-lg p-4 hover:border-gray-300 hover:shadow-sm transition">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <span class="font-semibold text-gray-900">
                                                    Invoice #<?php echo str_pad($invoice['id'], 4, '0', STR_PAD_LEFT); ?>
                                                </span>
                                                <span class="text-xl font-bold text-gray-900">
                                                    $<?php echo number_format($invoice['amount'], 2); ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-600">
                                                <i class="far fa-calendar mr-1"></i>
                                                Due <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
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
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-server text-green-600 mr-2"></i>
                        Active Services
                    </h2>
                    <a href="services.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                        View All &rarr;
                    </a>
                </div>
            </div>

            <div class="p-6">
                <?php if (empty($active_services)): ?>
                    <div class="text-center py-12">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                            <i class="fas fa-shopping-cart text-gray-400 text-2xl"></i>
                        </div>
                        <p class="text-gray-900 font-medium mb-3">No active services</p>
                        <a href="products.php" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition">
                            Browse Services
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($active_services as $service): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:border-gray-300 hover:shadow-sm transition">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="bg-blue-100 rounded-lg p-2">
                                        <i class="fas fa-server text-blue-600"></i>
                                    </div>
                                    <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                        Active
                                    </span>
                                </div>
                                <h3 class="font-semibold text-gray-900 mb-2 text-sm">
                                    <?php echo htmlspecialchars($service['product_name']); ?>
                                </h3>
                                <p class="text-2xl font-bold text-gray-900 mb-3">
                                    $<?php echo number_format($service['price'], 2); ?><span class="text-sm text-gray-600 font-normal">/mo</span>
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

    </div>

    <script src="/assets/js/dashboard.js"></script>

</body>
</html>