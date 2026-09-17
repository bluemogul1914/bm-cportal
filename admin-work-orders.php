<?php
/**
 * admin-work-orders.php — Field work orders (P1 #3).
 * Job/task records linked to client/ticket/site, checklist templates
 * (install/survey/decommission), calendar view, timestamped checklist items.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$success_message = '';
$error_message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $pdo = getDB();
    try {
        if ($action === 'create_work_order') {
            $client_id = (int)($_POST['client_id'] ?? 0) ?: null;
            $ticket_id = (int)($_POST['ticket_id'] ?? 0) ?: null;
            $project_id = (int)($_POST['project_id'] ?? 0) ?: null;
            $template = trim($_POST['checklist_template'] ?? 'install');
            $items = [];
            $t = $pdo->prepare("SELECT items FROM work_order_checklist_templates WHERE name = ?");
            $t->execute([$template]);
            $row = $t->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach (json_decode($row['items'], true) ?: [] as $it) {
                    $items[] = ['item' => $it, 'done' => false, 'done_at' => null];
                }
            }
            $stmt = $pdo->prepare("INSERT INTO work_orders
                (client_id, ticket_id, project_id, site_name, address, scheduled_date, assignee, status, checklist_template, checklist, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, NOW(), NOW())");
            $stmt->execute([
                $client_id, $ticket_id, $project_id,
                trim($_POST['site_name'] ?? '') ?: null,
                trim($_POST['address'] ?? '') ?: null,
                $_POST['scheduled_date'] ?? null ?: null,
                trim($_POST['assignee'] ?? '') ?: null,
                $template, json_encode($items), trim($_POST['notes'] ?? '') ?: null,
            ]);
            $wo_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$_SESSION['user_id'], 'work_order_created', 'work_order', $wo_id, 'Created work order #' . $wo_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $success_message = "Work order #$wo_id created.";
        } elseif ($action === 'update_status') {
            $wo_id = (int)($_POST['work_order_id'] ?? 0);
            $status = trim($_POST['status'] ?? 'open');
            $pdo->prepare("UPDATE work_orders SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $wo_id]);
            $success_message = "Work order #$wo_id status updated.";
        } elseif ($action === 'toggle_checklist_item') {
            $wo_id = (int)($_POST['work_order_id'] ?? 0);
            $idx = (int)($_POST['item_index'] ?? -1);
            $st = $pdo->prepare("SELECT checklist FROM work_orders WHERE id = ?");
            $st->execute([$wo_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $list = json_decode($row['checklist'], true) ?: [];
                if (isset($list[$idx])) {
                    $list[$idx]['done'] = !($list[$idx]['done'] ?? false);
                    $list[$idx]['done_at'] = $list[$idx]['done'] ? date('c') : null;
                    $pdo->prepare("UPDATE work_orders SET checklist = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([json_encode($list), $wo_id]);
                    $success_message = "Checklist item updated.";
                }
            }
        } elseif ($action === 'delete_work_order') {
            $wo_id = (int)($_POST['work_order_id'] ?? 0);
            $pdo->prepare("DELETE FROM work_orders WHERE id = ?")->execute([$wo_id]);
            $success_message = "Work order #$wo_id deleted.";
        }
    } catch (PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

$pdo = getDB();
$view = $_GET['view'] ?? 'list';
$detail_id = (int)($_GET['id'] ?? 0);

// Data
$clients = $pdo->query("SELECT id, name, company FROM clients ORDER BY name")->fetchAll();
$templates = $pdo->query("SELECT name, items FROM work_order_checklist_templates ORDER BY name")->fetchAll();
$tickets = $pdo->query("SELECT id, subject, client_id FROM tickets WHERE status NOT IN ('closed','resolved') ORDER BY created_at DESC LIMIT 100")->fetchAll();
$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name LIMIT 100")->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) total,
    SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) open,
    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) in_progress,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
    SUM(CASE WHEN scheduled_date < CURRENT_DATE AND status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) overdue
    FROM work_orders")->fetch();

$work_orders = $pdo->query("SELECT w.*, c.name AS client_name, t.subject AS ticket_subject
    FROM work_orders w
    LEFT JOIN clients c ON w.client_id = c.id
    LEFT JOIN tickets t ON w.ticket_id = t.id
    ORDER BY w.scheduled_date IS NULL, w.scheduled_date ASC, w.created_at DESC")->fetchAll();

$detail = null;
if ($detail_id) {
    $st = $pdo->prepare("SELECT w.*, c.name AS client_name, t.subject AS ticket_subject FROM work_orders w
        LEFT JOIN clients c ON w.client_id = c.id LEFT JOIN tickets t ON w.ticket_id = t.id WHERE w.id = ?");
    $st->execute([$detail_id]);
    $detail = $st->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Field Work Orders - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
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
                    <h1 class="text-2xl font-semibold text-gray-900"><i class="fas fa-clipboard-check text-blue-500 mr-2"></i>Field Work Orders</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Jobs, checklists, and scheduling for field installs</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="admin-work-orders.php?view=calendar" class="px-3 py-2 rounded-lg text-sm font-medium <?php echo $view==='calendar'?'bg-blue-100 text-blue-700':'text-gray-600 hover:bg-gray-100'; ?>"><i class="fas fa-calendar-alt mr-1"></i>Calendar</a>
                    <a href="admin-work-orders.php" class="px-3 py-2 rounded-lg text-sm font-medium <?php echo $view==='list'?'bg-blue-100 text-blue-700':'text-gray-600 hover:bg-gray-100'; ?>"><i class="fas fa-list mr-1"></i>List</a>
                    <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition"><i class="fas fa-plus mr-2"></i>New Work Order</button>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?><div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
            <?php if (!empty($error_message)): ?><div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total</p><p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['total']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Open</p><p class="text-3xl font-bold text-blue-600"><?php echo (int)$stats['open']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">In Progress</p><p class="text-3xl font-bold text-yellow-600"><?php echo (int)$stats['in_progress']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Completed</p><p class="text-3xl font-bold text-green-600"><?php echo (int)$stats['completed']; ?></p></div>
                <div class="bg-white rounded-lg border border-gray-200 p-5"><p class="text-xs font-semibold text-gray-500 uppercase mb-1">Overdue</p><p class="text-3xl font-bold <?php echo (int)$stats['overdue']>0?'text-red-600':'text-gray-400'; ?>"><?php echo (int)$stats['overdue']; ?></p></div>
            </div>

            <?php if ($detail): ?>
                <?php $list = json_decode($detail['checklist'], true) ?: []; $done = count(array_filter($list, fn($i)=>!empty($i['done']))); ?>
                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">Work Order #<?php echo $detail['id']; ?> — <?php echo htmlspecialchars($detail['site_name'] ?: 'Untitled'); ?></h2>
                            <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($detail['client_name'] ?: 'No client'); ?><?php echo $detail['ticket_subject'] ? ' · Ticket: ' . htmlspecialchars($detail['ticket_subject']) : ''; ?></p>
                        </div>
                        <form method="POST" class="flex items-center gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="work_order_id" value="<?php echo $detail['id']; ?>">
                            <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                                <?php foreach (['open','in_progress','completed','cancelled'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php echo $detail['status']===$s?'selected':''; ?>><?php echo ucwords(str_replace('_',' ',$s)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md text-sm">Update</button>
                        </form>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
                        <div><p class="text-xs text-gray-500 uppercase">Scheduled</p><p class="font-medium"><?php echo $detail['scheduled_date'] ? date('M d, Y', strtotime($detail['scheduled_date'])) : '—'; ?></p></div>
                        <div><p class="text-xs text-gray-500 uppercase">Assignee</p><p class="font-medium"><?php echo htmlspecialchars($detail['assignee'] ?: '—'); ?></p></div>
                        <div><p class="text-xs text-gray-500 uppercase">Template</p><p class="font-medium"><?php echo htmlspecialchars($detail['checklist_template'] ?: '—'); ?></p></div>
                        <div><p class="text-xs text-gray-500 uppercase">Progress</p><p class="font-medium"><?php echo $done; ?>/<?php echo count($list); ?></p></div>
                    </div>
                    <?php if ($detail['address']): ?><p class="text-sm text-gray-600 mb-4"><i class="fas fa-map-marker-alt mr-1 text-gray-400"></i><?php echo htmlspecialchars($detail['address']); ?></p><?php endif; ?>
                    <?php if ($detail['notes']): ?><p class="text-sm text-gray-600 mb-4"><?php echo htmlspecialchars($detail['notes']); ?></p><?php endif; ?>

                    <h3 class="text-sm font-semibold text-gray-700 uppercase mb-3">Checklist</h3>
                    <?php if (empty($list)): ?>
                        <p class="text-sm text-gray-500">No checklist items.</p>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($list as $i => $item): ?>
                                <form method="POST" class="flex items-center gap-3 bg-gray-50 border border-gray-100 rounded-lg px-4 py-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <input type="hidden" name="action" value="toggle_checklist_item">
                                    <input type="hidden" name="work_order_id" value="<?php echo $detail['id']; ?>">
                                    <input type="hidden" name="item_index" value="<?php echo $i; ?>">
                                    <button type="submit" class="w-5 h-5 rounded border <?php echo !empty($item['done'])?'bg-green-500 border-green-500 text-white':'border-gray-300 bg-white'; ?> flex items-center justify-center text-xs">
                                        <?php echo !empty($item['done']) ? '<i class="fas fa-check"></i>' : ''; ?>
                                    </button>
                                    <span class="flex-1 text-sm <?php echo !empty($item['done'])?'text-gray-400 line-through':'text-gray-800'; ?>"><?php echo htmlspecialchars($item['item']); ?></span>
                                    <?php if (!empty($item['done_at'])): ?><span class="text-xs text-gray-400"><?php echo date('M d, H:i', strtotime($item['done_at'])); ?></span><?php endif; ?>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-6 flex gap-2">
                        <a href="admin-work-orders.php" class="text-sm text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i>Back to list</a>
                        <form method="POST" onsubmit="return confirm('Delete this work order?');">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="delete_work_order">
                            <input type="hidden" name="work_order_id" value="<?php echo $detail['id']; ?>">
                            <button class="text-sm text-red-600 hover:underline ml-4"><i class="fas fa-trash mr-1"></i>Delete</button>
                        </form>
                    </div>
                </div>
            <?php elseif ($view === 'calendar'): ?>
                <?php
                    $month = $_GET['month'] ?? date('Y-m');
                    $ts = strtotime($month . '-01');
                    $ym = date('Y-m', $ts);
                    $firstDow = (int)date('w', $ts);
                    $daysInMonth = (int)date('t', $ts);
                    $byDay = [];
                    foreach ($work_orders as $wo) {
                        if ($wo['scheduled_date']) $byDay[(int)date('j', strtotime($wo['scheduled_date']))][] = $wo;
                    }
                    $prev = date('Y-m', strtotime($month . '-01 -1 month'));
                    $next = date('Y-m', strtotime($month . '-01 +1 month'));
                ?>
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <a href="admin-work-orders.php?view=calendar&month=<?php echo $prev; ?>" class="text-gray-500 hover:text-gray-800"><i class="fas fa-chevron-left"></i></a>
                        <h2 class="text-lg font-semibold text-gray-900"><?php echo date('F Y', $ts); ?></h2>
                        <a href="admin-work-orders.php?view=calendar&month=<?php echo $next; ?>" class="text-gray-500 hover:text-gray-800"><i class="fas fa-chevron-right"></i></a>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-gray-500 uppercase mb-2">
                        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?><div class="py-1"><?php echo $d; ?></div><?php endforeach; ?>
                    </div>
                    <div class="grid grid-cols-7 gap-1">
                        <?php for ($i = 0; $i < $firstDow; $i++): ?><div></div><?php endfor; ?>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                            <div class="min-h-[70px] border border-gray-100 rounded p-1 <?php echo $d===(int)date('j') && $ym===date('Y-m') ? 'bg-blue-50' : ''; ?>">
                                <div class="text-xs font-medium text-gray-500"><?php echo $d; ?></div>
                                <?php foreach (($byDay[$d] ?? []) as $wo): ?>
                                    <a href="admin-work-orders.php?id=<?php echo $wo['id']; ?>" class="block text-[10px] truncate rounded px-1 py-0.5 mb-0.5 <?php echo $wo['status']==='completed'?'bg-green-100 text-green-700':($wo['status']==='in_progress'?'bg-yellow-100 text-yellow-700':'bg-blue-100 text-blue-700'); ?>">
                                        <?php echo htmlspecialchars($wo['site_name'] ?: '#' . $wo['id']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Work Order</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Scheduled</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Assignee</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($work_orders)): ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500">No work orders yet. Create one to get started.</td></tr>
                            <?php else: foreach ($work_orders as $wo): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4"><a href="admin-work-orders.php?id=<?php echo $wo['id']; ?>" class="font-medium text-blue-600 hover:underline">#<?php echo $wo['id']; ?> — <?php echo htmlspecialchars($wo['site_name'] ?: 'Untitled'); ?></a></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($wo['client_name'] ?: '—'); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo $wo['scheduled_date'] ? date('M d, Y', strtotime($wo['scheduled_date'])) : '—'; ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($wo['assignee'] ?: '—'); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $wo['status']==='completed'?'bg-green-100 text-green-700':($wo['status']==='in_progress'?'bg-yellow-100 text-yellow-700':($wo['status']==='cancelled'?'bg-gray-100 text-gray-500':'bg-blue-100 text-blue-700')); ?>"><?php echo ucwords(str_replace('_',' ',$wo['status'])); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create modal -->
<div id="createModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">New Work Order</h3>
            <button onclick="document.getElementById('createModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="action" value="create_work_order">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">— No client —</option>
                    <?php foreach ($clients as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ticket (optional)</label>
                    <select name="ticket_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($tickets as $t): ?><option value="<?php echo $t['id']; ?>">#<?php echo $t['id']; ?> <?php echo htmlspecialchars(substr($t['subject'],0,40)); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Project (optional)</label>
                    <select name="project_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">— None —</option>
                        <?php foreach ($projects as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Site name</label>
                <input type="text" name="site_name" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="e.g. SEARHC — Angoon clinic">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input type="text" name="address" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Street, city, state, ZIP">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled date</label>
                    <input type="date" name="scheduled_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Assignee</label>
                    <input type="text" name="assignee" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Technician name">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Checklist template</label>
                <select name="checklist_template" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <?php foreach ($templates as $tmpl): ?><option value="<?php echo htmlspecialchars($tmpl['name']); ?>"><?php echo ucfirst(htmlspecialchars($tmpl['name'])); ?> (<?php echo count(json_decode($tmpl['items'], true) ?: []); ?> items)</option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Create Work Order</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
