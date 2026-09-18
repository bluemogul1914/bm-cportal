<?php
/**
 * admin-time-tracking.php — Time tracking UI + reports (P2 #5).
 * Surfaces all project + ticket time entries in one place with filters,
 * stat cards, a combined table, and per-user / per-client breakdowns.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$success_message = '';
$error_message = '';

$pdo = getDB();

// ── Filters ─────────────────────────────────────────────────────────────
$user_filter   = (int)($_GET['user'] ?? 0);
$client_filter = (int)($_GET['client'] ?? 0);
$from_filter   = trim($_GET['from'] ?? '');
$to_filter     = trim($_GET['to'] ?? '');
$billable_only = !empty($_GET['billable']);

$where = [];
$params = [];
if ($user_filter)   { $where[] = 'e.user_id = ?';   $params[] = $user_filter; }
if ($client_filter) { $where[] = 'e.client_id = ?'; $params[] = $client_filter; }
if ($from_filter)   { $where[] = 'e.created_at >= ?'; $params[] = $from_filter . ' 00:00:00'; }
if ($to_filter)     { $where[] = 'e.created_at <= ?'; $params[] = $to_filter . ' 23:59:59'; }
if ($billable_only) { $where[] = 'e.billable = true'; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Combined time entries (project + ticket) ──────────────────────────
$sql = "
    SELECT * FROM (
        SELECT 'project' AS type, pte.id, pte.project_id AS ref_id, pte.user_id,
               COALESCE(pte.user_name, '') AS user_name, pte.hours,
               pte.description, pte.billable, pte.rate, pte.created_at,
               pr.name AS ref_name, pr.client_id AS client_id, c.name AS client_name
        FROM project_time_entries pte
        LEFT JOIN projects pr ON pte.project_id = pr.id
        LEFT JOIN clients c ON pr.client_id = c.id
        UNION ALL
        SELECT 'ticket' AS type, tte.id, tte.ticket_id AS ref_id, tte.user_id,
               COALESCE(u.name, '') AS user_name, (tte.duration_minutes / 60.0) AS hours,
               tte.description, tte.billable, tte.hourly_rate AS rate, tte.created_at,
               t.subject AS ref_name, t.client_id AS client_id, c.name AS client_name
        FROM ticket_time_entries tte
        LEFT JOIN tickets t ON tte.ticket_id = t.id
        LEFT JOIN clients c ON t.client_id = c.id
        LEFT JOIN users u ON tte.user_id = u.id
    ) e
    $where_sql
    ORDER BY e.created_at DESC
";
$entries = $pdo->prepare($sql);
$entries->execute($params);
$entries = $entries->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ───────────────────────────────────────────────────────────────
$total_hours = 0.0; $billable_hours = 0.0; $billable_amount = 0.0;
foreach ($entries as $e) {
    $h = (float)$e['hours'];
    $total_hours += $h;
    if ($e['billable']) {
        $billable_hours += $h;
        $billable_amount += $h * (float)$e['rate'];
    }
}

// ── Per-user breakdown ──────────────────────────────────────────────────
$by_user = [];
foreach ($entries as $e) {
    $k = $e['user_name'] ?: ('User #' . $e['user_id']);
    if (!isset($by_user[$k])) $by_user[$k] = ['hours' => 0.0, 'billable_hours' => 0.0, 'amount' => 0.0];
    $h = (float)$e['hours'];
    $by_user[$k]['hours'] += $h;
    if ($e['billable']) { $by_user[$k]['billable_hours'] += $h; $by_user[$k]['amount'] += $h * (float)$e['rate']; }
}
uasort($by_user, fn($a, $b) => $b['hours'] <=> $a['hours']);

// ── Per-client breakdown ────────────────────────────────────────────────
$by_client = [];
foreach ($entries as $e) {
    $k = $e['client_name'] ?: 'Unassigned';
    if (!isset($by_client[$k])) $by_client[$k] = ['hours' => 0.0, 'amount' => 0.0];
    $h = (float)$e['hours'];
    $by_client[$k]['hours'] += $h;
    if ($e['billable']) $by_client[$k]['amount'] += $h * (float)$e['rate'];
}
uasort($by_client, fn($a, $b) => $b['hours'] <=> $a['hours']);

// ── Filter dropdown data ────────────────────────────────────────────────
$users = $pdo->query("SELECT DISTINCT user_id, user_name FROM project_time_entries WHERE user_name IS NOT NULL AND user_name <> '' ORDER BY user_name")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

function fmt_hours($h) { return number_format((float)$h, 1) . 'h'; }
function fmt_money($m) { return '$' . number_format((float)$m, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Tracking — Blue Mogul</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/tailwind-shared.js"></script>
    <script>tailwind.config = window.bmTailwindConfig;</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-clock text-blue-500 mr-2"></i>Time Tracking</h1>
                    <p class="text-sm text-gray-500 mt-0.5">All project &amp; ticket time entries, with reports</p>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Hours</p><p class="text-3xl font-bold text-gray-900"><?php echo fmt_hours($total_hours); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Billable Hours</p><p class="text-3xl font-bold text-blue-600"><?php echo fmt_hours($billable_hours); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Billable Amount</p><p class="text-3xl font-bold text-green-600"><?php echo fmt_money($billable_amount); ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Entries</p><p class="text-3xl font-bold text-gray-600"><?php echo count($entries); ?></p></div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">User</label>
                        <select name="user" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                            <option value="">All users</option>
                            <?php foreach ($users as $u): ?><option value="<?php echo (int)$u['user_id']; ?>" <?php echo $user_filter===(int)$u['user_id']?'selected':''; ?>><?php echo htmlspecialchars($u['user_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Client</label>
                        <select name="client" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                            <option value="">All clients</option>
                            <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $client_filter===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($from_filter); ?>" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($to_filter); ?>" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                    </div>
                    <div class="flex items-center gap-2 pb-2">
                        <input type="checkbox" name="billable" value="1" id="billableOnly" <?php echo $billable_only?'checked':''; ?> class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                        <label for="billableOnly" class="text-sm text-gray-600">Billable only</label>
                    </div>
                    <button class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-md text-sm">Filter</button>
                    <a href="admin-time-tracking.php" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-800">Clear</a>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">All Time Entries (<?php echo count($entries); ?>)</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Reference</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">User</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hours</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Billable</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($entries)): ?>
                                <tr><td colspan="8" class="px-6 py-12 text-center text-gray-500">No time entries match. Log time on a project or ticket to see it here.</td></tr>
                            <?php else: foreach ($entries as $e): ?>
                                <?php $h = (float)$e['hours']; $amt = $e['billable'] ? $h * (float)$e['rate'] : 0.0; ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo date('M j, Y', strtotime($e['created_at'])); ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo $e['type']==='project'?'bg-blue-100 text-blue-700':'bg-purple-100 text-purple-700'; ?>"><?php echo ucfirst($e['type']); ?></span>
                                    </td>
                                    <td class="px-6 py-3 text-sm">
                                        <?php if ($e['type']==='project'): ?><a href="admin-project-detail.php?id=<?php echo (int)$e['ref_id']; ?>" class="text-blue-600 hover:underline">#<?php echo (int)$e['ref_id']; ?> <?php echo htmlspecialchars($e['ref_name'] ?: ''); ?></a>
                                        <?php else: ?><a href="admin-ticket-detail.php?id=<?php echo (int)$e['ref_id']; ?>" class="text-blue-600 hover:underline">#<?php echo (int)$e['ref_id']; ?> <?php echo htmlspecialchars($e['ref_name'] ?: ''); ?></a><?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($e['user_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500 max-w-xs truncate"><?php echo htmlspecialchars($e['description'] ?: '—'); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_hours($h); ?></td>
                                    <td class="px-6 py-3 text-sm"><?php echo $e['billable'] ? '<span class="text-green-600">Yes</span>' : '<span class="text-gray-400">No</span>'; ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_money($amt); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">By User</h2>
                    </div>
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">User</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hours</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Billable</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($by_user)): ?><tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No data.</td></tr>
                            <?php else: foreach ($by_user as $u => $d): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($u); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_hours($d['hours']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-500"><?php echo fmt_hours($d['billable_hours']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_money($d['amount']); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">By Client</h2>
                    </div>
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hours</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($by_client)): ?><tr><td colspan="3" class="px-6 py-8 text-center text-gray-500">No data.</td></tr>
                            <?php else: foreach ($by_client as $c => $d): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($c); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_hours($d['hours']); ?></td>
                                    <td class="px-6 py-3 text-sm text-gray-700"><?php echo fmt_money($d['amount']); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
