<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_id = $_SESSION['user_id'];
$pdo = getDB();

$success_msg = '';
$error_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    
    $msg_id = intval($_POST['message_id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([$msg_id]);
        $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, 'message_deleted', 'message', $msg_id, 'Deleted message #' . $msg_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
        $success_msg = 'Message deleted.';
    } catch (PDOException $e) {
        $error_msg = 'Failed to delete message.';
    }
}

$filter_cat = $_GET['category'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];
if ($filter_cat !== 'all') { $where[] = "m.category = ?"; $params[] = $filter_cat; }
if ($filter_status !== 'all') { $where[] = "m.status = ?"; $params[] = $filter_status; }
if (!empty($search)) { $where[] = "(m.subject ILIKE ? OR m.body ILIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM messages m $where_sql");
    $count_stmt->execute($params);
    $total = $count_stmt->fetchColumn();
} catch (PDOException $e) { $total = 0; }

$per_page = 20;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;
$total_pages = max(1, ceil($total / $per_page));

try {
    $stmt = $pdo->prepare("SELECT m.*, u.name as sender_name FROM messages m LEFT JOIN users u ON m.sent_by = u.id $where_sql ORDER BY m.created_at DESC LIMIT ? OFFSET ?");
    $all_params = array_merge($params, [$per_page, $offset]);
    $stmt->execute($all_params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $messages = [];
}

$categories = [
    'news_blast' => ['label' => 'News Blast', 'color' => 'blue', 'icon' => 'fa-newspaper'],
    'alert' => ['label' => 'Alert', 'color' => 'red', 'icon' => 'fa-exclamation-triangle'],
    'promotion' => ['label' => 'Promotion', 'color' => 'green', 'icon' => 'fa-tag'],
    'network_outage' => ['label' => 'Network Outage', 'color' => 'orange', 'icon' => 'fa-wifi'],
    'maintenance' => ['label' => 'Maintenance', 'color' => 'yellow', 'icon' => 'fa-wrench'],
    'general' => ['label' => 'General', 'color' => 'gray', 'icon' => 'fa-envelope'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messaging - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1a56db',
                        secondary: '#0d1b3e',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans min-h-screen flex">
    <?php include 'includes/admin-sidebar.php'; ?>

    <div class="flex-1 overflow-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900" data-testid="text-page-title">Messaging</h1>
                    <p class="text-gray-500 mt-1">Send news blasts, alerts, promotions, and outage notifications to clients</p>
                </div>
                <div class="flex space-x-3">
                    <a href="admin-message-templates.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium" data-testid="link-templates">
                        <i class="fas fa-file-alt mr-2"></i>Templates
                    </a>
                    <a href="admin-message-compose.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition font-medium" data-testid="link-new-message">
                        <i class="fas fa-plus mr-2"></i>New Message
                    </a>
                </div>
            </div>

            <?php if (!empty($success_msg)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm text-green-800"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-800"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="p-4 border-b border-gray-200">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search messages..."
                                   data-testid="input-search"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
                            <select name="category" data-testid="select-category" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $key => $cat): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filter_cat === $key ? 'selected' : ''; ?>><?php echo $cat['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                            <select name="status" data-testid="select-status" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500">
                                <option value="all">All Statuses</option>
                                <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                                <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            </select>
                        </div>
                        <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium transition" data-testid="button-filter">
                            <i class="fas fa-filter mr-1"></i>Filter
                        </button>
                    </form>
                </div>

                <?php if (empty($messages) && empty($search) && $filter_cat === 'all' && $filter_status === 'all'): ?>
                    <div class="py-20 text-center">
                        <div class="mx-auto w-24 h-24 mb-6 text-gray-300">
                            <i class="fas fa-envelope-open-text text-6xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">Your first message yet to be created...</h3>
                        <p class="text-gray-500 mb-6 max-w-md mx-auto">Planned outage? Changes in pricing? Address specific groups of clients and message them all at once.</p>
                        <a href="admin-message-compose.php" class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition" data-testid="link-new-message-empty">
                            <i class="fas fa-plus mr-2"></i>New Message
                        </a>
                    </div>
                <?php elseif (empty($messages)): ?>
                    <div class="py-12 text-center text-gray-500">
                        <i class="fas fa-search text-3xl mb-3 text-gray-300"></i>
                        <p>No messages found matching your filters.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full" data-testid="table-messages">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subject</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recipients</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sent By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($messages as $msg): ?>
                                    <?php $cat = $categories[$msg['category']] ?? $categories['general']; ?>
                                    <tr class="hover:bg-gray-50" data-testid="row-message-<?php echo $msg['id']; ?>">
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-<?php echo $cat['color']; ?>-100 text-<?php echo $cat['color']; ?>-800">
                                                <i class="fas <?php echo $cat['icon']; ?> mr-1.5"></i>
                                                <?php echo $cat['label']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($msg['subject']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            <?php if ($msg['recipient_type'] === 'all'): ?>
                                                <span class="text-blue-600 font-medium">All Clients</span>
                                            <?php else: ?>
                                                <span><?php echo htmlspecialchars($msg['recipient_type']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($msg['sent_count'] > 0): ?>
                                                <span class="text-xs text-gray-400 ml-1">(<?php echo $msg['sent_count']; ?> sent<?php echo $msg['failed_count'] > 0 ? ', ' . $msg['failed_count'] . ' failed' : ''; ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($msg['sender_name'] ?? 'System'); ?></td>
                                        <td class="px-6 py-4">
                                            <?php if ($msg['status'] === 'sent'): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Sent</span>
                                            <?php elseif ($msg['status'] === 'draft'): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"><i class="fas fa-pencil-alt mr-1"></i>Draft</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i>Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo $msg['sent_at'] ? date('M j, Y g:ia', strtotime($msg['sent_at'])) : date('M j, Y', strtotime($msg['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex items-center justify-end space-x-2">
                                                <?php if ($msg['status'] === 'draft'): ?>
                                                    <a href="admin-message-compose.php?edit=<?php echo $msg['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Delete this message?');">
                            <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                    <button type="submit" class="text-red-500 hover:text-red-700 text-sm" title="Delete" data-testid="button-delete-<?php echo $msg['id']; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                            <span class="text-sm text-gray-500">Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total); ?> of <?php echo $total; ?> messages</span>
                            <div class="flex space-x-1">
                                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                    <a href="?page=<?php echo $p; ?>&category=<?php echo urlencode($filter_cat); ?>&status=<?php echo urlencode($filter_status); ?>&search=<?php echo urlencode($search); ?>"
                                       class="px-3 py-1 text-sm rounded <?php echo $p === $page ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
