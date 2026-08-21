<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_paid') {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("UPDATE invoices SET status='paid', paid_date=CURRENT_DATE WHERE id=?");
            $stmt->execute([$_POST['invoice_id']]);
            $success_message = "Invoice marked as paid!";
        } catch (PDOException $e) {
            $error_message = "Error updating invoice: " . $e->getMessage();
        }
    } elseif ($action === 'send_reminder') {
        $success_message = "Payment reminder sent!";
    }
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';

try {
    $pdo = getDB();

    $where_clauses = [];
    $params = [];

    if ($search) {
        $where_clauses[] = "(CAST(i.id AS TEXT) = ? OR c.name ILIKE ? OR c.email ILIKE ?)";
        $params[] = $search;
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if ($status_filter) {
        $where_clauses[] = "i.status = ?";
        $params[] = $status_filter;
    }

    if ($date_filter) {
        switch ($date_filter) {
            case 'today':
                $where_clauses[] = "DATE(i.created_at) = CURRENT_DATE";
                break;
            case 'week':
                $where_clauses[] = "i.created_at >= CURRENT_DATE - INTERVAL '7 days'";
                break;
            case 'month':
                $where_clauses[] = "EXTRACT(MONTH FROM i.created_at) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM i.created_at) = EXTRACT(YEAR FROM CURRENT_DATE)";
                break;
            case 'overdue':
                $where_clauses[] = "i.status = 'unpaid' AND i.due_date < CURRENT_DATE";
                break;
        }
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM invoices i 
        LEFT JOIN clients c ON i.client_id = c.id 
        $where_sql
    ");
    $count_stmt->execute($params);
    $total_invoices = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $total_pages = ceil($total_invoices / $limit);

    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT i.*, c.name as client_name, c.email as client_email
        FROM invoices i
        LEFT JOIN clients c ON i.client_id = c.id
        $where_sql
        ORDER BY i.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($query_params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
            COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) as unpaid_total,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_total,
            SUM(CASE WHEN status = 'unpaid' AND due_date < CURRENT_DATE THEN 1 ELSE 0 END) as overdue_count,
            COALESCE(SUM(CASE WHEN status = 'unpaid' AND due_date < CURRENT_DATE THEN amount ELSE 0 END), 0) as overdue_total
        FROM invoices
    ");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $revenue_stmt = $pdo->query("
        SELECT 
            TO_CHAR(paid_date, 'YYYY-MM') as month,
            SUM(amount) as revenue
        FROM invoices
        WHERE status = 'paid' AND paid_date >= CURRENT_DATE - INTERVAL '12 months'
        GROUP BY TO_CHAR(paid_date, 'YYYY-MM')
        ORDER BY month DESC
    ");
    $revenue_by_month = $revenue_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Invoices page error: " . $e->getMessage());
    $invoices = [];
    $total_invoices = 0;
    $total_pages = 0;
    $stats = [
        'total' => 0, 'unpaid_count' => 0, 'unpaid_total' => 0,
        'paid_count' => 0, 'paid_total' => 0, 'overdue_count' => 0, 'overdue_total' => 0
    ];
    $revenue_by_month = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Management - Blue Mogul Admin</title>

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
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900">Invoice Management</h1>
                            <p class="text-sm text-gray-600 mt-1">Track payments and billing</p>
                        </div>
                        <div class="flex space-x-3">
                            <button onclick="location.href='admin-invoice-add.php'" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                                <i class="fas fa-plus mr-2"></i>Create Invoice
                            </button>
                            <button onclick="exportInvoices()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium transition">
                                <i class="fas fa-download mr-2"></i>Export
                            </button>
                        </div>
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

                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Total Revenue</p>
                        <p class="text-3xl font-bold text-green-600">$<?php echo number_format($stats['paid_total'], 2); ?></p>
                        <p class="text-sm text-gray-600 mt-1"><?php echo $stats['paid_count']; ?> paid invoices</p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Outstanding</p>
                        <p class="text-3xl font-bold text-yellow-600">$<?php echo number_format($stats['unpaid_total'], 2); ?></p>
                        <p class="text-sm text-gray-600 mt-1"><?php echo $stats['unpaid_count']; ?> unpaid</p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Overdue</p>
                        <p class="text-3xl font-bold text-red-600">$<?php echo number_format($stats['overdue_total'], 2); ?></p>
                        <p class="text-sm text-gray-600 mt-1"><?php echo $stats['overdue_count']; ?> overdue</p>
                    </div>
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Total Invoices</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                        <p class="text-sm text-gray-600 mt-1">All time</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4">
                        <div class="flex-1 min-w-[200px]">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search invoices or clients..."
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                        </div>
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-md">
                            <option value="">All Status</option>
                            <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                        </select>
                        <select name="date" class="px-4 py-2 border border-gray-300 rounded-md">
                            <option value="">All Time</option>
                            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="overdue" <?php echo $date_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <?php if ($search || $status_filter || $date_filter): ?>
                            <a href="admin-invoices.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md font-medium">
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
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Invoice #</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Client</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Issue Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Due Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($invoices)): ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                            <i class="fas fa-file-invoice text-4xl mb-3 text-gray-400"></i>
                                            <p class="font-medium">No invoices found</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($invoices as $invoice): ?>
                                        <?php
                                            $is_overdue = $invoice['status'] === 'unpaid' && $invoice['due_date'] && strtotime($invoice['due_date']) < time();
                                            $days_until_due = $invoice['due_date'] ? ceil((strtotime($invoice['due_date']) - time()) / 86400) : 0;
                                        ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="font-semibold text-gray-900">#<?php echo str_pad($invoice['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div>
                                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($invoice['client_name'] ?? 'Unknown'); ?></p>
                                                    <p class="text-xs text-gray-600"><?php echo htmlspecialchars($invoice['client_email'] ?? ''); ?></p>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="text-lg font-bold text-gray-900">$<?php echo number_format($invoice['amount'], 2); ?></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                <?php echo date('M d, Y', strtotime($invoice['created_at'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div>
                                                    <p class="text-sm text-gray-900"><?php echo $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : 'N/A'; ?></p>
                                                    <?php if ($invoice['status'] === 'unpaid' && $invoice['due_date']): ?>
                                                        <?php if ($is_overdue): ?>
                                                            <p class="text-xs text-red-600 font-semibold"><?php echo abs($days_until_due); ?> days overdue</p>
                                                        <?php else: ?>
                                                            <p class="text-xs text-gray-600">Due in <?php echo $days_until_due; ?> days</p>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-3 py-1 text-xs font-medium rounded-full <?php
                                                    echo $invoice['status'] === 'paid' ? 'bg-green-100 text-green-700' :
                                                        ($is_overdue ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700');
                                                ?>">
                                                    <?php echo $is_overdue ? 'Overdue' : ucfirst($invoice['status']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-2">
                                                <a href="admin-invoice-detail.php?id=<?php echo $invoice['id']; ?>" class="text-blue-600 hover:text-blue-700" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($invoice['status'] === 'unpaid'): ?>
                                                    <button onclick="markPaid(<?php echo $invoice['id']; ?>)" class="text-green-600 hover:text-green-700" title="Mark Paid">
                                                        <i class="fas fa-check-circle"></i>
                                                    </button>
                                                    <button onclick="sendReminder(<?php echo $invoice['id']; ?>)" class="text-orange-600 hover:text-orange-700" title="Send Reminder">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="#" class="text-gray-600 hover:text-gray-700" title="Download PDF">
                                                    <i class="fas fa-download"></i>
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
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_invoices); ?> of <?php echo $total_invoices; ?>
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>"
                                       class="px-3 py-2 <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-md text-sm"><?php echo $i; ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date=<?php echo urlencode($date_filter); ?>"
                                       class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
        function markPaid(id) {
            if (confirm('Mark this invoice as paid?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="invoice_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function sendReminder(id) {
            if (confirm('Send payment reminder to client?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="send_reminder">
                    <input type="hidden" name="invoice_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function exportInvoices() {
            window.location.href = '/api/export-invoices.php';
        }
    </script>

</body>
</html>