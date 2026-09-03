<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

try {
    $pdo = getDB();

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM clients WHERE status = 'active'");
    $total_clients = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->query("
        SELECT COALESCE(SUM(p.price), 0) as total 
        FROM subscriptions s 
        JOIN products p ON s.product_id = p.id 
        WHERE s.status = 'active' AND p.billing_period = 'monthly'
    ");
    $mrr = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $arr = $mrr * 12;

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tickets WHERE status != 'Closed'");
    $open_tickets = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM invoices WHERE status = 'unpaid'");
    $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $unpaid_invoices_count = $invoice_data['count'];
    $unpaid_invoices_total = $invoice_data['total'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM clients WHERE EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE)");
    $new_clients_month = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM subscriptions WHERE status = 'cancelled' AND EXTRACT(MONTH FROM updated_at) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM updated_at) = EXTRACT(YEAR FROM CURRENT_DATE)");
    $churned_month = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $churn_rate = $total_clients > 0 ? round(($churned_month / $total_clients) * 100, 1) : 0;

    $stmt = $pdo->query("
        SELECT 
            TO_CHAR(created_at, 'YYYY-MM') as month,
            COUNT(*) as count,
            SUM(amount) as revenue
        FROM invoices 
        WHERE created_at >= CURRENT_DATE - INTERVAL '12 months'
        GROUP BY TO_CHAR(created_at, 'YYYY-MM')
        ORDER BY month ASC
    ");
    $revenue_by_month = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            p.name,
            p.price,
            p.category,
            COUNT(s.id) as subscriber_count,
            (p.price * COUNT(s.id)) as total_revenue
        FROM products p
        LEFT JOIN subscriptions s ON p.id = s.product_id AND s.status = 'active'
        GROUP BY p.id, p.name, p.price, p.category
        ORDER BY total_revenue DESC
        LIMIT 10
    ");
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC LIMIT 10");
    $recent_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Marketing overview metrics (Phase 5 & 6)
    $mkt_active_sequences = 0;
    $mkt_sent_month = 0;
    $mkt_social_week = 0;
    $mkt_leads = 0;
    $mkt_blog_posts = 0;
    $mkt_reply_rate = 0;
    try {
        $r = $pdo->query("SELECT COUNT(DISTINCT sequence_name) FROM email_sequences")->fetchColumn();
        $mkt_active_sequences = intval($r);
        $r = $pdo->query("SELECT COUNT(*) FROM email_sequences WHERE sent_at >= date_trunc('month', CURRENT_DATE)")->fetchColumn();
        $mkt_sent_month = intval($r);
        $r = $pdo->query("SELECT COUNT(*) FROM social_posts WHERE posted_at >= CURRENT_DATE - INTERVAL '7 days'")->fetchColumn();
        $mkt_social_week = intval($r);
        $r = $pdo->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn();
        $mkt_blog_posts = intval($r);
        // Leads in pipeline = open leads (not won/lost) — matches admin-leads-dashboard
        $r = $pdo->query("SELECT COUNT(*) FROM crm_leads WHERE status NOT IN ('won','lost')")->fetchColumn();
        $mkt_leads = intval($r);
        // Reply rate from real engagement data (replied / sent sequences)
        $total_seq = intval($pdo->query("SELECT COUNT(*) FROM email_sequences")->fetchColumn());
        $replied = intval($pdo->query("SELECT COUNT(*) FROM email_sequences WHERE replied = true")->fetchColumn());
        $mkt_reply_rate = $total_seq > 0 ? round($replied / $total_seq * 100, 1) : 0;
    } catch (PDOException $e) {}

    // Revenue trend selector support: this month's revenue vs previous month (MoM)
    $revenue_mom = 0;
    $rc = count($revenue_by_month);
    if ($rc >= 2) {
        $last_rev = (float)($revenue_by_month[$rc - 1]['revenue'] ?? 0);
        $prev_rev = (float)($revenue_by_month[$rc - 2]['revenue'] ?? 0);
        if ($prev_rev > 0) $revenue_mom = round(($last_rev - $prev_rev) / $prev_rev * 100, 1);
    }

    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM clients 
        WHERE created_at >= CURRENT_DATE - INTERVAL '2 months' 
        AND created_at < CURRENT_DATE - INTERVAL '1 month'
    ");
    $prev_month_clients = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $growth_rate = $prev_month_clients > 0 ? round((($new_clients_month - $prev_month_clients) / $prev_month_clients) * 100, 1) : 0;

} catch (PDOException $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    $total_clients = 0;
    $mrr = 0;
    $arr = 0;
    $open_tickets = 0;
    $unpaid_invoices_count = 0;
    $unpaid_invoices_total = 0;
    $new_clients_month = 0;
    $churn_rate = 0;
    $growth_rate = 0;
    $revenue_mom = 0;
    $revenue_by_month = [];
    $top_products = [];
    $recent_clients = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Blue Mogul</title>

    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#1a56db',
                        secondary: '#0d1b3e'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
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
                            <h1 class="text-2xl font-semibold text-gray-900">Admin Dashboard</h1>
                            <p class="text-sm text-gray-600 mt-1">Overview of your business metrics</p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <button onclick="exportReport()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                                <i class="fas fa-download mr-2"></i>Export Report
                            </button>
                            <div class="relative">
                                <button onclick="toggleAdminProfile()" class="flex items-center space-x-2 text-gray-700 hover:bg-gray-100 rounded-md px-3 py-2 transition">
                                    <div class="bg-blue-600 text-white rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm">
                                        <?php echo htmlspecialchars(strtoupper(substr($user_name, 0, 1) ?: 'U')); ?>
                                    </div>
                                    <span class="text-sm font-medium"><?php echo htmlspecialchars($user_name); ?></span>
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </button>
                                <div id="admin-profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-20">
                                    <a href="dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <i class="fas fa-arrow-left w-4 mr-2"></i>Client View
                                    </a>
                                    <a href="admin-settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <i class="fas fa-cog w-4 mr-2"></i>Settings
                                    </a>
                                    <div class="border-t border-gray-200"></div>
                                    <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-50">
                                        <i class="fas fa-sign-out-alt w-4 mr-2"></i>Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-blue-100 rounded-lg p-3">
                                <i class="fas fa-dollar-sign text-blue-600 text-xl"></i>
                            </div>
                            <span class="text-xs font-semibold px-2 py-1 rounded-full <?php echo $revenue_mom >= 0 ? 'text-green-600 bg-green-100' : 'text-red-600 bg-red-100'; ?>">
                                <i class="fas fa-arrow-<?php echo $revenue_mom >= 0 ? 'up' : 'down'; ?> mr-1"></i><?php echo $revenue_mom; ?>% MoM
                            </span>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Monthly Recurring Revenue</p>
                        <p class="text-3xl font-bold text-gray-900">$<?php echo number_format($mrr, 2); ?></p>
                        <p class="text-sm text-gray-600 mt-2">ARR: $<?php echo number_format($arr, 2); ?></p>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-purple-100 rounded-lg p-3">
                                <i class="fas fa-users text-purple-600 text-xl"></i>
                            </div>
                            <span class="text-xs font-semibold text-gray-600 bg-gray-100 px-2 py-1 rounded-full">
                                +<?php echo $new_clients_month; ?> this month
                            </span>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total Clients</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $total_clients; ?></p>
                        <p class="text-sm text-gray-600 mt-2">Churn: <?php echo $churn_rate; ?>% &middot; Growth: <?php echo $growth_rate; ?>%</p>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-yellow-100 rounded-lg p-3">
                                <i class="fas fa-ticket-alt text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Open Tickets</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $open_tickets; ?></p>
                        <a href="admin-tickets.php" class="text-sm text-blue-600 hover:text-blue-700 mt-2 inline-block">View All &rarr;</a>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="bg-red-100 rounded-lg p-3">
                                <i class="fas fa-file-invoice-dollar text-red-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Unpaid Invoices</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $unpaid_invoices_count; ?></p>
                        <p class="text-sm text-gray-900 font-semibold mt-2">$<?php echo number_format($unpaid_invoices_total, 2); ?></p>
                    </div>

                </div>

                <!-- Marketing Overview (Phase 5 & 6) -->
                <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-semibold text-gray-900">Marketing Overview</h2>
                        <div class="flex gap-2">
                            <a href="/admin/marketing/campaigns" class="text-xs text-blue-600 hover:underline">Campaigns</a>
                            <span class="text-gray-300">|</span>
                            <a href="/admin/marketing/social" class="text-xs text-blue-600 hover:underline">Social</a>
                            <span class="text-gray-300">|</span>
                            <a href="/admin/marketing/blog" class="text-xs text-blue-600 hover:underline">Blog</a>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div class="text-center p-3 bg-blue-50 rounded-lg">
                            <p class="text-2xl font-bold text-blue-700"><?php echo $mkt_active_sequences; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Active Sequences</p>
                        </div>
                        <div class="text-center p-3 bg-purple-50 rounded-lg">
                            <p class="text-2xl font-bold text-purple-700"><?php echo number_format($mkt_sent_month); ?></p>
                            <p class="text-xs text-gray-500 mt-1">Sent This Month</p>
                        </div>
                        <div class="text-center p-3 bg-green-50 rounded-lg">
                            <p class="text-2xl font-bold text-green-700"><?php echo $mkt_social_week; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Social Posts/Week</p>
                        </div>
                        <div class="text-center p-3 bg-yellow-50 rounded-lg">
                            <p class="text-2xl font-bold text-yellow-700"><?php echo $mkt_leads; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Leads in Pipeline</p>
                        </div>
                        <div class="text-center p-3 bg-indigo-50 rounded-lg">
                            <p class="text-2xl font-bold text-indigo-700"><?php echo $mkt_blog_posts; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Blog Posts</p>
                        </div>
                        <div class="text-center p-3 bg-red-50 rounded-lg">
                            <p class="text-2xl font-bold text-red-600"><?php echo $mkt_reply_rate; ?>%</p>
                            <p class="text-xs text-gray-500 mt-1">Reply Rate</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-semibold text-gray-900">Revenue Trend</h2>
                            <select id="revenueRange" class="text-sm border border-gray-300 rounded-md px-3 py-1">
                                <option value="12">Last 12 Months</option>
                                <option value="6">Last 6 Months</option>
                                <option value="3">Last 3 Months</option>
                            </select>
                        </div>
                        <div style="height: 300px;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-semibold text-gray-900">Top Products by Revenue</h2>
                            <a href="admin-products.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All &rarr;</a>
                        </div>
                        <div class="space-y-4">
                            <?php if (empty($top_products)): ?>
                                <p class="text-sm text-gray-500 py-6 text-center">No products with subscriptions yet. <a href="admin-products.php" class="text-blue-600 hover:underline">Add products</a> to see revenue here.</p>
                            <?php else: ?>
                            <?php foreach ($top_products as $index => $product): ?>
                                <?php if ($index < 5): ?>
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3">
                                                <span class="text-lg font-bold text-gray-400">#<?php echo $index + 1; ?></span>
                                                <div>
                                                    <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($product['name']); ?></p>
                                                    <p class="text-xs text-gray-600"><?php echo (int)$product['subscriber_count']; ?> subscribers</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-gray-900">$<?php echo number_format((float)$product['total_revenue'], 2); ?></p>
                                            <p class="text-xs text-gray-600">/month</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900">Recent Clients</h2>
                            <a href="admin-clients.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">View All &rarr;</a>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Client</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Company</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Joined</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($recent_clients)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">No clients yet. <a href="admin-client-add.php" class="text-blue-600 hover:underline">Add your first client</a>.</td>
                                    </tr>
                                <?php else: ?>
                                <?php foreach ($recent_clients as $client): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="bg-blue-100 rounded-full h-8 w-8 flex items-center justify-center font-semibold text-sm text-blue-600 mr-3">
                                                    <?php echo htmlspecialchars(strtoupper(substr($client['name'] ?? '?', 0, 1))); ?>
                                                </div>
                                                <span class="font-medium text-gray-900"><?php echo htmlspecialchars($client['name'] ?? ''); ?></span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            <?php echo htmlspecialchars($client['email']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            <?php echo htmlspecialchars($client['company'] ?? 'N/A'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            <?php echo $client['created_at'] ? date('M d, Y', strtotime($client['created_at'])) : '—'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700">
                                                Active
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                            <a href="admin-client-detail.php?id=<?php echo $client['id']; ?>" class="text-blue-600 hover:text-blue-700 mr-3">View</a>
                                            <a href="admin-client-edit.php?id=<?php echo $client['id']; ?>" class="text-gray-600 hover:text-gray-700">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        const revenueData = <?php echo json_encode($revenue_by_month); ?>;
        const allMonths = revenueData.map(item => {
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
        });
        const allRevenues = revenueData.map(item => parseFloat(item.revenue || 0));
        let revenueChart = null;

        function renderRevenueChart(monthsCount) {
            const months = allMonths.slice(-monthsCount);
            const revenues = allRevenues.slice(-monthsCount);
            const ctx = document.getElementById('revenueChart').getContext('2d');
            if (revenueChart) revenueChart.destroy();
            revenueChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Revenue',
                        data: revenues,
                        borderColor: '#1a56db',
                        backgroundColor: 'rgba(26, 86, 219, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        renderRevenueChart(12);
        document.getElementById('revenueRange').addEventListener('change', function() {
            renderRevenueChart(parseInt(this.value, 10));
        });

        function csvEscape(value) {
            const s = String(value ?? '');
            return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        }

        function exportReport() {
            const rows = [
                ['Metric', 'Value'],
                ['MRR', <?php echo json_encode($mrr); ?>],
                ['ARR', <?php echo json_encode($arr); ?>],
                ['MRR MoM %', <?php echo json_encode($revenue_mom); ?>],
                ['Active Clients', <?php echo json_encode($total_clients); ?>],
                ['New Clients This Month', <?php echo json_encode($new_clients_month); ?>],
                ['Churn Rate %', <?php echo json_encode($churn_rate); ?>],
                ['Growth Rate %', <?php echo json_encode($growth_rate); ?>],
                ['Open Tickets', <?php echo json_encode($open_tickets); ?>],
                ['Unpaid Invoices', <?php echo json_encode($unpaid_invoices_count); ?>],
                ['Unpaid Total', <?php echo json_encode($unpaid_invoices_total); ?>],
                ['Leads in Pipeline', <?php echo json_encode($mkt_leads); ?>],
                [],
                ['Month', 'Revenue']
            ];
            revenueData.forEach(item => rows.push([item.month, item.revenue]));
            const csv = rows.map(r => r.map(csvEscape).join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            const d = new Date();
            a.href = URL.createObjectURL(blob);
            a.download = 'blue-mogul-dashboard-report-' + d.toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        }

        function toggleAdminProfile() {
            const dropdown = document.getElementById('admin-profile-dropdown');
            dropdown.classList.toggle('hidden');
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.relative')) {
                document.getElementById('admin-profile-dropdown')?.classList.add('hidden');
            }
        });
    </script>

</body>
</html>