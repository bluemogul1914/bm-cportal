<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$user_name = $_SESSION['user_name'] ?? 'Admin';
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

// Date range filter
$owner  = $_GET['owner']  ?? 'Me';
$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-d');

// Stats
$new_leads    = $pdo->query("SELECT COUNT(*) FROM leads WHERE pipeline_status='new_enquiry'")->fetchColumn();
$active_leads = $pdo->query("SELECT COUNT(*) FROM leads WHERE pipeline_status NOT IN ('won','lost')")->fetchColumn();
$deals_val    = $pdo->query("SELECT COALESCE(SUM(deal_value),0) FROM leads WHERE pipeline_status='won'")->fetchColumn();
$total_leads  = $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();

// Tasks for today (todos not completed, scheduled today or earlier)
$todos_today  = $pdo->query("SELECT lt.*, l.full_name FROM lead_todos lt LEFT JOIN leads l ON lt.lead_id=l.id WHERE lt.completed=FALSE ORDER BY lt.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

// Recent activities
$activities = $pdo->query("SELECT la.*, l.full_name FROM lead_activities la LEFT JOIN leads l ON la.lead_id=l.id ORDER BY la.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

// Pipeline counts
$pipeline_raw = $pdo->query("SELECT pipeline_status, COUNT(*) as cnt, COALESCE(SUM(deal_value),0) as total FROM leads GROUP BY pipeline_status")->fetchAll(PDO::FETCH_ASSOC);
$pipeline = [];
foreach ($pipeline_raw as $r) $pipeline[$r['pipeline_status']] = $r;

// Weekly new leads (last 7 weeks)
$weekly = $pdo->query("SELECT TO_CHAR(DATE_TRUNC('week',created_at),'MM/DD') as wk, COUNT(*) as cnt FROM leads GROUP BY DATE_TRUNC('week',created_at) ORDER BY DATE_TRUNC('week',created_at) LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// Sources
$sources = $pdo->query("SELECT COALESCE(NULLIF(source,''),'Not Specified') as src, COUNT(*) as cnt FROM leads GROUP BY source")->fetchAll(PDO::FETCH_ASSOC);

// Total leads summary
$won_leads  = (int)($pipeline['won']['cnt']  ?? 0);
$lost_leads = (int)($pipeline['lost']['cnt'] ?? 0);
$new_count  = (int)($pipeline['new_enquiry']['cnt'] ?? 0);
$in_prog    = $total_leads - $won_leads - $lost_leads;

$status_labels = ['new_enquiry'=>'New enquiry','qualification'=>'Qualification','activation'=>'Activation','won'=>'Won','lost'=>'Lost'];
$status_order  = ['new_enquiry','qualification','activation','won','lost'];
$status_colors = ['#3b82f6','#0ea5e9','#22c55e','#15803d','#ef4444'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Leads Dashboard — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-tachometer-alt text-yellow-500 mr-2"></i>Dashboard</h1>
        </div>
        <div class="flex items-center gap-3">
            <select class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                <option>Me</option><option>All</option>
            </select>
            <div class="flex items-center gap-2">
                <input type="date" value="<?= $from ?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                <span class="text-gray-400">—</span>
                <input type="date" value="<?= $to ?>" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
            <button class="p-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-500"><i class="fas fa-sync-alt"></i></button>
        </div>
    </div>
</header>

<div class="p-6 space-y-6">

<!-- Stat cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="card-tasks-today">
        <div class="flex items-center gap-3 mb-2"><div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center"><i class="fas fa-tasks text-blue-600 text-sm"></i></div><span class="text-sm text-gray-500">Tasks for today</span></div>
        <p class="text-3xl font-bold text-gray-900"><?= count(array_filter($todos_today, fn($t)=>!$t['completed'])) ?></p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="card-new-leads">
        <div class="flex items-center gap-3 mb-2"><div class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center"><i class="fas fa-user-plus text-indigo-600 text-sm"></i></div><span class="text-sm text-gray-500">New leads</span></div>
        <div class="flex items-center justify-between">
            <p class="text-3xl font-bold text-gray-900"><?= $new_count ?></p>
            <a href="admin-leads-list.php" class="text-xs text-blue-500 hover:underline">View</a>
        </div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="card-active-leads">
        <div class="flex items-center gap-3 mb-2"><div class="w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center"><i class="fas fa-fire text-yellow-600 text-sm"></i></div><span class="text-sm text-gray-500">Active leads</span></div>
        <div class="flex items-center justify-between">
            <p class="text-3xl font-bold text-gray-900"><?= $active_leads ?></p>
            <a href="admin-leads-list.php" class="text-xs text-blue-500 hover:underline">View</a>
        </div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="card-deals">
        <div class="flex items-center gap-3 mb-2"><div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center"><i class="fas fa-handshake text-purple-600 text-sm"></i></div><span class="text-sm text-gray-500">Deals</span></div>
        <p class="text-3xl font-bold text-gray-900">$<?= number_format((float)$deals_val,2) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= $won_leads ?> won leads</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- To-Dos -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-base font-semibold text-gray-900">To-Dos</h3>
        <button class="w-7 h-7 bg-gray-100 hover:bg-gray-200 rounded text-gray-500 text-sm"><i class="fas fa-plus"></i></button>
    </div>
    <?php if (empty($todos_today)): ?>
    <div class="p-8 text-center text-gray-400">
        <i class="fas fa-check-circle text-4xl mb-2 text-green-300"></i>
        <p class="text-sm">No data to display</p>
    </div>
    <?php else: ?>
    <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Lead</th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">To-Do</th>
            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Scheduled</th>
            <th class="px-4 py-2 text-xs font-semibold text-gray-500">Actions</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
        <?php foreach ($todos_today as $t): ?>
        <tr class="hover:bg-gray-50">
            <td class="px-4 py-2"><a href="admin-leads-view.php?id=<?= $t['lead_id'] ?>" class="text-blue-600 hover:underline text-xs"><?= htmlspecialchars($t['full_name']??'—') ?></a></td>
            <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars($t['todo']) ?></td>
            <td class="px-4 py-2 text-gray-400 text-xs"><?= $t['scheduled_at'] ? date('M j H:i',strtotime($t['scheduled_at'])) : '—' ?></td>
            <td class="px-4 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500">Actions</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Recent activities -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-100">
        <h3 class="text-base font-semibold text-gray-900">Recent activities</h3>
    </div>
    <?php if (empty($activities)): ?>
    <div class="p-8 text-center text-gray-400"><i class="fas fa-history text-3xl mb-2"></i><p class="text-sm">No recent activities</p></div>
    <?php else: ?>
    <div class="divide-y divide-gray-50 max-h-72 overflow-y-auto">
    <?php foreach ($activities as $a): ?>
    <div class="px-5 py-3 flex items-start gap-3">
        <div class="w-7 h-7 bg-gray-200 rounded-full flex items-center justify-center shrink-0 text-xs font-bold text-gray-600"><?= strtoupper(substr($a['actor']??'S',0,1)) ?></div>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-700">
                <span class="font-medium"><?= htmlspecialchars($a['actor']??'System') ?></span>
                <?= htmlspecialchars($a['action']??'') ?>
                <?php if ($a['full_name']): ?> with name: <a href="admin-leads-view.php?id=<?= $a['lead_id'] ?>" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars($a['full_name']) ?></a><?php endif; ?>
            </p>
            <p class="text-xs text-gray-400 mt-0.5"><?= $a['created_at'] ? 'about '.human_time_diff(strtotime($a['created_at'])).' ago ('.date('Y-m-d H:i:s',strtotime($a['created_at'])).')' : '' ?></p>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Charts row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- New leads bar chart -->
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-base font-semibold text-gray-900">New leads</h3>
        <div class="flex gap-1">
            <?php foreach (['Day','Week','Month'] as $p): ?>
            <button class="px-3 py-1 text-xs rounded <?= $p==='Week'?'bg-blue-500 text-white':'border border-gray-300 text-gray-600' ?>"><?= $p ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <canvas id="newLeadsChart" height="180"></canvas>
    <div class="mt-3 flex gap-4 text-xs text-gray-500">
        <span><span class="inline-block w-3 h-3 rounded-sm bg-yellow-400 mr-1"></span>Added</span>
    </div>
</div>

<!-- Sources pie -->
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-base font-semibold text-gray-900">Sources</h3>
        <div class="flex gap-1">
            <?php foreach (['All','Leads','Converted leads'] as $p): ?>
            <button class="px-2 py-1 text-xs rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= $p ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="flex items-center gap-6">
        <canvas id="sourcesChart" width="180" height="180" style="max-width:180px;max-height:180px"></canvas>
        <div class="space-y-1 text-sm">
            <?php foreach ($sources as $s): ?>
            <div class="flex items-center gap-2">
                <span class="inline-block w-3 h-3 rounded-full bg-blue-500"></span>
                <span class="text-gray-700"><?= $s['cnt'] ?> &nbsp;</span><span class="text-gray-500"><?= htmlspecialchars($s['src']) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="font-semibold text-gray-700 mt-2">Total: <?= $total_leads ?></div>
        </div>
    </div>
</div>
</div>

<!-- Sales funnel -->
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <h3 class="text-base font-semibold text-gray-900 mb-4">Sales funnel</h3>
    <canvas id="funnelChart" height="140"></canvas>
</div>

<!-- Summary tables -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-3">Total leads</h3>
    <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-100">
            <?php foreach ([
                ['Total leads',          $total_leads,              ''],
                ['Total value of deals', '$'.number_format((float)($pipeline['won']['total']??0),2), ''],
                ['Won leads',            $won_leads.' ('.($total_leads>0?round($won_leads/$total_leads*100):0).'%)', ''],
                ['Value of won deals',   '$'.number_format((float)($pipeline['won']['total']??0),2).' (0%)', ''],
                ['Lost leads',           $lost_leads.' ('.($total_leads>0?round($lost_leads/$total_leads*100):0).'%)', ''],
                ['Value of lost deals',  '$'.number_format((float)($pipeline['lost']['total']??0),2).' (0%)', ''],
            ] as [$lbl,$val,$cls]): ?>
            <tr class="py-2"><td class="py-2 text-gray-500"><?= $lbl ?></td><td class="py-2 text-right font-semibold text-gray-900"><?= $val ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-3">New leads</h3>
    <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-100">
            <?php foreach ([
                ['New leads',               $new_count.' ('.($total_leads>0?round($new_count/$total_leads*100):0).'%)', ''],
                ['Value of new deals',      '$'.number_format((float)($pipeline['new_enquiry']['total']??0),2).' (0%)', ''],
                ['In progress leads',       $in_prog.' ('.($total_leads>0?round($in_prog/$total_leads*100):0).'%)', ''],
                ['Value of deals in progress','$'.number_format(array_sum(array_map(fn($s)=>(float)($pipeline[$s]['total']??0),['qualification','activation'])),2).' (0%)', ''],
            ] as [$lbl,$val,$cls]): ?>
            <tr class="py-2"><td class="py-2 text-gray-500"><?= $lbl ?></td><td class="py-2 text-right font-semibold text-gray-900"><?= $val ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>

</div><!-- /p-6 -->
</div><!-- /flex-1 -->
</div><!-- /flex -->

<script>
// New leads weekly bar chart
const wkLabels = <?= json_encode(array_column($weekly,'wk')) ?>;
const wkData   = <?= json_encode(array_map('intval',array_column($weekly,'cnt'))) ?>;
new Chart(document.getElementById('newLeadsChart'),{
    type:'bar',
    data:{labels:wkLabels,datasets:[{label:'Added',data:wkData,backgroundColor:'#eab308',borderRadius:3}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
});

// Sources doughnut
const srcLabels = <?= json_encode(array_column($sources,'src')) ?>;
const srcData   = <?= json_encode(array_map('intval',array_column($sources,'cnt'))) ?>;
new Chart(document.getElementById('sourcesChart'),{
    type:'doughnut',
    data:{labels:srcLabels,datasets:[{data:srcData,backgroundColor:['#3b82f6','#0ea5e9','#22c55e','#f59e0b','#8b5cf6','#ef4444']}]},
    options:{responsive:false,plugins:{legend:{display:false}}}
});

// Sales funnel bar
const funLabels = <?= json_encode(array_map(fn($s)=>['new_enquiry'=>'New enquiry','qualification'=>'Qualification','activation'=>'Activation','won'=>'Won','lost'=>'Lost'][$s]??$s, $status_order)) ?>;
const funData   = <?= json_encode(array_map(fn($s)=>(int)($pipeline[$s]['cnt']??0), $status_order)) ?>;
const funColors = <?= json_encode($status_colors) ?>;
new Chart(document.getElementById('funnelChart'),{
    type:'bar',
    data:{labels:funLabels,datasets:[{data:funData,backgroundColor:funColors,borderRadius:4}]},
    options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{afterBody:function(ctx){const i=ctx[0].dataIndex;const v=<?= json_encode(array_map(fn($s)=>'$'.number_format((float)($pipeline[$s]['total']??0),2),$status_order)) ?>[i];return'Total deals amount\n'+v;}}}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
});
</script>
<?php
function human_time_diff(int $t): string {
    $diff = time() - $t;
    if ($diff < 60) return $diff.'s';
    if ($diff < 3600) return round($diff/60).' min';
    if ($diff < 86400) return round($diff/3600).' hour'.(round($diff/3600)>1?'s':'');
    return round($diff/86400).' day'.(round($diff/86400)>1?'s':'');
}
?>
</body>
</html>
