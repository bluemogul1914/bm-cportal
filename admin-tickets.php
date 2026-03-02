<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$_POST['status'], $_POST['ticket_id']]);
            $success_message = "Ticket status updated!";
        } catch (PDOException $e) {
            $error_message = "Error updating ticket: " . $e->getMessage();
        }
    } elseif ($action === 'assign') {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("UPDATE tickets SET assigned_to=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$_POST['assigned_to'], $_POST['ticket_id']]);
            $success_message = "Ticket assigned!";
        } catch (PDOException $e) {
            $error_message = "Error assigning ticket: " . $e->getMessage();
        }
    }
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';

try {
    $pdo = getDB();

    $where_clauses = [];
    $params = [];

    if ($search) {
        $where_clauses[] = "(t.subject ILIKE ? OR t.description ILIKE ? OR c.name ILIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if ($status_filter) {
        $where_clauses[] = "t.status = ?";
        $params[] = $status_filter;
    }

    if ($priority_filter) {
        $where_clauses[] = "t.priority = ?";
        $params[] = $priority_filter;
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM tickets t 
        LEFT JOIN clients c ON t.client_id = c.id 
        $where_sql
    ");
    $count_stmt->execute($params);
    $total_tickets = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $total_pages = ceil($total_tickets / $limit);

    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT t.*, c.name as client_name, c.email as client_email
        FROM tickets t
        LEFT JOIN clients c ON t.client_id = c.id
        $where_sql
        ORDER BY 
            CASE t.priority
                WHEN 'high' THEN 1
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 3
                ELSE 4
            END,
            t.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($query_params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count,
            SUM(CASE WHEN priority = 'high' AND status != 'closed' THEN 1 ELSE 0 END) as high_priority,
            AVG(CASE WHEN status = 'closed' THEN EXTRACT(EPOCH FROM (updated_at - created_at)) / 3600 ELSE NULL END) as avg_resolution_time
        FROM tickets
    ");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Tickets page error: " . $e->getMessage());
    $tickets = [];
    $total_tickets = 0;
    $total_pages = 0;
    $stats = ['total' => 0, 'open_count' => 0, 'in_progress_count' => 0, 'closed_count' => 0, 'high_priority' => 0, 'avg_resolution_time' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Management - Blue Mogul Admin</title>

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
                            <h1 class="text-2xl font-semibold text-gray-900">Ticket Management</h1>
                            <p class="text-sm text-gray-600 mt-1">Support ticket tracking and resolution</p>
                        </div>
                        <button onclick="location.href='admin-ticket-add.php'" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                            <i class="fas fa-plus mr-2"></i>Create Ticket
                        </button>
                    </div>
                </div>
            </header>

            <?php if (isset($success_message)): ?>
                <div class="mx-6 mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="mx-6 mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Total Tickets</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Open</p>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $stats['open_count']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">In Progress</p>
                        <p class="text-3xl font-bold text-yellow-600"><?php echo $stats['in_progress_count']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Closed</p>
                        <p class="text-3xl font-bold text-green-600"><?php echo $stats['closed_count']; ?></p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">High Priority</p>
                        <p class="text-3xl font-bold text-red-600"><?php echo $stats['high_priority']; ?></p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4">
                        <div class="flex-1 min-w-[200px]">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search tickets or clients..."
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                        </div>
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-md">
                            <option value="">All Status</option>
                            <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                        <select name="priority" class="px-4 py-2 border border-gray-300 rounded-md">
                            <option value="">All Priority</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <?php if ($search || $status_filter || $priority_filter): ?>
                            <a href="admin-tickets.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md font-medium">
                                <i class="fas fa-times mr-2"></i>Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="space-y-4">
                    <?php if (empty($tickets)): ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <i class="fas fa-ticket-alt text-4xl mb-3 text-gray-400"></i>
                            <p class="font-medium text-gray-900">No tickets found</p>
                            <p class="text-sm text-gray-600 mt-1">All support tickets will appear here</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket): ?>
                            <?php
                                $time_ago = time() - strtotime($ticket['created_at']);
                                $hours_ago = floor($time_ago / 3600);
                                $days_ago = floor($time_ago / 86400);
                                $time_display = $days_ago > 0 ? $days_ago . 'd ago' : $hours_ago . 'h ago';
                            ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <span class="px-3 py-1 text-xs font-medium rounded-full <?php
                                                echo match($ticket['priority'] ?? 'medium') {
                                                    'high' => 'bg-red-100 text-red-700',
                                                    'medium' => 'bg-yellow-100 text-yellow-700',
                                                    'low' => 'bg-green-100 text-green-700',
                                                    default => 'bg-gray-100 text-gray-700'
                                                };
                                            ?>">
                                                <i class="fas fa-exclamation-circle mr-1"></i><?php echo ucfirst($ticket['priority'] ?? 'Medium'); ?>
                                            </span>
                                            <span class="px-3 py-1 text-xs font-medium rounded-full <?php
                                                echo match($ticket['status'] ?? 'open') {
                                                    'open' => 'bg-blue-100 text-blue-700',
                                                    'in_progress' => 'bg-yellow-100 text-yellow-700',
                                                    'closed' => 'bg-green-100 text-green-700',
                                                    default => 'bg-gray-100 text-gray-700'
                                                };
                                            ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $ticket['status'] ?? 'Open')); ?>
                                            </span>
                                            <span class="text-xs text-gray-500">
                                                <i class="far fa-clock mr-1"></i><?php echo $time_display; ?>
                                            </span>
                                            <span class="text-xs text-gray-500">
                                                #<?php echo str_pad($ticket['id'], 4, '0', STR_PAD_LEFT); ?>
                                            </span>
                                        </div>

                                        <h3 class="text-lg font-semibold text-gray-900 mb-2">
                                            <?php echo htmlspecialchars($ticket['subject']); ?>
                                        </h3>

                                        <p class="text-sm text-gray-600 mb-3">
                                            <?php echo htmlspecialchars(substr($ticket['description'] ?? '', 0, 150)); ?>...
                                        </p>

                                        <div class="flex items-center gap-4 text-sm text-gray-600">
                                            <div class="flex items-center">
                                                <i class="fas fa-user-circle mr-2 text-gray-400"></i>
                                                <span><?php echo htmlspecialchars($ticket['client_name'] ?? 'Unknown'); ?></span>
                                            </div>
                                            <div class="flex items-center">
                                                <i class="fas fa-envelope mr-2 text-gray-400"></i>
                                                <span><?php echo htmlspecialchars($ticket['client_email'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-col gap-2 ml-4">
                                        <a href="admin-ticket-detail.php?id=<?php echo $ticket['id']; ?>"
                                           class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition text-center">
                                            <i class="fas fa-eye mr-1"></i>View
                                        </a>
                                        <?php if ($ticket['status'] !== 'closed'): ?>
                                            <button onclick="updateTicketStatus(<?php echo $ticket['id']; ?>, 'closed')"
                                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition">
                                                <i class="fas fa-check mr-1"></i>Close
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="flex items-center justify-center space-x-2 mt-6">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&priority=<?php echo urlencode($priority_filter); ?>"
                               class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&priority=<?php echo urlencode($priority_filter); ?>"
                               class="px-3 py-2 <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-md text-sm"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&priority=<?php echo urlencode($priority_filter); ?>"
                               class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script>
        function updateTicketStatus(id, status) {
            if (confirm(`Mark ticket #${id} as ${status}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="ticket_id" value="${id}">
                    <input type="hidden" name="status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>

</body>
</html>