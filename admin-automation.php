<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true) {
    header('Location: /portal');
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_email = $_SESSION['user_email'] ?? '';
$is_admin = true;

$pdo = getDB();

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM agent_config WHERE enabled = true");
    $active_agents = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM agent_logs WHERE executed_at >= NOW() - INTERVAL '24 hours'");
    $runs_today = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM agent_logs WHERE executed_at >= NOW() - INTERVAL '24 hours' AND status = 'error'");
    $errors_today = $stmt->fetchColumn();

} catch (PDOException $e) {
    $active_agents = 0;
    $runs_today = 0;
    $errors_today = 0;
}

$deployment_roadmap = [
    [
        'week' => 1,
        'title' => 'Client Service Foundation',
        'priority' => 'SENTINEL + GUARDIAN',
        'status' => 'complete',
        'tasks' => [
            ['Install Uptime-Kuma on Coolify', true],
            ['Configure ITarian API webhooks to N8N', true],
            ['Build SENTINEL-001 workflow (alert triage)', true],
            ['Train AnythingLLM on past ticket data', true],
            ['Build GUARDIAN-001 workflow (email → ticket)', true],
            ['Deploy Flowise client portal chatbot', true],
            ['Test all workflows, go live', true],
        ]
    ],
    [
        'week' => 2,
        'title' => 'Revenue Generation — Pipeline Building',
        'priority' => 'HUNTER + CLOSER',
        'status' => 'in_progress',
        'tasks' => [
            ['Connect N8N to Google Maps API', true],
            ['Build HUNTER-001 (overnight lead scraping)', true],
            ['Configure HubSpot API integration', true],
            ['Build HUNTER-003 (email sequences)', false],
            ['Build CLOSER-002 (proposal automation)', false],
            ['Configure Flowise website chatbot', false],
            ['Launch all sales automation', false],
        ]
    ],
    [
        'week' => 3,
        'title' => 'Operations — Scale & Efficiency',
        'priority' => 'PUBLISHER + ACCOUNTANT',
        'status' => 'pending',
        'tasks' => [
            ['Train AnythingLLM on brand voice', false],
            ['Build PUBLISHER-001 (weekly content gen)', false],
            ['Set up HubSpot social scheduling', false],
            ['Connect Relay Financial API', false],
            ['Build ACCOUNTANT-001 (auto-invoicing)', false],
            ['Build ACCOUNTANT-003 (payment reminders)', false],
            ['Test all workflows', false],
        ]
    ],
    [
        'week' => 4,
        'title' => 'Advanced — Cloud Revenue + Orchestration',
        'priority' => 'COMMANDER + CLOUDMASTER + ORCHESTRATOR',
        'status' => 'pending',
        'tasks' => [
            ['Deploy Nextcloud Deck on Coolify', false],
            ['Build COMMANDER-001 (project automation)', false],
            ['Set up Nextcloud AIO staging server', false],
            ['Build CLOUDMASTER-001 (auto-deploy)', false],
            ['Train ORCHESTRATOR on all agent knowledge', false],
            ['Build ORCHESTRATOR daily briefing', false],
            ['Final testing, documentation', false],
        ]
    ],
];

$integrations = [
    [
        'name' => 'N8N',
        'icon' => 'fa-project-diagram',
        'color' => 'orange',
        'description' => 'Workflow automation engine — the backbone of all agent workflows',
        'status' => 'connected',
        'url_label' => 'Self-hosted on Coolify',
    ],
    [
        'name' => 'AnythingLLM',
        'icon' => 'fa-brain',
        'color' => 'purple',
        'description' => 'AI knowledge base for ticket triage, content generation, and agent intelligence',
        'status' => 'connected',
        'url_label' => 'Self-hosted on Coolify',
    ],
    [
        'name' => 'Flowise',
        'icon' => 'fa-robot',
        'color' => 'blue',
        'description' => 'AI agent builder for chatbots, lead qualification, and client support',
        'status' => 'connected',
        'url_label' => 'Self-hosted on Coolify',
    ],
    [
        'name' => 'Ollama',
        'icon' => 'fa-microchip',
        'color' => 'green',
        'description' => 'Local LLM inference — zero API costs for AI processing',
        'status' => 'connected',
        'url_label' => 'Self-hosted',
    ],
    [
        'name' => 'ITarian RMM',
        'icon' => 'fa-desktop',
        'color' => 'cyan',
        'description' => 'Remote monitoring & management for all client devices',
        'status' => 'connected',
        'url_label' => 'Cloud service (free tier)',
    ],
    [
        'name' => 'ITFlow',
        'icon' => 'fa-ticket-alt',
        'color' => 'yellow',
        'description' => 'PSA & ticketing system for service delivery',
        'status' => 'connected',
        'url_label' => 'Self-hosted on Coolify',
    ],
    [
        'name' => 'HubSpot CRM',
        'icon' => 'fa-handshake',
        'color' => 'red',
        'description' => 'Sales pipeline, lead management, and contact tracking',
        'status' => 'connected',
        'url_label' => 'Free tier',
    ],
    [
        'name' => 'Uptime-Kuma',
        'icon' => 'fa-heartbeat',
        'color' => 'pink',
        'description' => 'Website & service uptime monitoring every 60 seconds',
        'status' => 'connected',
        'url_label' => 'Self-hosted on Coolify',
    ],
    [
        'name' => 'Matrix',
        'icon' => 'fa-comments',
        'color' => 'indigo',
        'description' => 'Team messaging and agent notification channel',
        'status' => 'connected',
        'url_label' => 'Self-hosted on Coolify',
    ],
    [
        'name' => 'Grafana',
        'icon' => 'fa-chart-line',
        'color' => 'emerald',
        'description' => 'Real-time dashboards for system health and client metrics',
        'status' => 'connected',
        'url_label' => 'Self-hosted on Coolify',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automation - Blue Mogul Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">
                        <i class="fas fa-magic text-purple-600 mr-2"></i>Automation & Deployment
                    </h1>
                    <p class="text-sm text-gray-600 mt-1">30-day roadmap, integrations, and workflow management</p>
                </div>
                <a href="admin-ai-agents.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition">
                    <i class="fas fa-robot mr-1"></i>AI Agents
                </a>
            </div>
        </header>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-green-100 rounded-lg p-2.5"><i class="fas fa-plug text-green-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Active Agents</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $active_agents; ?>/10</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-blue-100 rounded-lg p-2.5"><i class="fas fa-play text-blue-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Runs (24h)</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $runs_today; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-red-100 rounded-lg p-2.5"><i class="fas fa-exclamation-triangle text-red-600"></i></div>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Errors (24h)</p>
                    <p class="text-2xl font-bold <?php echo $errors_today > 0 ? 'text-red-600' : 'text-gray-900'; ?>"><?php echo $errors_today; ?></p>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-road text-blue-500 mr-2"></i>30-Day Agent Army Deployment Roadmap</h2>
                    <p class="text-sm text-gray-500 mt-1">Build your agent army in 4 weeks</p>
                </div>
                <div class="p-6 space-y-6">
                    <?php foreach ($deployment_roadmap as $week): ?>
                        <?php
                            $completed = count(array_filter($week['tasks'], fn($t) => $t[1]));
                            $total = count($week['tasks']);
                            $pct = round(($completed / $total) * 100);
                            $status_color = match($week['status']) {
                                'complete' => 'green',
                                'in_progress' => 'blue',
                                default => 'gray'
                            };
                        ?>
                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <div class="px-5 py-4 bg-<?php echo $status_color; ?>-50 border-b border-gray-200 flex items-center justify-between">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-<?php echo $status_color; ?>-700">WEEK <?php echo $week['week']; ?></span>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-700">
                                            <?php echo match($week['status']) { 'complete' => 'Complete', 'in_progress' => 'In Progress', default => 'Pending' }; ?>
                                        </span>
                                    </div>
                                    <h3 class="font-semibold text-gray-900 mt-1"><?php echo $week['title']; ?></h3>
                                    <p class="text-xs text-gray-500">Priority: <?php echo $week['priority']; ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="text-2xl font-bold text-<?php echo $status_color; ?>-600"><?php echo $pct; ?>%</span>
                                    <p class="text-xs text-gray-500"><?php echo $completed; ?>/<?php echo $total; ?> tasks</p>
                                </div>
                            </div>
                            <div class="px-5 py-3">
                                <div class="w-full bg-gray-200 rounded-full h-1.5 mb-3">
                                    <div class="bg-<?php echo $status_color; ?>-500 h-1.5 rounded-full transition-all" style="width: <?php echo $pct; ?>%"></div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-1">
                                    <?php foreach ($week['tasks'] as $task): ?>
                                        <div class="flex items-center gap-2 py-1">
                                            <i class="fas <?php echo $task[1] ? 'fa-check-circle text-green-500' : 'fa-circle text-gray-300'; ?> text-sm"></i>
                                            <span class="text-sm <?php echo $task[1] ? 'text-gray-700' : 'text-gray-400'; ?>"><?php echo htmlspecialchars($task[0]); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-plug text-green-500 mr-2"></i>Integration Status</h2>
                    <p class="text-sm text-gray-500 mt-1">All tools self-hosted on Coolify/Vultr — total cost: $0-24/mo</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-6">
                    <?php foreach ($integrations as $int): ?>
                        <div class="flex items-center gap-4 p-4 border border-gray-200 rounded-lg hover:shadow-sm transition">
                            <div class="w-10 h-10 rounded-lg bg-<?php echo $int['color']; ?>-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas <?php echo $int['icon']; ?> text-<?php echo $int['color']; ?>-600"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-gray-900 text-sm"><?php echo $int['name']; ?></h3>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-green-100 text-green-700">Connected</span>
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5"><?php echo $int['description']; ?></p>
                                <p class="text-[10px] text-gray-400 mt-0.5"><?php echo $int['url_label']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-gradient-to-r from-green-800 to-emerald-900 rounded-lg p-6 text-white">
                <h2 class="text-lg font-semibold mb-2">Getting Started — Your First 3 Actions</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                    <div class="bg-white/10 rounded-lg p-4 backdrop-blur-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="bg-white/20 w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold">1</span>
                            <span class="font-semibold text-sm">This Weekend</span>
                        </div>
                        <p class="text-sm font-medium">Deploy Infrastructure (4 hours)</p>
                        <p class="text-xs text-green-200 mt-1">Vultr VPS + Coolify + N8N + AnythingLLM + Flowise</p>
                    </div>
                    <div class="bg-white/10 rounded-lg p-4 backdrop-blur-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="bg-white/20 w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold">2</span>
                            <span class="font-semibold text-sm">Week 1</span>
                        </div>
                        <p class="text-sm font-medium">Build SENTINEL (8 hours)</p>
                        <p class="text-xs text-green-200 mt-1">ITarian monitoring → N8N alert triage → ITFlow tickets</p>
                    </div>
                    <div class="bg-white/10 rounded-lg p-4 backdrop-blur-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="bg-white/20 w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold">3</span>
                            <span class="font-semibold text-sm">Week 2</span>
                        </div>
                        <p class="text-sm font-medium">Build GUARDIAN (6 hours)</p>
                        <p class="text-xs text-green-200 mt-1">Support email → N8N → AnythingLLM auto-response</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>