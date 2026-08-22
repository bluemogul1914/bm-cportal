<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    portal_redirect('/portal');
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = $_SESSION['is_admin'] ?? false;

try {
    $pdo = getDB();

    $client_stmt = $pdo->prepare("SELECT id FROM clients WHERE user_id = ?");
    $client_stmt->execute([$user_id]);
    $client = $client_stmt->fetch();
    $client_id = $client ? $client['id'] : null;

    if ($client_id) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                (SELECT COUNT(*) FROM project_tasks pt WHERE pt.project_id = p.id) as task_count,
                (SELECT COUNT(*) FROM project_tasks pt WHERE pt.project_id = p.id AND pt.status = 'done') as tasks_done
            FROM projects p
            WHERE p.client_id = ?
            ORDER BY CASE WHEN p.status IN ('in_progress','review') THEN 0 WHEN p.status = 'planning' THEN 1 WHEN p.status = 'on_hold' THEN 2 ELSE 3 END, p.updated_at DESC
        ");
        $stmt->execute([$client_id]);
        $projects = $stmt->fetchAll();
    } else {
        $projects = [];
    }

    $active_count = 0;
    $completed_count = 0;
    foreach ($projects as $p) {
        if (in_array($p['status'], ['in_progress', 'review', 'planning'])) $active_count++;
        if ($p['status'] === 'completed') $completed_count++;
    }
} catch (PDOException $e) {
    $projects = [];
    $active_count = 0;
    $completed_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects - Blue Mogul Client Portal</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>tailwind.config = { theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } } }</script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/client-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <h1 class="text-2xl font-semibold text-gray-900" data-testid="text-page-title"><i class="fas fa-project-diagram text-blue-500 mr-2"></i>My Projects</h1>
                <p class="text-sm text-gray-500 mt-0.5">Track the progress of your active projects and deployments</p>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Total Projects</p>
                    <p class="text-3xl font-bold text-gray-900" data-testid="stat-total"><?php echo count($projects); ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Active</p>
                    <p class="text-3xl font-bold text-blue-600" data-testid="stat-active"><?php echo $active_count; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Completed</p>
                    <p class="text-3xl font-bold text-green-600" data-testid="stat-completed"><?php echo $completed_count; ?></p>
                </div>
            </div>

            <?php if (empty($projects)): ?>
                <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                    <i class="fas fa-project-diagram text-5xl text-gray-300 mb-4"></i>
                    <h2 class="text-lg font-semibold text-gray-700 mb-1">No Projects Yet</h2>
                    <p class="text-sm text-gray-500">When we start a project for your account, it will appear here with full progress tracking.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
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
                            $s_class = $status_styles[$project['status']] ?? 'bg-gray-100 text-gray-700';
                            $progress = (int)$project['progress'];
                            $status_label = ucwords(str_replace('_', ' ', $project['status']));
                        ?>
                        <div class="bg-white rounded-lg border border-gray-200 p-5 hover:shadow-md transition" data-testid="card-project-<?php echo $project['id']; ?>">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900" data-testid="text-project-name-<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></h3>
                                    <p class="text-sm text-gray-500 mt-0.5"><?php echo ucfirst($project['project_type']); ?></p>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $s_class; ?>" data-testid="badge-status-<?php echo $project['id']; ?>"><?php echo $status_label; ?></span>
                            </div>
                            <?php if ($project['description']): ?>
                                <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars(substr($project['description'], 0, 200)); ?></p>
                            <?php endif; ?>
                            <div class="mb-3">
                                <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                                    <span>Progress</span>
                                    <span class="font-semibold"><?php echo $progress; ?>%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="h-2.5 rounded-full transition-all <?php echo $progress >= 100 ? 'bg-green-500' : 'bg-blue-500'; ?>" style="width: <?php echo min($progress, 100); ?>%"></div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-xs text-gray-400">
                                <div class="flex items-center gap-4">
                                    <?php if ($project['task_count'] > 0): ?>
                                        <span><i class="fas fa-tasks mr-1"></i><?php echo $project['tasks_done']; ?>/<?php echo $project['task_count']; ?> tasks</span>
                                    <?php endif; ?>
                                    <?php if ($project['due_date']): ?>
                                        <span><i class="fas fa-calendar mr-1"></i>Due: <?php echo date('M d, Y', strtotime($project['due_date'])); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($project['assigned_to']): ?>
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($project['assigned_to']); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($project['task_count'] > 0): ?>
                                <?php
                                    $task_stmt = $pdo->prepare("SELECT title, status, due_date FROM project_tasks WHERE project_id = ? ORDER BY sort_order ASC LIMIT 5");
                                    $task_stmt->execute([$project['id']]);
                                    $visible_tasks = $task_stmt->fetchAll();
                                ?>
                                <div class="mt-4 pt-3 border-t border-gray-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Recent Tasks</p>
                                    <div class="space-y-1.5">
                                        <?php foreach ($visible_tasks as $vt): ?>
                                            <div class="flex items-center gap-2 text-sm">
                                                <?php if ($vt['status'] === 'done'): ?>
                                                    <i class="fas fa-check-circle text-green-500 text-xs"></i>
                                                    <span class="text-gray-500 line-through"><?php echo htmlspecialchars($vt['title']); ?></span>
                                                <?php elseif ($vt['status'] === 'in_progress'): ?>
                                                    <i class="fas fa-spinner text-blue-500 text-xs"></i>
                                                    <span class="text-gray-700"><?php echo htmlspecialchars($vt['title']); ?></span>
                                                <?php else: ?>
                                                    <i class="far fa-circle text-gray-400 text-xs"></i>
                                                    <span class="text-gray-600"><?php echo htmlspecialchars($vt['title']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>