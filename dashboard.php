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

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM clients");
    $clients_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

} catch (PDOException $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $tickets_count = 0;
    $invoices_count = 0;
    $invoices_total = 0;
    $services_count = 0;
    $clients_count = 0;
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
                        <i class="fas fa-users text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium mb-1">ITFlow Clients</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $clients_count; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-green-500 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-wifi text-white text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium mb-1">UISP Subscribers</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $services_count; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-orange-400 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-comment-dots text-white text-lg"></i>
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
                        <p class="text-3xl font-bold text-gray-900"><?php echo $invoices_count; ?> <span class="text-lg font-semibold text-gray-600">($<?php echo number_format($invoices_total, 2); ?>)</span></p>
                    </div>
                </div>
            </div>

        </div>

        <div class="bg-white rounded-xl border border-gray-200">
            <div class="p-6 border-b border-gray-100">
                <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-external-link-alt text-gray-400"></i>
                    Quick Actions
                </h2>
            </div>

            <div class="divide-y divide-gray-100">
                <a href="<?php echo ITFLOW_URL; ?>" target="_blank" class="flex items-center gap-4 px-6 py-5 hover:bg-gray-50 transition group">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-100 transition">
                        <i class="fas fa-external-link-alt text-blue-600"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900">Open ITFlow</p>
                        <p class="text-sm text-gray-500">Access full ITFlow management system</p>
                    </div>
                </a>

                <a href="<?php echo UISP_URL; ?>" target="_blank" class="flex items-center gap-4 px-6 py-5 hover:bg-gray-50 transition group">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-100 transition">
                        <i class="fas fa-external-link-alt text-blue-600"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900">Open UISP</p>
                        <p class="text-sm text-gray-500">Access UISP network management</p>
                    </div>
                </a>

                <a href="settings.php" class="flex items-center gap-4 px-6 py-5 hover:bg-gray-50 transition group">
                    <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 group-hover:bg-gray-200 transition">
                        <i class="fas fa-cog text-gray-600"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900">Portal Settings</p>
                        <p class="text-sm text-gray-500">Configure API connections</p>
                    </div>
                </a>
            </div>
        </div>

    </div>

    <script src="/assets/js/dashboard.js"></script>

</body>
</html>