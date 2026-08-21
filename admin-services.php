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

    if ($action === 'create_subscription') {
        $client_id = intval($_POST['client_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);
        $start_date = $_POST['start_date'] ?? date('Y-m-d');

        if ($client_id && $product_id) {
            $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            $mrr = $product ? $product['price'] : 0;

            $stmt = $pdo->prepare("INSERT INTO subscriptions (client_id, product_id, status, start_date, mrr, created_at) VALUES (?, ?, 'active', ?, ?, NOW())");
            $stmt->execute([$client_id, $product_id, $start_date, $mrr]);
            portal_redirect('admin-services.php?success=created');
        }
    } elseif ($action === 'update_status') {
        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['active', 'suspended', 'cancelled'])) {
            $cancel_date = ($status === 'cancelled') ? date('Y-m-d') : null;
            $stmt = $pdo->prepare("UPDATE subscriptions SET status = ?, cancel_date = ? WHERE id = ?");
            $stmt->execute([$status, $cancel_date, $id]);
            portal_redirect('admin-services.php?success=updated');
        }
    }
}

$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $where_clauses = [];
    $params = [];

    if ($status_filter !== 'all') {
        $where_clauses[] = "s.status = ?";
        $params[] = $status_filter;
    }
    if ($search) {
        $where_clauses[] = "(c.company ILIKE ? OR p.name ILIKE ? OR c.name ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $stmt = $pdo->prepare("
        SELECT s.*, p.name as product_name, p.category, p.price as product_price, p.billing_period,
               c.name as client_name, c.company as client_company, c.email as client_email
        FROM subscriptions s
        JOIN products p ON s.product_id = p.id
        JOIN clients c ON s.client_id = c.id
        $where_sql
        ORDER BY s.status ASC, s.created_at DESC
    ");
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_subs = count($subscriptions);
    $active_subs = 0;
    $total_mrr = 0;
    foreach ($subscriptions as $sub) {
        if ($sub['status'] === 'active') {
            $active_subs++;
            $total_mrr += floatval($sub['mrr']);
        }
    }

    $stmt = $pdo->prepare("SELECT id, name, company FROM clients ORDER BY company, name");
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id, name, category, price FROM products WHERE active = true ORDER BY category, name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cloud_instances = $pdo->query("
        SELECT vi.*, c.name as client_name, c.company as client_company
        FROM vultr_instances vi
        LEFT JOIN clients c ON vi.client_id = c.id
        WHERE vi.client_id IS NOT NULL
        ORDER BY c.company, c.name, vi.label
    ")->fetchAll(PDO::FETCH_ASSOC);

    $cloud_total_cost = 0;
    $cloud_by_client = [];
    foreach ($cloud_instances as $ci) {
        $cloud_total_cost += floatval($ci['cost_per_month'] ?? 0);
        $ckey = $ci['client_id'];
        if (!isset($cloud_by_client[$ckey])) {
            $cloud_by_client[$ckey] = [
                'client_name' => $ci['client_company'] ?: $ci['client_name'],
                'client_id' => $ci['client_id'],
                'instances' => [],
                'total_cost' => 0,
            ];
        }
        $cloud_by_client[$ckey]['instances'][] = $ci;
        $cloud_by_client[$ckey]['total_cost'] += floatval($ci['cost_per_month'] ?? 0);
    }

} catch (PDOException $e) {
    error_log("Admin services error: " . $e->getMessage());
    $subscriptions = [];
    $clients = [];
    $products = [];
    $total_subs = 0;
    $active_subs = 0;
    $total_mrr = 0;
    $cloud_instances = [];
    $cloud_total_cost = 0;
    $cloud_by_client = [];
}

$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - Blue Mogul Admin</title>
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
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Services & Subscriptions</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage client subscriptions and service assignments</p>
                </div>
                <button onclick="document.getElementById('add-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2" data-testid="button-add-subscription">
                    <i class="fas fa-plus"></i> Add Subscription
                </button>
            </div>
        </header>

        <?php if ($success): ?>
            <div class="mx-6 mt-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo match($success) {
                    'created' => 'Subscription created successfully.',
                    'updated' => 'Subscription status updated.',
                    default => 'Operation completed.'
                }; ?>
            </div>
        <?php endif; ?>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-green-100 rounded-lg p-2.5"><i class="fas fa-check-circle text-green-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Active Subscriptions</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-active-subs"><?php echo $active_subs; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-blue-100 rounded-lg p-2.5"><i class="fas fa-dollar-sign text-blue-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total MRR</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-mrr">$<?php echo number_format($total_mrr, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-purple-100 rounded-lg p-2.5"><i class="fas fa-calendar-alt text-purple-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Annual Revenue</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-arr">$<?php echo number_format($total_mrr * 12, 2); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center gap-3">
                    <form method="GET" class="flex-1 min-w-[200px]">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by client or product..." class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-search">
                            <?php if ($status_filter !== 'all'): ?>
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="flex gap-2">
                        <?php
                        $statuses = ['all' => 'All', 'active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
                        foreach ($statuses as $key => $label):
                        ?>
                            <a href="admin-services.php?status=<?php echo $key; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $status_filter === $key ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?> transition">
                                <?php echo $label; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Product</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">MRR</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Start Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($subscriptions)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                        <i class="fas fa-server text-gray-300 text-3xl mb-3 block"></i>
                                        No subscriptions found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($subscriptions as $sub): ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="row-sub-<?php echo $sub['id']; ?>">
                                        <td class="px-6 py-4">
                                            <a href="admin-client-detail.php?id=<?php echo $sub['client_id']; ?>" class="hover:text-blue-600">
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($sub['client_company'] ?: $sub['client_name']); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($sub['client_email']); ?></div>
                                            </a>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($sub['product_name']); ?></div>
                                            <div class="text-xs text-gray-500">$<?php echo number_format($sub['product_price'], 2); ?>/<?php echo $sub['billing_period']; ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-medium"><?php echo htmlspecialchars($sub['category']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-sm font-medium text-gray-900">$<?php echo number_format($sub['mrr'], 2); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo date('M d, Y', strtotime($sub['start_date'])); ?></td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                                echo match($sub['status']) {
                                                    'active' => 'bg-green-100 text-green-700',
                                                    'suspended' => 'bg-yellow-100 text-yellow-700',
                                                    'cancelled' => 'bg-red-100 text-red-700',
                                                    default => 'bg-gray-100 text-gray-700'
                                                };
                                            ?>"><?php echo ucfirst($sub['status']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex items-center justify-end gap-1">
                                                <?php if ($sub['status'] === 'active'): ?>
                                                    <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                                        <input type="hidden" name="status" value="suspended">
                                                        <button type="submit" class="px-2 py-1 text-xs bg-yellow-100 text-yellow-700 hover:bg-yellow-200 rounded transition" data-testid="button-suspend-<?php echo $sub['id']; ?>">Suspend</button>
                                                    </form>
                                                    <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                                        <input type="hidden" name="status" value="cancelled">
                                                        <button type="submit" class="px-2 py-1 text-xs bg-red-100 text-red-700 hover:bg-red-200 rounded transition" data-testid="button-cancel-<?php echo $sub['id']; ?>">Cancel</button>
                                                    </form>
                                                <?php elseif ($sub['status'] === 'suspended'): ?>
                                                    <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                                        <input type="hidden" name="status" value="active">
                                                        <button type="submit" class="px-2 py-1 text-xs bg-green-100 text-green-700 hover:bg-green-200 rounded transition" data-testid="button-activate-<?php echo $sub['id']; ?>">Activate</button>
                                                    </form>
                                                <?php elseif ($sub['status'] === 'cancelled'): ?>
                                                    <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                                        <input type="hidden" name="status" value="active">
                                                        <button type="submit" class="px-2 py-1 text-xs bg-green-100 text-green-700 hover:bg-green-200 rounded transition" data-testid="button-reactivate-<?php echo $sub['id']; ?>">Reactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-8">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Cloud Services</h2>
                        <p class="text-sm text-gray-500">Vultr cloud instances assigned to clients</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Cloud Instances</span>
                            <p class="text-lg font-bold text-gray-900" data-testid="text-cloud-instance-count"><?php echo count($cloud_instances); ?></p>
                        </div>
                        <div class="text-right">
                            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Cloud MRR</span>
                            <p class="text-lg font-bold text-gray-900" data-testid="text-cloud-mrr">$<?php echo number_format($cloud_total_cost, 2); ?></p>
                        </div>
                        <a href="admin-vultr.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2" data-testid="link-manage-vultr">
                            <i class="fas fa-cloud"></i> Manage Vultr
                        </a>
                    </div>
                </div>

                <?php if (empty($cloud_by_client)): ?>
                    <div class="bg-white rounded-lg border border-gray-200 text-center py-12">
                        <i class="fas fa-cloud text-gray-300 text-3xl mb-3 block"></i>
                        <p class="text-gray-900 font-semibold mb-1">No cloud instances assigned</p>
                        <p class="text-sm text-gray-500">Assign Vultr instances to clients from the <a href="admin-vultr.php" class="text-blue-600 hover:underline">Vultr Cloud</a> page.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($cloud_by_client as $client_group): ?>
                        <div class="bg-white rounded-lg border border-gray-200 mb-4" data-testid="cloud-client-group-<?php echo $client_group['client_id']; ?>">
                            <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between gap-2 bg-gray-50 rounded-t-lg">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center">
                                        <i class="fas fa-building text-indigo-600 text-sm"></i>
                                    </div>
                                    <a href="admin-client-detail.php?id=<?php echo $client_group['client_id']; ?>" class="font-semibold text-gray-900 hover:text-blue-600">
                                        <?php echo htmlspecialchars($client_group['client_name']); ?>
                                    </a>
                                </div>
                                <div class="flex items-center gap-4 text-sm">
                                    <span class="text-gray-500"><?php echo count($client_group['instances']); ?> instance<?php echo count($client_group['instances']) !== 1 ? 's' : ''; ?></span>
                                    <span class="font-semibold text-gray-900">$<?php echo number_format($client_group['total_cost'], 2); ?>/mo</span>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Label</th>
                                            <th class="px-6 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">IP Address</th>
                                            <th class="px-6 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">OS</th>
                                            <th class="px-6 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Specs</th>
                                            <th class="px-6 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Region</th>
                                            <th class="px-6 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Cost/Mo</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($client_group['instances'] as $inst): ?>
                                            <tr class="hover:bg-gray-50 transition" data-testid="row-cloud-instance-<?php echo $inst['id']; ?>">
                                                <td class="px-6 py-3">
                                                    <span class="flex items-center gap-1.5">
                                                        <span class="w-2 h-2 rounded-full <?php echo ($inst['power_status'] ?? '') === 'running' ? 'bg-green-500' : 'bg-gray-400'; ?>"></span>
                                                        <span class="text-xs font-medium <?php echo ($inst['power_status'] ?? '') === 'running' ? 'text-green-700' : 'text-gray-500'; ?>"><?php echo ucfirst($inst['power_status'] ?? 'unknown'); ?></span>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-3">
                                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($inst['label'] ?: 'Unnamed'); ?></div>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($inst['plan'] ?? ''); ?></div>
                                                </td>
                                                <td class="px-6 py-3 text-sm text-gray-900"><?php echo htmlspecialchars($inst['main_ip'] ?? 'N/A'); ?></td>
                                                <td class="px-6 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($inst['os'] ?? 'N/A'); ?></td>
                                                <td class="px-6 py-3 text-sm text-gray-600"><?php echo intval($inst['vcpu_count'] ?? 0); ?> vCPU / <?php echo htmlspecialchars($inst['ram'] ?? '0'); ?> MB / <?php echo htmlspecialchars($inst['disk'] ?? '0'); ?> GB</td>
                                                <td class="px-6 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($inst['region'] ?? 'N/A'); ?></td>
                                                <td class="px-6 py-3 text-sm font-medium text-gray-900 text-right">$<?php echo number_format(floatval($inst['cost_per_month'] ?? 0), 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="add-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Add Subscription</h2>
            <button onclick="document.getElementById('add-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
                            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_subscription">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                <select name="client_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-client">
                    <option value="">Select client...</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['company'] ? $c['company'] . ' — ' . $c['name'] : $c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product *</label>
                <select name="product_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-product">
                    <option value="">Select product...</option>
                    <?php
                    $grouped_products = [];
                    foreach ($products as $p) {
                        $grouped_products[$p['category']][] = $p;
                    }
                    foreach ($grouped_products as $cat => $prods):
                    ?>
                        <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                            <?php foreach ($prods as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> — $<?php echo number_format($p['price'], 2); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-start-date">
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('add-modal').classList.add('hidden')" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium transition">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-submit-subscription">Create Subscription</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('add-modal')?.addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
</body>
</html>