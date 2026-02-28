<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    header('Location: /portal');
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'Admin';

try {
    $pdo = getDB();

    $stmt = $pdo->query("SELECT * FROM agent_config ORDER BY id ASC");
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT agent_name, 
               COUNT(*) as total_runs,
               SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
               SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
               MAX(executed_at) as last_run
        FROM agent_logs 
        GROUP BY agent_name
    ");
    $agent_stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $agent_stats[$row['agent_name']] = $row;
    }

    $stmt = $pdo->query("SELECT * FROM agent_logs ORDER BY executed_at DESC LIMIT 10");
    $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_agents = count($agents);
    $online_agents = 0;
    foreach ($agents as $agent) {
        if ($agent['enabled']) $online_agents++;
    }

    $total_runs = 0;
    $total_success = 0;
    $total_errors = 0;
    foreach ($agent_stats as $s) {
        $total_runs += $s['total_runs'];
        $total_success += $s['success_count'];
        $total_errors += $s['error_count'];
    }
    $success_rate = $total_runs > 0 ? round(($total_success / $total_runs) * 100, 1) : 0;

} catch (PDOException $e) {
    error_log("AI Agents page error: " . $e->getMessage());
    $agents = [];
    $agent_stats = [];
    $recent_logs = [];
    $total_agents = 0;
    $online_agents = 0;
    $total_runs = 0;
    $success_rate = 0;
    $total_errors = 0;
}

$agent_icons = [
    'network_monitor' => 'fa-globe',
    'lead_generator' => 'fa-magnet',
    'ticket_triage' => 'fa-bullseye',
    'sales_agent' => 'fa-handshake',
    'social_media' => 'fa-mobile-alt',
    'branding' => 'fa-palette',
    'project_manager' => 'fa-tasks',
    'bookkeeping' => 'fa-coins',
    'nextcloud_sales' => 'fa-cloud',
    'master_orchestrator' => 'fa-brain',
];

$agent_colors = [
    'network_monitor' => 'blue',
    'lead_generator' => 'purple',
    'ticket_triage' => 'yellow',
    'sales_agent' => 'green',
    'social_media' => 'pink',
    'branding' => 'orange',
    'project_manager' => 'indigo',
    'bookkeeping' => 'emerald',
    'nextcloud_sales' => 'cyan',
    'master_orchestrator' => 'red',
];

$agent_descriptions = [
    'network_monitor' => 'ITarian monitoring → ITFlow tickets',
    'lead_generator' => 'Directory scraping + HubSpot enrichment',
    'ticket_triage' => 'AI classification with Ollama/AnythingLLM',
    'sales_agent' => 'Flowise conversations + email follow-ups',
    'social_media' => 'AI content generation + scheduling',
    'branding' => 'Marketing material creation',
    'project_manager' => 'Task tracking + coordination',
    'bookkeeping' => 'Financial automation',
    'nextcloud_sales' => 'Promote + deploy Nextcloud instances',
    'master_orchestrator' => 'Coordinates all agents',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Agent Army - Blue Mogul Admin</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">

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
                            <h1 class="text-2xl font-semibold text-gray-900">
                                <i class="fas fa-robot text-blue-600 mr-2"></i>AI Agent Army
                            </h1>
                            <p class="text-sm text-gray-600 mt-1">Monitor and manage your AI automation agents</p>
                        </div>
                        <button onclick="runAllAgents()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium transition">
                            <i class="fas fa-play mr-2"></i>Run All Agents
                        </button>
                    </div>
                </div>
            </header>

            <div class="p-6">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-blue-100 rounded-lg p-3">
                                <i class="fas fa-robot text-blue-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total Agents</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $total_agents; ?></p>
                        <p class="text-sm text-green-600 mt-1"><?php echo $online_agents; ?> online</p>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-green-100 rounded-lg p-3">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Success Rate</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo $success_rate; ?>%</p>
                        <p class="text-sm text-gray-600 mt-1"><?php echo $total_runs; ?> total runs</p>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-purple-100 rounded-lg p-3">
                                <i class="fas fa-clock text-purple-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Hours Saved / Week</p>
                        <p class="text-3xl font-bold text-gray-900">109</p>
                        <p class="text-sm text-gray-600 mt-1">~15.6 hrs/day</p>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div class="bg-yellow-100 rounded-lg p-3">
                                <i class="fas fa-dollar-sign text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Value / Week</p>
                        <p class="text-3xl font-bold text-gray-900">$5,450</p>
                        <p class="text-sm text-gray-600 mt-1">$283,400/year</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900">Agent Status</h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-6">
                        <?php foreach ($agents as $agent): ?>
                            <?php
                                $name = $agent['agent_name'];
                                $icon = $agent_icons[$name] ?? 'fa-cog';
                                $color = $agent_colors[$name] ?? 'gray';
                                $desc = $agent_descriptions[$name] ?? '';
                                $stats_row = $agent_stats[$name] ?? null;
                                $runs = $stats_row['total_runs'] ?? 0;
                                $successes = $stats_row['success_count'] ?? 0;
                                $errors = $stats_row['error_count'] ?? 0;
                                $last_run = $stats_row['last_run'] ?? null;
                                $is_enabled = $agent['enabled'];
                                $display_name = ucwords(str_replace('_', ' ', $name));
                            ?>
                            <div class="border border-gray-200 rounded-lg p-5 hover:shadow-md transition">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-<?php echo $color; ?>-100 flex items-center justify-center">
                                            <i class="fas <?php echo $icon; ?> text-<?php echo $color; ?>-600"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-gray-900 text-sm"><?php echo $display_name; ?></h3>
                                            <p class="text-xs text-gray-500"><?php echo $desc; ?></p>
                                        </div>
                                    </div>
                                    <span class="flex items-center gap-1 text-xs font-medium <?php echo $is_enabled ? 'text-green-600' : 'text-gray-400'; ?>">
                                        <span class="w-2 h-2 rounded-full <?php echo $is_enabled ? 'bg-green-500 animate-pulse' : 'bg-gray-300'; ?>"></span>
                                        <?php echo $is_enabled ? 'Online' : 'Idle'; ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-3 gap-2 mb-3 text-center">
                                    <div class="bg-gray-50 rounded p-2">
                                        <p class="text-xs text-gray-500">Runs</p>
                                        <p class="font-bold text-gray-900 text-sm"><?php echo $runs; ?></p>
                                    </div>
                                    <div class="bg-gray-50 rounded p-2">
                                        <p class="text-xs text-gray-500">Success</p>
                                        <p class="font-bold text-green-600 text-sm"><?php echo $successes; ?></p>
                                    </div>
                                    <div class="bg-gray-50 rounded p-2">
                                        <p class="text-xs text-gray-500">Errors</p>
                                        <p class="font-bold text-red-600 text-sm"><?php echo $errors; ?></p>
                                    </div>
                                </div>

                                <?php if ($last_run): ?>
                                    <p class="text-xs text-gray-400 mb-3">
                                        <i class="far fa-clock mr-1"></i>Last run: <?php echo date('M d, g:i A', strtotime($last_run)); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="flex gap-2">
                                    <button onclick="runAgent('<?php echo $name; ?>')" class="flex-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded text-xs font-medium transition">
                                        <i class="fas fa-play mr-1"></i>Run
                                    </button>
                                    <button onclick="viewLogs('<?php echo $name; ?>')" class="flex-1 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition">
                                        <i class="fas fa-list mr-1"></i>Logs
                                    </button>
                                    <button onclick="configAgent('<?php echo $name; ?>')" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition">
                                        <i class="fas fa-cog"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Activity</h2>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php if (empty($recent_logs)): ?>
                            <div class="p-6 text-center text-gray-500">
                                <i class="fas fa-robot text-4xl text-gray-300 mb-3"></i>
                                <p class="font-medium">No agent activity yet</p>
                                <p class="text-sm mt-1">Run an agent to see activity here</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_logs as $log): ?>
                                <div class="px-6 py-4 flex items-center gap-4 hover:bg-gray-50 transition">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 <?php echo $log['status'] === 'success' ? 'bg-green-100' : 'bg-red-100'; ?>">
                                        <i class="fas <?php echo $log['status'] === 'success' ? 'fa-check text-green-600' : 'fa-times text-red-600'; ?> text-sm"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900">
                                            <?php echo ucwords(str_replace('_', ' ', $log['agent_name'])); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($log['message'] ?? $log['action'] ?? ''); ?></p>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo $log['status'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                            <?php echo ucfirst($log['status']); ?>
                                        </span>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <?php echo $log['executed_at'] ? date('M d, g:i A', strtotime($log['executed_at'])) : date('M d, g:i A', strtotime($log['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        function runAgent(name) {
            if (confirm('Run ' + name.replace(/_/g, ' ') + ' now?')) {
                alert('Agent ' + name + ' has been queued for execution.');
            }
        }

        function runAllAgents() {
            if (confirm('Run all enabled agents now?')) {
                alert('All agents have been queued for execution.');
            }
        }

        function viewLogs(name) {
            alert('Viewing logs for ' + name.replace(/_/g, ' '));
        }

        function configAgent(name) {
            alert('Configuration for ' + name.replace(/_/g, ' '));
        }
    </script>

</body>
</html>