<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

$date_range = $_GET['range'] ?? '30';
$export = $_GET['export'] ?? '';

try {
    $pdo = getDB();

    $revenue_stmt = $pdo->query("
        SELECT 
            TO_CHAR(paid_date, 'YYYY-MM') as month,
            SUM(amount) as revenue,
            COUNT(*) as invoice_count
        FROM invoices
        WHERE status = 'paid' AND paid_date >= CURRENT_DATE - INTERVAL '12 months'
        GROUP BY TO_CHAR(paid_date, 'YYYY-MM')
        ORDER BY month ASC
    ");
    $revenue_by_month = $revenue_stmt->fetchAll(PDO::FETCH_ASSOC);

    $client_growth_stmt = $pdo->query("
        SELECT 
            TO_CHAR(created_at, 'YYYY-MM') as month,
            COUNT(*) as new_clients
        FROM clients
        WHERE created_at >= CURRENT_DATE - INTERVAL '12 months'
        GROUP BY TO_CHAR(created_at, 'YYYY-MM')
        ORDER BY month ASC
    ");
    $client_growth = $client_growth_stmt->fetchAll(PDO::FETCH_ASSOC);

    $product_perf_stmt = $pdo->query("
        SELECT 
            p.name,
            p.category,
            COUNT(s.id) as subscribers,
            (p.price * COUNT(s.id)) as monthly_revenue
        FROM products p
        LEFT JOIN subscriptions s ON p.id = s.product_id AND s.status = 'active'
        WHERE p.active = true
        GROUP BY p.id, p.name, p.category, p.price
        ORDER BY monthly_revenue DESC
        LIMIT 10
    ");
    $product_performance = $product_perf_stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM clients WHERE status = 'active') as total_clients,
            (SELECT COALESCE(SUM(p.price), 0) 
             FROM subscriptions s 
             JOIN products p ON s.product_id = p.id 
             WHERE s.status = 'active' AND p.billing_period = 'monthly') as mrr,
            (SELECT COUNT(*) FROM tickets WHERE status != 'closed') as open_tickets,
            (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'unpaid') as outstanding
    ");
    $overall_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Reports page error: " . $e->getMessage());
    $revenue_by_month = [];
    $client_growth = [];
    $product_performance = [];
    $overall_stats = ['total_clients' => 0, 'mrr' => 0, 'open_tickets' => 0, 'outstanding' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Blue Mogul Admin</title>

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
                            <h1 class="text-2xl font-semibold text-gray-900">Reports & Analytics</h1>
                            <p class="text-sm text-gray-600 mt-1">Business intelligence and insights</p>
                        </div>
                        <div class="flex space-x-3">
                            <select onchange="window.location.href='?range='+this.value" class="px-4 py-2 border border-gray-300 rounded-md text-sm">
                                <option value="7" <?php echo $date_range == '7' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="30" <?php echo $date_range == '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="90" <?php echo $date_range == '90' ? 'selected' : ''; ?>>Last 90 Days</option>
                                <option value="365" <?php echo $date_range == '365' ? 'selected' : ''; ?>>Last Year</option>
                            </select>
                            <button onclick="window.print()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium transition">
                                <i class="fas fa-print mr-2"></i>Print
                            </button>
                            <button onclick="exportReport()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                                <i class="fas fa-download mr-2"></i>Export PDF
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-lg p-6">
                        <p class="text-sm opacity-90 mb-1">Monthly Recurring Revenue</p>
                        <p class="text-3xl font-bold">$<?php echo number_format($overall_stats['mrr'], 2); ?></p>
                        <p class="text-sm opacity-75 mt-2">ARR: $<?php echo number_format($overall_stats['mrr'] * 12, 2); ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-green-500 to-green-600 text-white rounded-lg p-6">
                        <p class="text-sm opacity-90 mb-1">Active Clients</p>
                        <p class="text-3xl font-bold"><?php echo $overall_stats['total_clients']; ?></p>
                        <p class="text-sm opacity-75 mt-2">Growing</p>
                    </div>
                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 text-white rounded-lg p-6">
                        <p class="text-sm opacity-90 mb-1">Open Tickets</p>
                        <p class="text-3xl font-bold"><?php echo $overall_stats['open_tickets']; ?></p>
                        <p class="text-sm opacity-75 mt-2">Support Queue</p>
                    </div>
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-lg p-6">
                        <p class="text-sm opacity-90 mb-1">Outstanding</p>
                        <p class="text-3xl font-bold">$<?php echo number_format($overall_stats['outstanding'], 2); ?></p>
                        <p class="text-sm opacity-75 mt-2">Unpaid Invoices</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Revenue Trend (Last 12 Months)</h2>
                        <div style="height: 300px;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Client Growth (Last 12 Months)</h2>
                        <div style="height: 300px;">
                            <canvas id="clientGrowthChart"></canvas>
                        </div>
                    </div>

                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Top Products by Revenue</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Category</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Subscribers</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Monthly Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($product_performance)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                            <p class="font-medium">No product data yet</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($product_performance as $index => $product): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="text-2xl font-bold text-gray-400">#<?php echo $index + 1; ?></span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="font-medium text-gray-900"><?php echo htmlspecialchars($product['name']); ?></span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="text-sm text-gray-600"><?php echo htmlspecialchars($product['category']); ?></span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="font-semibold text-gray-900"><?php echo $product['subscribers']; ?></span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="text-lg font-bold text-green-600">$<?php echo number_format($product['monthly_revenue'], 2); ?></span>
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
        const revenueLabels = revenueData.map(item => {
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
        });
        const revenueValues = revenueData.map(item => parseFloat(item.revenue || 0));

        new Chart(document.getElementById('revenueChart'), {
            type: 'line',
            data: {
                labels: revenueLabels,
                datasets: [{
                    label: 'Revenue',
                    data: revenueValues,
                    borderColor: '#1a56db',
                    backgroundColor: 'rgba(26, 86, 219, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
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

        const clientData = <?php echo json_encode($client_growth); ?>;
        const clientLabels = clientData.map(item => {
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
        });
        const clientValues = clientData.map(item => parseInt(item.new_clients || 0));

        new Chart(document.getElementById('clientGrowthChart'), {
            type: 'bar',
            data: {
                labels: clientLabels,
                datasets: [{
                    label: 'New Clients',
                    data: clientValues,
                    backgroundColor: '#10b981',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        function exportReport() {
            window.location.href = '/api/export-report.php?range=<?php echo $date_range; ?>';
        }
    </script>

</body>
</html>