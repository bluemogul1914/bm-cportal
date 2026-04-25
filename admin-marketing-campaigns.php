<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$pdo = getDB();

// Campaign analytics
$campaigns = [];
try {
    $stmt = $pdo->query("
        SELECT sequence_name,
               COUNT(*) AS total_sent,
               SUM(CASE WHEN opened THEN 1 ELSE 0 END) AS opened_count,
               SUM(CASE WHEN clicked THEN 1 ELSE 0 END) AS clicked_count,
               SUM(CASE WHEN replied THEN 1 ELSE 0 END) AS replied_count,
               SUM(CASE WHEN bounced THEN 1 ELSE 0 END) AS bounced_count
        FROM email_sequences
        GROUP BY sequence_name
        ORDER BY total_sent DESC
    ");
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

// Totals
$total_sent = array_sum(array_column($campaigns, 'total_sent'));
$total_opened = array_sum(array_column($campaigns, 'opened_count'));
$total_clicked = array_sum(array_column($campaigns, 'clicked_count'));
$overall_open_rate = $total_sent > 0 ? round($total_opened / $total_sent * 100, 1) : 0;
$overall_click_rate = $total_sent > 0 ? round($total_clicked / $total_sent * 100, 1) : 0;

// Recent sends (last 30)
$recent = [];
try {
    $stmt = $pdo->query("SELECT es.*, c.name AS client_name FROM email_sequences es LEFT JOIN clients c ON es.client_id = c.id ORDER BY es.sent_at DESC LIMIT 30");
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

include 'includes/admin-header.php';
?>
<div class="flex h-screen bg-gray-50">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include 'includes/admin-topbar.php'; ?>
        <main class="flex-1 overflow-y-auto p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Email Campaign Analytics</h1>
                <p class="text-gray-500 text-sm mt-1">Track email sequence performance across all campaigns</p>
            </div>

            <!-- Stats row -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Total Sent</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($total_sent); ?></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Opened</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo number_format($total_opened); ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $overall_open_rate; ?>% open rate</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Clicked</p>
                    <p class="text-2xl font-bold text-green-600 mt-1"><?php echo number_format($total_clicked); ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $overall_click_rate; ?>% click rate</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Active Sequences</p>
                    <p class="text-2xl font-bold text-purple-600 mt-1"><?php echo count($campaigns); ?></p>
                </div>
            </div>

            <!-- Campaign table -->
            <div class="bg-white rounded-xl border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-800">Sequence Performance</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="px-6 py-3 text-left">Sequence</th>
                                <th class="px-6 py-3 text-right">Sent</th>
                                <th class="px-6 py-3 text-right">Opened</th>
                                <th class="px-6 py-3 text-right">Open %</th>
                                <th class="px-6 py-3 text-right">Clicked</th>
                                <th class="px-6 py-3 text-right">Click %</th>
                                <th class="px-6 py-3 text-right">Replied</th>
                                <th class="px-6 py-3 text-right">Bounced</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($campaigns)): ?>
                            <tr><td colspan="8" class="px-6 py-8 text-center text-gray-400">No email sequences logged yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($campaigns as $c): ?>
                            <?php
                                $sent = intval($c['total_sent']);
                                $op = intval($c['opened_count']);
                                $cl = intval($c['clicked_count']);
                                $open_pct = $sent > 0 ? round($op / $sent * 100, 1) : 0;
                                $click_pct = $sent > 0 ? round($cl / $sent * 100, 1) : 0;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($c['sequence_name']); ?></td>
                                <td class="px-6 py-3 text-right"><?php echo number_format($sent); ?></td>
                                <td class="px-6 py-3 text-right"><?php echo number_format($op); ?></td>
                                <td class="px-6 py-3 text-right">
                                    <span class="px-2 py-0.5 rounded-full text-xs <?php echo $open_pct >= 20 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                                        <?php echo $open_pct; ?>%
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right"><?php echo number_format($cl); ?></td>
                                <td class="px-6 py-3 text-right">
                                    <span class="px-2 py-0.5 rounded-full text-xs <?php echo $click_pct >= 5 ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'; ?>">
                                        <?php echo $click_pct; ?>%
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right text-purple-600"><?php echo number_format(intval($c['replied_count'])); ?></td>
                                <td class="px-6 py-3 text-right text-red-500"><?php echo number_format(intval($c['bounced_count'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent sends -->
            <div class="bg-white rounded-xl border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-800">Recent Sends</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="px-6 py-3 text-left">Sequence</th>
                                <th class="px-6 py-3 text-left">Step</th>
                                <th class="px-6 py-3 text-left">Client</th>
                                <th class="px-6 py-3 text-left">Sent At</th>
                                <th class="px-6 py-3 text-center">Opened</th>
                                <th class="px-6 py-3 text-center">Clicked</th>
                                <th class="px-6 py-3 text-center">Replied</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($recent)): ?>
                            <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">No recent sends.</td></tr>
                            <?php else: ?>
                            <?php foreach ($recent as $r): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($r['sequence_name']); ?></td>
                                <td class="px-6 py-3 text-gray-500">Step <?php echo intval($r['step_number']); ?></td>
                                <td class="px-6 py-3 text-gray-500"><?php echo htmlspecialchars($r['client_name'] ?? 'Lead'); ?></td>
                                <td class="px-6 py-3 text-gray-500"><?php echo date('M d, Y H:i', strtotime($r['sent_at'])); ?></td>
                                <td class="px-6 py-3 text-center"><?php echo $r['opened'] ? '✓' : '—'; ?></td>
                                <td class="px-6 py-3 text-center"><?php echo $r['clicked'] ? '✓' : '—'; ?></td>
                                <td class="px-6 py-3 text-center"><?php echo $r['replied'] ? '✓' : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>
<?php include 'includes/admin-footer.php'; ?>
