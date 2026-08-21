<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    portal_redirect('/portal');
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    
    $action = $_POST['action'] ?? '';
    $pdo = getDB();

    if ($action === 'create_project') {
        try {
            $stmt = $pdo->prepare("INSERT INTO projects (client_id, name, description, status, priority, project_type, assigned_to, start_date, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['client_id'] ?: null,
                $_POST['name'],
                $_POST['description'] ?: null,
                $_POST['status'] ?: 'planning',
                $_POST['priority'] ?: 'medium',
                $_POST['project_type'] ?: 'general',
                $_POST['assigned_to'] ?: null,
                $_POST['start_date'] ?: null,
                $_POST['due_date'] ?: null,
                $_SESSION['user_id']
            ]);
            $project_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([
                $_SESSION['user_id'], 'project_created', 'project', $project_id, 'Created project: ' . $_POST['name'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
            $success_message = "Project created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error creating project: " . $e->getMessage();
        }
    } elseif ($action === 'delete_project') {
        try {
            $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$_POST['project_id']]);
            $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)")->execute([
                $_SESSION['user_id'], 'project_deleted', 'project', $_POST['project_id'], 'Deleted project #' . $_POST['project_id'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
            $success_message = "Project deleted.";
        } catch (PDOException $e) {
            $error_message = "Error deleting project: " . $e->getMessage();
        }
    }
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

try {
    $pdo = getDB();

    $where_clauses = [];
    $params = [];

    if ($search) {
        $where_clauses[] = "(p.name ILIKE ? OR p.description ILIKE ? OR c.name ILIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s, $s, $s]);
    }
    if ($status_filter) {
        $where_clauses[] = "p.status = ?";
        $params[] = $status_filter;
    }
    if ($type_filter) {
        $where_clauses[] = "p.project_type = ?";
        $params[] = $type_filter;
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    $count_params = $params;
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM projects p LEFT JOIN clients c ON p.client_id = c.id $where_sql");
    $count_stmt->execute($count_params);
    $total = $count_stmt->fetch()['count'];
    $total_pages = ceil($total / $limit);

    $query_params = $params;
    $query_params[] = $limit;
    $query_params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as client_name, c.company as client_company,
            (SELECT COUNT(*) FROM project_tasks pt WHERE pt.project_id = p.id) as task_count,
            (SELECT COUNT(*) FROM project_tasks pt WHERE pt.project_id = p.id AND pt.status = 'done') as tasks_done
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        $where_sql
        ORDER BY p.updated_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($query_params);
    $projects = $stmt->fetchAll();

    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN due_date < CURRENT_DATE AND status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) as overdue
        FROM projects
    ")->fetch();

    $clients = $pdo->query("SELECT id, name, company FROM clients ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $projects = [];
    $stats = ['total' => 0, 'active' => 0, 'completed' => 0, 'overdue' => 0];
    $clients = [];
    $total_pages = 0;
    $error_message = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-project-diagram text-blue-500 mr-2"></i>Projects</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Manage client projects, onboardings, and deployments</p>
                </div>
                <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition" data-testid="button-new-project">
                    <i class="fas fa-plus mr-2"></i>New Project
                </button>
            </div>
        </header>

        <div class="p-6">
            <?php if (!empty($success_message)): ?>
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm" data-testid="alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6" data-testid="project-stats">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Projects</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="stat-total"><?php echo $stats['total']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">In Progress</p>
                    <p class="text-3xl font-bold text-blue-600" data-testid="stat-active"><?php echo $stats['active']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Completed</p>
                    <p class="text-3xl font-bold text-green-600" data-testid="stat-completed"><?php echo $stats['completed']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Overdue</p>
                    <p class="text-3xl font-bold <?php echo $stats['overdue'] > 0 ? 'text-red-600' : 'text-gray-400'; ?>" data-testid="stat-overdue"><?php echo $stats['overdue']; ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
                <form method="GET" class="flex flex-wrap gap-3" data-testid="form-project-filters">
                    <div class="flex-1 min-w-[200px]">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search projects..." class="w-full px-4 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-search">
                    </div>
                    <select name="status" class="px-4 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-status">
                        <option value="">All Statuses</option>
                        <?php foreach (['planning','in_progress','on_hold','review','completed','cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $status_filter === $s ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $s)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="type" class="px-4 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-type">
                        <option value="">All Types</option>
                        <?php foreach (['general','onboarding','deployment','migration','maintenance','consultation'] as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo $type_filter === $t ? 'selected' : ''; ?>><?php echo ucfirst($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium" data-testid="button-search"><i class="fas fa-search mr-1"></i>Search</button>
                    <?php if ($search || $status_filter || $type_filter): ?>
                        <a href="admin-projects.php" class="px-5 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium" data-testid="link-clear">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-200" data-testid="projects-table">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Project</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Priority</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Progress</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Due Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($projects)): ?>
                                <tr><td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-project-diagram text-4xl mb-3 text-gray-300 block"></i>
                                    <p class="font-medium">No projects found</p>
                                    <p class="text-sm text-gray-400 mt-1">Create your first project to get started</p>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($projects as $project): ?>
                                    <?php
                                        $status_styles = [
                                            'planning' => 'bg-gray-100 text-gray-700',
                                            'in_progress' => 'bg-blue-100 text-blue-700',
                                            'on_hold' => 'bg-yellow-100 text-yellow-700',
                                            'review' => 'bg-purple-100 text-purple-700',
                                            'completed' => 'bg-green-100 text-green-700',
                                            'cancelled' => 'bg-red-100 text-red-700',
                                        ];
                                        $priority_styles = [
                                            'low' => 'bg-gray-100 text-gray-600',
                                            'medium' => 'bg-blue-100 text-blue-600',
                                            'high' => 'bg-orange-100 text-orange-600',
                                            'critical' => 'bg-red-100 text-red-600',
                                        ];
                                        $s_class = $status_styles[$project['status']] ?? 'bg-gray-100 text-gray-700';
                                        $p_class = $priority_styles[$project['priority']] ?? 'bg-gray-100 text-gray-600';
                                        $progress = (int)$project['progress'];
                                        $is_overdue = $project['due_date'] && $project['due_date'] < date('Y-m-d') && !in_array($project['status'], ['completed','cancelled']);
                                    ?>
                                    <tr class="hover:bg-gray-50 transition" data-testid="row-project-<?php echo $project['id']; ?>">
                                        <td class="px-6 py-4">
                                            <a href="admin-project-detail.php?id=<?php echo $project['id']; ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-800" data-testid="link-project-<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></a>
                                            <?php if ($project['task_count'] > 0): ?>
                                                <p class="text-xs text-gray-400 mt-0.5"><?php echo $project['tasks_done']; ?>/<?php echo $project['task_count']; ?> tasks complete</p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($project['client_company'] ?: $project['client_name'] ?: 'Unassigned'); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo ucfirst($project['project_type']); ?></td>
                                        <td class="px-6 py-4"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $s_class; ?>"><?php echo ucwords(str_replace('_', ' ', $project['status'])); ?></span></td>
                                        <td class="px-6 py-4"><span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $p_class; ?>"><?php echo ucfirst($project['priority']); ?></span></td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2">
                                                <div class="w-20 bg-gray-200 rounded-full h-2"><div class="h-2 rounded-full <?php echo $progress >= 100 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo min($progress, 100); ?>%"></div></div>
                                                <span class="text-xs text-gray-500 font-medium"><?php echo $progress; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm <?php echo $is_overdue ? 'text-red-600 font-semibold' : 'text-gray-600'; ?>">
                                            <?php echo $project['due_date'] ? date('M d, Y', strtotime($project['due_date'])) : '-'; ?>
                                            <?php if ($is_overdue): ?><i class="fas fa-exclamation-circle ml-1"></i><?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <a href="admin-project-detail.php?id=<?php echo $project['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm" data-testid="button-view-<?php echo $project['id']; ?>"><i class="fas fa-eye"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                        <div class="text-sm text-gray-600">Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total); ?> of <?php echo $total; ?></div>
                        <div class="flex space-x-2">
                            <?php $qs = http_build_query(array_filter(['search' => $search, 'status' => $status_filter, 'type' => $type_filter])); ?>
                            <?php if ($page > 1): ?><a href="?page=<?php echo $page - 1; ?>&<?php echo $qs; ?>" class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50" data-testid="link-prev">Previous</a><?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&<?php echo $qs; ?>" class="px-3 py-2 <?php echo $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> rounded-md text-sm"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?><a href="?page=<?php echo $page + 1; ?>&<?php echo $qs; ?>" class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50" data-testid="link-next">Next</a><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="createModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" data-testid="modal-create-project">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-plus text-blue-500 mr-2"></i>New Project</h2>
            <button onclick="document.getElementById('createModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600" data-testid="button-close-modal"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4" data-testid="form-create-project">
                            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_project">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Project Name *</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-project-name">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-client">
                    <option value="">No Client (Internal)</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['company'] ?: $c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500" data-testid="input-description"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="project_type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-project-type">
                        <option value="general">General</option>
                        <option value="onboarding">Onboarding</option>
                        <option value="deployment">Deployment</option>
                        <option value="migration">Migration</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="consultation">Consultation</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                    <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-priority">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                    <input type="date" name="start_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-start-date">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                    <input type="date" name="due_date" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-due-date">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="select-create-status">
                    <option value="planning">Planning</option>
                    <option value="in_progress">In Progress</option>
                    <option value="on_hold">On Hold</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Assigned To</label>
                <input type="text" name="assigned_to" placeholder="Team member name" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" data-testid="input-assigned-to">
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium" data-testid="button-cancel">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium" data-testid="button-create-submit"><i class="fas fa-plus mr-1"></i>Create Project</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>