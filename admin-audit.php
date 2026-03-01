<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 25;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$action_filter = $_GET['action'] ?? '';
$entity_filter = $_GET['entity_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

try {
    $pdo = getDB();

    $where_clauses = [];
    $params = [];

    if ($search) {
        $where_clauses[] = "al.details ILIKE ?";
        $params[] = "%$search%";
    }

    if ($action_filter) {
        $where_clauses[] = "al.action = ?";
        $params[] = $action_filter;
    }

    if ($entity_filter) {
        $where_clauses[] = "al.entity_type = ?";
        $params[] = $entity_filter;
    }

    if ($date_from) {
        $where_clauses[] = "al.created_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    }

    if ($date_to) {
        $where_clauses[] = "al.created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        $where_sql
    ");
    $count_stmt->execute($params);
    $total_logs = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $total_pages = ceil($total_logs / $limit);

    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT al.*, u.name as user_name, u.email as user_email
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        $where_sql
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($query_params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today_stmt = $pdo->query("
        SELECT COUNT(*) as count FROM activity_log WHERE DATE(created_at) = CURRENT_DATE
    ");
    $total_today = $today_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $week_stmt = $pdo->query("
        SELECT COUNT(*) as count FROM activity_log WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'
    ");
    $total_week = $week_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $unique_users_stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) as count FROM activity_log WHERE DATE(created_at) = CURRENT_DATE
    ");
    $unique_users_today = $unique_users_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $common_action_stmt = $pdo->query("
        SELECT action, COUNT(*) as count FROM activity_log GROUP BY action ORDER BY count DESC LIMIT 1
    ");
    $common_action_row = $common_action_stmt->fetch(PDO::FETCH_ASSOC);
    $most_common_action = $common_action_row ? $common_action_row['action'] : 'N/A';

    $action_types_stmt = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action");
    $action_types = $action_types_stmt->fetchAll(PDO::FETCH_COLUMN);

    $entity_types_stmt = $pdo->query("SELECT DISTINCT entity_type FROM activity_log ORDER BY entity_type");
    $entity_types = $entity_types_stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    error_log("Audit trail page error: " . $e->getMessage());
    $logs = [];
    $total_logs = 0;
    $total_pages = 0;
    $total_today = 0;
    $total_week = 0;
    $unique_users_today = 0;
    $most_common_action = 'N/A';
    $action_types = [];
    $entity_types = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - Blue Mogul Admin</title>

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
                            <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">Audit Trail</h1>
                            <p class="text-sm text-gray-600 mt-1">Activity log and user action history</p>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6" data-testid="audit-summary-cards">
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-actions-today">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Actions Today</p>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $total_today; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-actions-week">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Actions This Week</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $total_week; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-unique-users">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Unique Users Today</p>
                        <p class="text-3xl font-bold text-green-600"><?php echo $unique_users_today; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-common-action">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Most Common Action</p>
                        <p class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($most_common_action); ?></p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4" data-testid="form-audit-filters">
                        <div class="flex-1 min-w-[200px]">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search details..."
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"
                                   data-testid="input-search">
                        </div>
                        <select name="action" class="px-4 py-2 border border-gray-300 rounded-md" data-testid="select-action">
                            <option value="">All Actions</option>
                            <?php foreach ($action_types as $at): ?>
                                <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $action_filter === $at ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $at))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="entity_type" class="px-4 py-2 border border-gray-300 rounded-md" data-testid="select-entity-type">
                            <option value="">All Entities</option>
                            <?php foreach ($entity_types as $et): ?>
                                <option value="<?php echo htmlspecialchars($et); ?>" <?php echo $entity_filter === $et ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($et)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                               class="px-4 py-2 border border-gray-300 rounded-md"
                               placeholder="From" title="From date"
                               data-testid="input-date-from">
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                               class="px-4 py-2 border border-gray-300 rounded-md"
                               placeholder="To" title="To date"
                               data-testid="input-date-to">
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium" data-testid="button-search">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <?php if ($search || $action_filter || $entity_filter || $date_from || $date_to): ?>
                            <a href="admin-audit.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md font-medium" data-testid="link-clear-filters">
                                <i class="fas fa-times mr-2"></i>Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="bg-white rounded-lg border border-gray-200" data-testid="audit-log-table">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Timestamp</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Action</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Entity Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Entity ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                            <i class="fas fa-clipboard-list text-4xl mb-3 text-gray-400"></i>
                                            <p class="font-medium">No activity logs found</p>
                                            <p class="text-sm text-gray-400 mt-1">Activity will appear here as users perform actions</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $index => $log): ?>
                                        <?php
                                            $action_colors = [
                                                'login' => 'bg-blue-100 text-blue-700',
                                                'created' => 'bg-green-100 text-green-700',
                                                'updated' => 'bg-yellow-100 text-yellow-700',
                                                'deleted' => 'bg-red-100 text-red-700',
                                            ];
                                            $badge_class = 'bg-gray-100 text-gray-700';
                                            $action_val = $log['action'] ?? '';
                                            if (stripos($action_val, 'login') !== false) {
                                                $badge_class = $action_colors['login'];
                                            } elseif (stripos($action_val, 'created') !== false || stripos($action_val, 'create') !== false || stripos($action_val, 'uploaded') !== false) {
                                                $badge_class = $action_colors['created'];
                                            } elseif (stripos($action_val, 'updated') !== false || stripos($action_val, 'update') !== false) {
                                                $badge_class = $action_colors['updated'];
                                            } elseif (stripos($action_val, 'deleted') !== false || stripos($action_val, 'delete') !== false) {
                                                $badge_class = $action_colors['deleted'];
                                            }
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="row-audit-<?php echo $log['id']; ?>">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" data-testid="text-timestamp-<?php echo $log['id']; ?>">
                                                <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                                            </td>
                                            <td class="px-6 py-4" data-testid="text-user-<?php echo $log['id']; ?>">
                                                <div>
                                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($log['user_name'] ?? 'Unknown'); ?></p>
                                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($log['user_email'] ?? ''); ?></p>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap" data-testid="text-action-<?php echo $log['id']; ?>">
                                                <span class="px-3 py-1 text-xs font-medium rounded-full <?php echo $badge_class; ?>">
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $action_val))); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" data-testid="text-entity-type-<?php echo $log['id']; ?>">
                                                <?php echo htmlspecialchars(ucfirst($log['entity_type'] ?? '')); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" data-testid="text-entity-id-<?php echo $log['id']; ?>">
                                                <?php echo htmlspecialchars($log['entity_id'] ?? '-'); ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate" data-testid="text-details-<?php echo $log['id']; ?>">
                                                <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" data-testid="text-ip-<?php echo $log['id']; ?>">
                                                <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between" data-testid="audit-pagination">
                            <div class="text-sm text-gray-600">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_logs); ?> of <?php echo $total_logs; ?>
                            </div>
                            <div class="flex space-x-2">
                                <?php
                                    $query_string = http_build_query(array_filter([
                                        'search' => $search,
                                        'action' => $action_filter,
                                        'entity_type' => $entity_filter,
                                        'date_from' => $date_from,
                                        'date_to' => $date_to,
                                    ]));
                                ?>
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&<?php echo $query_string; ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50"
                                       data-testid="link-prev-page">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&<?php echo $query_string; ?>"
                                       class="px-3 py-2 <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-md text-sm"
                                       data-testid="link-page-<?php echo $i; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&<?php echo $query_string; ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50"
                                       data-testid="link-next-page">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

</body>
</html>