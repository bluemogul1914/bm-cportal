<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$project_id) {
    portal_redirect('admin-projects.php');
}

$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';

    if ($action === 'update_project') {
        try {
            $stmt = $pdo->prepare("UPDATE projects SET name=?, description=?, status=?, priority=?, project_type=?, assigned_to=?, start_date=?, due_date=?, progress=?, updated_at=NOW() WHERE id=?");
            $new_status = $_POST['status'];
            $completed_at_sql = "";
            if ($new_status === 'completed') {
                $pdo->prepare("UPDATE projects SET completed_at = COALESCE(completed_at, NOW()) WHERE id = ?")->execute([$project_id]);
            }
            $stmt->execute([
                $_POST['name'], $_POST['description'] ?: null, $new_status, $_POST['priority'], $_POST['project_type'],
                $_POST['assigned_to'] ?: null, $_POST['start_date'] ?: null, $_POST['due_date'] ?: null,
                (int)$_POST['progress'], $project_id
            ]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([
                $_SESSION['user_id'], 'project_updated', 'project', $project_id, 'Updated project: ' . $_POST['name'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
            $success_message = "Project updated!";
        } catch (PDOException $e) { $error_message = $e->getMessage(); }
    } elseif ($action === 'add_task') {
        try {
            $max_sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM project_tasks WHERE project_id = ?");
            $max_sort->execute([$project_id]);
            $next_sort = $max_sort->fetchColumn();
            $pdo->prepare("INSERT INTO project_tasks (project_id, title, description, status, priority, assigned_to, due_date, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $project_id, $_POST['task_title'], $_POST['task_description'] ?: null, 'todo', $_POST['task_priority'] ?: 'medium',
                $_POST['task_assigned_to'] ?: null, $_POST['task_due_date'] ?: null, $next_sort
            ]);
            $success_message = "Task added!";
        } catch (PDOException $e) { $error_message = $e->getMessage(); }
    } elseif ($action === 'update_task_status') {
        try {
            $new_status = $_POST['task_status'];
            $completed = $new_status === 'done' ? 'NOW()' : 'NULL';
            $pdo->prepare("UPDATE project_tasks SET status=?, completed_at = CASE WHEN ? = 'done' THEN NOW() ELSE NULL END, updated_at=NOW() WHERE id=? AND project_id=?")->execute([
                $new_status, $new_status, $_POST['task_id'], $project_id
            ]);
            $task_counts = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) as done FROM project_tasks WHERE project_id = ?");
            $task_counts->execute([$project_id]);
            $tc = $task_counts->fetch();
            if ($tc['total'] > 0) {
                $auto_progress = round(($tc['done'] / $tc['total']) * 100);
                $pdo->prepare("UPDATE projects SET progress = ?, updated_at = NOW() WHERE id = ?")->execute([$auto_progress, $project_id]);
            }
            $success_message = "Task status updated!";
        } catch (PDOException $e) { $error_message = $e->getMessage(); }
    } elseif ($action === 'delete_task') {
        try {
            $pdo->prepare("DELETE FROM project_tasks WHERE id=? AND project_id=?")->execute([$_POST['task_id'], $project_id]);
            $success_message = "Task deleted.";
        } catch (PDOException $e) { $error_message = $e->getMessage(); }
    } elseif ($action === 'add_note') {
        try {
            $pdo->prepare("INSERT INTO project_notes (project_id, user_id, note) VALUES (?, ?, ?)")->execute([
                $project_id, $_SESSION['user_id'], $_POST['note']
            ]);
            $success_message = "Note added!";
        } catch (PDOException $e) { $error_message = $e->getMessage(); }
    } elseif ($action === 'add_time_entry') {
        try {
            $hours = floatval($_POST['time_hours'] ?? 0);
            $desc = trim($_POST['time_description'] ?? '');
            $billable = isset($_POST['time_billable']) ? true : false;
            $rate = floatval($_POST['time_rate'] ?? 0);
            if ($hours > 0) {
                $pdo->prepare("INSERT INTO project_time_entries (project_id, user_id, user_name, hours, description, billable, rate) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$project_id, $_SESSION['user_id'], $user_name, $hours, $desc, $billable, $rate]);
                $success_message = "Time entry added!";
            } else {
                $error_message = "Hours must be greater than 0.";
            }
        } catch (PDOException $e) { $error_message = $e->getMessage(); }
    } elseif ($action === 'delete_time_entry') {
        try {
            $pdo->prepare("DELETE FROM project_time_entries WHERE id = ? AND project_id = ?")->execute([$_POST['time_entry_id'], $project_id]);
            $success_message = "Time entry deleted.";
        } catch (PDOException $e) { $error_message = $e->getMessage(); }
    }
}

try {
    $stmt = $pdo->prepare("SELECT p.*, c.name as client_name, c.company as client_company FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    if (!$project) { portal_redirect('admin-projects.php'); exit(); }

    $tasks_stmt = $pdo->prepare("SELECT * FROM project_tasks WHERE project_id = ? ORDER BY sort_order ASC, created_at ASC");
    $tasks_stmt->execute([$project_id]);
    $tasks = $tasks_stmt->fetchAll();

    $notes_stmt = $pdo->prepare("SELECT pn.*, u.name as author_name FROM project_notes pn LEFT JOIN users u ON pn.user_id = u.id WHERE pn.project_id = ? ORDER BY pn.created_at DESC");
    $notes_stmt->execute([$project_id]);
    $notes = $notes_stmt->fetchAll();

    $clients = $pdo->query("SELECT id, name, company FROM clients ORDER BY name")->fetchAll();

    $time_entries = $pdo->prepare("SELECT * FROM project_time_entries WHERE project_id = ? ORDER BY created_at DESC");
    $time_entries->execute([$project_id]);
    $time_entries = $time_entries->fetchAll();
} catch (PDOException $e) {
    portal_redirect('admin-projects.php');
}

$total_hours = 0; $billable_hours = 0; $billable_amount = 0;
foreach (($time_entries ?? []) as $te) {
    $total_hours += (float)$te['hours'];
    if ($te['billable']) {
        $billable_hours += (float)$te['hours'];
        $billable_amount += (float)$te['hours'] * (float)$te['rate'];
    }
}

$task_groups = ['todo' => [], 'in_progress' => [], 'review' => [], 'done' => []];
foreach ($tasks as $t) {
    $s = $t['status'] ?? 'todo';
    if (!isset($task_groups[$s])) $task_groups[$s] = [];
    $task_groups[$s][] = $t;
}

$status_styles = [
    'planning' => 'bg-gray-100 text-gray-700', 'in_progress' => 'bg-blue-100 text-blue-700',
    'on_hold' => 'bg-yellow-100 text-yellow-700', 'review' => 'bg-purple-100 text-purple-700',
    'completed' => 'bg-green-100 text-green-700', 'cancelled' => 'bg-red-100 text-red-700',
];
$priority_styles = [
    'low' => 'bg-gray-100 text-gray-600', 'medium' => 'bg-blue-100 text-blue-600',
    'high' => 'bg-orange-100 text-orange-600', 'critical' => 'bg-red-100 text-red-600',
];
$task_status_icons = ['todo' => 'fa-circle text-gray-400', 'in_progress' => 'fa-spinner text-blue-500', 'review' => 'fa-eye text-purple-500', 'done' => 'fa-check-circle text-green-500'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project['name']); ?> - Blue Mogul Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="admin-projects.php" class="text-gray-400 hover:text-gray-600" data-testid="link-back"><i class="fas fa-arrow-left"></i></a>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-project-name"><?php echo htmlspecialchars($project['name']); ?></h1>
                        <p class="text-sm text-gray-500 mt-0.5"><?php echo htmlspecialchars($project['client_company'] ?: $project['client_name'] ?: 'Internal Project'); ?> &middot; <?php echo ucfirst($project['project_type']); ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-3 py-1.5 rounded-full text-xs font-medium <?php echo $status_styles[$project['status']] ?? 'bg-gray-100 text-gray-700'; ?>" data-testid="badge-project-status"><?php echo ucwords(str_replace('_', ' ', $project['status'])); ?></span>
                    <span class="px-3 py-1.5 rounded-full text-xs font-medium <?php echo $priority_styles[$project['priority']] ?? 'bg-gray-100 text-gray-600'; ?>" data-testid="badge-project-priority"><?php echo ucfirst($project['priority']); ?></span>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?>
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="section-progress">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-700">Progress</h3>
                            <span class="text-sm font-bold <?php echo $project['progress'] >= 100 ? 'text-green-600' : 'text-blue-600'; ?>"><?php echo $project['progress']; ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div class="h-3 rounded-full transition-all <?php echo $project['progress'] >= 100 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo min((int)$project['progress'], 100); ?>%"></div>
                        </div>
                        <div class="flex justify-between text-xs text-gray-400 mt-2">
                            <span><?php echo count($task_groups['done']); ?> of <?php echo count($tasks); ?> tasks done</span>
                            <?php if ($project['due_date']): ?>
                                <span>Due: <?php echo date('M d, Y', strtotime($project['due_date'])); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200" data-testid="section-tasks">
                        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-tasks text-blue-500 mr-2"></i>Tasks (<?php echo count($tasks); ?>)</h2>
                            <button onclick="document.getElementById('taskModal').classList.remove('hidden')" class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md font-medium" data-testid="button-add-task"><i class="fas fa-plus mr-1"></i>Add Task</button>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <?php if (empty($tasks)): ?>
                                <div class="p-8 text-center text-gray-500 text-sm">
                                    <i class="fas fa-tasks text-3xl text-gray-300 mb-2 block"></i>
                                    <p>No tasks yet. Add your first task to track progress.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach (['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'] as $group_key => $group_label): ?>
                                    <?php if (!empty($task_groups[$group_key])): ?>
                                        <div class="px-5 py-2 bg-gray-50">
                                            <p class="text-xs font-semibold text-gray-500 uppercase"><?php echo $group_label; ?> (<?php echo count($task_groups[$group_key]); ?>)</p>
                                        </div>
                                        <?php foreach ($task_groups[$group_key] as $task): ?>
                                            <div class="px-5 py-3 flex items-center justify-between hover:bg-gray-50 transition" data-testid="task-row-<?php echo $task['id']; ?>">
                                                <div class="flex items-center gap-3">
                                                    <i class="fas <?php echo $task_status_icons[$task['status']] ?? 'fa-circle text-gray-400'; ?>"></i>
                                                    <div>
                                                        <p class="text-sm font-medium text-gray-900 <?php echo $task['status'] === 'done' ? 'line-through text-gray-500' : ''; ?>"><?php echo htmlspecialchars($task['title']); ?></p>
                                                        <div class="flex items-center gap-2 mt-0.5">
                                                            <?php if ($task['assigned_to']): ?><span class="text-xs text-gray-400"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($task['assigned_to']); ?></span><?php endif; ?>
                                                            <?php if ($task['due_date']): ?><span class="text-xs <?php echo ($task['due_date'] < date('Y-m-d') && $task['status'] !== 'done') ? 'text-red-500 font-semibold' : 'text-gray-400'; ?>"><i class="fas fa-calendar mr-1"></i><?php echo date('M d', strtotime($task['due_date'])); ?></span><?php endif; ?>
                                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-medium <?php echo $priority_styles[$task['priority']] ?? ''; ?>"><?php echo ucfirst($task['priority']); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <form method="POST" class="inline" data-testid="form-task-status-<?php echo $task['id']; ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="update_task_status">
                                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                        <select name="task_status" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded px-2 py-1" data-testid="select-task-status-<?php echo $task['id']; ?>">
                                                            <?php foreach (['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'] as $sk => $sl): ?>
                                                                <option value="<?php echo $sk; ?>" <?php echo $task['status'] === $sk ? 'selected' : ''; ?>><?php echo $sl; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </form>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this task?')">
                            <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_task">
                                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                        <button type="submit" class="text-red-400 hover:text-red-600 text-xs" data-testid="button-delete-task-<?php echo $task['id']; ?>"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200" data-testid="section-time-tracking">
                        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-clock text-green-500 mr-2"></i>Time Tracking</h2>
                            <div class="flex items-center gap-4 text-sm">
                                <span class="text-gray-500">Total: <strong class="text-gray-900"><?= number_format($total_hours, 1) ?>h</strong></span>
                                <span class="text-gray-500">Billable: <strong class="text-green-600">$<?= number_format($billable_amount, 2) ?></strong></span>
                            </div>
                        </div>
                        <div class="p-5">
                            <div class="bg-gray-50 rounded-lg p-4 mb-4" data-testid="timer-widget">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-sm font-semibold text-gray-700"><i class="fas fa-stopwatch mr-1"></i>Timer</h3>
                                    <span id="timerDisplay" class="text-2xl font-mono font-bold text-gray-900" data-testid="text-timer-display">00:00:00</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" id="timerStartBtn" onclick="startTimer()" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium" data-testid="button-start-timer"><i class="fas fa-play mr-1"></i>Start</button>
                                    <button type="button" id="timerStopBtn" onclick="stopTimer()" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium hidden" data-testid="button-stop-timer"><i class="fas fa-stop mr-1"></i>Stop</button>
                                    <button type="button" id="timerLogBtn" onclick="logTimerEntry()" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium hidden" data-testid="button-log-timer"><i class="fas fa-save mr-1"></i>Log Time</button>
                                    <button type="button" id="timerResetBtn" onclick="resetTimer()" class="px-3 py-1.5 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium hidden" data-testid="button-reset-timer"><i class="fas fa-redo mr-1"></i>Reset</button>
                                </div>
                            </div>

                            <form method="POST" class="bg-gray-50 rounded-lg p-4 mb-4 space-y-3" data-testid="form-add-time">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_time_entry">
                                <input type="hidden" name="time_hours" id="timeHoursInput" value="">
                                <h3 class="text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-edit mr-1"></i>Manual Entry</h3>
                                <div class="grid grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Hours *</label>
                                        <input type="number" id="manualHoursInput" min="0.1" step="0.1" value="1.0" class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm" oninput="document.getElementById('timeHoursInput').value=this.value" data-testid="input-time-hours">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Rate ($/hr)</label>
                                        <input type="number" name="time_rate" min="0" step="5" value="150" class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm" data-testid="input-time-rate">
                                    </div>
                                    <div class="flex items-end">
                                        <label class="flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" name="time_billable" checked class="rounded border-gray-300" data-testid="checkbox-time-billable">
                                            Billable
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Description</label>
                                    <input type="text" name="time_description" placeholder="What did you work on?" class="w-full px-2 py-1.5 border border-gray-300 rounded-md text-sm" data-testid="input-time-description">
                                </div>
                                <button type="submit" onclick="if(!document.getElementById('timeHoursInput').value)document.getElementById('timeHoursInput').value=document.getElementById('manualHoursInput').value" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md text-sm font-medium" data-testid="button-add-time"><i class="fas fa-plus mr-1"></i>Add Entry</button>
                            </form>

                            <?php if (!empty($time_entries)): ?>
                            <div class="space-y-2">
                                <?php foreach ($time_entries as $te): ?>
                                <div class="flex items-center justify-between bg-white border border-gray-200 rounded-lg px-4 py-2.5" data-testid="time-entry-<?= $te['id'] ?>">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-gray-900"><?= number_format((float)$te['hours'], 1) ?>h</span>
                                            <?php if ($te['billable']): ?>
                                                <span class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded">$<?= number_format((float)$te['hours'] * (float)$te['rate'], 2) ?></span>
                                            <?php else: ?>
                                                <span class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">Non-billable</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-0.5">
                                            <?= htmlspecialchars($te['description'] ?: 'No description') ?>
                                            &middot; <?= htmlspecialchars($te['user_name'] ?? 'Unknown') ?>
                                            &middot; <?= date('M d, g:i A', strtotime($te['created_at'])) ?>
                                        </p>
                                    </div>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this time entry?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_time_entry">
                                        <input type="hidden" name="time_entry_id" value="<?= $te['id'] ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-600 text-xs ml-3" data-testid="button-delete-time-<?= $te['id'] ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                                <p class="text-sm text-gray-400 text-center py-2">No time logged yet</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200" data-testid="section-notes">
                        <div class="px-5 py-4 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-sticky-note text-yellow-500 mr-2"></i>Notes</h2>
                        </div>
                        <div class="p-5">
                            <form method="POST" class="mb-4" data-testid="form-add-note">
                            <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_note">
                                <textarea name="note" required rows="2" placeholder="Add a note..." class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 mb-2" data-testid="input-note"></textarea>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-md text-sm font-medium" data-testid="button-add-note"><i class="fas fa-plus mr-1"></i>Add Note</button>
                            </form>
                            <div class="space-y-3">
                                <?php foreach ($notes as $note): ?>
                                    <div class="bg-gray-50 rounded-lg p-3" data-testid="note-<?php echo $note['id']; ?>">
                                        <p class="text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($note['note'])); ?></p>
                                        <p class="text-xs text-gray-400 mt-2">
                                            <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($note['author_name'] ?? 'System'); ?>
                                            &middot; <?php echo date('M d, Y g:i A', strtotime($note['created_at'])); ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($notes)): ?>
                                    <p class="text-sm text-gray-400 text-center py-4">No notes yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white rounded-lg border border-gray-200" data-testid="section-project-details">
                        <div class="px-5 py-4 border-b border-gray-200">
                            <h2 class="text-sm font-semibold text-gray-900">Project Details</h2>
                        </div>
                        <form method="POST" class="p-5 space-y-3" data-testid="form-update-project">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_project">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Name</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($project['name']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-edit-name">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                                <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-edit-description"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-edit-status">
                                        <?php foreach (['planning','in_progress','on_hold','review','completed','cancelled'] as $s): ?>
                                            <option value="<?php echo $s; ?>" <?php echo $project['status'] === $s ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $s)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Priority</label>
                                    <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-edit-priority">
                                        <?php foreach (['low','medium','high','critical'] as $p): ?>
                                            <option value="<?php echo $p; ?>" <?php echo $project['priority'] === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                                <select name="project_type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-edit-type">
                                    <?php foreach (['general','onboarding','deployment','migration','maintenance','consultation'] as $t): ?>
                                        <option value="<?php echo $t; ?>" <?php echo $project['project_type'] === $t ? 'selected' : ''; ?>><?php echo ucfirst($t); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Assigned To</label>
                                <input type="text" name="assigned_to" value="<?php echo htmlspecialchars($project['assigned_to'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-edit-assigned">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Start Date</label>
                                    <input type="date" name="start_date" value="<?php echo $project['start_date'] ?? ''; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-edit-start">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Due Date</label>
                                    <input type="date" name="due_date" value="<?php echo $project['due_date'] ?? ''; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-edit-due">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Progress (%)</label>
                                <input type="number" name="progress" min="0" max="100" value="<?php echo $project['progress']; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-edit-progress">
                            </div>
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-md text-sm font-medium" data-testid="button-update-project"><i class="fas fa-save mr-1"></i>Save Changes</button>
                        </form>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-5" data-testid="section-timeline">
                        <h3 class="text-sm font-semibold text-gray-900 mb-3">Timeline</h3>
                        <div class="space-y-3 text-xs">
                            <div class="flex items-center gap-2 text-gray-500">
                                <i class="fas fa-calendar-plus text-blue-500 w-4"></i>
                                <span>Created: <?php echo date('M d, Y', strtotime($project['created_at'])); ?></span>
                            </div>
                            <?php if ($project['start_date']): ?>
                            <div class="flex items-center gap-2 text-gray-500">
                                <i class="fas fa-play text-green-500 w-4"></i>
                                <span>Start: <?php echo date('M d, Y', strtotime($project['start_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($project['due_date']): ?>
                            <div class="flex items-center gap-2 <?php echo ($project['due_date'] < date('Y-m-d') && $project['status'] !== 'completed') ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>">
                                <i class="fas fa-flag-checkered w-4"></i>
                                <span>Due: <?php echo date('M d, Y', strtotime($project['due_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($project['completed_at']): ?>
                            <div class="flex items-center gap-2 text-green-600 font-semibold">
                                <i class="fas fa-check-circle w-4"></i>
                                <span>Completed: <?php echo date('M d, Y', strtotime($project['completed_at'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex items-center gap-2 text-gray-400">
                                <i class="fas fa-clock w-4"></i>
                                <span>Updated: <?php echo date('M d, Y g:i A', strtotime($project['updated_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="taskModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" data-testid="modal-add-task">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-plus text-blue-500 mr-2"></i>Add Task</h2>
            <button onclick="document.getElementById('taskModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4" data-testid="form-add-task">
                            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_task">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Task Title *</label>
                <input type="text" name="task_title" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-task-title">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="task_description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-task-description"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                    <select name="task_priority" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-task-priority">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                    <input type="date" name="task_due_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-task-due">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Assigned To</label>
                <input type="text" name="task_assigned_to" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-task-assigned">
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('taskModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium" data-testid="button-submit-task"><i class="fas fa-plus mr-1"></i>Add Task</button>
            </div>
        </form>
    </div>
</div>

<script>
let timerInterval = null;
let timerSeconds = 0;
let timerRunning = false;

function updateTimerDisplay() {
    var h = Math.floor(timerSeconds / 3600);
    var m = Math.floor((timerSeconds % 3600) / 60);
    var s = timerSeconds % 60;
    document.getElementById('timerDisplay').textContent =
        String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}

function startTimer() {
    if (timerRunning) return;
    timerRunning = true;
    document.getElementById('timerStartBtn').classList.add('hidden');
    document.getElementById('timerStopBtn').classList.remove('hidden');
    document.getElementById('timerLogBtn').classList.add('hidden');
    document.getElementById('timerResetBtn').classList.add('hidden');
    timerInterval = setInterval(function() {
        timerSeconds++;
        updateTimerDisplay();
    }, 1000);
}

function stopTimer() {
    timerRunning = false;
    clearInterval(timerInterval);
    document.getElementById('timerStartBtn').classList.remove('hidden');
    document.getElementById('timerStopBtn').classList.add('hidden');
    if (timerSeconds > 0) {
        document.getElementById('timerLogBtn').classList.remove('hidden');
        document.getElementById('timerResetBtn').classList.remove('hidden');
    }
}

function resetTimer() {
    timerSeconds = 0;
    timerRunning = false;
    clearInterval(timerInterval);
    updateTimerDisplay();
    document.getElementById('timerStartBtn').classList.remove('hidden');
    document.getElementById('timerStopBtn').classList.add('hidden');
    document.getElementById('timerLogBtn').classList.add('hidden');
    document.getElementById('timerResetBtn').classList.add('hidden');
}

function logTimerEntry() {
    var hours = Math.round((timerSeconds / 3600) * 100) / 100;
    if (hours < 0.01) hours = 0.1;
    document.getElementById('manualHoursInput').value = hours.toFixed(2);
    document.getElementById('timeHoursInput').value = hours.toFixed(2);
    resetTimer();
    document.querySelector('[data-testid="input-time-description"]').focus();
}
</script>
</body>
</html>