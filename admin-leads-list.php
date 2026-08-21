<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) portal_redirect('/portal');
$pdo = getDB();
require_once 'includes/leads-db-bootstrap.php';
try { leads_bootstrap($pdo); } catch (Exception $e) {}

$success_msg = ''; $error_msg = '';

// Bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf_token($_POST['_csrf_token'] ?? '')) {
    if ($_POST['action'] === 'delete_lead') {
        $id = (int)($_POST['lead_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM leads WHERE id=?")->execute([$id]);
            $success_msg = 'Lead deleted.';
        }
    }
}

// Filters
$condition = $_GET['condition'] ?? 'active';
$search    = trim($_GET['search'] ?? '');
$pipeline  = $_GET['pipeline']  ?? '';
$per_page  = max(1, (int)($_GET['per_page'] ?? 100));
$page      = max(1, (int)($_GET['page']     ?? 1));
$offset    = ($page - 1) * $per_page;

$where  = [];
$params = [];
if ($condition === 'active') { $where[] = "pipeline_status NOT IN ('won','lost')"; }
elseif ($condition === 'won') { $where[] = "pipeline_status='won'"; }
elseif ($condition === 'lost')  { $where[] = "pipeline_status='lost'"; }
if ($search) { $where[] = "(full_name ILIKE ? OR email ILIKE ? OR phone ILIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($pipeline) { $where[] = "pipeline_status=?"; $params[] = $pipeline; }

$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';
$total_rows = $pdo->prepare("SELECT COUNT(*) FROM leads $where_sql"); $total_rows->execute($params); $total_rows = (int)$total_rows->fetchColumn();
$leads_st = $pdo->prepare("SELECT * FROM leads $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$leads_st->execute($params);
$leads = $leads_st->fetchAll(PDO::FETCH_ASSOC);

// Pipeline status totals (always all leads)
$totals_raw = $pdo->query("SELECT pipeline_status, COUNT(*) as cnt, COALESCE(SUM(deal_value),0) as total_val FROM leads GROUP BY pipeline_status ORDER BY pipeline_status")->fetchAll(PDO::FETCH_ASSOC);
$status_labels = ['new_enquiry'=>'New enquiry','qualification'=>'Qualification','activation'=>'Activation','won'=>'Won','lost'=>'Lost'];
$status_badge_colors = [
    'new_enquiry'   => 'bg-yellow-400 text-gray-900',
    'qualification' => 'bg-blue-500 text-white',
    'activation'    => 'bg-green-500 text-white',
    'won'           => 'bg-emerald-600 text-white',
    'lost'          => 'bg-red-500 text-white',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Leads List — Blue Mogul Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
<script>tailwind.config={theme:{extend:{colors:{primary:'#1a56db',secondary:'#0d1b3e'},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
<?php include 'includes/admin-sidebar.php'; ?>
<div class="flex-1 overflow-y-auto">

<header class="bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="px-6 py-4">
        <p class="text-xs text-gray-400">Leads /</p>
        <h1 class="text-2xl font-semibold text-gray-900 flex items-center gap-2">
            <span class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center"><i class="fas fa-list text-white text-sm"></i></span>
            List
        </h1>
    </div>
    <!-- Filter bar -->
    <div class="px-6 pb-3 border-t border-gray-100 pt-3 flex items-center gap-3 flex-wrap">
        <form method="GET" class="flex items-center gap-3 flex-wrap flex-1">
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">Lead condition</label>
                <select name="condition" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                    <option value="active" <?= $condition==='active'?'selected':'' ?>>Active leads</option>
                    <option value="all"    <?= $condition==='all'?'selected':'' ?>>All leads</option>
                    <option value="won"    <?= $condition==='won'?'selected':'' ?>>Won</option>
                    <option value="lost"   <?= $condition==='lost'?'selected':'' ?>>Lost</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">Pipeline</label>
                <select name="pipeline" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                    <option value="">All selected</option>
                    <?php foreach ($status_labels as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $pipeline===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-1.5 bg-blue-600 text-white rounded-lg text-sm font-medium">Show</button>
            <div class="ml-auto flex items-center gap-2">
                <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search…"
                    class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-52 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    data-testid="input-search">
                <button type="submit" class="p-1.5 text-gray-500 hover:text-gray-700"><i class="fas fa-search"></i></button>
            </div>
        </form>
        <a href="admin-leads-add.php" class="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition">
            <i class="fas fa-plus mr-1"></i>Add Lead
        </a>
    </div>
</header>

<div class="p-6 space-y-6">
<?php if ($success_msg): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg"><?= htmlspecialchars($success_msg) ?></div>
<?php endif; ?>

<!-- Leads table -->
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-4 flex-wrap">
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">Show</span>
            <select onchange="window.location.href='?per_page='+this.value+'&condition=<?= urlencode($condition) ?>&pipeline=<?= urlencode($pipeline) ?>&search=<?= urlencode($search) ?>'" class="border border-gray-300 rounded px-2 py-1 text-sm">
                <?php foreach ([10,25,50,100] as $n): ?><option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?>
            </select>
            <span class="text-sm text-gray-500">entries</span>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="table-leads">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="w-8 px-4 py-3"><input type="checkbox" id="chk-all" onchange="document.querySelectorAll('.chk-row').forEach(c=>c.checked=this.checked)" class="rounded"></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Status <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">ID <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Full name <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Phone number</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Last contacted</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Last comments</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase cursor-pointer">Date added <i class="fas fa-sort text-gray-300 ml-1"></i></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Custom status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($leads)): ?>
                <tr><td colspan="10" class="px-4 py-10 text-center text-gray-400">
                    <i class="fas fa-users text-3xl mb-2"></i><br>No leads found. <a href="admin-leads-add.php" class="text-blue-500 hover:underline">Add your first lead</a>
                </td></tr>
                <?php else: ?>
                <?php foreach ($leads as $l):
                    $stat = $l['pipeline_status'];
                    $bcls = $status_badge_colors[$stat] ?? 'bg-gray-200 text-gray-700';
                    $slbl = $status_labels[$stat]       ?? ucwords(str_replace('_',' ',$stat));
                ?>
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='admin-leads-view.php?id=<?= $l['id'] ?>'" data-testid="row-lead-<?= $l['id'] ?>">
                    <td class="px-4 py-3" onclick="event.stopPropagation()"><input type="checkbox" class="chk-row rounded" value="<?= $l['id'] ?>"></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-semibold <?= $bcls ?>"><?= $slbl ?></span></td>
                    <td class="px-4 py-3 font-mono text-gray-500"><?= htmlspecialchars($l['lead_number'] ?? $l['id']) ?></td>
                    <td class="px-4 py-3 font-medium text-blue-600"><?= htmlspecialchars($l['full_name']) ?></td>
                    <td class="px-4 py-3 font-mono text-gray-600"><?= htmlspecialchars($l['phone'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($l['email'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= $l['last_contacted'] ? date('Y-m-d H:i:s',strtotime($l['last_contacted'])) : '—' ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs max-w-xs truncate"><?= htmlspecialchars($l['last_comments'] ?? '') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= date('Y-m-d',strtotime($l['created_at'])) ?></td>
                    <td class="px-4 py-3"><span class="text-xs text-gray-500"><?= htmlspecialchars($l['custom_status'] ?? 'customer') ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
        <span>Showing <?= $offset+1 ?> to <?= min($offset+$per_page,$total_rows) ?> of <?= $total_rows ?> entries</span>
        <div class="flex gap-1">
            <?php for ($p=1; $p<=ceil($total_rows/$per_page); $p++): ?>
            <a href="?page=<?= $p ?>&per_page=<?= $per_page ?>&condition=<?= urlencode($condition) ?>&pipeline=<?= urlencode($pipeline) ?>&search=<?= urlencode($search) ?>"
                class="w-8 h-8 flex items-center justify-center rounded text-xs <?= $p==$page?'bg-blue-600 text-white':'border border-gray-300 hover:bg-gray-50' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Totals table -->
<div class="bg-white rounded-lg border border-gray-200 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-3">Totals</h3>
    <div class="overflow-x-auto">
    <table class="w-full text-sm" data-testid="table-totals">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Status</th>
                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Amount of leads</th>
                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Sum of deals</th>
                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Status</th>
                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Amount of leads</th>
                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Sum of deals</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $left  = ['new_enquiry','activation','lost'];
            $right = ['qualification','won'];
            $max   = max(count($left),count($right));
            for ($i=0; $i<$max; $i++):
                $lk = $left[$i]  ?? null;
                $rk = $right[$i] ?? null;
                $lr = $totals_raw[array_search($lk, array_column($totals_raw,'pipeline_status'))] ?? null;
                $rr = $totals_raw[array_search($rk, array_column($totals_raw,'pipeline_status'))] ?? null;
            ?>
            <tr class="border-t border-gray-100">
                <td class="px-4 py-2"><?php if ($lk): ?><span class="px-2 py-0.5 rounded text-xs font-semibold <?= $status_badge_colors[$lk]?? '' ?>"><?= $status_labels[$lk] ?></span><?php endif; ?></td>
                <td class="px-4 py-2 text-right text-gray-700"><?= $lr ? $lr['cnt'] : ($lk?'0':'') ?></td>
                <td class="px-4 py-2 text-right text-gray-500"><?= $lr ? '$'.number_format((float)$lr['total_val'],2) : ($lk?'0.00 $':'') ?></td>
                <td class="px-4 py-2"><?php if ($rk): ?><span class="px-2 py-0.5 rounded text-xs font-semibold <?= $status_badge_colors[$rk]?? '' ?>"><?= $status_labels[$rk] ?></span><?php endif; ?></td>
                <td class="px-4 py-2 text-right text-gray-700"><?= $rr ? $rr['cnt'] : ($rk?'0':'') ?></td>
                <td class="px-4 py-2 text-right text-gray-500"><?= $rr ? '$'.number_format((float)$rr['total_val'],2) : ($rk?'0.00 $':'') ?></td>
            </tr>
            <?php endfor; ?>
            <!-- Total row -->
            <?php $total_cnt = array_sum(array_column($totals_raw,'cnt')); $total_sum = array_sum(array_column($totals_raw,'total_val')); ?>
            <tr class="border-t-2 border-gray-200 bg-gray-50">
                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-800 text-white">Total</span></td>
                <td class="px-4 py-2 text-right font-semibold"><?= $total_cnt ?></td>
                <td class="px-4 py-2 text-right font-semibold">$<?= number_format((float)$total_sum,2) ?></td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>
    </div>
</div>

</div>
</div>
</div>
</body>
</html>
