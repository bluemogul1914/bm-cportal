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
$action_filter = $_GET['action_filter'] ?? '';
$entity_filter = $_GET['entity_filter'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$predefined_actions = [
    'login', 'ticket_created', 'comment_added', 'document_uploaded',
    'document_deleted', 'profile_updated', 'ticket_updated',
    'invoice_created', 'invoice_updated', 'client_updated', 'password_reset'
];

$predefined_entities = ['user', 'ticket', 'document', 'invoice', 'client'];

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
        SELECT COUNT(*) as count FROM activity_log WHERE created_at >= CURRENT_DATE
    ");
    $total_today = $today_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $week_stmt = $pdo->query("
        SELECT COUNT(*) as count FROM activity_log WHERE created_at >= CURRENT_DATE - 7
    ");
    $total_week = $week_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $unique_users_stmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id) as count FROM activity_log WHERE created_at >= CURRENT_DATE
    ");
    $unique_users_today = $unique_users_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $common_action_stmt = $pdo->query("
        SELECT action, COUNT(*) as count FROM activity_log GROUP BY action ORDER BY count DESC LIMIT 1
    ");
    $common_action_row = $common_action_stmt->fetch(PDO::FETCH_ASSOC);
    $most_common_action = $common_action_row ? $common_action_row['action'] : 'N/A';
    $most_common_count = $common_action_row ? $common_action_row['count'] : 0;

    $total_all_stmt = $pdo->query("SELECT COUNT(*) as count FROM activity_log");
    $total_all = $total_all_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $breakdown_stmt = $pdo->query("
        SELECT action, COUNT(*) as count 
        FROM activity_log 
        GROUP BY action 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $action_breakdown = $breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);

    $recent_users_stmt = $pdo->query("
        SELECT DISTINCT ON (u.id) u.name, u.email, al.action, al.created_at
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.id IS NOT NULL
        ORDER BY u.id, al.created_at DESC
        LIMIT 5
    ");
    $recent_active_users = $recent_users_stmt->fetchAll(PDO::FETCH_ASSOC);

    $action_types_stmt = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action");
    $db_action_types = $action_types_stmt->fetchAll(PDO::FETCH_COLUMN);

    $entity_types_stmt = $pdo->query("SELECT DISTINCT entity_type FROM activity_log ORDER BY entity_type");
    $db_entity_types = $entity_types_stmt->fetchAll(PDO::FETCH_COLUMN);

    $all_actions = array_unique(array_merge($predefined_actions, $db_action_types));
    sort($all_actions);

    $all_entities = array_unique(array_merge($predefined_entities, $db_entity_types));
    sort($all_entities);

} catch (PDOException $e) {
    error_log("Audit trail page error: " . $e->getMessage());
    $logs = [];
    $total_logs = 0;
    $total_pages = 0;
    $total_today = 0;
    $total_week = 0;
    $total_all = 0;
    $unique_users_today = 0;
    $most_common_action = 'N/A';
    $most_common_count = 0;
    $all_actions = $predefined_actions;
    $all_entities = $predefined_entities;
    $action_breakdown = [];
    $recent_active_users = [];
}

function get_action_badge_class($action) {
    if (stripos($action, 'login') !== false || stripos($action, 'auth') !== false || stripos($action, 'password') !== false) {
        return 'bg-blue-100 text-blue-700';
    } elseif (stripos($action, 'created') !== false || stripos($action, 'create') !== false || stripos($action, 'uploaded') !== false) {
        return 'bg-green-100 text-green-700';
    } elseif (stripos($action, 'updated') !== false || stripos($action, 'update') !== false) {
        return 'bg-yellow-100 text-yellow-700';
    } elseif (stripos($action, 'deleted') !== false || stripos($action, 'delete') !== false) {
        return 'bg-red-100 text-red-700';
    }
    return 'bg-gray-100 text-gray-700';
}

function get_action_icon($action) {
    if (stripos($action, 'login') !== false) return 'fa-sign-in-alt';
    if (stripos($action, 'ticket') !== false) return 'fa-ticket-alt';
    if (stripos($action, 'comment') !== false) return 'fa-comment';
    if (stripos($action, 'document') !== false || stripos($action, 'upload') !== false) return 'fa-file';
    if (stripos($action, 'profile') !== false) return 'fa-user-edit';
    if (stripos($action, 'invoice') !== false) return 'fa-file-invoice-dollar';
    if (stripos($action, 'client') !== false) return 'fa-users';
    if (stripos($action, 'password') !== false) return 'fa-key';
    if (stripos($action, 'delete') !== false) return 'fa-trash';
    return 'fa-circle';
}

function get_entity_icon($entity) {
    $icons = [
        'user' => 'fa-user',
        'ticket' => 'fa-ticket-alt',
        'document' => 'fa-file-alt',
        'invoice' => 'fa-file-invoice-dollar',
        'client' => 'fa-building',
        'auth' => 'fa-shield-alt',
    ];
    return $icons[$entity] ?? 'fa-cube';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - Blue Mogul Admin</title>
    <meta name="description" content="View and filter activity logs, user actions, and system audit trail for the Blue Mogul portal.">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">

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
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title">
                                <i class="fas fa-clipboard-list mr-2 text-blue-600"></i>Audit Trail
                            </h1>
                            <p class="text-sm text-gray-600 mt-1">Activity log and user action history</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-gray-500" data-testid="text-total-records">
                                <i class="fas fa-database mr-1"></i><?php echo number_format($total_all); ?> total records
                            </span>
                            <button onclick="window.print()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium transition" data-testid="button-print">
                                <i class="fas fa-print mr-2"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6" data-testid="audit-summary-cards">
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-actions-today">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions Today</p>
                            <div class="h-10 w-10 bg-blue-50 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bolt text-blue-600"></i>
                            </div>
                        </div>
                        <p class="text-3xl font-bold text-blue-600"><?php echo number_format($total_today); ?></p>
                        <p class="text-xs text-gray-500 mt-1">Since midnight</p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-actions-week">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions This Week</p>
                            <div class="h-10 w-10 bg-indigo-50 rounded-lg flex items-center justify-center">
                                <i class="fas fa-calendar-week text-indigo-600"></i>
                            </div>
                        </div>
                        <p class="text-3xl font-bold text-gray-900"><?php echo number_format($total_week); ?></p>
                        <p class="text-xs text-gray-500 mt-1">Last 7 days</p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-unique-users">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Unique Users Today</p>
                            <div class="h-10 w-10 bg-green-50 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-green-600"></i>
                            </div>
                        </div>
                        <p class="text-3xl font-bold text-green-600"><?php echo number_format($unique_users_today); ?></p>
                        <p class="text-xs text-gray-500 mt-1">Active users</p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="card-common-action">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Most Common Action</p>
                            <div class="h-10 w-10 bg-orange-50 rounded-lg flex items-center justify-center">
                                <i class="fas fa-fire text-orange-600"></i>
                            </div>
                        </div>
                        <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $most_common_action))); ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?php echo number_format($most_common_count); ?> occurrences</p>
                    </div>
                </div>

                <?php if (!empty($action_breakdown)): ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6" data-testid="section-action-breakdown">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">
                            <i class="fas fa-chart-bar mr-2 text-gray-400"></i>Action Breakdown
                        </h3>
                        <div class="space-y-3">
                            <?php 
                            $max_count = !empty($action_breakdown) ? $action_breakdown[0]['count'] : 1;
                            foreach ($action_breakdown as $ab): 
                                $percentage = $max_count > 0 ? ($ab['count'] / $max_count) * 100 : 0;
                                $badge_class = get_action_badge_class($ab['action']);
                            ?>
                            <div class="flex items-center gap-3" data-testid="breakdown-<?php echo htmlspecialchars($ab['action']); ?>">
                                <div class="w-36 flex-shrink-0">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $ab['action']))); ?>
                                    </span>
                                </div>
                                <div class="flex-1">
                                    <div class="w-full bg-gray-100 rounded-full h-2">
                                        <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                                <span class="text-sm font-medium text-gray-700 w-12 text-right"><?php echo number_format($ab['count']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="section-recent-active-users">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">
                            <i class="fas fa-user-clock mr-2 text-gray-400"></i>Recently Active Users
                        </h3>
                        <?php if (empty($recent_active_users)): ?>
                            <p class="text-sm text-gray-500">No recent activity</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($recent_active_users as $ru): ?>
                                <div class="flex items-center gap-3" data-testid="active-user-<?php echo htmlspecialchars($ru['email'] ?? ''); ?>">
                                    <div class="h-8 w-8 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <span class="text-xs font-semibold text-blue-700">
                                            <?php echo strtoupper(substr($ru['name'] ?? '?', 0, 1)); ?>
                                        </span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($ru['name'] ?? 'Unknown'); ?></p>
                                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $ru['action'] ?? ''))); ?> &middot; <?php echo date('M d, g:ia', strtotime($ru['created_at'])); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6" data-testid="section-filters">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">
                        <i class="fas fa-filter mr-2 text-gray-400"></i>Filter Activity Logs
                    </h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4" data-testid="form-audit-filters">
                        <div class="lg:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Search Details</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                       placeholder="Search in details..."
                                       class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                       data-testid="input-search">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Action Type</label>
                            <select name="action_filter" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" data-testid="select-action">
                                <option value="">All Actions</option>
                                <?php foreach ($all_actions as $at): ?>
                                    <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $action_filter === $at ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $at))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Entity Type</label>
                            <select name="entity_filter" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" data-testid="select-entity-type">
                                <option value="">All Entities</option>
                                <?php foreach ($all_entities as $et): ?>
                                    <option value="<?php echo htmlspecialchars($et); ?>" <?php echo $entity_filter === $et ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($et)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">From Date</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500"
                                   data-testid="input-date-from">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">To Date</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500"
                                   data-testid="input-date-to">
                        </div>
                        <div class="lg:col-span-6 flex items-center gap-3 flex-wrap">
                            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium text-sm transition" data-testid="button-apply">
                                <i class="fas fa-filter mr-2"></i>Apply Filters
                            </button>
                            <?php if ($search || $action_filter || $entity_filter || $date_from || $date_to): ?>
                                <a href="admin-audit.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md font-medium text-sm transition" data-testid="link-clear-filters">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <span class="text-sm text-gray-500" data-testid="text-filter-results">
                                    <i class="fas fa-info-circle mr-1"></i>Showing <?php echo number_format($total_logs); ?> filtered result<?php echo $total_logs !== 1 ? 's' : ''; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-lg border border-gray-200" data-testid="audit-log-table">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
                        <h3 class="text-sm font-semibold text-gray-900">
                            <i class="fas fa-list mr-2 text-gray-400"></i>Activity Log
                            <span class="ml-2 text-xs font-normal text-gray-500">(<?php echo number_format($total_logs); ?> entries)</span>
                        </h3>
                        <div class="text-xs text-gray-500">
                            Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Timestamp</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Action</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Entity Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Entity ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-16 text-center">
                                            <div class="flex flex-col items-center">
                                                <div class="h-16 w-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                                                    <i class="fas fa-clipboard-list text-2xl text-gray-400"></i>
                                                </div>
                                                <p class="font-medium text-gray-900 mb-1">No activity logs found</p>
                                                <p class="text-sm text-gray-500 max-w-sm">
                                                    <?php if ($search || $action_filter || $entity_filter || $date_from || $date_to): ?>
                                                        No logs match your current filters. Try adjusting or <a href="admin-audit.php" class="text-blue-600 hover:underline">clearing filters</a>.
                                                    <?php else: ?>
                                                        Activity will appear here as users perform actions in the portal.
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $index => $log): ?>
                                        <?php
                                            $badge_class = get_action_badge_class($log['action'] ?? '');
                                            $action_icon = get_action_icon($log['action'] ?? '');
                                            $entity_icon = get_entity_icon($log['entity_type'] ?? '');
                                            $action_val = $log['action'] ?? '';

                                            $log_time = strtotime($log['created_at'] ?? 'now') ?: time();
                                            $now = time();
                                            $diff = $now - $log_time;
                                            if ($diff < 60) {
                                                $relative_time = 'Just now';
                                            } elseif ($diff < 3600) {
                                                $relative_time = floor($diff / 60) . 'm ago';
                                            } elseif ($diff < 86400) {
                                                $relative_time = floor($diff / 3600) . 'h ago';
                                            } else {
                                                $relative_time = floor($diff / 86400) . 'd ago';
                                            }
                                        ?>
                                        <tr class="hover:bg-gray-50 transition" data-testid="row-audit-<?php echo $log['id']; ?>">
                                            <td class="px-6 py-4 whitespace-nowrap" data-testid="text-timestamp-<?php echo $log['id']; ?>">
                                                <div>
                                                    <p class="text-sm text-gray-900"><?php echo date('M d, Y', $log_time); ?></p>
                                                    <p class="text-xs text-gray-500"><?php echo date('H:i:s', $log_time); ?> &middot; <?php echo $relative_time; ?></p>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4" data-testid="text-user-<?php echo $log['id']; ?>">
                                                <div class="flex items-center gap-2">
                                                    <div class="h-7 w-7 bg-gray-100 rounded-full flex items-center justify-center flex-shrink-0">
                                                        <span class="text-xs font-semibold text-gray-600">
                                                            <?php echo strtoupper(substr($log['user_name'] ?? '?', 0, 1)); ?>
                                                        </span>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></p>
                                                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($log['user_email'] ?? ''); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap" data-testid="text-action-<?php echo $log['id']; ?>">
                                                <span class="inline-flex items-center gap-1 px-3 py-1 text-xs font-medium rounded-full <?php echo $badge_class; ?>">
                                                    <i class="fas <?php echo $action_icon; ?> text-[10px]"></i>
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $action_val))); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap" data-testid="text-entity-type-<?php echo $log['id']; ?>">
                                                <span class="inline-flex items-center gap-1 text-sm text-gray-600">
                                                    <i class="fas <?php echo $entity_icon; ?> text-xs text-gray-400"></i>
                                                    <?php echo htmlspecialchars(ucfirst($log['entity_type'] ?? '')); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap" data-testid="text-entity-id-<?php echo $log['id']; ?>">
                                                <?php if (!empty($log['entity_id'])): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono bg-gray-100 text-gray-700">
                                                        #<?php echo htmlspecialchars($log['entity_id']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs" data-testid="text-details-<?php echo $log['id']; ?>">
                                                <span class="block truncate" title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap" data-testid="text-ip-<?php echo $log['id']; ?>">
                                                <?php if (!empty($log['ip_address']) && $log['ip_address'] !== '0.0.0.0'): ?>
                                                    <span class="inline-flex items-center gap-1 text-sm text-gray-500">
                                                        <i class="fas fa-globe text-xs text-gray-400"></i>
                                                        <?php echo htmlspecialchars($log['ip_address']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-400">
                                                        <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between gap-4 flex-wrap" data-testid="audit-pagination">
                            <div class="text-sm text-gray-600" data-testid="text-pagination-info">
                                Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to <span class="font-medium"><?php echo min($offset + $limit, $total_logs); ?></span> of <span class="font-medium"><?php echo number_format($total_logs); ?></span> entries
                            </div>
                            <div class="flex items-center gap-1 flex-wrap">
                                <?php
                                    $query_string = http_build_query(array_filter([
                                        'search' => $search,
                                        'action_filter' => $action_filter,
                                        'entity_filter' => $entity_filter,
                                        'date_from' => $date_from,
                                        'date_to' => $date_to,
                                    ]));
                                ?>
                                <?php if ($page > 1): ?>
                                    <a href="?page=1&<?php echo $query_string; ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50 transition"
                                       data-testid="link-first-page" title="First page">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                    <a href="?page=<?php echo $page - 1; ?>&<?php echo $query_string; ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50 transition"
                                       data-testid="link-prev-page">
                                        <i class="fas fa-angle-left mr-1"></i>Previous
                                    </a>
                                <?php endif; ?>

                                <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    if ($start_page > 1): ?>
                                    <span class="px-2 py-2 text-sm text-gray-400">...</span>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&<?php echo $query_string; ?>"
                                       class="px-3 py-2 <?php echo $i === $page ? 'bg-blue-600 text-white border border-blue-600' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-md text-sm font-medium transition"
                                       data-testid="link-page-<?php echo $i; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <span class="px-2 py-2 text-sm text-gray-400">...</span>
                                <?php endif; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&<?php echo $query_string; ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50 transition"
                                       data-testid="link-next-page">
                                        Next<i class="fas fa-angle-right ml-1"></i>
                                    </a>
                                    <a href="?page=<?php echo $total_pages; ?>&<?php echo $query_string; ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50 transition"
                                       data-testid="link-last-page" title="Last page">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
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