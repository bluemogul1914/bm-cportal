<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'create_product') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $tier = trim($_POST['tier'] ?? '') ?: null;
        $price = floatval($_POST['price'] ?? 0);
        $billing_period = $_POST['billing_period'] ?? 'monthly';
        $description = trim($_POST['description'] ?? '') ?: null;
        $features_raw = trim($_POST['features'] ?? '');
        $features = $features_raw ? json_encode(array_map('trim', explode("\n", $features_raw))) : null;

        if ($name && $category && $price >= 0) {
            $stmt = $pdo->prepare("INSERT INTO products (name, category, tier, price, billing_period, description, features, active) VALUES (?, ?, ?, ?, ?, ?, ?, true)");
            $stmt->execute([$name, $category, $tier, $price, $billing_period, $description, $features]);
            portal_redirect('admin-products.php?success=created');
        }
    } elseif ($action === 'update_product') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $tier = trim($_POST['tier'] ?? '') ?: null;
        $price = floatval($_POST['price'] ?? 0);
        $billing_period = $_POST['billing_period'] ?? 'monthly';
        $description = trim($_POST['description'] ?? '') ?: null;
        $features_raw = trim($_POST['features'] ?? '');
        $features = $features_raw ? json_encode(array_map('trim', explode("\n", $features_raw))) : null;
        $active = isset($_POST['active']) ? true : false;

        if ($id && $name && $category) {
            $stmt = $pdo->prepare("UPDATE products SET name=?, category=?, tier=?, price=?, billing_period=?, description=?, features=?, active=? WHERE id=?");
            $stmt->execute([$name, $category, $tier, $price, $billing_period, $description, $features, $active, $id]);
            portal_redirect('admin-products.php?success=updated');
        }
    } elseif ($action === 'toggle_active') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE products SET active = NOT active WHERE id = ?");
            $stmt->execute([$id]);
            portal_redirect('admin-products.php?success=toggled');
        }
    }
}

$category_filter = $_GET['category'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM products ORDER BY category");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $where_clauses = [];
    $params = [];

    if ($category_filter !== 'all') {
        $where_clauses[] = "category = ?";
        $params[] = $category_filter;
    }
    if ($search) {
        $where_clauses[] = "(name ILIKE ? OR description ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";
    $stmt = $pdo->prepare("SELECT * FROM products $where_sql ORDER BY category, tier NULLS LAST, price ASC");
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_products = count($products);
    $active_count = count(array_filter($products, fn($p) => $p['active']));
    $category_count = count(array_unique(array_column($products, 'category')));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
    $stmt->execute();
    $active_subscriptions = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Admin products error: " . $e->getMessage());
    $products = [];
    $categories = [];
    $total_products = 0;
    $active_count = 0;
    $category_count = 0;
    $active_subscriptions = 0;
}

$success = $_GET['success'] ?? '';
$edit_id = intval($_GET['edit'] ?? 0);
$edit_product = null;
if ($edit_id) {
    foreach ($products as $p) {
        if ($p['id'] == $edit_id) {
            $edit_product = $p;
            break;
        }
    }
    if (!$edit_product) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
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
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Products</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage your product catalog and service offerings</p>
                </div>
                <button onclick="document.getElementById('add-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2" data-testid="button-add-product">
                    <i class="fas fa-plus"></i> Add Product
                </button>
            </div>
        </header>

        <?php if ($success): ?>
            <div class="mx-6 mt-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo match($success) {
                    'created' => 'Product created successfully.',
                    'updated' => 'Product updated successfully.',
                    'toggled' => 'Product status updated.',
                    default => 'Operation completed.'
                }; ?>
            </div>
        <?php endif; ?>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-blue-100 rounded-lg p-2.5"><i class="fas fa-box text-blue-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total Products</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-products"><?php echo $total_products; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-green-100 rounded-lg p-2.5"><i class="fas fa-check-circle text-green-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Active Products</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-active-products"><?php echo $active_count; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-purple-100 rounded-lg p-2.5"><i class="fas fa-tags text-purple-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Categories</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-categories"><?php echo $category_count; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-orange-100 rounded-lg p-2.5"><i class="fas fa-link text-orange-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Active Subscriptions</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-subscriptions"><?php echo $active_subscriptions; ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center gap-3">
                    <form method="GET" class="flex-1 min-w-[200px]">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search products..." class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-search">
                            <?php if ($category_filter !== 'all'): ?>
                                <input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="flex flex-wrap gap-2">
                        <a href="admin-products.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>" class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $category_filter === 'all' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition">All</a>
                        <?php foreach ($categories as $cat): ?>
                            <a href="admin-products.php?category=<?php echo urlencode($cat); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $category_filter === $cat ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition">
                                <?php echo htmlspecialchars($cat); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Product</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tier</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Price</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Billing</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                        <i class="fas fa-box text-gray-300 text-3xl mb-3 block"></i>
                                        No products found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="row-product-<?php echo $product['id']; ?>">
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($product['name']); ?></div>
                                            <?php if ($product['description']): ?>
                                                <div class="text-xs text-gray-500 mt-0.5 truncate max-w-xs"><?php echo htmlspecialchars(substr($product['description'], 0, 60)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-medium"><?php echo htmlspecialchars($product['category']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo $product['tier'] ? htmlspecialchars($product['tier']) : '—'; ?></td>
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900">$<?php echo number_format($product['price'], 2); ?></td>
                                        <td class="px-6 py-4 text-xs text-gray-500"><?php echo htmlspecialchars($product['billing_period']); ?></td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $product['active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                <?php echo $product['active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <a href="admin-products.php?edit=<?php echo $product['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm" data-testid="button-edit-<?php echo $product['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                                                    <button type="submit" class="text-gray-400 hover:text-gray-600 text-sm" title="<?php echo $product['active'] ? 'Deactivate' : 'Activate'; ?>" data-testid="button-toggle-<?php echo $product['id']; ?>">
                                                        <i class="fas <?php echo $product['active'] ? 'fa-toggle-on text-green-500' : 'fa-toggle-off'; ?>"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="add-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
            <h2 class="text-lg font-semibold text-gray-900">Add New Product</h2>
            <button onclick="document.getElementById('add-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
                            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_product">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-name">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category *</label>
                    <select name="category" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-category">
                        <option value="">Select...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom">+ New Category</option>
                    </select>
                    <input type="text" id="custom-category" class="hidden mt-2 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="Enter new category">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tier</label>
                    <input type="text" name="tier" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. Tier 1" data-testid="input-tier">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price *</label>
                    <input type="number" name="price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-price">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Billing Period</label>
                    <select name="billing_period" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-billing">
                        <option value="monthly">Monthly</option>
                        <option value="one-time">One-Time</option>
                        <option value="per-hour">Per Hour</option>
                        <option value="per-visit">Per Visit</option>
                        <option value="per-session">Per Session</option>
                        <option value="per-day">Per Day</option>
                        <option value="per-gb-monthly">Per GB/Monthly</option>
                        <option value="per-user-monthly">Per User/Monthly</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-description"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Features (one per line)</label>
                <textarea name="features" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Feature 1&#10;Feature 2&#10;Feature 3" data-testid="input-features"></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('add-modal').classList.add('hidden')" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-submit-product">Create Product</button>
            </div>
        </form>
    </div>
</div>

<?php if ($edit_product): ?>
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
            <h2 class="text-lg font-semibold text-gray-900">Edit Product</h2>
            <a href="admin-products.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></a>
        </div>
        <form method="POST" class="p-6 space-y-4">
                            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="id" value="<?php echo $edit_product['id']; ?>">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                <input type="text" name="name" required value="<?php echo htmlspecialchars($edit_product['name']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category *</label>
                    <select name="category" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $edit_product['category'] === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tier</label>
                    <input type="text" name="tier" value="<?php echo htmlspecialchars($edit_product['tier'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price *</label>
                    <input type="number" name="price" step="0.01" min="0" required value="<?php echo $edit_product['price']; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Billing Period</label>
                    <select name="billing_period" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <?php
                        $periods = ['monthly','one-time','per-hour','per-visit','per-session','per-day','per-gb-monthly','per-user-monthly'];
                        foreach ($periods as $period):
                        ?>
                            <option value="<?php echo $period; ?>" <?php echo ($edit_product['billing_period'] ?? '') === $period ? 'selected' : ''; ?>><?php echo ucwords(str_replace('-', ' ', $period)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($edit_product['description'] ?? ''); ?></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Features (one per line)</label>
                <?php
                    $edit_features = $edit_product['features'] ? json_decode($edit_product['features'], true) : [];
                    $features_text = is_array($edit_features) ? implode("\n", $edit_features) : '';
                ?>
                <textarea name="features" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($features_text); ?></textarea>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="active" id="edit-active" <?php echo $edit_product['active'] ? 'checked' : ''; ?> class="rounded border-gray-300">
                <label for="edit-active" class="text-sm text-gray-700">Active</label>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <a href="admin-products.php" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition">Cancel</a>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelector('select[name="category"]')?.addEventListener('change', function() {
    const custom = document.getElementById('custom-category');
    if (this.value === '__custom') {
        custom.classList.remove('hidden');
        custom.focus();
        custom.addEventListener('input', () => {
            this.dataset.customValue = custom.value;
        });
    } else {
        custom.classList.add('hidden');
    }
});

document.querySelector('#add-modal form')?.addEventListener('submit', function(e) {
    const sel = this.querySelector('select[name="category"]');
    if (sel.value === '__custom') {
        const custom = document.getElementById('custom-category');
        if (custom.value.trim()) {
            const opt = new Option(custom.value.trim(), custom.value.trim(), true, true);
            sel.add(opt, sel.length - 1);
        } else {
            e.preventDefault();
            alert('Please enter a category name');
        }
    }
});

document.getElementById('add-modal')?.addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
</body>
</html>