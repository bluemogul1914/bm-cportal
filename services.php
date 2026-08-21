<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$pdo = getDB();

try {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_id = $client ? $client['id'] : $user_id;

    $stmt = $pdo->prepare("
        SELECT s.*, p.name as product_name, p.price, p.category, p.description as product_description, p.features, p.billing_period
        FROM subscriptions s
        JOIN products p ON s.product_id = p.id
        WHERE s.client_id = ?
        ORDER BY s.status ASC, s.created_at DESC
    ");
    $stmt->execute([$client_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $monthly_total = 0;
    $active_count = 0;
    foreach ($services as $s) {
        if ($s['status'] === 'active') {
            $monthly_total += floatval($s['mrr']);
            $active_count++;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM vultr_instances WHERE client_id = ? ORDER BY label");
    $stmt->execute([$client_id]);
    $cloud_instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cloud_monthly_total = 0;
    foreach ($cloud_instances as $ci) {
        $cloud_monthly_total += floatval($ci['cost_per_month'] ?? 0);
    }

} catch (PDOException $e) {
    error_log("Services error: " . $e->getMessage());
    $services = [];
    $monthly_total = 0;
    $active_count = 0;
    $cloud_instances = [];
    $cloud_monthly_total = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - Blue Mogul Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
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
                <h1 class="text-2xl font-semibold text-gray-900">My Services</h1>
                <p class="text-sm text-gray-600 mt-1">Manage your active subscriptions and services</p>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="bg-green-100 rounded-lg p-3"><i class="fas fa-check-circle text-green-600 text-xl"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Active Services</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="text-active-count"><?php echo $active_count; ?></p>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="bg-blue-100 rounded-lg p-3"><i class="fas fa-dollar-sign text-blue-600 text-xl"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Monthly Cost</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="text-monthly-cost">$<?php echo number_format($monthly_total, 2); ?></p>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="bg-purple-100 rounded-lg p-3"><i class="fas fa-calendar-alt text-purple-600 text-xl"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Annual Cost</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="text-annual-cost">$<?php echo number_format($monthly_total * 12, 2); ?></p>
                </div>
            </div>

            <?php if (empty($services)): ?>
                <div class="bg-white rounded-lg border border-gray-200 text-center py-16">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                        <i class="fas fa-server text-gray-400 text-2xl"></i>
                    </div>
                    <p class="text-gray-900 font-semibold mb-1">No active services</p>
                    <p class="text-sm text-gray-500 mb-4">Browse our products to get started</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php foreach ($services as $service): ?>
                        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden" data-testid="service-card-<?php echo $service['id']; ?>">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg <?php echo $service['status'] === 'active' ? 'bg-blue-100' : 'bg-gray-100'; ?> flex items-center justify-center">
                                        <i class="fas <?php
                                            echo match($service['category'] ?? '') {
                                                'managed_it' => 'fa-laptop-code',
                                                'voip' => 'fa-phone-volume',
                                                'cloud' => 'fa-cloud',
                                                'security' => 'fa-shield-alt',
                                                'network' => 'fa-network-wired',
                                                default => 'fa-server'
                                            };
                                        ?> <?php echo $service['status'] === 'active' ? 'text-blue-600' : 'text-gray-400'; ?>"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($service['product_name']); ?></h3>
                                        <p class="text-xs text-gray-500"><?php echo ucfirst(str_replace('_', ' ', $service['category'] ?? 'Service')); ?></p>
                                    </div>
                                </div>
                                <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                    echo match($service['status']) {
                                        'active' => 'bg-green-100 text-green-700',
                                        'cancelled' => 'bg-red-100 text-red-700',
                                        'suspended' => 'bg-yellow-100 text-yellow-700',
                                        default => 'bg-gray-100 text-gray-700'
                                    };
                                ?>"><?php echo ucfirst($service['status']); ?></span>
                            </div>
                            <div class="px-6 py-4">
                                <div class="flex items-baseline gap-1 mb-4">
                                    <span class="text-3xl font-bold text-gray-900">$<?php echo number_format($service['price'], 2); ?></span>
                                    <span class="text-sm text-gray-500">/<?php echo $service['billing_period'] === 'monthly' ? 'mo' : $service['billing_period']; ?></span>
                                </div>
                                <?php if ($service['product_description']): ?>
                                    <p class="text-sm text-gray-600 mb-4"><?php echo htmlspecialchars(substr($service['product_description'], 0, 120)); ?></p>
                                <?php endif; ?>
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <p class="text-gray-500 text-xs">Start Date</p>
                                        <p class="font-medium text-gray-900"><?php echo date('M d, Y', strtotime($service['start_date'])); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-500 text-xs">MRR</p>
                                        <p class="font-medium text-gray-900">$<?php echo number_format($service['mrr'], 2); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($cloud_instances)): ?>
                <div class="mt-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Cloud Services</h2>
                            <p class="text-sm text-gray-500">Vultr cloud instances assigned to your account</p>
                        </div>
                        <div class="bg-white rounded-lg border border-gray-200 px-4 py-2">
                            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Cloud Monthly Cost</span>
                            <p class="text-lg font-bold text-gray-900" data-testid="text-cloud-monthly-cost">$<?php echo number_format($cloud_monthly_total, 2); ?></p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($cloud_instances as $instance): ?>
                            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden" data-testid="cloud-instance-card-<?php echo $instance['id']; ?>">
                                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
                                            <i class="fas fa-cloud text-indigo-600"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-gray-900" data-testid="text-instance-label-<?php echo $instance['id']; ?>"><?php echo htmlspecialchars($instance['label'] ?: 'Unnamed Instance'); ?></h3>
                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($instance['plan'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                    <span class="flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-medium <?php
                                        echo ($instance['power_status'] ?? '') === 'running' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700';
                                    ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?php echo ($instance['power_status'] ?? '') === 'running' ? 'bg-green-500' : 'bg-gray-400'; ?>"></span>
                                        <?php echo ucfirst($instance['power_status'] ?? 'unknown'); ?>
                                    </span>
                                </div>
                                <div class="px-6 py-4">
                                    <div class="flex items-baseline gap-1 mb-4">
                                        <span class="text-3xl font-bold text-gray-900">$<?php echo number_format(floatval($instance['cost_per_month'] ?? 0), 2); ?></span>
                                        <span class="text-sm text-gray-500">/mo</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <p class="text-gray-500 text-xs">IP Address</p>
                                            <p class="font-medium text-gray-900" data-testid="text-instance-ip-<?php echo $instance['id']; ?>"><?php echo htmlspecialchars($instance['main_ip'] ?? 'N/A'); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">OS</p>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($instance['os'] ?? 'N/A'); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">Specs</p>
                                            <p class="font-medium text-gray-900"><?php echo intval($instance['vcpu_count'] ?? 0); ?> vCPU / <?php echo htmlspecialchars($instance['ram'] ?? '0'); ?> MB RAM</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">Region</p>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($instance['region'] ?? 'N/A'); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">Storage</p>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($instance['disk'] ?? '0'); ?> GB</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">Bandwidth</p>
                                            <p class="font-medium text-gray-900">
                                                <?php
                                                    $used = floatval($instance['current_bandwidth'] ?? 0);
                                                    $allowed = floatval($instance['allowed_bandwidth'] ?? 0);
                                                    echo number_format($used, 1) . ' / ' . number_format($allowed, 0) . ' GB';
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php if ($allowed > 0): ?>
                                        <div class="mt-3">
                                            <div class="w-full bg-gray-200 rounded-full h-1.5">
                                                <div class="bg-indigo-500 h-1.5 rounded-full" style="width: <?php echo min(100, ($used / $allowed) * 100); ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>