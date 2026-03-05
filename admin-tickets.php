<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$sd_connected = !empty(ITARIAN_SD_API_KEY);
$sd_url = rtrim(ITARIAN_SD_URL, '/');

$success_message = '';
$error_message = '';
$tickets = [];
$api_available = false;
$stats = ['total' => 0, 'open' => 0, 'closed' => 0, 'unassigned' => 0, 'overdue' => 0];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'close_ticket' && $sd_connected) {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        if ($ticket_id > 0) {
            $result = itarian_sd_api('closeTicket', ['ticketId' => $ticket_id]);
            if (isset($result['error'])) {
                try {
                    $pdo = getDB();
                    $pdo->prepare("UPDATE tickets SET status='closed', updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
                    $success_message = "Ticket #$ticket_id closed.";
                } catch (PDOException $e) {
                    $error_message = 'Failed to close ticket.';
                }
            } else {
                $success_message = "Ticket #$ticket_id closed.";
            }
        }
    } elseif ($action === 'update_status') {
        try {
            $pdo = getDB();
            $pdo->prepare("UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?")->execute([$_POST['status'], $_POST['ticket_id']]);
            $success_message = "Ticket status updated.";
        } catch (PDOException $e) {
            $error_message = "Error updating ticket.";
        }
    }
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';

if ($sd_connected) {
    $result = itarian_sd_api('listtickets', []);
    if (!isset($result['error'])) {
        $api_available = true;
        $raw_tickets = $result['data'] ?? $result['tickets'] ?? $result ?? [];
        if (is_array($raw_tickets) && !empty($raw_tickets)) {
            $tickets = $raw_tickets;
            foreach ($tickets as $t) {
                $stats['total']++;
                $st = strtolower($t['status'] ?? $t['ticketStatus'] ?? '');
                if ($st === 'open' || $st === 'new') $stats['open']++;
                elseif ($st === 'closed' || $st === 'resolved') $stats['closed']++;
                if (empty($t['assigned'] ?? $t['assignedTo'] ?? $t['staff'] ?? '')) $stats['unassigned']++;
                if (isset($t['isOverdue']) && $t['isOverdue']) $stats['overdue']++;
            }
            if ($search || $status_filter) {
                $tickets = array_filter($tickets, function($t) use ($search, $status_filter) {
                    $match = true;
                    if ($search) {
                        $s = strtolower($search);
                        $subj = strtolower($t['subject'] ?? $t['summary'] ?? '');
                        $email = strtolower($t['email'] ?? '');
                        $match = (strpos($subj, $s) !== false || strpos($email, $s) !== false);
                    }
                    if ($match && $status_filter) {
                        $match = (strtolower($t['status'] ?? '') === strtolower($status_filter));
                    }
                    return $match;
                });
                $tickets = array_values($tickets);
            }
        }
    }
}

if (!$api_available) {
    try {
        $pdo = getDB();
        $where_clauses = [];
        $params = [];
        if ($search) {
            $where_clauses[] = "(t.subject ILIKE ? OR t.description ILIKE ? OR c.name ILIKE ?)";
            $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
        }
        if ($status_filter) { $where_clauses[] = "t.status = ?"; $params[] = $status_filter; }
        if ($priority_filter) { $where_clauses[] = "t.priority = ?"; $params[] = $priority_filter; }
        $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

        $count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets t LEFT JOIN clients c ON t.client_id = c.id $where_sql");
        $count_stmt->execute($params);
        $total_tickets = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $total_pages = ceil($total_tickets / $limit);

        $query_params = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare("SELECT t.*, c.name as client_name, c.email as client_email FROM tickets t LEFT JOIN clients c ON t.client_id = c.id $where_sql ORDER BY CASE t.priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END, t.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute($query_params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats_stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) as open, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) as closed, SUM(CASE WHEN priority='high' AND status!='closed' THEN 1 ELSE 0 END) as high_priority FROM tickets");
        $db_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        $stats = [
            'total' => (int)($db_stats['total'] ?? 0),
            'open' => (int)($db_stats['open'] ?? 0) + (int)($db_stats['in_progress'] ?? 0),
            'closed' => (int)($db_stats['closed'] ?? 0),
            'unassigned' => 0,
            'overdue' => (int)($db_stats['high_priority'] ?? 0),
        ];
    } catch (PDOException $e) {
        $tickets = [];
        $total_tickets = 0;
        $total_pages = 0;
    }
}
$total_pages = $total_pages ?? 0;
$total_tickets = $total_tickets ?? $stats['total'];
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
                        <p class="text-sm text-gray-600 mt-1">ITarian Service Desk &mdash; Support ticket tracking and resolution</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if ($sd_connected): ?>
                            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium" data-testid="badge-connected"><i class="fas fa-link mr-1"></i>SD Linked</span>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars($sd_url); ?>/scp" target="_blank" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition" data-testid="link-open-sd">
                            <i class="fas fa-external-link-alt mr-2"></i>Open Service Desk
                        </a>
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
            <?php if ($sd_connected && !$api_available): ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 flex items-start gap-3" data-testid="notice-sd-link">
                    <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-medium text-blue-800">ITarian Service Desk is linked</p>
                        <p class="text-xs text-blue-600 mt-0.5">Your Service Desk is at <a href="<?php echo htmlspecialchars($sd_url); ?>/scp" target="_blank" class="underline font-medium"><?php echo htmlspecialchars($sd_url); ?></a>. Manage tickets directly in the Service Desk admin panel, or use the local ticket system below for portal-created tickets.</p>
                        <p class="text-xs text-blue-600 mt-1">To enable API sync, ensure the Service Desk REST API is enabled in <strong>Manage &rarr; API Keys</strong> in your SD admin panel.</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-total">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Total Tickets</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-open">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Open</p>
                    <p class="text-3xl font-bold text-blue-600"><?php echo $stats['open']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-closed">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Closed</p>
                    <p class="text-3xl font-bold text-green-600"><?php echo $stats['closed']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-unassigned">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Unassigned</p>
                    <p class="text-3xl font-bold text-yellow-600"><?php echo $stats['unassigned']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-6" data-testid="stat-overdue">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">High Priority</p>
                    <p class="text-3xl font-bold text-red-600"><?php echo $stats['overdue']; ?></p>
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
                        <p class="text-sm text-gray-600 mt-1">Tickets will appear here when created via the portal or synced from ITarian Service Desk</p>
                        <a href="<?php echo htmlspecialchars($sd_url); ?>/scp" target="_blank" class="inline-block mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                            <i class="fas fa-external-link-alt mr-2"></i>Open Service Desk
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket):
                        if ($api_available) {
                            $tid = $ticket['ticketId'] ?? $ticket['id'] ?? '';
                            $subject_text = $ticket['subject'] ?? $ticket['summary'] ?? 'No subject';
                            $client_name_display = $ticket['clientName'] ?? $ticket['name'] ?? ($ticket['email'] ?? '');
                            $client_email_display = $ticket['email'] ?? '';
                            $status = strtolower($ticket['status'] ?? 'open');
                            $priority_text = strtolower($ticket['priority'] ?? 'medium');
                            $created_at_str = $ticket['created'] ?? $ticket['createdDate'] ?? '';
                            $assigned_text = $ticket['assigned'] ?? $ticket['assignedTo'] ?? '';
                        } else {
                            $tid = $ticket['id'];
                            $subject_text = $ticket['subject'];
                            $client_name_display = $ticket['client_name'] ?? 'Unknown';
                            $client_email_display = $ticket['client_email'] ?? '';
                            $status = $ticket['status'] ?? 'open';
                            $priority_text = $ticket['priority'] ?? 'medium';
                            $created_at_str = $ticket['created_at'] ?? '';
                            $assigned_text = $ticket['assigned_to'] ?? '';
                        }

                        $time_display = '';
                        if ($created_at_str) {
                            $ts = strtotime($created_at_str);
                            if ($ts) {
                                $ago = time() - $ts;
                                $days = floor($ago / 86400);
                                $hours = floor($ago / 3600);
                                $time_display = $days > 0 ? $days . 'd ago' : $hours . 'h ago';
                            } else {
                                $time_display = $created_at_str;
                            }
                        }
                    ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition" data-testid="ticket-row-<?php echo htmlspecialchars($tid); ?>">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="px-3 py-1 text-xs font-medium rounded-full <?php
                                            echo match($priority_text) {
                                                'high', 'urgent', 'critical' => 'bg-red-100 text-red-700',
                                                'medium', 'normal' => 'bg-yellow-100 text-yellow-700',
                                                'low' => 'bg-green-100 text-green-700',
                                                default => 'bg-gray-100 text-gray-700'
                                            };
                                        ?>">
                                            <i class="fas fa-exclamation-circle mr-1"></i><?php echo ucfirst($priority_text); ?>
                                        </span>
                                        <span class="px-3 py-1 text-xs font-medium rounded-full <?php
                                            echo match($status) {
                                                'open', 'new' => 'bg-blue-100 text-blue-700',
                                                'in_progress' => 'bg-yellow-100 text-yellow-700',
                                                'closed', 'resolved' => 'bg-green-100 text-green-700',
                                                default => 'bg-gray-100 text-gray-700'
                                            };
                                        ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $status)); ?>
                                        </span>
                                        <?php if ($time_display): ?>
                                        <span class="text-xs text-gray-500"><i class="far fa-clock mr-1"></i><?php echo htmlspecialchars($time_display); ?></span>
                                        <?php endif; ?>
                                        <span class="text-xs text-gray-500">#<?php echo htmlspecialchars($tid); ?></span>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars($subject_text); ?></h3>
                                    <div class="flex items-center gap-4 text-sm text-gray-600">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-circle mr-2 text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($client_name_display ?: 'Unknown'); ?></span>
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
                                    <a href="admin-ticket-detail.php?id=<?php echo urlencode($tid); ?><?php echo $api_available ? '&source=sd' : ''; ?>"
                                       class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition text-center" data-testid="button-view-<?php echo htmlspecialchars($tid); ?>">
                                        <i class="fas fa-eye mr-1"></i>View
                                    </a>
                                    <?php if ($status !== 'closed' && $status !== 'resolved'): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Close this ticket?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="<?php echo $api_available ? 'close_ticket' : 'update_status'; ?>">
                                            <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($tid); ?>">
                                            <?php if (!$api_available): ?><input type="hidden" name="status" value="closed"><?php endif; ?>
                                            <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium transition w-full" data-testid="button-close-<?php echo htmlspecialchars($tid); ?>">
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

            <?php if (!$api_available && ($total_pages ?? 0) > 1): ?>
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
</body>
</html>
