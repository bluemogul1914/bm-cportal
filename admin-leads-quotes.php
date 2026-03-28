<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
$stat_filter = $_GET['status'] ?? '';

$where = ["q.document_date BETWEEN ? AND ?"]; $params = [$from, $to];
if ($stat_filter) { $where[] = "q.status=?"; $params[] = $stat_filter; }
$where_sql = 'WHERE '.implode(' AND ',$where);

$quotes = $pdo->prepare("SELECT q.*, l.full_name as lead_name FROM lead_quotes q LEFT JOIN leads l ON q.lead_id=l.id $where_sql ORDER BY q.created_at DESC");
$quotes->execute($params);
$quotes = $quotes->fetchAll(PDO::FETCH_ASSOC);

// Totals per status
$status_list = ['new'=>'New','sent'=>'Sent','on_review'=>'On review','accepted'=>'Accepted','denied'=>'Denied'];
$q_colors = ['new'=>'bg-blue-500','sent'=>'bg-indigo-500','on_review'=>'bg-yellow-500','accepted'=>'bg-green-500','denied'=>'bg-red-500'];
$totals = [];
foreach ($status_list as $k=>$v) {
    $rows = array_filter($quotes, fn($q)=>$q['status']===$k);
    $totals[$k] = ['count'=>count($rows),'sum'=>array_sum(array_map(fn($q)=>(float)$q['total'],$rows))];
}
$grand_total = array_sum(array_column($totals,'count'));
$grand_sum   = array_sum(array_map(fn($t)=>$t['sum'],$totals));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lead Quotes — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
        <div>
            <p class="text-xs text-gray-400">Leads /</p>
            <h1 class="text-2xl font-semibold text-gray-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center"><i class="fas fa-file-alt text-white text-sm"></i></span>
                Quotes
            </h1>
        </div>
        <form method="GET" class="flex items-center gap-3 flex-wrap">
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">Period</label>
                <input type="date" name="from" value="<?= $from ?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                <span class="text-gray-400">-</span>
                <input type="date" name="to" value="<?= $to ?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none">
            </div>
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">Partner</label>
                <select name="partner" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                    <option value="">Any</option>
                </select>
            </div>
            <button type="submit" class="w-8 h-8 border border-gray-300 rounded-lg flex items-center justify-center text-gray-500 hover:bg-gray-50"><i class="fas fa-sync-alt text-xs"></i></button>
        </form>
    </div>
</header>

<div class="p-6 space-y-6">

<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">Show</span>
            <select class="border border-gray-300 rounded px-2 py-1 text-sm"><option>100</option></select>
            <span class="text-sm text-gray-500">entries</span>
        </div>
        <div class="flex gap-2 items-center">
            <input type="search" placeholder="Search…" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-44 focus:outline-none">
            <button class="p-1.5 border border-gray-300 rounded text-gray-500 hover:bg-gray-50"><i class="fas fa-ellipsis-h text-xs"></i></button>
            <button class="p-1.5 border border-gray-300 rounded text-gray-500 hover:bg-gray-50"><i class="fas fa-file-export text-xs"></i></button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-all-quotes">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Status <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Lead name <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Number <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Document date <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase cursor-pointer">Total <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Valid until <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($quotes)): ?>
                <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No data available in table</td></tr>
                <?php else: ?>
                <?php foreach ($quotes as $q):
                    $qcol = $q_colors[$q['status']??'new'] ?? 'bg-gray-400';
                ?>
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='admin-leads-view.php?id=<?= $q['lead_id'] ?>&tab=quotes'" data-testid="row-quote-<?= $q['id'] ?>">
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-semibold <?= $qcol ?> text-white"><?= ucfirst(str_replace('_',' ',$q['status'])) ?></span></td>
                    <td class="px-4 py-3 text-blue-600 font-medium"><?= htmlspecialchars($q['lead_name']??'—') ?></td>
                    <td class="px-4 py-3 font-mono text-gray-600"><?= htmlspecialchars($q['quote_number']??'') ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= $q['document_date'] ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-900">$<?= number_format((float)($q['total']??0),2) ?></td>
                    <td class="px-4 py-3 text-gray-400"><?= $q['valid_until'] ?></td>
                    <td class="px-4 py-3" onclick="event.stopPropagation()"><button class="text-xs text-gray-400 hover:text-gray-600 border border-gray-200 rounded px-2 py-0.5">···</button></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400">
        Showing 0 to <?= count($quotes) ?> of <?= count($quotes) ?> entries
    </div>
</div>

<!-- Totals -->
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-4">Totals</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-quote-totals">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Status</th>
                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Amount</th>
                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Total</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($status_list as $k=>$v): ?>
                <tr>
                    <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold <?= $q_colors[$k] ?? 'bg-gray-400' ?> text-white"><?= $v ?></span></td>
                    <td class="px-4 py-2 text-right text-gray-700"><?= $totals[$k]['count'] ?></td>
                    <td class="px-4 py-2 text-right text-gray-500">$<?= number_format($totals[$k]['sum'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="border-t-2 border-gray-200 bg-gray-50">
                    <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-800 text-white">Total</span></td>
                    <td class="px-4 py-2 text-right font-semibold"><?= $grand_total ?></td>
                    <td class="px-4 py-2 text-right font-semibold">$<?= number_format($grand_sum,2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <!-- Status filter pills -->
    <div class="mt-4 pt-4 border-t border-gray-100">
        <h4 class="text-xs font-semibold text-gray-500 mb-3">Filter</h4>
        <div class="flex flex-wrap gap-3">
            <?php foreach ($status_list as $k=>$v): ?>
            <a href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&status=<?= $k ?>" class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-xs font-semibold <?= $q_colors[$k] ?? 'bg-gray-400' ?> text-white"><?= $v ?></span>
            </a>
            <?php endforeach; ?>
            <a href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-700 text-white">Reset filter</a>
        </div>
    </div>
</div>

</div>
</div>
</div>
</body>
</html>
