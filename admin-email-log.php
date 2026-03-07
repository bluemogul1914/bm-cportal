<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$pdo = getDB();

$status_filter = $_GET['status'] ?? 'all';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, intval($_GET['page'] ?? 1));
$per_page      = 50;
$offset        = ($page - 1) * $per_page;

$where  = [];
$params = [];

if ($status_filter !== 'all') {
    $where[]  = 'status = ?';
    $params[] = $status_filter;
}
if ($search !== '') {
    $where[]  = '(recipient ILIKE ? OR subject ILIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_params = $params;
$total = (int)$pdo->prepare("SELECT COUNT(*) FROM email_log $where_sql")->execute($count_params) ? 0 : 0;
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM email_log $where_sql");
$cnt_stmt->execute($count_params);
$total = (int)$cnt_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$list_params   = array_merge($params, [$per_page, $offset]);
$list_stmt     = $pdo->prepare("SELECT * FROM email_log $where_sql ORDER BY sent_at DESC LIMIT ? OFFSET ?");
$list_stmt->execute($list_params);
$emails = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

$stats_sent   = (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE status = 'sent'")->fetchColumn();
$stats_failed = (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE status = 'failed'")->fetchColumn();
$stats_today  = (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE sent_at >= CURRENT_DATE")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Log - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">

        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Email Log</h1>
                    <p class="text-sm text-gray-600 mt-1">Audit trail of all outbound emails sent by the portal</p>
                </div>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-green-100 rounded-lg p-2.5"><i class="fas fa-check-circle text-green-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Sent Successfully</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-sent-count"><?php echo number_format($stats_sent); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-red-100 rounded-lg p-2.5"><i class="fas fa-times-circle text-red-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Failed</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-failed-count"><?php echo number_format($stats_failed); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-blue-100 rounded-lg p-2.5"><i class="fas fa-paper-plane text-blue-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Sent Today</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-today-count"><?php echo number_format($stats_today); ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center gap-3">
                    <form method="GET" class="flex-1 min-w-[200px]">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search recipient or subject..." class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" data-testid="input-search">
                            <?php if ($status_filter !== 'all'): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
                        </div>
                    </form>
                    <div class="flex gap-2 flex-wrap">
                        <?php foreach (['all' => 'All', 'sent' => 'Sent', 'failed' => 'Failed'] as $sv => $sl): ?>
                        <a href="admin-email-log.php?status=<?php echo $sv; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
                           class="px-3 py-1.5 rounded-md text-xs font-medium transition <?php echo $status_filter === $sv ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:bg-gray-100'; ?>"
                           data-testid="filter-<?php echo $sv; ?>"><?php echo $sl; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (empty($emails)): ?>
                <div class="text-center py-16">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                        <i class="fas fa-paper-plane text-gray-400 text-2xl"></i>
                    </div>
                    <p class="text-gray-900 font-semibold">No emails found</p>
                    <p class="text-sm text-gray-500 mt-1">Emails will appear here once sent through the portal</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-left">
                                <th class="px-5 py-3 font-semibold text-xs text-gray-500 uppercase tracking-wide">Date / Time</th>
                                <th class="px-5 py-3 font-semibold text-xs text-gray-500 uppercase tracking-wide">Recipient</th>
                                <th class="px-5 py-3 font-semibold text-xs text-gray-500 uppercase tracking-wide">Subject</th>
                                <th class="px-5 py-3 font-semibold text-xs text-gray-500 uppercase tracking-wide">Status</th>
                                <th class="px-5 py-3 font-semibold text-xs text-gray-500 uppercase tracking-wide">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($emails as $row): ?>
                            <tr class="hover:bg-gray-50 transition" data-testid="row-email-<?php echo $row['id']; ?>">
                                <td class="px-5 py-3 text-gray-500 whitespace-nowrap text-xs">
                                    <?php echo date('M d, Y', strtotime($row['sent_at'])); ?><br>
                                    <span class="text-gray-400"><?php echo date('g:i A', strtotime($row['sent_at'])); ?></span>
                                </td>
                                <td class="px-5 py-3 text-gray-900 font-medium max-w-[200px] truncate" title="<?php echo htmlspecialchars($row['recipient']); ?>" data-testid="text-recipient-<?php echo $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['recipient']); ?>
                                </td>
                                <td class="px-5 py-3 text-gray-700 max-w-[280px] truncate" title="<?php echo htmlspecialchars($row['subject']); ?>" data-testid="text-subject-<?php echo $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['subject']); ?>
                                </td>
                                <td class="px-5 py-3">
                                    <?php if ($row['status'] === 'sent'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700" data-testid="badge-status-<?php echo $row['id']; ?>">
                                        <i class="fas fa-check-circle text-[10px]"></i> Sent
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700" data-testid="badge-status-<?php echo $row['id']; ?>">
                                        <i class="fas fa-times-circle text-[10px]"></i> Failed
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 text-xs text-gray-400 max-w-[200px] truncate" title="<?php echo htmlspecialchars($row['error_message'] ?? ''); ?>">
                                    <?php echo $row['error_message'] ? htmlspecialchars($row['error_message']) : '<span class="text-green-500">—</span>'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between text-sm">
                    <span class="text-gray-500">Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $per_page, $total); ?> of <?php echo number_format($total); ?> emails</span>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition text-xs" data-testid="button-prev-page">Prev</a>
                        <?php endif; ?>
                        <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition text-xs" data-testid="button-next-page">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
