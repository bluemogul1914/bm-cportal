<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

try {
    $pdo = getDB();

    $where_clauses = [];
    $params = [];

    if ($search) {
        $where_clauses[] = "(name ILIKE ? OR email ILIKE ? OR company ILIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if ($status_filter) {
        $where_clauses[] = "status = ?";
        $params[] = $status_filter;
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM clients $where_sql");
    $count_stmt->execute($params);
    $total_clients = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $total_pages = ceil($total_clients / $limit);

    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $stmt = $pdo->prepare("SELECT * FROM clients $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($query_params);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE) THEN 1 ELSE 0 END) as new_this_month
        FROM clients
    ");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Clients page error: " . $e->getMessage());
    $clients = [];
    $total_clients = 0;
    $total_pages = 0;
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'new_this_month' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management - Blue Mogul Admin</title>

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

        <?php include 'includes/admin-sidebar.php'; ?>

        <div class="flex-1 overflow-y-auto">

            <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900">Client Management</h1>
                            <p class="text-sm text-gray-600 mt-1">Manage all your clients and accounts</p>
                        </div>
                        <button onclick="location.href='admin-client-add.php'" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                            <i class="fas fa-plus mr-2"></i>Add New Client
                        </button>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Total Clients</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Active</p>
                        <p class="text-3xl font-bold text-green-600"><?php echo $stats['active']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Inactive</p>
                        <p class="text-3xl font-bold text-gray-600"><?php echo $stats['inactive']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">New This Month</p>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $stats['new_this_month']; ?></p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4">
                        <div class="flex-1 min-w-[200px]">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search clients..." 
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium transition">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <?php if ($search || $status_filter): ?>
                            <a href="admin-clients.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md font-medium transition">
                                <i class="fas fa-times mr-2"></i>Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        <input type="checkbox" class="rounded border-gray-300" data-testid="checkbox-all">
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Client Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Primary Location</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Primary Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($clients)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                            <i class="fas fa-users text-4xl mb-3 text-gray-400"></i>
                                            <p class="font-medium">No clients found</p>
                                            <p class="text-sm mt-1">Add your first client to get started</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($clients as $client): ?>
                                        <?php
                                            $company_name = !empty($client['company']) ? $client['company'] : $client['name'];
                                            $company_initial = strtoupper(substr($company_name, 0, 1));
                                            $location_parts = [];
                                            if (!empty($client['address'])) $location_parts[] = $client['address'];
                                            $city_state = [];
                                            if (!empty($client['city'])) $city_state[] = $client['city'];
                                            if (!empty($client['state'])) $city_state[] = $client['state'];
                                            if (!empty($client['zip'])) $city_state[] = $client['zip'];
                                            if (!empty($city_state)) $location_parts[] = implode(' ', $city_state);
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="row-client-<?php echo $client['id']; ?>">
                                            <td class="px-6 py-4">
                                                <input type="checkbox" class="rounded border-gray-300" data-testid="checkbox-client-<?php echo $client['id']; ?>">
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-start">
                                                    <div class="bg-blue-100 rounded-lg h-10 w-10 flex items-center justify-center font-bold text-sm text-blue-600 mr-3 flex-shrink-0 mt-0.5">
                                                        <?php echo $company_initial; ?>
                                                    </div>
                                                    <div>
                                                        <a href="admin-client-detail.php?id=<?php echo $client['id']; ?>" class="font-semibold text-blue-600 hover:text-blue-800 hover:underline" data-testid="link-client-<?php echo $client['id']; ?>">
                                                            <?php echo htmlspecialchars($company_name); ?>
                                                        </a>
                                                        <?php if (!empty($client['company']) && $client['company'] !== $client['name']): ?>
                                                            <p class="text-xs text-gray-500 mt-0.5"><i class="fas fa-user text-gray-400 mr-1"></i><?php echo htmlspecialchars($client['name']); ?></p>
                                                        <?php endif; ?>
                                                        <?php if (!empty($client['contact_person'])): ?>
                                                            <p class="text-xs text-blue-500 mt-0.5"><i class="fas fa-id-badge text-blue-300 mr-1"></i><?php echo htmlspecialchars($client['contact_person']); ?></p>
                                                        <?php endif; ?>
                                                        <div class="flex items-center gap-2 mt-1">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium <?php echo ($client['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                                                <?php echo ucfirst($client['status'] ?? 'active'); ?>
                                                            </span>
                                                            <?php if (!empty($client['client_code'])): ?>
                                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-semibold bg-blue-50 text-blue-700 border border-blue-200" data-testid="badge-client-code-<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['client_code']); ?></span>
                                                            <?php else: ?>
                                                                <span class="text-[10px] text-gray-400">ID: #<?php echo $client['id']; ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-[10px] text-gray-400 mt-0.5">Created: <?php echo date('Y-m-d', strtotime($client['created_at'])); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if (!empty($location_parts)): ?>
                                                    <div class="text-sm text-gray-700">
                                                        <?php if (!empty($client['address'])): ?>
                                                            <p><i class="fas fa-map-marker-alt text-gray-400 mr-1"></i><?php echo htmlspecialchars($client['address']); ?></p>
                                                        <?php endif; ?>
                                                        <?php if (!empty($city_state)): ?>
                                                            <p class="text-gray-500"><?php echo htmlspecialchars(implode(', ', array_filter([$client['city'] ?? '', ($client['state'] ?? '') . ' ' . ($client['zip'] ?? '')]))); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm">
                                                    <p class="font-medium text-blue-600" data-testid="text-contact-<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></p>
                                                    <?php if (!empty($client['phone'])): ?>
                                                        <p class="text-gray-600 mt-0.5"><i class="fas fa-phone text-gray-400 text-xs mr-1"></i><?php echo htmlspecialchars($client['phone']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($client['email'])): ?>
                                                        <p class="text-gray-500 mt-0.5"><i class="fas fa-envelope text-gray-400 text-xs mr-1"></i><?php echo htmlspecialchars($client['email']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo ($client['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>" data-testid="badge-status-<?php echo $client['id']; ?>">
                                                    <span class="h-1.5 w-1.5 rounded-full <?php echo ($client['status'] ?? 'active') === 'active' ? 'bg-green-500' : 'bg-gray-500'; ?> mr-1.5"></span>
                                                    <?php echo ucfirst($client['status'] ?? 'Active'); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-1">
                                                <a href="admin-client-detail.php?id=<?php echo $client['id']; ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md text-blue-600 hover:bg-blue-50 transition" title="View" data-testid="button-view-<?php echo $client['id']; ?>">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="admin-client-edit.php?id=<?php echo $client['id']; ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md text-gray-600 hover:bg-gray-100 transition" title="Edit" data-testid="button-edit-<?php echo $client['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                            <div class="text-sm text-gray-600">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_clients); ?> of <?php echo $total_clients; ?> clients
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                        Previous
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="px-3 py-2 <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-md text-sm">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                        Next
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
        function deleteClient(id) {
            if (confirm('Are you sure you want to delete this client? This action cannot be undone.')) {
                fetch('/api/clients.php?id=' + id, {
                    method: 'DELETE'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error deleting client');
                    }
                });
            }
        }
    </script>

</body>
</html>