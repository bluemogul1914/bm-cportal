<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

$success_msg = '';
$error_msg = '';

$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_ticket') {
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $category = $_POST['category'] ?? 'general';

    if (empty($subject)) {
        $error_msg = 'Subject is required.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            $client_id = $client ? $client['id'] : $user_id;

            $stmt = $pdo->prepare("INSERT INTO tickets (client_id, subject, description, status, priority, source, created_at, updated_at) VALUES (?, ?, ?, 'open', ?, 'portal', NOW(), NOW())");
            $stmt->execute([$client_id, $subject, $description, $priority]);
            $new_ticket_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'ticket_created', 'ticket', $new_ticket_id, 'Created ticket: ' . $subject, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_msg = 'Ticket created successfully!';
        } catch (PDOException $e) {
            error_log("Ticket creation error: " . $e->getMessage());
            $error_msg = 'Failed to create ticket. Please try again.';
        }
    }
}

$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    $client_id = $client ? $client['id'] : $user_id;

    $where = "WHERE t.client_id = ?";
    $params = [$client_id];

    if ($status_filter !== 'all') {
        $where .= " AND t.status = ?";
        $params[] = $status_filter;
    }

    if (!empty($search)) {
        $where .= " AND (t.subject ILIKE ? OR t.description ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $stmt = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = t.id AND is_internal = false) as comment_count FROM tickets t $where ORDER BY t.created_at DESC");
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM tickets WHERE client_id = ? GROUP BY status");
    $stmt->execute([$client_id]);
    $status_counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status_counts[$row['status']] = $row['count'];
    }
    $total_tickets = array_sum($status_counts);
} catch (PDOException $e) {
    error_log("Tickets fetch error: " . $e->getMessage());
    $tickets = [];
    $status_counts = [];
    $total_tickets = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - Blue Mogul Client Portal</title>
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
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Support Tickets</h1>
                    <p class="text-sm text-gray-600 mt-1">Create and manage your support requests</p>
                </div>
                <button onclick="document.getElementById('create-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition" data-testid="button-create-ticket">
                    <i class="fas fa-plus mr-2"></i>New Ticket
                </button>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-success">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md mb-6 flex items-center" data-testid="alert-error">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <a href="tickets.php" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'all' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-all">
                    <p class="text-xs font-semibold text-gray-500 uppercase">All Tickets</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $total_tickets; ?></p>
                </a>
                <a href="tickets.php?status=open" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'open' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-open">
                    <p class="text-xs font-semibold text-gray-500 uppercase">Open</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo $status_counts['open'] ?? 0; ?></p>
                </a>
                <a href="tickets.php?status=in_progress" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'in_progress' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-in-progress">
                    <p class="text-xs font-semibold text-gray-500 uppercase">In Progress</p>
                    <p class="text-2xl font-bold text-yellow-600 mt-1"><?php echo $status_counts['in_progress'] ?? 0; ?></p>
                </a>
                <a href="tickets.php?status=closed" class="bg-white rounded-lg border border-gray-200 p-4 hover:border-blue-300 transition <?php echo $status_filter === 'closed' ? 'ring-2 ring-blue-500' : ''; ?>" data-testid="filter-closed">
                    <p class="text-xs font-semibold text-gray-500 uppercase">Closed</p>
                    <p class="text-2xl font-bold text-green-600 mt-1"><?php echo $status_counts['closed'] ?? 0; ?></p>
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <form method="GET" action="tickets.php" class="flex items-center gap-3">
                        <?php if ($status_filter !== 'all'): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <?php endif; ?>
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search tickets..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-search">
                        </div>
                        <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm font-medium transition" data-testid="button-search">Search</button>
                    </form>
                </div>

                <?php if (empty($tickets)): ?>
                    <div class="text-center py-16">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                            <i class="fas fa-ticket-alt text-gray-400 text-2xl"></i>
                        </div>
                        <p class="text-gray-900 font-semibold mb-1">No tickets found</p>
                        <p class="text-sm text-gray-500 mb-4">Create a support ticket to get help</p>
                        <button onclick="document.getElementById('create-modal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md font-medium text-sm transition">
                            <i class="fas fa-plus mr-2"></i>Create Ticket
                        </button>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($tickets as $ticket): ?>
                            <a href="ticket-detail.php?id=<?php echo $ticket['id']; ?>" class="flex items-center px-6 py-4 hover:bg-gray-50 transition group" data-testid="ticket-row-<?php echo $ticket['id']; ?>">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3 mb-1">
                                        <span class="text-xs font-mono text-gray-400">#<?php echo str_pad($ticket['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                        <h3 class="font-medium text-gray-900 truncate group-hover:text-blue-600 transition"><?php echo htmlspecialchars($ticket['subject']); ?></h3>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs text-gray-500">
                                        <span><i class="far fa-clock mr-1"></i><?php echo date('M d, Y g:i A', strtotime($ticket['created_at'])); ?></span>
                                        <?php if ($ticket['comment_count'] > 0): ?>
                                            <span><i class="far fa-comment mr-1"></i><?php echo $ticket['comment_count']; ?> replies</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 ml-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                        echo match($ticket['priority'] ?? 'medium') {
                                            'high', 'urgent' => 'bg-red-100 text-red-700',
                                            'medium' => 'bg-yellow-100 text-yellow-700',
                                            'low' => 'bg-green-100 text-green-700',
                                            default => 'bg-gray-100 text-gray-700'
                                        };
                                    ?>"><?php echo ucfirst($ticket['priority'] ?? 'Medium'); ?></span>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                        echo match($ticket['status']) {
                                            'open' => 'bg-blue-100 text-blue-700',
                                            'in_progress' => 'bg-yellow-100 text-yellow-700',
                                            'closed' => 'bg-gray-100 text-gray-700',
                                            default => 'bg-gray-100 text-gray-700'
                                        };
                                    ?>"><?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?></span>
                                    <i class="fas fa-chevron-right text-gray-300 group-hover:text-blue-500 transition"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="create-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Create New Ticket</h2>
            <button onclick="document.getElementById('create-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600" data-testid="button-close-modal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="tickets.php" class="p-6 space-y-4">
            <input type="hidden" name="action" value="create_ticket">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Subject *</label>
                <input type="text" name="subject" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Brief description of your issue" data-testid="input-subject">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="select-priority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="5" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Describe your issue in detail..." data-testid="textarea-description"></textarea>
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