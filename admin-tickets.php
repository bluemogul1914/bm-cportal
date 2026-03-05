<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';

$success_message = '';
$error_message = '';
$tickets = [];
$stats = ['total' => 0, 'open' => 0, 'closed' => 0, 'in_progress' => 0, 'high_priority' => 0];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $client_id = intval($_POST['client_id'] ?? 0);
        $assigned_to = trim($_POST['assigned_to'] ?? '');

        if (empty($subject) || $client_id <= 0) {
            $error_message = 'Subject and client are required.';
        } else {
            try {
                $pdo = getDB();
                $stmt = $pdo->prepare("INSERT INTO tickets (client_id, subject, description, status, priority, assigned_to, source, created_at, updated_at) VALUES (?, ?, ?, 'open', ?, ?, 'admin', NOW(), NOW()) RETURNING id");
                $stmt->execute([$client_id, $subject, $description, $priority, $assigned_to ?: null]);
                $new_id = $stmt->fetchColumn();
                $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, 'ticket_created', 'ticket', $new_id, 'Created ticket: ' . $subject, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                $success_message = "Ticket #$new_id created successfully!";
            } catch (PDOException $e) {
                error_log("Admin ticket create error: " . $e->getMessage());
                $error_message = 'Failed to create ticket.';
            }
        }
    } elseif ($action === 'update_status') {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        if ($ticket_id > 0 && in_array($new_status, ['open', 'in_progress', 'closed'])) {
            try {
                $pdo = getDB();
                $pdo->prepare("UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?")->execute([$new_status, $ticket_id]);
                $success_message = "Ticket #$ticket_id status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
            } catch (PDOException $e) {
                $error_message = "Error updating ticket.";
            }
        }
    }
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
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
        $where_clauses[] = "(t.subject ILIKE ? OR t.description ILIKE ? OR c.name ILIKE ? OR c.email ILIKE ?)";
        $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
    }
    if ($status_filter) { $where_clauses[] = "t.status = ?"; $params[] = $status_filter; }
    if ($priority_filter) { $where_clauses[] = "t.priority = ?"; $params[] = $priority_filter; }
    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets t LEFT JOIN clients c ON t.client_id = c.id $where_sql");
    $count_stmt->execute($params);
    $total_tickets = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $total_pages = ceil($total_tickets / $limit);

    $query_params = array_merge($params, [$limit, $offset]);
    $stmt = $pdo->prepare("SELECT t.*, c.name as client_name, c.email as client_email FROM tickets t LEFT JOIN clients c ON t.client_id = c.id $where_sql ORDER BY CASE WHEN t.status = 'open' THEN 0 WHEN t.status = 'in_progress' THEN 1 ELSE 2 END, CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END, t.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($query_params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) as open, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) as closed, SUM(CASE WHEN priority IN ('high','urgent') AND status!='closed' THEN 1 ELSE 0 END) as high_priority FROM tickets");
    $db_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    $stats = [
        'total' => (int)($db_stats['total'] ?? 0),
        'open' => (int)($db_stats['open'] ?? 0),
        'in_progress' => (int)($db_stats['in_progress'] ?? 0),
        'closed' => (int)($db_stats['closed'] ?? 0),
        'high_priority' => (int)($db_stats['high_priority'] ?? 0),
    ];

    $clients_list = $pdo->query("SELECT id, name, email, company FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tickets = [];
    $total_tickets = 0;
    $total_pages = 0;
    $clients_list = [];
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
                        <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-headset mr-2"></i>Ticket Management</h1>
                        <p class="text-sm text-gray-600 mt-1">Support ticket tracking and resolution</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="document.getElementById('create-modal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-create-ticket">
                            <i class="fas fa-plus mr-2"></i>Create Ticket
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($success_message): ?>
            <div class="mx-6 mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" data-testid="alert-success">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="mx-6 mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" data-testid="alert-error">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-total">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Total Tickets</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-open">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Open</p>
                    <p class="text-3xl font-bold text-blue-600"><?php echo $stats['open']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-in-progress">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">In Progress</p>
                    <p class="text-3xl font-bold text-yellow-600"><?php echo $stats['in_progress']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-closed">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Closed</p>
                    <p class="text-3xl font-bold text-green-600"><?php echo $stats['closed']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-high-priority">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">High Priority</p>
                    <p class="text-3xl font-bold text-red-600"><?php echo $stats['high_priority']; ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                <form method="GET" class="flex flex-wrap gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search tickets..."
                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500" data-testid="input-search">
                    </div>
                    <select name="status" class="px-4 py-2 border border-gray-300 rounded-md" data-testid="select-status-filter">
                        <option value="">All Status</option>
                        <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                    <select name="priority" class="px-4 py-2 border border-gray-300 rounded-md" data-testid="select-priority-filter">
                        <option value="">All Priority</option>
                        <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium" data-testid="button-search">
                        <i class="fas fa-search mr-2"></i>Search
                    </button>
                    <?php if ($search || $status_filter || $priority_filter): ?>
                        <a href="admin-tickets.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md font-medium" data-testid="link-clear-filter">
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
                        <p class="text-sm text-gray-600 mt-1">Create a new ticket to get started</p>
                        <button onclick="document.getElementById('create-modal').classList.remove('hidden')" class="inline-block mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                            <i class="fas fa-plus mr-2"></i>Create Ticket
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket):
                        $tid = $ticket['id'];
                        $subject_text = $ticket['subject'];
                        $client_name_display = $ticket['client_name'] ?? 'Unknown';
                        $client_email_display = $ticket['client_email'] ?? '';
                        $status = $ticket['status'] ?? 'open';
                        $priority_text = $ticket['priority'] ?? 'medium';
                        $created_at_str = $ticket['created_at'] ?? '';
                        $assigned_text = $ticket['assigned_to'] ?? '';

                        $time_display = '';
                        if ($created_at_str) {
                            $ts = strtotime($created_at_str);
                            if ($ts) {
                                $ago = time() - $ts;
                                $days = floor($ago / 86400);
                                $hours = floor($ago / 3600);
                                $time_display = $days > 0 ? $days . 'd ago' : ($hours > 0 ? $hours . 'h ago' : 'Just now');
                            }
                        }
                    ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition" data-testid="ticket-row-<?php echo $tid; ?>">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="px-3 py-1 text-xs font-medium rounded-full <?php
                                            echo match($priority_text) {
                                                'urgent' => 'bg-red-200 text-red-800',
                                                'high' => 'bg-red-100 text-red-700',
                                                'medium' => 'bg-yellow-100 text-yellow-700',
                                                'low' => 'bg-green-100 text-green-700',
                                                default => 'bg-gray-100 text-gray-700'
                                            };
                                        ?>">
                                            <i class="fas fa-exclamation-circle mr-1"></i><?php echo ucfirst($priority_text); ?>
                                        </span>
                                        <span class="px-3 py-1 text-xs font-medium rounded-full <?php
                                            echo match($status) {
                                                'open' => 'bg-blue-100 text-blue-700',
                                                'in_progress' => 'bg-yellow-100 text-yellow-700',
                                                'closed' => 'bg-green-100 text-green-700',
                                                default => 'bg-gray-100 text-gray-700'
                                            };
                                        ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                        </span>
                                        <?php if ($time_display): ?>
                                        <span class="text-xs text-gray-500"><i class="far fa-clock mr-1"></i><?php echo htmlspecialchars($time_display); ?></span>
                                        <?php endif; ?>
                                        <span class="text-xs text-gray-500">#<?php echo $tid; ?></span>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars($subject_text); ?></h3>
                                    <div class="flex items-center gap-4 text-sm text-gray-600">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-circle mr-2 text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($client_name_display); ?></span>
                                        </div>
                                        <?php if ($client_email_display): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-envelope mr-2 text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($client_email_display); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($assigned_text): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-user-tag mr-2 text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($assigned_text); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2 ml-4">
                                    <a href="admin-ticket-detail.php?id=<?php echo $tid; ?>"
                                       class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition text-center" data-testid="button-view-<?php echo $tid; ?>">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>
                                    <?php if ($status !== 'closed'): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Close this ticket?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="ticket_id" value="<?php echo $tid; ?>">
                                            <input type="hidden" name="status" value="closed">
                                            <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition w-full" data-testid="button-close-<?php echo $tid; ?>">
                                                <i class="fas fa-check mr-1"></i>Close
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (($total_pages ?? 0) > 1): ?>
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

<div id="create-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Create New Ticket</h2>
            <button onclick="document.getElementById('create-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600" data-testid="button-close-modal"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="admin-tickets.php" class="p-6 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_ticket">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                <select name="client_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" data-testid="select-client">
                    <option value="">Select a client...</option>
                    <?php foreach ($clients_list ?? [] as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> <?php echo $c['company'] ? '(' . htmlspecialchars($c['company']) . ')' : ''; ?> — <?php echo htmlspecialchars($c['email']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Subject *</label>
                <input type="text" name="subject" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="Brief description of the issue" data-testid="input-subject">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-priority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Assigned To</label>
                <input type="text" name="assigned_to" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="Staff name (optional)" data-testid="input-assigned-to">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="5" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" placeholder="Describe the issue in detail..." data-testid="textarea-description"></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('create-modal').classList.add('hidden')" class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium transition" data-testid="button-cancel">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="button-submit-ticket">Create Ticket</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('create-modal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
</body>
</html>