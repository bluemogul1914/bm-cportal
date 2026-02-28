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

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE client_id = ? AND status = 'unpaid'");
    $stmt->execute([$user_id]);
    $invoices_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM subscriptions WHERE client_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $services_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE client_id = ? AND status != 'Closed' ORDER BY created_at DESC LIMIT 3");
    $stmt->execute([$user_id]);
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE client_id = ? AND status = 'unpaid' ORDER BY due_date ASC LIMIT 3");
    $stmt->execute([$user_id]);
    $unpaid_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT s.*, p.name as product_name, p.price 
        FROM subscriptions s 
        JOIN products p ON s.product_id = p.id 
        WHERE s.client_id = ? AND s.status = 'active' 
        ORDER BY s.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $active_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $tickets_count = 0;
    $invoices_count = 0;
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1a56db',
                        secondary: '#0d1b3e',
                        accent: '#3b82f6'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen font-sans">

    <?php include 'includes/header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <div class="mb-8 animate-fade-in">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                Welcome back, <?php echo htmlspecialchars($user_name); ?>! 👋
            </h1>
            <p class="text-gray-600">
                <i class="far fa-clock mr-2"></i>Last login: <?php echo $last_login_formatted; ?>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition duration-300 animate-slide-up" style="animation-delay: 0.1s;">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-blue-100 rounded-lg p-3">
                        <i class="fas fa-ticket-alt text-blue-600 text-2xl"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-500">TICKETS</span>
                </div>
                <div class="text-center">
                    <h3 class="text-4xl font-bold text-gray-900 mb-1"><?php echo $tickets_count; ?></h3>
                    <p class="text-gray-600">Active</p>
                </div>
                <a href="tickets.php" class="block mt-4 text-center text-blue-600 hover:text-blue-700 font-medium text-sm">
                    View All <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition duration-300 animate-slide-up" style="animation-delay: 0.2s;">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-yellow-100 rounded-lg p-3">
                        <i class="fas fa-file-invoice-dollar text-yellow-600 text-2xl"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-500">INVOICES</span>
                </div>
                <div class="text-center">
                    <h3 class="text-4xl font-bold text-gray-900 mb-1"><?php echo $invoices_count; ?></h3>
                    <p class="text-gray-600">Unpaid</p>
                </div>
                <a href="billing.php" class="block mt-4 text-center text-blue-600 hover:text-blue-700 font-medium text-sm">
                    View All <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 transform hover:scale-105 transition duration-300 animate-slide-up" style="animation-delay: 0.3s;">
                <div class="flex items-center justify-between mb-4">
                    <div class="bg-green-100 rounded-lg p-3">
                        <i class="fas fa-server text-green-600 text-2xl"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-500">SERVICES</span>
                </div>
                <div class="text-center">
                    <h3 class="text-4xl font-bold text-gray-900 mb-1"><?php echo $services_count; ?></h3>
                    <p class="text-gray-600">Active</p>
                </div>
                <a href="services.php" class="block mt-4 text-center text-blue-600 hover:text-blue-700 font-medium text-sm">
                    View All <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="bg-white rounded-xl shadow-lg p-6 animate-slide-up" style="animation-delay: 0.4s;">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-900">
                        <i class="fas fa-ticket-alt text-blue-600 mr-2"></i>Open Tickets
                    </h2>
                    <a href="tickets.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (empty($recent_tickets)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-check-circle text-5xl mb-3 text-green-500"></i>
                        <p>No open tickets</p>
                        <p class="text-sm mt-2">All caught up!</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recent_tickets as $ticket): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition duration-200">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <h3 class="font-semibold text-gray-900 mb-1">
                                            <?php echo htmlspecialchars($ticket['subject']); ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 mb-2">
                                            <?php echo htmlspecialchars(substr($ticket['description'] ?? '', 0, 100)); ?>...
                                        </p>
                                        <div class="flex items-center gap-3 text-xs text-gray-500">
                                            <span>
                                                <i class="far fa-clock mr-1"></i>
                                                <?php echo date('M d, Y', strtotime($ticket['created_at'])); ?>
                                            </span>
                                            <span class="px-2 py-1 rounded-full <?php 
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
                                    <a href="ticket-detail.php?id=<?php echo $ticket['id']; ?>" class="ml-4 text-blue-600 hover:text-blue-700">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 animate-slide-up" style="animation-delay: 0.5s;">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-900">
                        <i class="fas fa-file-invoice-dollar text-yellow-600 mr-2"></i>Outstanding Invoices
                    </h2>
                    <a href="billing.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (empty($unpaid_invoices)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-check-circle text-5xl mb-3 text-green-500"></i>
                        <p>No outstanding invoices</p>
                        <p class="text-sm mt-2">You're all paid up!</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($unpaid_invoices as $invoice): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition duration-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <span class="font-semibold text-gray-900">
                                                Invoice #<?php echo str_pad($invoice['id'], 4, '0', STR_PAD_LEFT); ?>
                                            </span>
                                            <span class="text-2xl font-bold text-gray-900">
                                                $<?php echo number_format($invoice['amount'], 2); ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600">
                                            <i class="far fa-calendar mr-1"></i>
                                            Due <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
                                        </p>
                                    </div>
                                    <button onclick="payInvoice(<?php echo $invoice['id']; ?>)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-sm transition duration-200">
                                        Pay Now
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <div class="mt-6 bg-white rounded-xl shadow-lg p-6 animate-slide-up" style="animation-delay: 0.6s;">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold text-gray-900">
                    <i class="fas fa-server text-green-600 mr-2"></i>Active Services
                </h2>
                <a href="services.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                    View All <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>

            <?php if (empty($active_services)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-shopping-cart text-5xl mb-3"></i>
                    <p>No active services</p>
                    <a href="products.php" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                        Browse Services
                    </a>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($active_services as $service): ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition duration-200">
                            <div class="flex items-start justify-between mb-3">
                                <div class="bg-blue-100 rounded-lg p-2">
                                    <i class="fas fa-server text-blue-600"></i>
                                </div>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                    Active
                                </span>
                            </div>
                            <h3 class="font-semibold text-gray-900 mb-2">
                                <?php echo htmlspecialchars($service['product_name']); ?>
                            </h3>
                            <p class="text-2xl font-bold text-blue-600 mb-3">
                                $<?php echo number_format($service['price'], 2); ?><span class="text-sm text-gray-600">/mo</span>
                            </p>
                            <a href="service-detail.php?id=<?php echo $service['id']; ?>" class="block text-center bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium text-sm transition duration-200">
                                Manage <i class="fas fa-cog ml-1"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4 animate-slide-up" style="animation-delay: 0.7s;">
            <a href="tickets.php?action=new" class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition duration-300 text-center group">
                <div class="bg-blue-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-blue-600 transition duration-300">
                    <i class="fas fa-plus text-blue-600 text-2xl group-hover:text-white transition duration-300"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-2">Open New Ticket</h3>
                <p class="text-sm text-gray-600">Need help? Submit a support ticket</p>
            </a>

            <a href="products.php" class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition duration-300 text-center group">
                <div class="bg-green-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-green-600 transition duration-300">
                    <i class="fas fa-shopping-cart text-green-600 text-2xl group-hover:text-white transition duration-300"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-2">Browse Services</h3>
                <p class="text-sm text-gray-600">Explore our product catalog</p>
            </a>

            <a href="profile.php" class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition duration-300 text-center group">
                <div class="bg-purple-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-purple-600 transition duration-300">
                    <i class="fas fa-user-cog text-purple-600 text-2xl group-hover:text-white transition duration-300"></i>
                </div>
                <h3 class="font-bold text-gray-900 mb-2">Account Settings</h3>
                <p class="text-sm text-gray-600">Update your profile and preferences</p>
            </a>
        </div>

    </div>

    <script src="assets/js/dashboard.js"></script>

</body>
</html>