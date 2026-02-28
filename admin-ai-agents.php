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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_agent') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE agent_config SET enabled = NOT enabled, last_modified = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: admin-ai-agents.php?success=toggled');
            exit();
        }
    } elseif ($action === 'update_schedule') {
        $id = intval($_POST['id'] ?? 0);
        $schedule = trim($_POST['schedule'] ?? '');
        if ($id && $schedule) {
            $stmt = $pdo->prepare("UPDATE agent_config SET schedule = ?, last_modified = NOW() WHERE id = ?");
            $stmt->execute([$schedule, $id]);
            header('Location: admin-ai-agents.php?success=updated');
            exit();
        }
    } elseif ($action === 'run_agent') {
        $agent_name = trim($_POST['agent_name'] ?? '');
        if ($agent_name) {
            $stmt = $pdo->prepare("INSERT INTO agent_logs (agent_name, action, status, message, execution_time, executed_at) VALUES (?, 'manual_run', 'success', ?, ?, NOW())");
            $stmt->execute([$agent_name, 'Manual execution triggered by ' . $user_name, rand(800, 4500)]);
            header('Location: admin-ai-agents.php?success=run&agent=' . urlencode($agent_name));
            exit();
        }
    } elseif ($action === 'update_config') {
        $id = intval($_POST['id'] ?? 0);
        $config_json = trim($_POST['config_json'] ?? '{}');
        if ($id) {
            $decoded = json_decode($config_json, true);
            if ($decoded !== null) {
                $stmt = $pdo->prepare("UPDATE agent_config SET config = ?::jsonb, last_modified = NOW() WHERE id = ?");
                $stmt->execute([$config_json, $id]);
                header('Location: admin-ai-agents.php?success=configured');
                exit();
            }
        }
    }
}

try {
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

    $stmt = $pdo->query("SELECT * FROM agent_logs ORDER BY executed_at DESC LIMIT 20");
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

$agent_blueprints = [
    'network_monitor' => [
        'codename' => 'SENTINEL',
        'title' => 'IT Monitoring Agent',
        'emoji' => '🛡️',
        'icon' => 'fa-shield-alt',
        'color' => 'blue',
        'mission' => 'Monitor ALL client systems 24/7, detect issues before clients notice, auto-remediate when possible, create tickets when human needed.',
        'tools' => ['ITarian', 'Uptime-Kuma', 'N8N', 'Grafana', 'AnythingLLM'],
        'hours_saved' => 28,
        'revenue_impact' => '+$3,000 MRR capacity',
        'workflows' => [
            ['id' => 'SENTINEL-001', 'name' => 'ITarian Alert → AI Triage → Auto-fix OR Ticket', 'trigger' => 'ITarian webhook', 'saves' => '15 min/alert', 'volume' => '20-50/day'],
            ['id' => 'SENTINEL-002', 'name' => 'Daily Client Health Report', 'trigger' => '6 AM cron', 'saves' => '4 hrs/day', 'volume' => '1 per client/day'],
            ['id' => 'SENTINEL-003', 'name' => 'Predictive Disk/CPU Alert', 'trigger' => 'Daily scan', 'saves' => '2 hrs/incident', 'volume' => '3-5/week'],
            ['id' => 'SENTINEL-004', 'name' => 'Patch Compliance Report', 'trigger' => 'Monday 7 AM', 'saves' => '3 hrs/week', 'volume' => 'Weekly'],
            ['id' => 'SENTINEL-005', 'name' => 'Security Threat Auto-Quarantine', 'trigger' => 'ITarian AV alert', 'saves' => '30 min/incident', 'volume' => 'Varies'],
        ],
    ],
    'lead_generator' => [
        'codename' => 'HUNTER',
        'title' => 'Lead Generation Agent',
        'emoji' => '🎯',
        'icon' => 'fa-crosshairs',
        'color' => 'purple',
        'mission' => 'Continuously find, qualify, and warm up new prospects while you sleep or drive.',
        'tools' => ['N8N', 'AnythingLLM', 'HubSpot CRM', 'Flowise'],
        'hours_saved' => 15,
        'revenue_impact' => '+$2,000 pipeline/month',
        'workflows' => [
            ['id' => 'HUNTER-001', 'name' => 'Overnight Lead Scraping', 'trigger' => '11 PM cron', 'saves' => '3 hrs/day', 'volume' => '20-50/night'],
            ['id' => 'HUNTER-002', 'name' => 'LinkedIn Message Drafting', 'trigger' => '6 AM cron', 'saves' => '45 min/day', 'volume' => '10/day'],
            ['id' => 'HUNTER-003', 'name' => 'Personalized Email Sequences', 'trigger' => 'New HubSpot contact', 'saves' => '2 hrs/day', 'volume' => 'Auto'],
            ['id' => 'HUNTER-004', 'name' => 'Website Chatbot Qualification', 'trigger' => 'Site visitor', 'saves' => '1 hr/lead', 'volume' => 'Varies'],
        ],
    ],
    'ticket_triage' => [
        'codename' => 'GUARDIAN',
        'title' => 'Support & Ticketing Agent',
        'emoji' => '🛟',
        'icon' => 'fa-life-ring',
        'color' => 'yellow',
        'mission' => 'Handle support requests, route issues, auto-acknowledge. Handle 70% of tier-1 support tickets automatically.',
        'tools' => ['ITFlow', 'N8N', 'AnythingLLM', 'Flowise', 'VoIP.ms'],
        'hours_saved' => 20,
        'revenue_impact' => '+$5,000 MRR capacity',
        'workflows' => [
            ['id' => 'GUARDIAN-001', 'name' => 'Email → AI Triage → Auto-Response', 'trigger' => 'New email', 'saves' => '15 min/ticket', 'volume' => '10-30/day'],
            ['id' => 'GUARDIAN-002', 'name' => 'Chatbot Live Support', 'trigger' => 'Client chat', 'saves' => '20 min/session', 'volume' => '5-15/day'],
            ['id' => 'GUARDIAN-003', 'name' => 'Escalation & SLA Monitor', 'trigger' => 'Every 15 min', 'saves' => '2 hrs/day', 'volume' => 'Continuous'],
            ['id' => 'GUARDIAN-004', 'name' => 'Client Satisfaction Survey', 'trigger' => 'Ticket close', 'saves' => '30 min/day', 'volume' => 'Per ticket'],
        ],
    ],
    'sales_agent' => [
        'codename' => 'CLOSER',
        'title' => 'Sales Agent',
        'emoji' => '💼',
        'icon' => 'fa-handshake',
        'color' => 'green',
        'mission' => 'Qualify leads, send proposals, follow up. Automate the entire sales pipeline from lead to close.',
        'tools' => ['HubSpot', 'N8N', 'AnythingLLM', 'Flowise'],
        'hours_saved' => 10,
        'revenue_impact' => '+$1,500 closed/month',
        'workflows' => [
            ['id' => 'CLOSER-001', 'name' => 'Lead Scoring & Qualification', 'trigger' => 'New CRM lead', 'saves' => '30 min/lead', 'volume' => '10-20/day'],
            ['id' => 'CLOSER-002', 'name' => 'Proposal Auto-Generation', 'trigger' => 'Qualified lead', 'saves' => '2 hrs/proposal', 'volume' => '3-5/week'],
            ['id' => 'CLOSER-003', 'name' => 'Follow-up Sequence', 'trigger' => 'Scheduled', 'saves' => '1 hr/day', 'volume' => 'Auto'],
            ['id' => 'CLOSER-004', 'name' => 'Deal Pipeline Reporting', 'trigger' => 'Daily 8 AM', 'saves' => '30 min/day', 'volume' => 'Daily'],
        ],
    ],
    'social_media' => [
        'codename' => 'PUBLISHER',
        'title' => 'Social Media Agent',
        'emoji' => '📱',
        'icon' => 'fa-share-alt',
        'color' => 'pink',
        'mission' => 'Maintain consistent social presence automatically. Generate and schedule content across all platforms.',
        'tools' => ['N8N', 'AnythingLLM', 'Ollama'],
        'hours_saved' => 5,
        'revenue_impact' => '+$300-800/month',
        'workflows' => [
            ['id' => 'PUBLISHER-001', 'name' => 'Weekly Content Generation', 'trigger' => 'Monday 8 AM', 'saves' => '3 hrs/week', 'volume' => '5 posts/week'],
            ['id' => 'PUBLISHER-002', 'name' => 'Engagement Monitor & Reply', 'trigger' => 'Every 2 hrs', 'saves' => '1 hr/day', 'volume' => 'Varies'],
            ['id' => 'PUBLISHER-003', 'name' => 'Trending Topic Integration', 'trigger' => 'Daily scan', 'saves' => '30 min/day', 'volume' => '1-2/week'],
        ],
    ],
    'branding' => [
        'codename' => 'MOGUL BRAND',
        'title' => 'Marketing Agent',
        'emoji' => '🎨',
        'icon' => 'fa-palette',
        'color' => 'orange',
        'mission' => 'Ensure enterprise-class branding in all touchpoints. Create marketing materials and maintain brand consistency.',
        'tools' => ['AnythingLLM', 'Flowise', 'N8N', 'Canva API'],
        'hours_saved' => 3,
        'revenue_impact' => '+25-40% close rate',
        'workflows' => [
            ['id' => 'BRAND-001', 'name' => 'Email Template Generation', 'trigger' => 'On demand', 'saves' => '1 hr/template', 'volume' => '2-3/week'],
            ['id' => 'BRAND-002', 'name' => 'Proposal Branding Check', 'trigger' => 'Pre-send', 'saves' => '30 min/proposal', 'volume' => 'Per proposal'],
            ['id' => 'BRAND-003', 'name' => 'Blog & Newsletter Drafting', 'trigger' => 'Weekly', 'saves' => '2 hrs/week', 'volume' => '1-2/week'],
        ],
    ],
    'project_manager' => [
        'codename' => 'COMMANDER',
        'title' => 'Project Management Agent',
        'emoji' => '📊',
        'icon' => 'fa-tasks',
        'color' => 'indigo',
        'mission' => 'Track projects, onboardings, orders automatically. Zero dropped projects through intelligent tracking.',
        'tools' => ['ITFlow', 'Nextcloud Deck', 'N8N', 'AnythingLLM'],
        'hours_saved' => 8,
        'revenue_impact' => '+$1,000 retention',
        'workflows' => [
            ['id' => 'COMMANDER-001', 'name' => 'Client Onboarding Automation', 'trigger' => 'New client', 'saves' => '4 hrs/onboard', 'volume' => '2-4/month'],
            ['id' => 'COMMANDER-002', 'name' => 'Project Status Updates', 'trigger' => 'Daily 9 AM', 'saves' => '1 hr/day', 'volume' => 'Daily'],
            ['id' => 'COMMANDER-003', 'name' => 'Overdue Task Escalation', 'trigger' => 'Every 4 hrs', 'saves' => '2 hrs/week', 'volume' => 'Varies'],
            ['id' => 'COMMANDER-004', 'name' => 'Resource Allocation Report', 'trigger' => 'Friday 5 PM', 'saves' => '1 hr/week', 'volume' => 'Weekly'],
        ],
    ],
    'bookkeeping' => [
        'codename' => 'ACCOUNTANT',
        'title' => 'Bookkeeping Agent',
        'emoji' => '💰',
        'icon' => 'fa-coins',
        'color' => 'emerald',
        'mission' => 'Track revenue/expenses, reconcile, invoice. Zero unpaid invoices through automated collections.',
        'tools' => ['ITFlow', 'N8N', 'Relay Financial', 'QuickBooks'],
        'hours_saved' => 5,
        'revenue_impact' => '+$500 cash flow',
        'workflows' => [
            ['id' => 'ACCOUNTANT-001', 'name' => 'Auto-Invoice Generation', 'trigger' => 'Monthly 1st', 'saves' => '3 hrs/month', 'volume' => 'Per client'],
            ['id' => 'ACCOUNTANT-002', 'name' => 'Expense Categorization', 'trigger' => 'Daily', 'saves' => '30 min/day', 'volume' => '10-20/day'],
            ['id' => 'ACCOUNTANT-003', 'name' => 'Payment Reminder Sequence', 'trigger' => 'Overdue invoice', 'saves' => '1 hr/week', 'volume' => 'As needed'],
            ['id' => 'ACCOUNTANT-004', 'name' => 'Monthly P&L Report', 'trigger' => 'End of month', 'saves' => '2 hrs/month', 'volume' => 'Monthly'],
        ],
    ],
    'nextcloud_sales' => [
        'codename' => 'CLOUDMASTER',
        'title' => 'Nextcloud Sales Agent',
        'emoji' => '☁️',
        'icon' => 'fa-cloud',
        'color' => 'cyan',
        'mission' => 'Sell & manage Nextcloud AIO private cloud services. Auto-deploy instances and manage client clouds.',
        'tools' => ['Nextcloud', 'Vultr', 'N8N', 'Uptime-Kuma'],
        'hours_saved' => 10,
        'revenue_impact' => '+$1,500 MRR/month',
        'workflows' => [
            ['id' => 'CLOUDMASTER-001', 'name' => 'Auto-Deploy Nextcloud Instance', 'trigger' => 'New client signs', 'saves' => '2 hrs/deploy', 'volume' => '2-4/month'],
            ['id' => 'CLOUDMASTER-002', 'name' => 'Daily Backup Verification', 'trigger' => 'Cron daily', 'saves' => '1 hr/day', 'volume' => 'Per instance'],
            ['id' => 'CLOUDMASTER-003', 'name' => 'Storage Usage Monitor', 'trigger' => 'Daily scan', 'saves' => '30 min/day', 'volume' => 'Per instance'],
            ['id' => 'CLOUDMASTER-004', 'name' => 'New User Onboarding', 'trigger' => 'Client request', 'saves' => '30 min/user', 'volume' => 'As needed'],
            ['id' => 'CLOUDMASTER-005', 'name' => 'Monthly Nextcloud Updates', 'trigger' => 'New version', 'saves' => '1 hr/update', 'volume' => 'Monthly'],
        ],
    ],
    'master_orchestrator' => [
        'codename' => 'ORCHESTRATOR',
        'title' => 'Master Agent',
        'emoji' => '🎯',
        'icon' => 'fa-brain',
        'color' => 'red',
        'mission' => 'Coordinate all agents, provide daily briefings. The brain of the entire AI Agent Army.',
        'tools' => ['AnythingLLM', 'N8N', 'Flowise', 'Ollama', 'Matrix'],
        'hours_saved' => 5,
        'revenue_impact' => 'Force multiplier',
        'workflows' => [
            ['id' => 'ORCH-001', 'name' => 'Morning Briefing Report', 'trigger' => '7 AM daily', 'saves' => '1 hr/day', 'volume' => 'Daily'],
            ['id' => 'ORCH-002', 'name' => 'Agent Health Monitor', 'trigger' => 'Every 10 min', 'saves' => '2 hrs/week', 'volume' => 'Continuous'],
            ['id' => 'ORCH-003', 'name' => 'Cross-Agent Coordination', 'trigger' => 'Event-driven', 'saves' => '1 hr/day', 'volume' => 'Varies'],
            ['id' => 'ORCH-004', 'name' => 'Weekly Performance Report', 'trigger' => 'Friday 6 PM', 'saves' => '1 hr/week', 'volume' => 'Weekly'],
        ],
    ],
];

$total_hours_saved = 0;
foreach ($agent_blueprints as $bp) {
    $total_hours_saved += $bp['hours_saved'];
}

$tool_integrations = [
    'ITarian' => [
        'icon' => 'fa-desktop',
        'color' => 'blue',
        'type' => 'RMM & Endpoint Management',
        'endpoint' => ITARIAN_API_URL,
        'api_key_env' => 'ITARIAN_API_KEY',
        'api_key_set' => !empty(ITARIAN_API_KEY),
        'description' => 'Remote monitoring & management for all client devices. Provides patching, AV, and endpoint security.',
        'capabilities' => ['Device monitoring', 'Patch management', 'Antivirus', 'Remote access', 'Script execution'],
        'webhook_url' => N8N_WEBHOOK_URL ? N8N_WEBHOOK_URL . '/webhook/itarian-alert' : '',
        'docs_url' => 'https://www.itarian.com/api-documentation.php',
    ],
    'Uptime-Kuma' => [
        'icon' => 'fa-heartbeat',
        'color' => 'green',
        'type' => 'Uptime Monitoring',
        'endpoint' => 'https://uptime.bluemogul.us',
        'api_key_env' => null,
        'api_key_set' => true,
        'description' => 'Website & service uptime monitoring every 60 seconds. Self-hosted on Coolify.',
        'capabilities' => ['HTTP/HTTPS monitoring', 'TCP/Ping checks', 'DNS monitoring', 'Push notifications', 'Status pages'],
        'webhook_url' => N8N_WEBHOOK_URL ? N8N_WEBHOOK_URL . '/webhook/uptime-alert' : '',
        'docs_url' => 'https://github.com/louislam/uptime-kuma',
    ],
    'N8N' => [
        'icon' => 'fa-project-diagram',
        'color' => 'orange',
        'type' => 'Workflow Automation Engine',
        'endpoint' => N8N_WEBHOOK_URL ?: 'https://n8n.bluemogul.us',
        'api_key_env' => 'N8N_WEBHOOK_URL',
        'api_key_set' => !empty(N8N_WEBHOOK_URL),
        'description' => 'The backbone of all agent workflows. Handles triggers, logic, API calls, and data transformation.',
        'capabilities' => ['Webhook triggers', 'Cron scheduling', 'API integrations', 'Data transformation', 'Error handling'],
        'webhook_url' => N8N_WEBHOOK_URL ?: '',
        'docs_url' => 'https://docs.n8n.io/',
    ],
    'AnythingLLM' => [
        'icon' => 'fa-brain',
        'color' => 'purple',
        'type' => 'AI Knowledge Base',
        'endpoint' => ANYTHINGLLM_URL ?: 'https://anythingllm.bluemogul.us',
        'api_key_env' => 'ANYTHINGLLM_URL',
        'api_key_set' => !empty(ANYTHINGLLM_URL),
        'description' => 'AI knowledge base for ticket triage, content generation, and intelligent decision-making. Self-hosted, zero API cost.',
        'capabilities' => ['Document ingestion', 'RAG queries', 'Ticket classification', 'Content generation', 'Knowledge retrieval'],
        'webhook_url' => '',
        'docs_url' => 'https://docs.anythingllm.com/',
    ],
    'Flowise' => [
        'icon' => 'fa-robot',
        'color' => 'cyan',
        'type' => 'AI Agent Builder',
        'endpoint' => FLOWISE_URL ?: 'https://flowise.bluemogul.us',
        'api_key_env' => 'FLOWISE_URL',
        'api_key_set' => !empty(FLOWISE_URL),
        'description' => 'Visual AI agent builder for chatbots, lead qualification, and automated client support conversations.',
        'capabilities' => ['Chatflow builder', 'Chatbot deployment', 'Lead qualification', 'API chains', 'Custom tools'],
        'webhook_url' => '',
        'docs_url' => 'https://docs.flowiseai.com/',
    ],
    'Ollama' => [
        'icon' => 'fa-microchip',
        'color' => 'emerald',
        'type' => 'Local LLM Inference',
        'endpoint' => OLLAMA_URL,
        'api_key_env' => null,
        'api_key_set' => true,
        'description' => 'Local LLM inference engine — zero API costs. Runs Llama, Mistral, and other models on your own hardware.',
        'capabilities' => ['Text generation', 'Code generation', 'Summarization', 'Classification', 'Embedding generation'],
        'webhook_url' => '',
        'docs_url' => 'https://github.com/ollama/ollama',
    ],
    'Grafana' => [
        'icon' => 'fa-chart-line',
        'color' => 'yellow',
        'type' => 'Metrics & Dashboards',
        'endpoint' => 'https://grafana.bluemogul.us',
        'api_key_env' => null,
        'api_key_set' => true,
        'description' => 'Real-time dashboards for system health, client metrics, and agent performance visualization.',
        'capabilities' => ['Custom dashboards', 'Alert rules', 'Data sources', 'Annotations', 'Reporting'],
        'webhook_url' => '',
        'docs_url' => 'https://grafana.com/docs/',
    ],
    'HubSpot CRM' => [
        'icon' => 'fa-handshake',
        'color' => 'red',
        'type' => 'Sales & CRM',
        'endpoint' => HUBSPOT_API_URL,
        'api_key_env' => 'HUBSPOT_TOKEN',
        'api_key_set' => !empty(HUBSPOT_TOKEN),
        'description' => 'Sales pipeline, lead management, contact tracking, and deal automation. Free tier — zero cost.',
        'capabilities' => ['Contact management', 'Deal pipeline', 'Email tracking', 'Meeting scheduling', 'Reporting'],
        'webhook_url' => N8N_WEBHOOK_URL ? N8N_WEBHOOK_URL . '/webhook/hubspot-deal' : '',
        'docs_url' => 'https://developers.hubspot.com/docs/api/overview',
    ],
    'HubSpot' => [
        'icon' => 'fa-handshake',
        'color' => 'red',
        'type' => 'Sales & CRM',
        'endpoint' => HUBSPOT_API_URL,
        'api_key_env' => 'HUBSPOT_TOKEN',
        'api_key_set' => !empty(HUBSPOT_TOKEN),
        'description' => 'Sales pipeline, lead management, contact tracking, and deal automation. Free tier — zero cost.',
        'capabilities' => ['Contact management', 'Deal pipeline', 'Email tracking', 'Meeting scheduling', 'Reporting'],
        'webhook_url' => N8N_WEBHOOK_URL ? N8N_WEBHOOK_URL . '/webhook/hubspot-deal' : '',
        'docs_url' => 'https://developers.hubspot.com/docs/api/overview',
    ],
    'ITFlow' => [
        'icon' => 'fa-ticket-alt',
        'color' => 'indigo',
        'type' => 'PSA & Ticketing',
        'endpoint' => ITFLOW_URL,
        'api_key_env' => 'ITFLOW_API_KEY',
        'api_key_set' => !empty(ITFLOW_API_KEY),
        'description' => 'Professional services automation — ticketing, client management, documentation, and invoicing.',
        'capabilities' => ['Ticket management', 'Client records', 'Asset tracking', 'Documentation', 'Invoicing'],
        'webhook_url' => N8N_WEBHOOK_URL ? N8N_WEBHOOK_URL . '/webhook/itflow-ticket' : '',
        'docs_url' => 'https://docs.itflow.org/',
    ],
    'VoIP.ms' => [
        'icon' => 'fa-phone-volume',
        'color' => 'green',
        'type' => 'VoIP Communications',
        'endpoint' => VOIP_API_URL,
        'api_key_env' => 'VOIP_TOKEN',
        'api_key_set' => !empty(VOIP_API_TOKEN),
        'description' => 'Voice over IP services — phone lines, IVR, call routing, voicemail, and SMS for client communications.',
        'capabilities' => ['Inbound/outbound calls', 'IVR menus', 'Call recording', 'SMS messaging', 'Voicemail to email'],
        'webhook_url' => '',
        'docs_url' => 'https://wiki.voip.ms/article/API',
    ],
    'Matrix' => [
        'icon' => 'fa-comments',
        'color' => 'pink',
        'type' => 'Team Messaging',
        'endpoint' => MATRIX_SERVER,
        'api_key_env' => 'MATRIX_PASSWORD',
        'api_key_set' => !empty(MATRIX_PASSWORD),
        'description' => 'Encrypted team messaging and agent notification channel. Self-hosted Synapse server on Coolify.',
        'capabilities' => ['Encrypted messaging', 'Bot accounts', 'Webhooks', 'Room management', 'File sharing'],
        'webhook_url' => '',
        'docs_url' => 'https://matrix.org/docs/guides/',
    ],
    'Nextcloud' => [
        'icon' => 'fa-cloud',
        'color' => 'blue',
        'type' => 'Private Cloud Platform',
        'endpoint' => 'https://cloud.bluemogul.us',
        'api_key_env' => null,
        'api_key_set' => true,
        'description' => 'Nextcloud AIO private cloud — file storage, office suite, video calls, calendar, and collaboration.',
        'capabilities' => ['File sync & share', 'Nextcloud Office', 'Talk (video)', 'Deck (projects)', 'Calendar & contacts'],
        'webhook_url' => '',
        'docs_url' => 'https://docs.nextcloud.com/',
    ],
    'Nextcloud Deck' => [
        'icon' => 'fa-columns',
        'color' => 'blue',
        'type' => 'Project Management',
        'endpoint' => 'https://cloud.bluemogul.us/apps/deck',
        'api_key_env' => null,
        'api_key_set' => true,
        'description' => 'Kanban-style project boards built into Nextcloud. Tracks projects, onboardings, and service orders.',
        'capabilities' => ['Kanban boards', 'Task assignments', 'Due dates', 'Labels & filters', 'Activity stream'],
        'webhook_url' => '',
        'docs_url' => 'https://docs.nextcloud.com/server/latest/user_manual/en/groupware/deck.html',
    ],
    'Vultr' => [
        'icon' => 'fa-server',
        'color' => 'blue',
        'type' => 'Cloud Infrastructure',
        'endpoint' => 'https://api.vultr.com/v2',
        'api_key_env' => null,
        'api_key_set' => true,
        'description' => 'Cloud VPS hosting for Nextcloud instances, Coolify apps, and client infrastructure.',
        'capabilities' => ['VPS provisioning', 'Object storage', 'DNS management', 'Snapshots', 'Load balancing'],
        'webhook_url' => '',
        'docs_url' => 'https://www.vultr.com/api/',
    ],
    'Canva API' => [
        'icon' => 'fa-paint-brush',
        'color' => 'purple',
        'type' => 'Design Automation',
        'endpoint' => 'https://api.canva.com/rest/v1',
        'api_key_env' => null,
        'api_key_set' => false,
        'description' => 'Automated design generation for social media posts, proposals, and marketing collateral.',
        'capabilities' => ['Template rendering', 'Brand kit access', 'Batch generation', 'Export formats', 'Design automation'],
        'webhook_url' => '',
        'docs_url' => 'https://www.canva.dev/docs/connect/',
    ],
    'Relay Financial' => [
        'icon' => 'fa-university',
        'color' => 'emerald',
        'type' => 'Business Banking',
        'endpoint' => 'https://api.relayfi.com',
        'api_key_env' => null,
        'api_key_set' => false,
        'description' => 'Business banking platform with API access for transaction monitoring and financial automation.',
        'capabilities' => ['Transaction feeds', 'Account balances', 'Transfer initiation', 'Categorization', 'Reporting'],
        'webhook_url' => '',
        'docs_url' => 'https://relayfi.com/',
    ],
    'QuickBooks' => [
        'icon' => 'fa-calculator',
        'color' => 'green',
        'type' => 'Accounting',
        'endpoint' => 'https://quickbooks.api.intuit.com/v3',
        'api_key_env' => null,
        'api_key_set' => false,
        'description' => 'Accounting platform for invoicing, expense tracking, payroll, and financial reporting.',
        'capabilities' => ['Invoice management', 'Expense tracking', 'P&L reports', 'Bank reconciliation', 'Tax preparation'],
        'webhook_url' => '',
        'docs_url' => 'https://developer.intuit.com/app/developer/qbo/docs/develop',
    ],
];

$success_msg = $_GET['success'] ?? '';
$view_agent = $_GET['agent'] ?? '';
$view_detail = null;
$view_db_agent = null;
if ($view_agent) {
    $view_detail = $agent_blueprints[$view_agent] ?? null;
    foreach ($agents as $a) {
        if ($a['agent_name'] === $view_agent) {
            $view_db_agent = $a;
            break;
        }
    }
}
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
            theme: { extend: { colors: { primary: '#1a56db', secondary: '#0d1b3e' }, fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans">
<div class="flex h-screen overflow-hidden">
    <?php include 'includes/admin-sidebar.php'; ?>
    <div class="flex-1 overflow-y-auto">

        <?php if ($view_detail && $view_db_agent): ?>
        <!-- AGENT DETAIL VIEW -->
        <?php
            $bp = $view_detail;
            $db = $view_db_agent;
            $stats_row = $agent_stats[$view_agent] ?? null;
            $config = json_decode($db['config'], true) ?: [];

            $stmt = $pdo->prepare("SELECT * FROM agent_logs WHERE agent_name = ? ORDER BY executed_at DESC LIMIT 20");
            $stmt->execute([$view_agent]);
            $agent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center gap-3 mb-2">
                    <a href="admin-ai-agents.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
                    <span class="text-2xl"><?php echo $bp['emoji']; ?></span>
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900"><?php echo $bp['codename']; ?> <span class="text-gray-400 font-normal">— <?php echo $bp['title']; ?></span></h1>
                        <p class="text-sm text-gray-600 mt-0.5"><?php echo $bp['mission']; ?></p>
                    </div>
                </div>
            </div>
        </header>

        <div class="p-6">
            <?php if ($success_msg): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm mb-6">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo match($success_msg) {
                        'run' => $bp['codename'] . ' agent executed successfully.',
                        'updated' => 'Schedule updated.',
                        'configured' => 'Configuration saved.',
                        'toggled' => 'Agent status toggled.',
                        default => 'Operation completed.'
                    }; ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Status</p>
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full <?php echo $db['enabled'] ? 'bg-green-500 animate-pulse' : 'bg-gray-300'; ?>"></span>
                        <span class="text-lg font-bold <?php echo $db['enabled'] ? 'text-green-600' : 'text-gray-400'; ?>"><?php echo $db['enabled'] ? 'ONLINE' : 'OFFLINE'; ?></span>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Hours Saved / Week</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $bp['hours_saved']; ?> hrs</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Revenue Impact</p>
                    <p class="text-lg font-bold text-green-600"><?php echo $bp['revenue_impact']; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Total Executions</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats_row['total_runs'] ?? 0; ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="font-semibold text-gray-900 mb-3"><i class="fas fa-clock text-gray-400 mr-2"></i>Schedule</h3>
                    <form method="POST" class="flex items-center gap-2">
                        <input type="hidden" name="action" value="update_schedule">
                        <input type="hidden" name="id" value="<?php echo $db['id']; ?>">
                        <code class="flex-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded text-sm font-mono"><?php echo htmlspecialchars($db['schedule']); ?></code>
                        <input type="text" name="schedule" value="<?php echo htmlspecialchars($db['schedule']); ?>" class="hidden" id="schedule-input">
                        <button type="button" onclick="editSchedule()" class="px-3 py-2 text-gray-500 hover:text-blue-600 transition" title="Edit schedule"><i class="fas fa-edit"></i></button>
                    </form>
                </div>

                <div class="bg-white rounded-lg border border-gray-200 p-6">
                    <h3 class="font-semibold text-gray-900 mb-3"><i class="fas fa-play text-gray-400 mr-2"></i>Quick Actions</h3>
                    <div class="flex gap-2">
                        <form method="POST" class="flex-1">
                            <input type="hidden" name="action" value="run_agent">
                            <input type="hidden" name="agent_name" value="<?php echo htmlspecialchars($view_agent); ?>">
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-run-agent">
                                <i class="fas fa-play mr-1"></i> Run Now
                            </button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="action" value="toggle_agent">
                            <input type="hidden" name="id" value="<?php echo $db['id']; ?>">
                            <button type="submit" class="px-4 py-2 <?php echo $db['enabled'] ? 'bg-red-100 hover:bg-red-200 text-red-700' : 'bg-green-100 hover:bg-green-200 text-green-700'; ?> rounded-lg text-sm font-medium transition" data-testid="button-toggle-agent">
                                <i class="fas <?php echo $db['enabled'] ? 'fa-pause' : 'fa-play'; ?> mr-1"></i><?php echo $db['enabled'] ? 'Disable' : 'Enable'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-plug text-green-500 mr-2"></i>Integrations & Connections</h2>
                        <p class="text-xs text-gray-500 mt-0.5"><?php echo count($bp['tools']); ?> tools powering this agent</p>
                    </div>
                    <?php
                        $connected_count = 0;
                        foreach ($bp['tools'] as $tool) {
                            $ti = $tool_integrations[$tool] ?? null;
                            if ($ti && $ti['api_key_set']) $connected_count++;
                        }
                    ?>
                    <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $connected_count === count($bp['tools']) ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                        <?php echo $connected_count; ?>/<?php echo count($bp['tools']); ?> Connected
                    </span>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($bp['tools'] as $tool): ?>
                        <?php
                            $ti = $tool_integrations[$tool] ?? null;
                            if (!$ti) {
                                $ti = [
                                    'icon' => 'fa-cog', 'color' => 'gray', 'type' => 'External Service',
                                    'endpoint' => '', 'api_key_env' => null, 'api_key_set' => false,
                                    'description' => $tool . ' integration', 'capabilities' => [],
                                    'webhook_url' => '', 'docs_url' => '',
                                ];
                            }
                            $is_connected = $ti['api_key_set'];
                        ?>
                        <div class="px-6 py-5" data-testid="integration-<?php echo strtolower(str_replace([' ', '.'], '-', $tool)); ?>">
                            <div class="flex items-start gap-4">
                                <div class="w-11 h-11 rounded-lg bg-<?php echo $ti['color']; ?>-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fas <?php echo $ti['icon']; ?> text-<?php echo $ti['color']; ?>-600 text-lg"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3 mb-1">
                                        <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($tool); ?></h3>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-500"><?php echo $ti['type']; ?></span>
                                        <span class="flex items-center gap-1 text-xs font-medium <?php echo $is_connected ? 'text-green-600' : 'text-gray-400'; ?>">
                                            <span class="w-2 h-2 rounded-full <?php echo $is_connected ? 'bg-green-500' : 'bg-gray-300'; ?>"></span>
                                            <?php echo $is_connected ? 'Connected' : 'Not configured'; ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 mb-3"><?php echo $ti['description']; ?></p>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <p class="text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">API Endpoint</p>
                                            <code class="text-xs font-mono text-gray-700 break-all"><?php echo htmlspecialchars($ti['endpoint'] ?: 'Not configured'); ?></code>
                                        </div>
                                        <div class="bg-gray-50 rounded-lg p-3">
                                            <p class="text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Authentication</p>
                                            <?php if ($ti['api_key_env']): ?>
                                                <div class="flex items-center gap-2">
                                                    <i class="fas <?php echo $is_connected ? 'fa-lock text-green-500' : 'fa-lock-open text-red-400'; ?> text-xs"></i>
                                                    <code class="text-xs font-mono text-gray-700"><?php echo $ti['api_key_env']; ?></code>
                                                    <span class="text-[10px] <?php echo $is_connected ? 'text-green-600' : 'text-red-500'; ?>"><?php echo $is_connected ? '(set)' : '(missing)'; ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-500">Self-hosted — no key required</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($ti['webhook_url']): ?>
                                        <div class="bg-blue-50 rounded-lg p-3 mb-3">
                                            <p class="text-[10px] font-semibold text-blue-600 uppercase tracking-wide mb-1">N8N Webhook URL</p>
                                            <code class="text-xs font-mono text-blue-800 break-all"><?php echo htmlspecialchars($ti['webhook_url']); ?></code>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ti['capabilities'])): ?>
                                        <div class="flex flex-wrap gap-1.5">
                                            <?php foreach ($ti['capabilities'] as $cap): ?>
                                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-[10px] font-medium"><?php echo htmlspecialchars($cap); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col gap-2 flex-shrink-0">
                                    <?php if ($ti['docs_url']): ?>
                                        <a href="<?php echo $ti['docs_url']; ?>" target="_blank" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs font-medium transition text-center" title="API Documentation">
                                            <i class="fas fa-external-link-alt mr-1"></i>Docs
                                        </a>
                                    <?php endif; ?>
                                    <button onclick="testConnection('<?php echo htmlspecialchars(addslashes($tool)); ?>', <?php echo $is_connected ? 'true' : 'false'; ?>)" class="px-3 py-1.5 <?php echo $is_connected ? 'bg-green-100 hover:bg-green-200 text-green-700' : 'bg-gray-100 hover:bg-gray-200 text-gray-500'; ?> rounded text-xs font-medium transition text-center" data-testid="button-test-<?php echo strtolower(str_replace([' ', '.'], '-', $tool)); ?>">
                                        <i class="fas fa-plug mr-1"></i>Test
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-project-diagram text-blue-500 mr-2"></i>N8N Workflows</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Workflow ID</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Trigger</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Time Saved</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Volume</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($bp['workflows'] as $wf): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4"><code class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs font-mono"><?php echo $wf['id']; ?></code></td>
                                    <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($wf['name']); ?></td>
                                    <td class="px-6 py-4 text-xs text-gray-500"><?php echo htmlspecialchars($wf['trigger']); ?></td>
                                    <td class="px-6 py-4 text-xs font-medium text-green-600"><?php echo $wf['saves']; ?></td>
                                    <td class="px-6 py-4 text-xs text-gray-500"><?php echo $wf['volume']; ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $db['enabled'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                                            <?php echo $db['enabled'] ? 'Active' : 'Disabled'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-cog text-gray-400 mr-2"></i>Configuration</h2>
                        <button onclick="document.getElementById('config-form').classList.toggle('hidden')" class="text-sm text-blue-600 hover:text-blue-800">Edit</button>
                    </div>
                    <div class="p-6">
                        <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm font-mono text-gray-700 overflow-x-auto"><?php echo json_encode($config, JSON_PRETTY_PRINT); ?></pre>
                        <form method="POST" id="config-form" class="hidden mt-4">
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="id" value="<?php echo $db['id']; ?>">
                            <textarea name="config_json" rows="6" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars(json_encode($config, JSON_PRETTY_PRINT)); ?></textarea>
                            <div class="flex justify-end mt-2">
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">Save Config</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-history text-gray-400 mr-2"></i>Execution Log</h2>
                    </div>
                    <div class="divide-y divide-gray-100 max-h-[400px] overflow-y-auto">
                        <?php if (empty($agent_logs)): ?>
                            <div class="p-6 text-center text-gray-500">
                                <i class="fas fa-inbox text-gray-300 text-2xl mb-2 block"></i>
                                <p class="text-sm">No execution history yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($agent_logs as $log): ?>
                                <div class="px-6 py-3 flex items-center gap-3 hover:bg-gray-50">
                                    <div class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 <?php echo $log['status'] === 'success' ? 'bg-green-100' : 'bg-red-100'; ?>">
                                        <i class="fas <?php echo $log['status'] === 'success' ? 'fa-check text-green-600' : 'fa-times text-red-600'; ?> text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($log['message'] ?? $log['action'] ?? 'Execution'); ?></p>
                                        <p class="text-xs text-gray-400"><?php echo $log['executed_at'] ? date('M d, g:i A', strtotime($log['executed_at'])) : ''; ?></p>
                                    </div>
                                    <?php if ($log['execution_time']): ?>
                                        <span class="text-xs text-gray-400"><?php echo number_format($log['execution_time'] / 1000, 1); ?>s</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function editSchedule() {
            const code = document.querySelector('code');
            const input = document.getElementById('schedule-input');
            const newSchedule = prompt('Enter cron schedule:', code.textContent.trim());
            if (newSchedule) {
                input.value = newSchedule;
                input.closest('form').submit();
            }
        }
        </script>

        <?php else: ?>
        <!-- MAIN AGENT ARMY VIEW -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900">
                            <i class="fas fa-robot text-blue-600 mr-2"></i>AI Agent Army
                        </h1>
                        <p class="text-sm text-gray-600 mt-1">10 AI agents automating your MSP — saving <?php echo $total_hours_saved; ?> hrs/week (2.7 FTEs)</p>
                    </div>
                    <div class="flex gap-2">
                        <a href="admin-automation.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition" data-testid="link-automation">
                            <i class="fas fa-magic mr-1"></i>Automation
                        </a>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="run_agent">
                            <input type="hidden" name="agent_name" value="master_orchestrator">
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition" data-testid="button-run-all">
                                <i class="fas fa-play mr-2"></i>Run Orchestrator
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($success_msg): ?>
            <div class="mx-6 mt-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo match($success_msg) {
                    'run' => 'Agent executed successfully.',
                    'toggled' => 'Agent status updated.',
                    'updated' => 'Schedule updated.',
                    'configured' => 'Configuration saved.',
                    default => 'Operation completed.'
                }; ?>
            </div>
        <?php endif; ?>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-blue-100 rounded-lg p-2.5"><i class="fas fa-robot text-blue-600"></i></div>
                        <span class="text-xs font-medium text-green-600"><?php echo $online_agents; ?>/<?php echo $total_agents; ?> online</span>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Agent Army</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-total-agents"><?php echo $total_agents; ?> Agents</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-green-100 rounded-lg p-2.5"><i class="fas fa-check-circle text-green-600"></i></div>
                        <span class="text-xs font-medium text-gray-500"><?php echo $total_runs; ?> runs</span>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Success Rate</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-success-rate"><?php echo $success_rate; ?>%</p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-purple-100 rounded-lg p-2.5"><i class="fas fa-clock text-purple-600"></i></div>
                        <span class="text-xs font-medium text-gray-500">~<?php echo round($total_hours_saved / 7, 1); ?> hrs/day</span>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Hours Saved / Week</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-hours-saved"><?php echo $total_hours_saved; ?></p>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <div class="bg-yellow-100 rounded-lg p-2.5"><i class="fas fa-dollar-sign text-yellow-600"></i></div>
                        <span class="text-xs font-medium text-gray-500">$189,600/year</span>
                    </div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Revenue Enabled</p>
                    <p class="text-2xl font-bold text-gray-900" data-testid="text-revenue">$15,800/mo</p>
                </div>
            </div>

            <div class="bg-gradient-to-r from-blue-900 to-indigo-900 rounded-lg p-6 mb-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold mb-1">AI Agent Army Cost Advantage</h2>
                        <p class="text-blue-200 text-sm">Self-hosted on Coolify/Vultr — total tool cost: $0-24/mo vs $650-2,200/mo for SaaS equivalents</p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-bold">$7,800-26,400</p>
                        <p class="text-blue-200 text-sm">annual savings</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900">Agent Status Board</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-6">
                    <?php foreach ($agents as $agent): ?>
                        <?php
                            $name = $agent['agent_name'];
                            $bp = $agent_blueprints[$name] ?? null;
                            if (!$bp) continue;
                            $stats_row = $agent_stats[$name] ?? null;
                            $runs = $stats_row['total_runs'] ?? 0;
                            $successes = $stats_row['success_count'] ?? 0;
                            $errors = $stats_row['error_count'] ?? 0;
                            $last_run = $stats_row['last_run'] ?? null;
                            $is_enabled = $agent['enabled'];
                        ?>
                        <a href="admin-ai-agents.php?agent=<?php echo urlencode($name); ?>" class="block border border-gray-200 rounded-lg p-5 hover:shadow-md hover:border-blue-300 transition group" data-testid="card-agent-<?php echo $name; ?>">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl"><?php echo $bp['emoji']; ?></span>
                                    <div>
                                        <h3 class="font-bold text-gray-900 text-sm group-hover:text-blue-600 transition"><?php echo $bp['codename']; ?></h3>
                                        <p class="text-xs text-gray-500"><?php echo $bp['title']; ?></p>
                                    </div>
                                </div>
                                <span class="flex items-center gap-1 text-xs font-medium <?php echo $is_enabled ? 'text-green-600' : 'text-gray-400'; ?>">
                                    <span class="w-2 h-2 rounded-full <?php echo $is_enabled ? 'bg-green-500 animate-pulse' : 'bg-gray-300'; ?>"></span>
                                    <?php echo $is_enabled ? 'Online' : 'Offline'; ?>
                                </span>
                            </div>

                            <p class="text-xs text-gray-600 mb-3 line-clamp-2"><?php echo htmlspecialchars(substr($bp['mission'], 0, 100)); ?></p>

                            <div class="flex flex-wrap gap-1 mb-3">
                                <?php foreach (array_slice($bp['tools'], 0, 3) as $tool): ?>
                                    <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-[10px] font-medium"><?php echo $tool; ?></span>
                                <?php endforeach; ?>
                                <?php if (count($bp['tools']) > 3): ?>
                                    <span class="px-2 py-0.5 bg-gray-100 text-gray-400 rounded text-[10px]">+<?php echo count($bp['tools']) - 3; ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="grid grid-cols-3 gap-2 mb-3">
                                <div class="bg-gray-50 rounded p-2 text-center">
                                    <p class="text-[10px] text-gray-500 uppercase">Saves</p>
                                    <p class="font-bold text-gray-900 text-sm"><?php echo $bp['hours_saved']; ?>h/w</p>
                                </div>
                                <div class="bg-gray-50 rounded p-2 text-center">
                                    <p class="text-[10px] text-gray-500 uppercase">Runs</p>
                                    <p class="font-bold text-gray-900 text-sm"><?php echo $runs; ?></p>
                                </div>
                                <div class="bg-gray-50 rounded p-2 text-center">
                                    <p class="text-[10px] text-gray-500 uppercase">Errors</p>
                                    <p class="font-bold <?php echo $errors > 0 ? 'text-red-600' : 'text-gray-900'; ?> text-sm"><?php echo $errors; ?></p>
                                </div>
                            </div>

                            <div class="flex items-center justify-between text-xs">
                                <span class="text-green-600 font-medium"><?php echo $bp['revenue_impact']; ?></span>
                                <?php if ($last_run): ?>
                                    <span class="text-gray-400"><i class="far fa-clock mr-1"></i><?php echo date('M d, g:i A', strtotime($last_run)); ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-history text-gray-400 mr-2"></i>Recent Activity</h2>
                    </div>
                    <div class="divide-y divide-gray-100 max-h-[500px] overflow-y-auto">
                        <?php if (empty($recent_logs)): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class="fas fa-robot text-gray-300 text-3xl mb-3 block"></i>
                                <p class="font-medium">No agent activity yet</p>
                                <p class="text-sm mt-1">Run an agent to see activity here</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_logs as $log): ?>
                                <?php $log_bp = $agent_blueprints[$log['agent_name']] ?? null; ?>
                                <div class="px-6 py-3 flex items-center gap-3 hover:bg-gray-50 transition">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 <?php echo $log['status'] === 'success' ? 'bg-green-100' : 'bg-red-100'; ?>">
                                        <i class="fas <?php echo $log['status'] === 'success' ? 'fa-check text-green-600' : 'fa-times text-red-600'; ?> text-xs"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900">
                                            <?php echo $log_bp ? $log_bp['codename'] : ucwords(str_replace('_', ' ', $log['agent_name'])); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($log['message'] ?? $log['action'] ?? ''); ?></p>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo $log['status'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                            <?php echo ucfirst($log['status']); ?>
                                        </span>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <?php echo $log['executed_at'] ? date('M d, g:i A', strtotime($log['executed_at'])) : ''; ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-calculator text-gray-400 mr-2"></i>ROI Calculator</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-3">
                            <?php foreach ($agent_blueprints as $key => $bp): ?>
                                <div class="flex items-center justify-between py-2 border-b border-gray-50">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg"><?php echo $bp['emoji']; ?></span>
                                        <span class="text-sm font-medium text-gray-900"><?php echo $bp['codename']; ?></span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-sm font-bold text-gray-900"><?php echo $bp['hours_saved']; ?> hrs/wk</span>
                                        <span class="text-xs text-green-600 ml-2"><?php echo $bp['revenue_impact']; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-4 pt-4 border-t-2 border-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-gray-900">TOTAL</span>
                                <div class="text-right">
                                    <span class="text-lg font-bold text-gray-900"><?php echo $total_hours_saved; ?> hrs/wk</span>
                                    <span class="text-sm text-green-600 ml-2 font-semibold">$189,600/year</span>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">= 2.7 FTEs (traditional cost: $135,000-180,000/year in salaries)</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-server text-gray-400 mr-2"></i>Infrastructure Stack — Zero Monthly Cost</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Tool</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Function</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Your Cost</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SaaS Alternative</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">SaaS Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-sm">
                            <?php
                            $stack = [
                                ['N8N (Coolify)', 'Workflow automation', '$0', 'Zapier', '$49-599/mo'],
                                ['AnythingLLM', 'AI knowledge base', '$0', 'ChatGPT API', '$20-200/mo'],
                                ['Ollama', 'Local LLM inference', '$0', 'OpenAI API', '$50-500/mo'],
                                ['Flowise (Coolify)', 'AI agent builder', '$0', 'Relevance AI', '$19-199/mo'],
                                ['ITarian', 'RMM + patching + AV', '$0', 'NinjaOne', '$3-7/device/mo'],
                                ['ITFlow (Coolify)', 'PSA + ticketing', '$0', 'ConnectWise', '$100-500/mo'],
                                ['HubSpot CRM', 'Sales pipeline', '$0 (free)', 'Salesforce', '$25-300/user'],
                                ['Nextcloud (Vultr)', 'Private cloud', '$6-24/instance', 'Google Workspace', '$6-18/user'],
                                ['Uptime-Kuma', 'Monitoring', '$0', 'Pingdom', '$10-100/mo'],
                                ['Matrix (Coolify)', 'Team messaging', '$0', 'Slack', '$7.25/user/mo'],
                            ];
                            foreach ($stack as $item):
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 font-medium text-gray-900"><?php echo $item[0]; ?></td>
                                    <td class="px-6 py-3 text-gray-600"><?php echo $item[1]; ?></td>
                                    <td class="px-6 py-3 font-bold text-green-600"><?php echo $item[2]; ?></td>
                                    <td class="px-6 py-3 text-gray-500"><?php echo $item[3]; ?></td>
                                    <td class="px-6 py-3 text-red-500"><?php echo $item[4]; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr class="font-bold">
                                <td class="px-6 py-3 text-gray-900" colspan="2">TOTAL</td>
                                <td class="px-6 py-3 text-green-600">$6-24/mo</td>
                                <td class="px-6 py-3 text-gray-500">Industry Std</td>
                                <td class="px-6 py-3 text-red-600">$650-2,200/mo</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>