<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$service_id = intval($_GET['id'] ?? 0);
if ($service_id <= 0) {
    portal_redirect('/portal/services.php');
}

$success_msg = '';
$error_msg = '';
$service = null;

$pdo = getDB();

try {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_id = $client ? $client['id'] : $user_id;
} catch (PDOException $e) {
    $client_id = $user_id;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    require_csrf();

    if ($_POST['action'] === 'cancel_service') {
        try {
            $stmt = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ? AND client_id = ? AND status = 'active'");
            $stmt->execute([$service_id, $client_id]);
            if ($stmt->rowCount() > 0) {
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'service_cancelled', 'subscription', $service_id, 'Cancelled subscription #' . $service_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

                try {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$user_id, 'Service Cancelled', 'Your service subscription has been cancelled.', 'service', 'subscription', $service_id]);
                } catch (PDOException $e) {}

                $success_msg = 'Service has been cancelled successfully.';
            } else {
                $error_msg = 'Unable to cancel this service. It may already be cancelled.';
            }
        } catch (PDOException $e) {
            error_log("Cancel service error: " . $e->getMessage());
            $error_msg = 'Failed to cancel service. Please try again.';
        }
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT s.*, p.name as product_name, p.price, p.category, p.description as product_description, 
               p.features, p.billing_period
        FROM subscriptions s
        JOIN products p ON s.product_id = p.id
        WHERE s.id = ? AND s.client_id = ?
    ");
    $stmt->execute([$service_id, $client_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        portal_redirect('/portal/services.php');
    }
} catch (PDOException $e) {
    error_log("Service detail error: " . $e->getMessage());
    portal_redirect('/portal/services.php');
}

$start_date = $service['start_date'] ? date('M d, Y', strtotime($service['start_date'])) : 'N/A';
$billing_period = $service['billing_period'] ?? 'monthly';

if ($service['start_date'] && $billing_period === 'monthly') {
    $start = new DateTime($service['start_date']);
    $now = new DateTime();
    $next_billing = clone $start;
    while ($next_billing <= $now) {
        $next_billing->modify('+1 month');
    }
    $next_billing_date = $next_billing->format('M d, Y');
} elseif ($service['start_date'] && $billing_period === 'yearly') {
    $start = new DateTime($service['start_date']);
    $now = new DateTime();
    $next_billing = clone $start;
    while ($next_billing <= $now) {
        $next_billing->modify('+1 year');
    }
    $next_billing_date = $next_billing->format('M d, Y');
} else {
    $next_billing_date = 'N/A';
}

$features = [];
if (!empty($service['features'])) {
    $decoded = json_decode($service['features'], true);
    if (is_array($decoded)) {
        $features = $decoded;
    } else {
        $features = array_filter(array_map('trim', explode(',', $service['features'])));
    }
}

$category_icon = match($service['category'] ?? '') {
    'managed_it' => 'fa-laptop-code',
    'voip' => 'fa-phone-volume',
    'cloud' => 'fa-cloud',
    'security' => 'fa-shield-alt',
    'network' => 'fa-network-wired',
    default => 'fa-server'
};

$status = $service['status'];
$is_active = ($status === 'active');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Details - Blue Mogul Client Portal</title>
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
                <div class="flex items-center gap-3 mb-1">
                    <a href="services.php" class="text-gray-400 hover:text-gray-600 transition" data-testid="link-back-services"><i class="fas fa-arrow-left"></i></a>
                    <h1 class="text-xl font-semibold text-gray-900" data-testid="text-service-name"><?php echo htmlspecialchars($service['product_name']); ?></h1>
                </div>
                <div class="flex items-center gap-3 ml-8 mt-1">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php
                        echo match($status) {
                            'active' => 'bg-green-100 text-green-700',
                            'cancelled' => 'bg-red-100 text-red-700',
                            'suspended' => 'bg-yellow-100 text-yellow-700',
                            default => 'bg-gray-100 text-gray-700'
                        };
                    ?>" data-testid="status-badge"><?php echo ucfirst($status); ?></span>
                    <span class="text-xs text-gray-500"><?php echo ucfirst(str_replace('_', ' ', $service['category'] ?? 'Service')); ?></span>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center gap-2" data-testid="alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center gap-2" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <div class="max-w-4xl">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white rounded-lg border border-gray-200">
                            <div class="px-6 py-4 border-b border-gray-100">
                                <h2 class="font-semibold text-gray-900">Subscription Details</h2>
                            </div>
                            <div class="px-6 py-5">
                                <div class="flex items-center gap-4 mb-6">
                                    <div class="w-12 h-12 rounded-lg <?php echo $is_active ? 'bg-blue-100' : 'bg-gray-100'; ?> flex items-center justify-center">
                                        <i class="fas <?php echo $category_icon; ?> <?php echo $is_active ? 'text-blue-600' : 'text-gray-400'; ?> text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900" data-testid="text-product-name"><?php echo htmlspecialchars($service['product_name']); ?></h3>
                                        <?php if ($service['product_description']): ?>
                                            <p class="text-sm text-gray-600 mt-0.5"><?php echo htmlspecialchars($service['product_description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-6">
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Price</p>
                                        <p class="text-2xl font-bold text-gray-900" data-testid="text-price">$<?php echo number_format($service['price'], 2); ?><span class="text-sm text-gray-500 font-normal">/<?php echo $billing_period === 'monthly' ? 'mo' : $billing_period; ?></span></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Status</p>
                                        <p class="text-sm font-medium" data-testid="text-status">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="w-2 h-2 rounded-full <?php echo $is_active ? 'bg-green-500' : ($status === 'cancelled' ? 'bg-red-500' : 'bg-yellow-500'); ?>"></span>
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Start Date</p>
                                        <p class="text-sm font-medium text-gray-900" data-testid="text-start-date"><?php echo $start_date; ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Next Billing</p>
                                        <p class="text-sm font-medium text-gray-900" data-testid="text-next-billing"><?php echo $is_active ? $next_billing_date : 'N/A'; ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Monthly Recurring Revenue</p>
                                        <p class="text-sm font-medium text-gray-900" data-testid="text-mrr">$<?php echo number_format($service['mrr'] ?? 0, 2); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Billing Period</p>
                                        <p class="text-sm font-medium text-gray-900" data-testid="text-billing-period"><?php echo ucfirst($billing_period); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($features)): ?>
                        <div class="bg-white rounded-lg border border-gray-200">
                            <div class="px-6 py-4 border-b border-gray-100">
                                <h2 class="font-semibold text-gray-900">Included Features</h2>
                            </div>
                            <div class="px-6 py-5">
                                <ul class="space-y-3">
                                    <?php foreach ($features as $feature): ?>
                                        <li class="flex items-center gap-3 text-sm text-gray-700" data-testid="text-feature">
                                            <i class="fas fa-check text-green-500 text-xs"></i>
                                            <?php echo htmlspecialchars($feature); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="space-y-6">
                        <div class="bg-white rounded-lg border border-gray-200">
                            <div class="px-6 py-4 border-b border-gray-100">
                                <h2 class="font-semibold text-gray-900">Actions</h2>
                            </div>
                            <div class="p-6 space-y-3">
                                <a href="tickets.php" class="flex items-center gap-3 w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium text-sm transition" data-testid="link-contact-support">
                                    <i class="fas fa-headset"></i>
                                    Contact Support
                                </a>
                                <a href="billing.php" class="flex items-center gap-3 w-full px-4 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md font-medium text-sm transition" data-testid="link-billing">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    View Billing
                                </a>
                                <?php if ($is_active): ?>
                                    <button onclick="confirmCancel()" class="flex items-center gap-3 w-full px-4 py-3 bg-white hover:bg-red-50 text-red-600 border border-red-200 rounded-md font-medium text-sm transition" data-testid="button-cancel-service">
                                        <i class="fas fa-times-circle"></i>
                                        Cancel Service
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-200">
                            <div class="px-6 py-4 border-b border-gray-100">
                                <h2 class="font-semibold text-gray-900">Need Help?</h2>
                            </div>
                            <div class="p-6">
                                <p class="text-sm text-gray-600 mb-4">Have questions about your service? Our support team is here to help.</p>
                                <div class="space-y-3 text-sm">
                                    <div class="flex items-center gap-3 text-gray-700">
                                        <i class="fas fa-envelope text-gray-400 w-4"></i>
                                        <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="text-blue-600 hover:text-blue-700" data-testid="link-support-email"><?php echo ADMIN_EMAIL; ?></a>
                                    </div>
                                    <div class="flex items-center gap-3 text-gray-700">
                                        <i class="fas fa-phone text-gray-400 w-4"></i>
                                        <span data-testid="text-support-phone"><?php echo SUPPORT_PHONE; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="cancelModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-50" onclick="closeCancel()"></div>
    <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 bg-red-100 rounded-full mb-4">
                <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">Cancel Service</h3>
            <p class="text-sm text-gray-600 mt-2">Are you sure you want to cancel <strong><?php echo htmlspecialchars($service['product_name']); ?></strong>? This action cannot be undone.</p>
        </div>
        <form method="POST" action="service-detail.php?id=<?php echo $service_id; ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_service">
            <div class="flex gap-3">
                <button type="button" onclick="closeCancel()" class="flex-1 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md font-medium text-sm transition" data-testid="button-cancel-dismiss">
                    Keep Service
                </button>
                <button type="submit" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md font-medium text-sm transition" data-testid="button-confirm-cancel">
                    Yes, Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmCancel() {
    document.getElementById('cancelModal').classList.remove('hidden');
}
function closeCancel() {
    document.getElementById('cancelModal').classList.add('hidden');
}
</script>
</body>
</html>