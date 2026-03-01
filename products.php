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

$category_filter = $_GET['category'] ?? 'all';

try {
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM products WHERE active = true ORDER BY category");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($category_filter !== 'all') {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE active = true AND category = ? ORDER BY tier NULLS LAST, price ASC");
        $stmt->execute([$category_filter]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE active = true ORDER BY category, tier NULLS LAST, price ASC");
        $stmt->execute();
    }
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($products as $p) {
        $grouped[$p['category']][] = $p;
    }

} catch (PDOException $e) {
    error_log("Products fetch error: " . $e->getMessage());
    $products = [];
    $categories = [];
    $grouped = [];
}

$category_icons = [
    'Managed IT' => 'fa-laptop-code',
    'VoIP' => 'fa-phone-volume',
    'Cloud Storage' => 'fa-cloud',
    'Internet' => 'fa-wifi',
    'Security' => 'fa-shield-alt',
    'Bundle' => 'fa-cubes',
    'Add-on' => 'fa-puzzle-piece',
    'Project' => 'fa-project-diagram',
    'Training' => 'fa-graduation-cap',
    'Professional Services' => 'fa-briefcase',
];

$category_colors = [
    'Managed IT' => ['bg-blue-50', 'text-blue-600', 'border-blue-200'],
    'VoIP' => ['bg-purple-50', 'text-purple-600', 'border-purple-200'],
    'Cloud Storage' => ['bg-cyan-50', 'text-cyan-600', 'border-cyan-200'],
    'Internet' => ['bg-green-50', 'text-green-600', 'border-green-200'],
    'Security' => ['bg-red-50', 'text-red-600', 'border-red-200'],
    'Bundle' => ['bg-indigo-50', 'text-indigo-600', 'border-indigo-200'],
    'Add-on' => ['bg-orange-50', 'text-orange-600', 'border-orange-200'],
    'Project' => ['bg-yellow-50', 'text-yellow-600', 'border-yellow-200'],
    'Training' => ['bg-teal-50', 'text-teal-600', 'border-teal-200'],
    'Professional Services' => ['bg-pink-50', 'text-pink-600', 'border-pink-200'],
];

function formatBillingPeriod($period) {
    return match($period) {
        'monthly' => '/mo',
        'per-gb-monthly' => '/GB/mo',
        'per-user-monthly' => '/user/mo',
        'per-hour' => '/hr',
        'per-visit' => '/visit',
        'per-session' => '/session',
        'per-day' => '/day',
        'one-time' => ' one-time',
        default => '/' . $period
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products & Services - Blue Mogul Client Portal</title>
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
                <h1 class="text-2xl font-semibold text-gray-900">Products & Services</h1>
                <p class="text-sm text-gray-600 mt-1">Browse our available products and service plans</p>
            </div>
        </header>

        <div class="p-6">
            <div class="flex flex-wrap items-center gap-2 mb-6">
                <a href="products.php" class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $category_filter === 'all' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition" data-testid="filter-all">All</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="products.php?category=<?php echo urlencode($cat); ?>" class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $category_filter === $cat ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition" data-testid="filter-<?php echo strtolower(str_replace(' ', '-', $cat)); ?>">
                        <?php echo htmlspecialchars($cat); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($grouped)): ?>
                <div class="bg-white rounded-lg border border-gray-200 text-center py-16">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                        <i class="fas fa-box text-gray-400 text-2xl"></i>
                    </div>
                    <p class="text-gray-900 font-semibold">No products found</p>
                    <p class="text-sm text-gray-500 mt-1">Check back later for available products</p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $category => $cat_products): ?>
                    <?php
                        $icon = $category_icons[$category] ?? 'fa-box';
                        $colors = $category_colors[$category] ?? ['bg-gray-50', 'text-gray-600', 'border-gray-200'];
                    ?>
                    <div class="mb-8">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg <?php echo $colors[0]; ?> flex items-center justify-center">
                                <i class="fas <?php echo $icon; ?> <?php echo $colors[1]; ?>"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($category); ?></h2>
                                <p class="text-xs text-gray-500"><?php echo count($cat_products); ?> product<?php echo count($cat_products) !== 1 ? 's' : ''; ?></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($cat_products as $product): ?>
                                <?php
                                    $features = $product['features'] ? json_decode($product['features'], true) : [];
                                    if (!is_array($features)) $features = [];
                                ?>
                                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden hover:border-gray-300 hover:shadow-sm transition" data-testid="product-card-<?php echo $product['id']; ?>">
                                    <div class="p-5">
                                        <div class="flex items-start justify-between mb-3">
                                            <div>
                                                <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($product['name']); ?></h3>
                                                <?php if ($product['tier']): ?>
                                                    <span class="text-xs text-gray-500"><?php echo htmlspecialchars($product['tier']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $colors[0]; ?> <?php echo $colors[1]; ?>">
                                                <?php echo htmlspecialchars($category); ?>
                                            </span>
                                        </div>

                                        <div class="flex items-baseline gap-1 mb-3">
                                            <span class="text-2xl font-bold text-gray-900">$<?php echo number_format($product['price'], 2); ?></span>
                                            <span class="text-sm text-gray-500"><?php echo formatBillingPeriod($product['billing_period']); ?></span>
                                        </div>

                                        <?php if ($product['description']): ?>
                                            <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars(substr($product['description'], 0, 120)); ?></p>
                                        <?php endif; ?>

                                        <?php if (!empty($features)): ?>
                                            <ul class="space-y-1.5 mb-4">
                                                <?php foreach (array_slice($features, 0, 4) as $feature): ?>
                                                    <li class="flex items-start gap-2 text-sm text-gray-600">
                                                        <i class="fas fa-check text-green-500 text-xs mt-1 flex-shrink-0"></i>
                                                        <span><?php echo htmlspecialchars(is_string($feature) ? $feature : ''); ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                                <?php if (count($features) > 4): ?>
                                                    <li class="text-xs text-gray-400 pl-5">+<?php echo count($features) - 4; ?> more features</li>
                                                <?php endif; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div class="px-5 py-3 bg-gray-50 border-t border-gray-100">
                                        <button onclick="requestService(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['name'])); ?>')" class="w-full text-center bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-md font-medium text-sm transition" data-testid="button-request-<?php echo $product['id']; ?>">
                                            Request Service
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="request-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Request Service</h2>
            <button onclick="document.getElementById('request-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6">
            <p class="text-gray-600 mb-2">You're requesting:</p>
            <p class="font-semibold text-gray-900 text-lg mb-4" id="request-product-name"></p>
            <p class="text-sm text-gray-500 mb-4">A support ticket will be created and our team will reach out to set up this service for you.</p>
            <div class="flex justify-end gap-3">
                <button onclick="document.getElementById('request-modal').classList.add('hidden')" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition">Cancel</button>
                <button onclick="submitRequest()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-confirm-request">Confirm Request</button>
            </div>
        </div>
    </div>
</div>

<script>
let selectedProductId = null;
let selectedProductName = '';

function requestService(id, name) {
    selectedProductId = id;
    selectedProductName = name;
    document.getElementById('request-product-name').textContent = name;
    document.getElementById('request-modal').classList.remove('hidden');
}

async function submitRequest() {
    try {
        const response = await fetch('/portal/tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'create_ticket',
                subject: 'Service Request: ' + selectedProductName,
                description: 'I would like to request the following service: ' + selectedProductName + '\n\nPlease reach out to set up this service for my account.',
                priority: 'medium'
            })
        });
        document.getElementById('request-modal').classList.add('hidden');
        alert('Service request submitted! A support ticket has been created.');
        window.location.href = 'tickets.php';
    } catch (err) {
        alert('Failed to submit request. Please try again.');
    }
}

document.getElementById('request-modal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
</body>
</html>